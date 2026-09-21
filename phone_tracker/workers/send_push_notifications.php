<?php
declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . "/db.php";

function worker_config(): array
{
    $config = [
        "firebase_project_id" => getenv("FIREBASE_PROJECT_ID") ?: "",
        "firebase_service_account_file" => getenv("FIREBASE_SERVICE_ACCOUNT_FILE") ?: "",
    ];
    $local = dirname(__DIR__) . "/config/mobile_api.php";
    if (is_file($local)) {
        $values = require $local;
        if (is_array($values)) {
            $config = array_replace($config, $values);
        }
    }
    return $config;
}

function base64url(string $value): string
{
    return rtrim(strtr(base64_encode($value), "+/", "-_"), "=");
}

function http_post(string $url, array $headers, string $body): array
{
    if (!function_exists("curl_init")) {
        throw new RuntimeException("The PHP cURL extension is required");
    }
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($response === false) {
        throw new RuntimeException($error ?: "HTTP request failed");
    }
    return ["status" => $status, "body" => (string) $response];
}

function firebase_access_token(array $serviceAccount): string
{
    $email = (string) ($serviceAccount["client_email"] ?? "");
    $privateKey = (string) ($serviceAccount["private_key"] ?? "");
    $tokenUri = (string) ($serviceAccount["token_uri"] ?? "https://oauth2.googleapis.com/token");
    if ($email === "" || $privateKey === "") {
        throw new RuntimeException("Firebase service account JSON is incomplete");
    }
    $now = time();
    $header = base64url(json_encode(["alg" => "RS256", "typ" => "JWT"]));
    $claims = base64url(json_encode([
        "iss" => $email,
        "scope" => "https://www.googleapis.com/auth/firebase.messaging",
        "aud" => $tokenUri,
        "iat" => $now,
        "exp" => $now + 3600,
    ]));
    $unsigned = $header . "." . $claims;
    $signature = "";
    if (!openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException("Could not sign the Firebase OAuth request");
    }
    $assertion = $unsigned . "." . base64url($signature);
    $body = http_build_query([
        "grant_type" => "urn:ietf:params:oauth:grant-type:jwt-bearer",
        "assertion" => $assertion,
    ]);
    $response = http_post($tokenUri, ["Content-Type: application/x-www-form-urlencoded"], $body);
    $decoded = json_decode($response["body"], true);
    if ($response["status"] < 200 || $response["status"] >= 300 || !is_array($decoded) || empty($decoded["access_token"])) {
        throw new RuntimeException("Firebase OAuth token request failed");
    }
    return (string) $decoded["access_token"];
}

function fcm_error_code(array $response): string
{
    $details = $response["error"]["details"] ?? [];
    if (is_array($details)) {
        foreach ($details as $detail) {
            if (is_array($detail) && isset($detail["errorCode"])) {
                return (string) $detail["errorCode"];
            }
        }
    }
    return (string) ($response["error"]["status"] ?? "");
}

$config = worker_config();
$projectId = trim((string) ($config["firebase_project_id"] ?? ""));
$credentialsFile = trim((string) ($config["firebase_service_account_file"] ?? ""));
if ($projectId === "" || $credentialsFile === "" || !is_file($credentialsFile)) {
    fwrite(STDERR, "Firebase is not configured. Copy config/mobile_api.example.php to mobile_api.php and provide a service-account file.\n");
    exit(2);
}
$serviceAccount = json_decode((string) file_get_contents($credentialsFile), true);
if (!is_array($serviceAccount)) {
    fwrite(STDERR, "Firebase service-account JSON is invalid.\n");
    exit(2);
}
try {
    $accessToken = firebase_access_token($serviceAccount);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(2);
}

$conn->query(
    "UPDATE notification_deliveries
     SET status = 'failed', next_attempt_at = NOW(), last_error = 'Recovered interrupted delivery'
     WHERE status = 'sending' AND updated_at < NOW() - INTERVAL 10 MINUTE"
);
$result = $conn->query(
    "SELECT d.id, d.notification_id, d.device_id, d.attempt_count,
            n.appointment_id, n.notification_type, n.title, n.message, n.data_json,
            m.fcm_token
     FROM notification_deliveries d
     INNER JOIN app_notifications n ON n.id = d.notification_id
     INNER JOIN mobile_devices m ON m.id = d.device_id AND m.is_active = 1
     WHERE d.status IN ('queued','failed') AND d.next_attempt_at <= NOW() AND d.attempt_count < 5
     ORDER BY d.id ASC LIMIT 100"
);
if (!$result) {
    fwrite(STDERR, "Notification delivery migration is required.\n");
    exit(2);
}
$endpoint = "https://fcm.googleapis.com/v1/projects/" . rawurlencode($projectId) . "/messages:send";
$sent = 0;
$failed = 0;
$invalid = 0;
while ($delivery = $result->fetch_assoc()) {
    $deliveryId = (int) $delivery["id"];
    $claim = $conn->prepare(
        "UPDATE notification_deliveries SET status = 'sending', attempt_count = attempt_count + 1
         WHERE id = ? AND status IN ('queued','failed')"
    );
    $claim->bind_param("i", $deliveryId);
    $claim->execute();
    $claimed = $claim->affected_rows === 1;
    $claim->close();
    if (!$claimed) {
        continue;
    }
    $data = $delivery["data_json"] ? json_decode((string) $delivery["data_json"], true) : [];
    if (!is_array($data)) {
        $data = [];
    }
    $data = array_map(static fn($value): string => is_scalar($value) ? (string) $value : json_encode($value), $data);
    $data["notification_id"] = (string) $delivery["notification_id"];
    $data["appointment_id"] = $delivery["appointment_id"] === null ? "" : (string) $delivery["appointment_id"];
    $data["type"] = (string) $delivery["notification_type"];
    $body = json_encode([
        "message" => [
            "token" => (string) $delivery["fcm_token"],
            "notification" => ["title" => (string) $delivery["title"], "body" => (string) $delivery["message"]],
            "data" => $data,
            "android" => ["priority" => "high"],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    try {
        $response = http_post($endpoint, [
            "Authorization: Bearer " . $accessToken,
            "Content-Type: application/json",
        ], $body);
        $decoded = json_decode($response["body"], true);
        if ($response["status"] >= 200 && $response["status"] < 300 && is_array($decoded) && !empty($decoded["name"])) {
            $providerId = substr((string) $decoded["name"], 0, 255);
            $update = $conn->prepare(
                "UPDATE notification_deliveries SET status = 'sent', provider_message_id = ?, sent_at = NOW(), last_error = '' WHERE id = ?"
            );
            $update->bind_param("si", $providerId, $deliveryId);
            $update->execute();
            $update->close();
            $sent++;
            continue;
        }
        $code = is_array($decoded) ? fcm_error_code($decoded) : "HTTP_" . $response["status"];
        $message = substr((string) ($decoded["error"]["message"] ?? $code), 0, 1000);
        if (in_array($code, ["UNREGISTERED", "SENDER_ID_MISMATCH"], true)) {
            $update = $conn->prepare("UPDATE notification_deliveries SET status = 'invalid_token', last_error = ? WHERE id = ?");
            $update->bind_param("si", $message, $deliveryId);
            $update->execute();
            $update->close();
            $deviceId = (int) $delivery["device_id"];
            $disable = $conn->prepare("UPDATE mobile_devices SET is_active = 0 WHERE id = ?");
            $disable->bind_param("i", $deviceId);
            $disable->execute();
            $disable->close();
            $invalid++;
        } else {
            throw new RuntimeException($message);
        }
    } catch (Throwable $error) {
        $attempt = (int) $delivery["attempt_count"] + 1;
        $delayMinutes = min(60, 2 ** min(5, $attempt));
        $next = (new DateTimeImmutable("now"))->modify("+{$delayMinutes} minutes")->format("Y-m-d H:i:s");
        $message = substr($error->getMessage(), 0, 1000);
        $update = $conn->prepare(
            "UPDATE notification_deliveries SET status = 'failed', next_attempt_at = ?, last_error = ? WHERE id = ?"
        );
        $update->bind_param("ssi", $next, $message, $deliveryId);
        $update->execute();
        $update->close();
        $failed++;
    }
}
echo "Push worker complete: {$sent} sent, {$failed} failed, {$invalid} invalid token(s).\n";


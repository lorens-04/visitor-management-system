<?php
declare(strict_types=1);
require_once dirname(__DIR__) . "/_bootstrap.php";

api_require_method("POST");
$input = api_body();
$identifier = strtolower(api_text($input, "identifier", 190));
$password = (string) ($input["password"] ?? "");
$installationId = api_text($input, "installation_id", 191);
$deviceName = api_text($input, "device_name", 100);
if ($identifier === "" || $password === "") {
    api_fail("Email and password are required", 422);
}
api_rate_limit($conn, "login", $identifier, 10, 900);

$stmt = $conn->prepare(
    "SELECT id, username, email, password_hash, display_name, contact_number,
            email_verified_at, role, is_active
     FROM app_users
     WHERE (LOWER(email) = ? OR LOWER(username) = ?) AND role = 'visitor'
     LIMIT 1"
);
if (!$stmt) {
    api_fail("Mobile API database migration is required", 503);
}
$stmt->bind_param("ss", $identifier, $identifier);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$user || !(int) $user["is_active"] || !password_verify($password, (string) $user["password_hash"])) {
    api_fail("Invalid email or password", 401);
}
if (password_needs_rehash((string) $user["password_hash"], PASSWORD_DEFAULT)) {
    $rehash = password_hash($password, PASSWORD_DEFAULT);
    $update = $conn->prepare("UPDATE app_users SET password_hash = ? WHERE id = ?");
    $userId = (int) $user["id"];
    $update->bind_param("si", $rehash, $userId);
    $update->execute();
    $update->close();
}
$access = api_issue_access_token($conn, (int) $user["id"], $installationId, $deviceName);
api_audit($conn, (int) $user["id"], "mobile.signed_in", "api_access_token", (string) $access["id"], ["installation_id" => $installationId]);
api_success([
    "access_token" => $access["token"],
    "token_type" => "Bearer",
    "expires_at" => $access["expires_at"],
    "user" => api_user_payload($user),
]);

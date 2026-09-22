<?php
declare(strict_types=1);
require_once __DIR__ . "/_bootstrap.php";

api_require_method("POST");
$user = api_require_visitor();
$input = api_body();
$sessionId = (int) ($input["tracking_session_id"] ?? 0);
$points = isset($input["points"]) && is_array($input["points"]) ? $input["points"] : [];
if (!$points && isset($input["latitude"])) {
    $points = [$input];
}
$maxPoints = api_setting($conn, "location_batch_max_points", 100, 1, 500);
if ($sessionId <= 0 || !$points || count($points) > $maxPoints) {
    api_fail("Provide a valid tracking session and between 1 and {$maxPoints} location points", 422);
}
$userId = (int) $user["id"];
$stmt = $conn->prepare(
    "SELECT s.id, s.appointment_id, s.started_at, s.ended_at, s.ended_reason,
            a.status, a.checked_in_at, a.completed_at, a.device_name
     FROM location_tracking_sessions s
     INNER JOIN appointments a ON a.id = s.appointment_id
     WHERE s.id = ? AND s.visitor_user_id = ? AND a.visitor_user_id = ? LIMIT 1"
);
if (!$stmt) {
    api_fail("Mobile API database migration is required", 503);
}
$stmt->bind_param("iii", $sessionId, $userId, $userId);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$session) {
    api_fail("Tracking session not found", 404);
}
$appointmentStatus = (string) $session["status"];
$graceHours = api_setting($conn, "location_offline_upload_grace_hours", 24, 1, 168);
$completedAt = $session["completed_at"] ? strtotime((string) $session["completed_at"]) : null;
if ($appointmentStatus !== "checked_in") {
    $withinCompletedGrace = $appointmentStatus === "completed" && $completedAt
        && time() <= $completedAt + ($graceHours * 3600);
    if (!$withinCompletedGrace) {
        api_fail("Tracking is no longer accepting location updates", 409);
    }
}
$consent = $conn->prepare(
    "SELECT id FROM visitor_consents
     WHERE appointment_id = ? AND visitor_user_id = ? AND consent_type = 'location_tracking' AND withdrawn_at IS NULL
     ORDER BY id DESC LIMIT 1"
);
$appointmentId = (int) $session["appointment_id"];
$consent->bind_param("ii", $appointmentId, $userId);
$consent->execute();
$hasConsent = (bool) $consent->get_result()->fetch_assoc();
$consent->close();
if (!$hasConsent) {
    api_fail("Location consent has been withdrawn", 409);
}
$checkedInAt = strtotime((string) $session["checked_in_at"]);
$latestAllowed = $appointmentStatus === "completed" && $completedAt ? $completedAt : time() + 300;
$validated = [];
$errors = [];
foreach ($points as $index => $point) {
    if (!is_array($point)) {
        $errors[(string) $index] = "Point must be an object";
        continue;
    }
    $eventId = trim((string) ($point["client_event_id"] ?? ""));
    $latitude = $point["latitude"] ?? null;
    $longitude = $point["longitude"] ?? null;
    $accuracy = $point["accuracy"] ?? null;
    $capturedText = trim((string) ($point["captured_at"] ?? ""));
    $captured = $capturedText !== "" ? strtotime($capturedText) : time();
    if (!preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $eventId)) {
        $errors[(string) $index] = "client_event_id must contain 8–64 safe characters";
    } elseif (!is_numeric($latitude) || (float) $latitude < -90 || (float) $latitude > 90
        || !is_numeric($longitude) || (float) $longitude < -180 || (float) $longitude > 180) {
        $errors[(string) $index] = "Coordinates are invalid";
    } elseif ($accuracy !== null && (!is_numeric($accuracy) || (float) $accuracy < 0 || (float) $accuracy > 10000)) {
        $errors[(string) $index] = "Accuracy is invalid";
    } elseif (!$captured || $captured < $checkedInAt || $captured > $latestAllowed) {
        $errors[(string) $index] = "captured_at is outside the active visit";
    } else {
        $validated[] = [
            "event_id" => $eventId,
            "latitude" => (float) $latitude,
            "longitude" => (float) $longitude,
            "accuracy" => $accuracy === null ? null : (float) $accuracy,
            "captured_at" => date("Y-m-d H:i:s", $captured),
        ];
    }
}
if ($errors) {
    api_fail("One or more location points are invalid", 422, ["points" => $errors]);
}

$inserted = 0;
$duplicates = 0;
$conn->begin_transaction();
try {
    $insert = $conn->prepare(
        "INSERT IGNORE INTO locations
         (appointment_id, visitor_user_id, tracking_session_id, client_event_id, device_name,
          latitude, longitude, accuracy, captured_at, received_at, recorded_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)"
    );
    foreach ($validated as $point) {
        $eventId = $point["event_id"];
        $deviceName = (string) $session["device_name"];
        $latitude = $point["latitude"];
        $longitude = $point["longitude"];
        $accuracy = $point["accuracy"];
        $capturedAt = $point["captured_at"];
        $insert->bind_param(
            "iiissdddss",
            $appointmentId, $userId, $sessionId, $eventId, $deviceName,
            $latitude, $longitude, $accuracy, $capturedAt, $capturedAt
        );
        $insert->execute();
        if ($insert->affected_rows === 1) {
            $inserted++;
        } else {
            $duplicates++;
        }
    }
    $insert->close();
    $touch = $conn->prepare("UPDATE location_tracking_sessions SET last_upload_at = NOW() WHERE id = ?");
    $touch->bind_param("i", $sessionId);
    $touch->execute();
    $touch->close();
    if ($appointmentStatus === "completed") {
        $end = $conn->prepare(
            "UPDATE location_tracking_sessions SET ended_at = COALESCE(ended_at, ?), ended_reason = 'completed' WHERE id = ?"
        );
        $completedSql = (string) $session["completed_at"];
        $end->bind_param("si", $completedSql, $sessionId);
        $end->execute();
        $end->close();
    }
    $conn->commit();
} catch (Throwable $error) {
    $conn->rollback();
    api_fail("Could not save the location batch", 500);
}
api_success([
    "tracking_session_id" => $sessionId,
    "accepted" => $inserted,
    "duplicates" => $duplicates,
    "server_time" => date("Y-m-d H:i:s"),
], 200, "Location batch processed");


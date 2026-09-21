<?php
declare(strict_types=1);
require_once __DIR__ . "/_bootstrap.php";

api_require_method("GET", "POST");
$user = api_require_visitor();
$userId = (int) $user["id"];
$input = $_SERVER["REQUEST_METHOD"] === "POST" ? api_body() : [];
$appointmentId = (int) ($input["appointment_id"] ?? $_GET["appointment_id"] ?? 0);
if ($appointmentId <= 0) {
    api_fail("Select a valid appointment", 422);
}
$stmt = $conn->prepare(
    "SELECT id, status, checked_in_at, completed_at, scheduled_end_at
     FROM appointments WHERE id = ? AND visitor_user_id = ? LIMIT 1"
);
$stmt->bind_param("ii", $appointmentId, $userId);
$stmt->execute();
$appointment = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$appointment) {
    api_fail("Appointment not found", 404);
}
if ($appointment["status"] === "completed") {
    $end = $conn->prepare(
        "UPDATE location_tracking_sessions SET ended_at = COALESCE(ended_at, ?), ended_reason = 'completed'
         WHERE appointment_id = ? AND ended_at IS NULL"
    );
    $completedAt = (string) $appointment["completed_at"];
    $end->bind_param("si", $completedAt, $appointmentId);
    $end->execute();
    $end->close();
}

$policy = [
    "upload_interval_seconds" => api_setting($conn, "location_upload_interval_seconds", 15, 5, 300),
    "offline_upload_grace_hours" => api_setting($conn, "location_offline_upload_grace_hours", 24, 1, 168),
    "maximum_batch_points" => api_setting($conn, "location_batch_max_points", 100, 1, 500),
    "retention_days" => api_setting($conn, "location_retention_days", 90, 1, 3650),
];
$loadSession = function () use ($conn, $appointmentId, $userId): ?array {
    $session = $conn->prepare(
        "SELECT id, client_session_id, installation_id, started_at, last_upload_at, ended_at, ended_reason
         FROM location_tracking_sessions WHERE appointment_id = ? AND visitor_user_id = ? LIMIT 1"
    );
    $session->bind_param("ii", $appointmentId, $userId);
    $session->execute();
    $row = $session->get_result()->fetch_assoc();
    $session->close();
    if (!$row) {
        return null;
    }
    $row["id"] = (int) $row["id"];
    $row["active"] = $row["ended_at"] === null;
    return $row;
};
if ($_SERVER["REQUEST_METHOD"] === "GET") {
    api_success([
        "appointment_id" => $appointmentId,
        "appointment_status" => $appointment["status"],
        "tracking_allowed" => $appointment["status"] === "checked_in",
        "session" => $loadSession(),
        "policy" => $policy,
        "server_time" => date("Y-m-d H:i:s"),
    ]);
}

$action = strtolower(api_text($input, "action", 16));
if (!in_array($action, ["start", "stop"], true)) {
    api_fail("Tracking action must be start or stop", 422);
}
if ($action === "stop") {
    $end = $conn->prepare(
        "UPDATE location_tracking_sessions SET ended_at = COALESCE(ended_at, NOW()), ended_reason = 'manual'
         WHERE appointment_id = ? AND visitor_user_id = ? AND ended_at IS NULL"
    );
    $end->bind_param("ii", $appointmentId, $userId);
    $end->execute();
    $end->close();
    api_success(["session" => $loadSession(), "policy" => $policy], 200, "Location tracking stopped on this device");
}
if ($appointment["status"] !== "checked_in") {
    api_fail("Tracking starts only after Security checks the visitor in", 409);
}
$consent = $conn->prepare(
    "SELECT id FROM visitor_consents
     WHERE appointment_id = ? AND visitor_user_id = ? AND consent_type = 'location_tracking' AND withdrawn_at IS NULL
     ORDER BY id DESC LIMIT 1"
);
$consent->bind_param("ii", $appointmentId, $userId);
$consent->execute();
$consentRow = $consent->get_result()->fetch_assoc();
$consent->close();
if (!$consentRow) {
    api_fail("Active location consent is required", 409);
}
$clientSessionId = api_text($input, "client_session_id", 64);
if ($clientSessionId === "") {
    $clientSessionId = bin2hex(random_bytes(16));
}
$installationId = (string) ($user["installation_id"] ?? "");
$consentId = (int) $consentRow["id"];
$tokenId = (int) $user["access_token_id"];
$upsert = $conn->prepare(
    "INSERT INTO location_tracking_sessions
     (appointment_id, visitor_user_id, consent_id, access_token_id, installation_id, client_session_id)
     VALUES (?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE consent_id = VALUES(consent_id), access_token_id = VALUES(access_token_id),
       installation_id = VALUES(installation_id), client_session_id = VALUES(client_session_id),
       ended_at = NULL, ended_reason = NULL"
);
if (!$upsert) {
    api_fail("Mobile API database migration is required", 503);
}
$upsert->bind_param("iiiiss", $appointmentId, $userId, $consentId, $tokenId, $installationId, $clientSessionId);
$upsert->execute();
$upsert->close();
api_audit($conn, $userId, "mobile.tracking_started", "appointment", (string) $appointmentId, ["installation_id" => $installationId], $appointmentId);
api_success(["session" => $loadSession(), "policy" => $policy, "server_time" => date("Y-m-d H:i:s")], 200, "Location tracking session ready");


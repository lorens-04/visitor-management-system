<?php
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
}

require_roles_json(["security", "admin"]);
$input = json_decode(file_get_contents("php://input"), true);
if (!is_array($input)) {
    echo json_encode(["success" => false, "message" => "Invalid request body"]);
    exit;
}

$appointmentId = isset($input["appointment_id"]) ? (int) $input["appointment_id"] : 0;
$reason = isset($input["reason"]) ? trim((string) $input["reason"]) : "";
$validMinutes = isset($input["valid_minutes"]) ? (int) $input["valid_minutes"] : 30;
$validMinutes = max(5, min(240, $validMinutes));

if ($appointmentId <= 0 || strlen($reason) < 5) {
    echo json_encode(["success" => false, "message" => "Appointment and a clear override reason are required"]);
    exit;
}
if (strlen($reason) > 500) {
    $reason = substr($reason, 0, 500);
}

$stmt = $conn->prepare(
    "SELECT id, status, scheduled_start_at, scheduled_end_at
     FROM appointments WHERE id = ? LIMIT 1"
);
$stmt->bind_param("i", $appointmentId);
$stmt->execute();
$appointment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$appointment || !in_array($appointment["status"], ["approved", "window_closed"], true)) {
    $conn->close();
    echo json_encode(["success" => false, "message" => "Only approved or closed appointment windows can be overridden"]);
    exit;
}

$start = new DateTime($appointment["scheduled_start_at"]);
$start->modify("-30 minutes");
$end = new DateTime($appointment["scheduled_end_at"]);
$overrideEnd = new DateTime("now");
$overrideEnd->modify("+" . $validMinutes . " minutes");
$authorizedBy = (int) $_SESSION["user_id"];
$originalStatus = (string) $appointment["status"];
$validFromSql = $start->format("Y-m-d H:i:s");
$validUntilSql = $end->format("Y-m-d H:i:s");
$overrideUntilSql = $overrideEnd->format("Y-m-d H:i:s");

$conn->begin_transaction();
try {
    $insert = $conn->prepare(
        "INSERT INTO appointment_qr_overrides
         (appointment_id, authorized_by_user_id, reason, original_valid_from, original_valid_until, override_valid_until)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $insert->bind_param("iissss", $appointmentId, $authorizedBy, $reason, $validFromSql, $validUntilSql, $overrideUntilSql);
    $insert->execute();
    $overrideId = (int) $conn->insert_id;
    $insert->close();

    if ($originalStatus === "window_closed") {
        $reopen = $conn->prepare(
            "UPDATE appointments SET status = 'approved', status_updated_at = NOW(), window_closed_at = NULL
             WHERE id = ? AND status = 'window_closed'"
        );
        $reopen->bind_param("i", $appointmentId);
        $reopen->execute();
        $reopen->close();

        $history = $conn->prepare(
            "INSERT INTO appointment_status_history
             (appointment_id, from_status, to_status, changed_by_user_id, note)
             VALUES (?, 'window_closed', 'approved', ?, 'Appointment window reopened by authorized override')"
        );
        $history->bind_param("ii", $appointmentId, $authorizedBy);
        $history->execute();
        $history->close();
    }

    $audit = $conn->prepare(
        "INSERT INTO audit_logs
         (actor_user_id, appointment_id, action, entity_type, entity_id, details_json, ip_address)
         VALUES (?, ?, 'appointment.qr_override_created', 'appointment', ?, ?, ?)"
    );
    if ($audit) {
        $entityId = (string) $appointmentId;
        $details = json_encode(["override_id" => $overrideId, "reason" => $reason, "valid_until" => $overrideUntilSql]);
        $ipAddress = isset($_SERVER["REMOTE_ADDR"]) ? substr((string) $_SERVER["REMOTE_ADDR"], 0, 45) : "";
        $audit->bind_param("iisss", $authorizedBy, $appointmentId, $entityId, $details, $ipAddress);
        $audit->execute();
        $audit->close();
    }
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    $conn->close();
    echo json_encode(["success" => false, "message" => "Could not create the QR time override"]);
    exit;
}

$conn->close();
echo json_encode([
    "success" => true,
    "override_id" => $overrideId,
    "appointment_id" => $appointmentId,
    "valid_until" => $overrideUntilSql,
    "message" => "QR time override recorded",
]);

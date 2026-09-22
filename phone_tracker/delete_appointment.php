<?php
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
}

require_roles_json(["visitor"]);
$input = json_decode(file_get_contents("php://input"), true);
$appointmentId = is_array($input) && isset($input["id"]) ? (int) $input["id"] : 0;
$visitorUserId = (int) $_SESSION["user_id"];
if ($appointmentId <= 0) {
    echo json_encode(["success" => false, "message" => "Invalid appointment"]);
    exit;
}

$conn->begin_transaction();
try {
    $select = $conn->prepare(
        "SELECT status FROM appointments WHERE id = ? AND visitor_user_id = ? FOR UPDATE"
    );
    $select->bind_param("ii", $appointmentId, $visitorUserId);
    $select->execute();
    $appointment = $select->get_result()->fetch_assoc();
    $select->close();
    if (!$appointment) {
        throw new RuntimeException("Appointment not found");
    }

    $fromStatus = (string) $appointment["status"];
    if (!in_array($fromStatus, ["pending_approval", "approved", "reschedule_proposed"], true)) {
        throw new RuntimeException("This appointment can no longer be cancelled");
    }

    $update = $conn->prepare(
        "UPDATE appointments
         SET status = 'cancelled', status_updated_at = NOW(), cancelled_at = NOW(), cancelled_by_user_id = ?
         WHERE id = ? AND visitor_user_id = ?"
    );
    $update->bind_param("iii", $visitorUserId, $appointmentId, $visitorUserId);
    $update->execute();
    $update->close();

    $history = $conn->prepare(
        "INSERT INTO appointment_status_history
         (appointment_id, from_status, to_status, changed_by_user_id, note)
         VALUES (?, ?, 'cancelled', ?, 'Cancelled by visitor')"
    );
    $history->bind_param("isi", $appointmentId, $fromStatus, $visitorUserId);
    $history->execute();
    $history->close();

    $audit = $conn->prepare(
        "INSERT INTO audit_logs
         (actor_user_id, appointment_id, action, entity_type, entity_id, details_json, ip_address)
         VALUES (?, ?, 'appointment.cancelled', 'appointment', ?, ?, ?)"
    );
    if ($audit) {
        $entityId = (string) $appointmentId;
        $details = json_encode(["from_status" => $fromStatus]);
        $ipAddress = isset($_SERVER["REMOTE_ADDR"]) ? substr((string) $_SERVER["REMOTE_ADDR"], 0, 45) : "";
        $audit->bind_param("iisss", $visitorUserId, $appointmentId, $entityId, $details, $ipAddress);
        $audit->execute();
        $audit->close();
    }
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    $conn->close();
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
    exit;
}

$conn->close();
echo json_encode(["success" => true, "message" => "Appointment cancelled"]);

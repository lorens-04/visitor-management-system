<?php
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/office_availability_service.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
}
require_roles_json(["offices"]);

$input = json_decode(file_get_contents("php://input"), true);
if (!is_array($input)) {
    echo json_encode(["success" => false, "message" => "Invalid request body"]);
    exit;
}
$appointmentId = (int) ($input["appointment_id"] ?? 0);
$action = strtolower(trim((string) ($input["action"] ?? "")));
$reason = trim((string) ($input["reason"] ?? ""));
$officeCode = strtoupper(trim((string) ($_SESSION["office_code"] ?? "")));
$actorId = (int) $_SESSION["user_id"];

if ($appointmentId <= 0 || !in_array($action, ["approve", "reject"], true)) {
    echo json_encode(["success" => false, "message" => "Invalid appointment action"]);
    exit;
}
if ($action === "reject" && strlen($reason) < 5) {
    echo json_encode(["success" => false, "message" => "Explain why the appointment is being declined"]);
    exit;
}
$reason = substr($reason, 0, 500);

$conn->begin_transaction();
try {
    $lookup = $conn->prepare(
        "SELECT id, office_code, visitor_user_id, visitor_full_name, status,
                scheduled_start_at, scheduled_end_at
         FROM appointments WHERE id = ? AND office_code = ? FOR UPDATE"
    );
    if (!$lookup) {
        throw new RuntimeException("Database update required");
    }
    $lookup->bind_param("is", $appointmentId, $officeCode);
    $lookup->execute();
    $appointment = $lookup->get_result()->fetch_assoc();
    $lookup->close();
    if (!$appointment) {
        throw new RuntimeException("Appointment not found for this office");
    }
    if ($appointment["status"] !== "pending_approval") {
        throw new RuntimeException("Only pending appointment requests can be processed");
    }

    $fromStatus = "pending_approval";
    $toStatus = $action === "approve" ? "approved" : "rejected";
    if ($action === "approve") {
        $start = new DateTime((string) $appointment["scheduled_start_at"]);
        $end = new DateTime((string) $appointment["scheduled_end_at"]);
        if ($end <= new DateTime("now")) {
            throw new RuntimeException("This appointment time has already ended");
        }
        $availability = office_availability_check($conn, $officeCode, $start, $end, $appointmentId);
        if (!$availability["available"]) {
            throw new RuntimeException($availability["message"] . " Suggest another schedule instead.");
        }
        $update = $conn->prepare(
            "UPDATE appointments
             SET status = 'approved', status_updated_at = NOW(), approved_at = NOW(),
                 approved_by_user_id = ?, qr_issued_at = NOW(), rejection_reason = ''
             WHERE id = ? AND status = 'pending_approval'"
        );
        $update->bind_param("ii", $actorId, $appointmentId);
        $historyNote = "Appointment approved by office personnel";
        $notificationTitle = "Appointment approved";
        $notificationMessage = "Your appointment for " . date("M j, Y g:i A", strtotime((string) $appointment["scheduled_start_at"])) . " was approved. Your QR visitor pass is now available.";
    } else {
        $update = $conn->prepare(
            "UPDATE appointments
             SET status = 'rejected', status_updated_at = NOW(), rejected_at = NOW(),
                 rejected_by_user_id = ?, rejection_reason = ?
             WHERE id = ? AND status = 'pending_approval'"
        );
        $update->bind_param("isi", $actorId, $reason, $appointmentId);
        $historyNote = "Declined: " . $reason;
        $notificationTitle = "Appointment declined";
        $notificationMessage = "The office declined your appointment. Reason: " . $reason;
    }

    if (!$update || !$update->execute() || $update->affected_rows !== 1) {
        throw new RuntimeException("The appointment changed before this action was completed");
    }
    $update->close();

    $history = $conn->prepare(
        "INSERT INTO appointment_status_history
         (appointment_id, from_status, to_status, changed_by_user_id, note)
         VALUES (?, ?, ?, ?, ?)"
    );
    $history->bind_param("issis", $appointmentId, $fromStatus, $toStatus, $actorId, $historyNote);
    $history->execute();
    $history->close();

    $visitorUserId = (int) $appointment["visitor_user_id"];
    $notificationType = "appointment." . $toStatus;
    $notification = $conn->prepare(
        "INSERT INTO app_notifications
         (recipient_user_id, appointment_id, notification_type, title, message)
         VALUES (?, ?, ?, ?, ?)"
    );
    $notification->bind_param("iisss", $visitorUserId, $appointmentId, $notificationType, $notificationTitle, $notificationMessage);
    $notification->execute();
    $notification->close();

    $audit = $conn->prepare(
        "INSERT INTO audit_logs
         (actor_user_id, appointment_id, action, entity_type, entity_id, details_json, ip_address)
         VALUES (?, ?, ?, 'appointment', ?, ?, ?)"
    );
    if ($audit) {
        $auditAction = "appointment." . $toStatus;
        $entityId = (string) $appointmentId;
        $details = json_encode(["from_status" => $fromStatus, "to_status" => $toStatus, "reason" => $reason]);
        $ipAddress = substr((string) ($_SERVER["REMOTE_ADDR"] ?? ""), 0, 45);
        $audit->bind_param("iissss", $actorId, $appointmentId, $auditAction, $entityId, $details, $ipAddress);
        $audit->execute();
        $audit->close();
    }

    $conn->commit();
    $conn->close();
    echo json_encode([
        "success" => true,
        "appointment_id" => $appointmentId,
        "status" => $toStatus,
        "message" => $action === "approve" ? "Appointment approved" : "Appointment declined",
    ]);
} catch (Throwable $error) {
    $conn->rollback();
    $conn->close();
    echo json_encode(["success" => false, "message" => $error->getMessage()]);
}


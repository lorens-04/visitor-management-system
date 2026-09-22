<?php
declare(strict_types=1);
require_once __DIR__ . "/_bootstrap.php";
require_once dirname(__DIR__, 2) . "/office_availability_service.php";

api_require_method("POST");
$user = api_require_visitor();
$input = api_body();
$proposalId = (int) ($input["proposal_id"] ?? 0);
$action = strtolower(api_text($input, "action", 16));
$selectedSlotId = (int) ($input["selected_slot_id"] ?? 0);
$visitorUserId = (int) $user["id"];
if ($proposalId <= 0 || !in_array($action, ["accept", "decline"], true)) {
    api_fail("Invalid reschedule response", 422);
}
if ($action === "accept" && $selectedSlotId <= 0) {
    api_fail("Choose one of the proposed schedules", 422);
}

$conn->begin_transaction();
try {
    $lookup = $conn->prepare(
        "SELECT p.id, p.appointment_id, p.proposed_by_user_id, p.status AS proposal_status,
                p.response_deadline, a.office_code, a.status AS appointment_status, a.visitor_full_name
         FROM appointment_reschedule_proposals p
         INNER JOIN appointments a ON a.id = p.appointment_id
         WHERE p.id = ? AND a.visitor_user_id = ? FOR UPDATE"
    );
    $lookup->bind_param("ii", $proposalId, $visitorUserId);
    $lookup->execute();
    $proposal = $lookup->get_result()->fetch_assoc();
    $lookup->close();
    if (!$proposal || $proposal["proposal_status"] !== "pending" || $proposal["appointment_status"] !== "reschedule_proposed") {
        throw new DomainException("This schedule proposal is no longer available");
    }
    if ($proposal["response_deadline"] && strtotime((string) $proposal["response_deadline"]) < time()) {
        throw new DomainException("The response deadline for this proposal has passed");
    }
    $appointmentId = (int) $proposal["appointment_id"];
    $officeCode = (string) $proposal["office_code"];
    if ($action === "accept") {
        $slotStmt = $conn->prepare(
            "SELECT id, scheduled_start_at, scheduled_end_at
             FROM appointment_reschedule_slots WHERE id = ? AND proposal_id = ? LIMIT 1"
        );
        $slotStmt->bind_param("ii", $selectedSlotId, $proposalId);
        $slotStmt->execute();
        $slot = $slotStmt->get_result()->fetch_assoc();
        $slotStmt->close();
        if (!$slot) {
            throw new DomainException("The selected schedule is not part of this proposal");
        }
        $start = new DateTime((string) $slot["scheduled_start_at"]);
        $end = new DateTime((string) $slot["scheduled_end_at"]);
        if ($start <= new DateTime("now")) {
            throw new DomainException("The selected schedule has already passed");
        }
        $availability = office_availability_check($conn, $officeCode, $start, $end, $appointmentId);
        if (!$availability["available"]) {
            throw new DomainException($availability["message"] . " Ask the office for another schedule.");
        }
        $startSql = $start->format("Y-m-d H:i:s");
        $endSql = $end->format("Y-m-d H:i:s");
        $approvedBy = (int) $proposal["proposed_by_user_id"];
        $update = $conn->prepare(
            "UPDATE appointments SET appointment_at = ?, scheduled_start_at = ?, scheduled_end_at = ?,
             status = 'approved', status_updated_at = NOW(), approved_at = NOW(), approved_by_user_id = ?,
             qr_issued_at = NOW(), rejection_reason = '' WHERE id = ? AND status = 'reschedule_proposed'"
        );
        $update->bind_param("sssii", $startSql, $startSql, $endSql, $approvedBy, $appointmentId);
        $update->execute();
        if ($update->affected_rows !== 1) {
            throw new RuntimeException("Appointment changed before the response was saved");
        }
        $update->close();
        $proposalUpdate = $conn->prepare("UPDATE appointment_reschedule_proposals SET status = 'accepted', responded_at = NOW() WHERE id = ?");
        $proposalUpdate->bind_param("i", $proposalId);
        $proposalUpdate->execute();
        $proposalUpdate->close();
        $selectSlot = $conn->prepare("UPDATE appointment_reschedule_slots SET is_selected = (id = ?) WHERE proposal_id = ?");
        $selectSlot->bind_param("ii", $selectedSlotId, $proposalId);
        $selectSlot->execute();
        $selectSlot->close();
        $toStatus = "approved";
        $note = "Visitor accepted the proposed schedule for " . $start->format("M j, Y g:i A");
        $officeTitle = "Proposed schedule accepted";
        $officeMessage = $proposal["visitor_full_name"] . " accepted " . $start->format("M j, Y g:i A") . ".";
    } else {
        $proposalUpdate = $conn->prepare("UPDATE appointment_reschedule_proposals SET status = 'declined', responded_at = NOW() WHERE id = ?");
        $proposalUpdate->bind_param("i", $proposalId);
        $proposalUpdate->execute();
        $proposalUpdate->close();
        $update = $conn->prepare(
            "UPDATE appointments SET status = 'cancelled', status_updated_at = NOW(), cancelled_at = NOW(),
             cancelled_by_user_id = ? WHERE id = ? AND status = 'reschedule_proposed'"
        );
        $update->bind_param("ii", $visitorUserId, $appointmentId);
        $update->execute();
        $update->close();
        $toStatus = "cancelled";
        $note = "Visitor declined the proposed schedules";
        $officeTitle = "Proposed schedule declined";
        $officeMessage = $proposal["visitor_full_name"] . " declined the alternative schedules.";
    }
    $history = $conn->prepare(
        "INSERT INTO appointment_status_history
         (appointment_id, from_status, to_status, changed_by_user_id, note)
         VALUES (?, 'reschedule_proposed', ?, ?, ?)"
    );
    $history->bind_param("isis", $appointmentId, $toStatus, $visitorUserId, $note);
    $history->execute();
    $history->close();
    $notify = $conn->prepare(
        "INSERT INTO app_notifications
         (recipient_user_id, appointment_id, notification_type, title, message)
         SELECT id, ?, 'appointment.reschedule_response', ?, ?
         FROM app_users WHERE role = 'offices' AND office_code = ? AND is_active = 1"
    );
    $notify->bind_param("isss", $appointmentId, $officeTitle, $officeMessage, $officeCode);
    $notify->execute();
    $notify->close();
    api_audit($conn, $visitorUserId, "mobile.reschedule_response", "appointment", (string) $appointmentId, [
        "proposal_id" => $proposalId,
        "response" => $action,
        "selected_slot_id" => $selectedSlotId ?: null,
    ], $appointmentId);
    $conn->commit();
} catch (DomainException $error) {
    $conn->rollback();
    api_fail($error->getMessage(), 409);
} catch (Throwable $error) {
    $conn->rollback();
    api_fail("Could not save the schedule response", 500);
}
api_success([
    "appointment_id" => $appointmentId,
    "status" => $toStatus,
], 200, $action === "accept" ? "Schedule accepted. Your QR pass is now available." : "Proposed schedules declined.");


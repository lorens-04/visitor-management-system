<?php
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";

require_roles_json(["offices"]);
$appointmentId = isset($_GET["id"]) ? (int) $_GET["id"] : 0;
$officeCode = strtoupper(trim((string) ($_SESSION["office_code"] ?? "")));
if ($appointmentId <= 0 || $officeCode === "") {
    echo json_encode(["success" => false, "message" => "Invalid appointment"]);
    exit;
}

$stmt = $conn->prepare(
    "SELECT a.id, a.registration_code, a.visitor_full_name, a.visitor_email,
            a.contact_number, a.visit_type, a.purpose, a.destination, a.subject,
            a.additional_details, a.scheduled_start_at, a.scheduled_end_at,
            a.status, a.rejection_reason, a.created_at, a.status_updated_at,
            a.approved_at, a.rejected_at, a.checked_in_at, a.completed_at,
            COALESCE(NULLIF(processor.display_name, ''), processor.username, '') AS processed_by
     FROM appointments a
     LEFT JOIN app_users processor ON processor.id = COALESCE(
         a.approved_by_user_id, a.rejected_by_user_id, a.completed_by_user_id, a.cancelled_by_user_id
     )
     WHERE a.id = ? AND a.office_code = ? LIMIT 1"
);
if (!$stmt) {
    echo json_encode(["success" => false, "message" => "Database update required. Run the Phase 1 migration."]);
    exit;
}
$stmt->bind_param("is", $appointmentId, $officeCode);
$stmt->execute();
$appointment = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$appointment) {
    $conn->close();
    http_response_code(404);
    echo json_encode(["success" => false, "message" => "Appointment not found for this office"]);
    exit;
}
$appointment["id"] = (int) $appointment["id"];

$history = [];
$historyStmt = $conn->prepare(
    "SELECT h.from_status, h.to_status, h.note, h.changed_at,
            COALESCE(NULLIF(u.display_name, ''), u.username, 'System') AS changed_by
     FROM appointment_status_history h
     LEFT JOIN app_users u ON u.id = h.changed_by_user_id
     WHERE h.appointment_id = ? ORDER BY h.changed_at DESC, h.id DESC"
);
if ($historyStmt) {
    $historyStmt->bind_param("i", $appointmentId);
    $historyStmt->execute();
    $result = $historyStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $history[] = $row;
    }
    $historyStmt->close();
}

$proposal = null;
$proposalStmt = $conn->prepare(
    "SELECT id, reason, message, status, response_deadline, responded_at, created_at
     FROM appointment_reschedule_proposals
     WHERE appointment_id = ? ORDER BY id DESC LIMIT 1"
);
if ($proposalStmt) {
    $proposalStmt->bind_param("i", $appointmentId);
    $proposalStmt->execute();
    $proposal = $proposalStmt->get_result()->fetch_assoc();
    $proposalStmt->close();
    if ($proposal) {
        $proposal["id"] = (int) $proposal["id"];
        $proposal["slots"] = [];
        $slotStmt = $conn->prepare(
            "SELECT id, scheduled_start_at, scheduled_end_at, is_selected
             FROM appointment_reschedule_slots WHERE proposal_id = ?
             ORDER BY scheduled_start_at ASC"
        );
        if ($slotStmt) {
            $slotStmt->bind_param("i", $proposal["id"]);
            $slotStmt->execute();
            $result = $slotStmt->get_result();
            while ($slot = $result->fetch_assoc()) {
                $slot["id"] = (int) $slot["id"];
                $slot["is_selected"] = (int) $slot["is_selected"];
                $proposal["slots"][] = $slot;
            }
            $slotStmt->close();
        }
    }
}

$conn->close();
echo json_encode([
    "success" => true,
    "appointment" => $appointment,
    "history" => $history,
    "reschedule_proposal" => $proposal,
]);


<?php
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/appointment_offices.php";
require_once __DIR__ . "/appointment_maintenance.php";

require_roles_json(["visitor"]);
refresh_appointment_time_states($conn);

$token = isset($_GET["token"]) ? preg_replace("/[^a-f0-9]/i", "", (string) $_GET["token"]) : "";
if (strlen($token) !== 64) {
    echo json_encode(["success" => false, "message" => "Invalid token"]);
    exit;
}

$visitorUserId = (int) $_SESSION["user_id"];

$stmt = $conn->prepare(
    "SELECT public_token, registration_code, status, status_updated_at, device_name, visitor_full_name,
            office_code, appointment_at, scheduled_start_at, scheduled_end_at,
            approved_at, rejection_reason, checked_in_at, completed_at
     FROM appointments WHERE public_token = ? AND visitor_user_id = ? LIMIT 1"
);
if (!$stmt) {
    echo json_encode(["success" => false, "message" => "Server error"]);
    exit;
}

$stmt->bind_param("si", $token, $visitorUserId);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$stmt->close();
$conn->close();

if (!$row) {
    echo json_encode(["success" => false, "message" => "Appointment not found"]);
    exit;
}

$map = appointment_office_map();
$officeLabel = $map[$row["office_code"]] ?? $row["office_code"];

$checkedIn = $row["status"] === "checked_in";
$completed = $row["status"] === "completed";
$approved = $row["status"] === "approved";
$validFrom = null;
$qrCurrentlyValid = false;
if ($approved && $row["scheduled_start_at"] && $row["scheduled_end_at"]) {
    $validFromDate = new DateTime($row["scheduled_start_at"]);
    $validFromDate->modify("-30 minutes");
    $validUntilDate = new DateTime($row["scheduled_end_at"]);
    $validFrom = $validFromDate->format("Y-m-d H:i:s");
    $now = new DateTime("now");
    $qrCurrentlyValid = $now >= $validFromDate && $now <= $validUntilDate;
}

echo json_encode([
    "success" => true,
    "status" => $row["status"],
    "registration_code" => $row["registration_code"],
    "approved" => $approved,
    "checked_in" => $checkedIn,
    "completed" => $completed,
    "device_name" => $row["device_name"],
    "visitor_full_name" => $row["visitor_full_name"],
    "office_label" => $officeLabel,
    "appointment_at" => $row["appointment_at"],
    "scheduled_start_at" => $row["scheduled_start_at"],
    "scheduled_end_at" => $row["scheduled_end_at"],
    "qr_valid_from" => $validFrom,
    "qr_valid_until" => $row["scheduled_end_at"],
    "qr_currently_valid" => $qrCurrentlyValid,
    "rejection_reason" => $row["rejection_reason"],
    "checked_in_at" => $row["checked_in_at"],
    "completed_at" => $row["completed_at"],
    "status_updated_at" => $row["status_updated_at"],
]);

<?php
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/appointment_maintenance.php";

require_roles_json(["visitor"]);
refresh_appointment_time_states($conn);

$data = json_decode(file_get_contents("php://input"), true);

if (!is_array($data)) {
    echo json_encode([
        "success" => false,
        "message" => "No data received"
    ]);
    exit;
}

$appointmentToken = isset($data["appointment_token"]) ? preg_replace("/[^a-f0-9]/i", "", (string) $data["appointment_token"]) : "";
$latitude = isset($data["latitude"]) ? $data["latitude"] : null;
$longitude = isset($data["longitude"]) ? $data["longitude"] : null;
$accuracy = isset($data["accuracy"]) ? $data["accuracy"] : null;

if (strlen($appointmentToken) !== 64) {
    echo json_encode([
        "success" => false,
        "message" => "Missing or invalid appointment token"
    ]);
    exit;
}

if ($latitude === null || $longitude === null) {
    echo json_encode([
        "success" => false,
        "message" => "Missing latitude or longitude"
    ]);
    exit;
}

if (!is_numeric($latitude) || !is_numeric($longitude)) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid latitude or longitude"
    ]);
    exit;
}

$visitorUserId = (int) $_SESSION["user_id"];
$check = $conn->prepare(
    "SELECT id, status, device_name FROM appointments WHERE public_token = ? AND visitor_user_id = ? LIMIT 1"
);
if (!$check) {
    echo json_encode([
        "success" => false,
        "message" => "Server error"
    ]);
    exit;
}

$check->bind_param("si", $appointmentToken, $visitorUserId);
$check->execute();
$checkResult = $check->get_result();
$appointment = $checkResult->fetch_assoc();
$check->close();

if (!$appointment) {
    echo json_encode([
        "success" => false,
        "message" => "Appointment not found"
    ]);
    exit;
}

if ($appointment["status"] !== "checked_in") {
    echo json_encode([
        "success" => false,
        "message" => "Tracking is active only during an active checked-in visit"
    ]);
    exit;
}

$appointmentId = (int) $appointment["id"];
$device_name = (string) $appointment["device_name"];
$consent = $conn->prepare(
    "SELECT id FROM visitor_consents
     WHERE appointment_id = ? AND visitor_user_id = ? AND consent_type = 'location_tracking' AND withdrawn_at IS NULL
     ORDER BY id DESC LIMIT 1"
);
if (!$consent) {
    echo json_encode(["success" => false, "message" => "Database update required. Run phase1_workflow_migration.sql."]);
    exit;
}
$consent->bind_param("ii", $appointmentId, $visitorUserId);
$consent->execute();
$hasConsent = (bool) $consent->get_result()->fetch_assoc();
$consent->close();
if (!$hasConsent) {
    echo json_encode(["success" => false, "message" => "Active location-tracking consent was not found"]);
    exit;
}

$stmt = $conn->prepare(
    "INSERT INTO locations (appointment_id, visitor_user_id, device_name, latitude, longitude, accuracy)
     VALUES (?, ?, ?, ?, ?, ?)"
);
if (!$stmt) {
    echo json_encode([
        "success" => false,
        "message" => "Server error"
    ]);
    exit;
}

$latValue = (float) $latitude;
$lngValue = (float) $longitude;
$accuracyValue = is_numeric($accuracy) ? (float) $accuracy : null;
$stmt->bind_param("iisddd", $appointmentId, $visitorUserId, $device_name, $latValue, $lngValue, $accuracyValue);

if ($stmt->execute()) {
    echo json_encode([
        "success" => true,
        "message" => "Location saved"
    ]);
} else {
    echo json_encode([
        "success" => false,
        "message" => "Failed to save location"
    ]);
}

$stmt->close();
$conn->close();

<?php
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";

require_roles_json(["visitor"]);

$token = isset($_GET["token"]) ? preg_replace("/[^a-f0-9]/i", "", (string) $_GET["token"]) : "";
if (strlen($token) !== 64) {
    echo json_encode(["success" => false, "message" => "Invalid token"]);
    exit;
}

$visitorUserId = (int) $_SESSION["user_id"];
$apptStmt = $conn->prepare(
    "SELECT id, device_name, status FROM appointments WHERE public_token = ? AND visitor_user_id = ? LIMIT 1"
);
if (!$apptStmt) {
    echo json_encode(["success" => false, "message" => "Server error"]);
    exit;
}

$apptStmt->bind_param("si", $token, $visitorUserId);
$apptStmt->execute();
$apptRes = $apptStmt->get_result();
$appt = $apptRes->fetch_assoc();
$apptStmt->close();

if (!$appt) {
    $conn->close();
    echo json_encode(["success" => false, "message" => "Appointment not found"]);
    exit;
}

if ($appt["status"] !== "checked_in" && $appt["status"] !== "completed") {
    $conn->close();
    echo json_encode(["success" => false, "message" => "Waiting for security scan"]);
    exit;
}

$appointmentId = (int) $appt["id"];
$locStmt = $conn->prepare(
    "SELECT id, appointment_id, device_name, latitude, longitude, accuracy, recorded_at
     FROM locations
     WHERE appointment_id = ?
     ORDER BY id DESC
     LIMIT 1"
);
if (!$locStmt) {
    $conn->close();
    echo json_encode(["success" => false, "message" => "Server error"]);
    exit;
}

$locStmt->bind_param("i", $appointmentId);
$locStmt->execute();
$locRes = $locStmt->get_result();
$loc = $locRes->fetch_assoc();
$locStmt->close();
$conn->close();

if (!$loc) {
    echo json_encode(["success" => false, "message" => "No location data yet"]);
    exit;
}

echo json_encode([
    "success" => true,
    "data" => $loc,
]);

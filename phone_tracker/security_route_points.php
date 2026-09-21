<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";

require_roles_json(["security", "admin"]);

$device = isset($_GET["device"]) ? trim((string) $_GET["device"]) : "";
$date = isset($_GET["date"]) ? trim((string) $_GET["date"]) : date("Y-m-d");
$appointmentId = isset($_GET["appointment_id"]) ? (int) $_GET["appointment_id"] : 0;

if ($appointmentId <= 0 || !preg_match("/^\d{4}-\d{2}-\d{2}$/", $date)) {
    echo json_encode(["success" => false, "message" => "Select a valid visitor and date", "data" => []]);
    exit;
}

$appointmentCheck = $conn->prepare(
    "SELECT id, device_name, checked_in_at, completed_at
     FROM appointments
     WHERE id = ? AND status IN ('checked_in', 'completed')
     LIMIT 1"
);
$appointmentCheck->bind_param("i", $appointmentId);
$appointmentCheck->execute();
$appointment = $appointmentCheck->get_result()->fetch_assoc();
$appointmentCheck->close();

if (!$appointment || !$appointment["checked_in_at"]) {
    $conn->close();
    echo json_encode(["success" => false, "message" => "Active visitor record not found", "data" => []]);
    exit;
}
$device = (string) $appointment["device_name"];

$start = $date . " 00:00:00";
$end = $date . " 23:59:59";
if (strtotime($appointment["checked_in_at"]) > strtotime($start)) {
    $start = $appointment["checked_in_at"];
}
if ($appointment["completed_at"] && strtotime($appointment["completed_at"]) < strtotime($end)) {
    $end = $appointment["completed_at"];
}
$stmt = $conn->prepare(
    "SELECT id, appointment_id, device_name, latitude, longitude, accuracy, recorded_at
     FROM locations
     WHERE appointment_id = ? AND recorded_at BETWEEN ? AND ?
     ORDER BY recorded_at ASC, id ASC
     LIMIT 2000"
);
$stmt->bind_param("iss", $appointmentId, $start, $end);
$stmt->execute();
$result = $stmt->get_result();
$points = [];
while ($row = $result->fetch_assoc()) {
    $points[] = [
        "id" => (int) $row["id"],
        "appointment_id" => (int) $row["appointment_id"],
        "device_name" => $row["device_name"],
        "latitude" => (float) $row["latitude"],
        "longitude" => (float) $row["longitude"],
        "accuracy" => $row["accuracy"] === null ? null : (float) $row["accuracy"],
        "recorded_at" => $row["recorded_at"],
    ];
}
$stmt->close();
$conn->close();

echo json_encode([
    "success" => true,
    "visitor" => $device,
    "date" => $date,
    "data" => $points,
]);

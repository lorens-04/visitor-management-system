<?php
header("Content-Type: application/json");
require_once "db.php";

$device = isset($_GET["device"]) ? trim($_GET["device"]) : "";
$date = isset($_GET["date"]) ? trim($_GET["date"]) : date("Y-m-d");

if ($device === "") {
    echo json_encode([
        "success" => false,
        "message" => "Visitor is required"
    ]);
    $conn->close();
    exit;
}

if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $date)) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid date format"
    ]);
    $conn->close();
    exit;
}

$startDateTime = $date . " 00:00:00";
$endDateTime = $date . " 23:59:59";

$startQuery = "SELECT id, device_name, latitude, longitude, accuracy, recorded_at
               FROM locations
               WHERE device_name = ? AND recorded_at BETWEEN ? AND ?
               ORDER BY recorded_at ASC, id ASC
               LIMIT 1";

$endQuery = "SELECT id, device_name, latitude, longitude, accuracy, recorded_at
             FROM locations
             WHERE device_name = ? AND recorded_at BETWEEN ? AND ?
             ORDER BY recorded_at DESC, id DESC
             LIMIT 1";

$startStmt = $conn->prepare($startQuery);
$endStmt = $conn->prepare($endQuery);

if (!$startStmt || !$endStmt) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare visitor route query"
    ]);
    if ($startStmt) {
        $startStmt->close();
    }
    if ($endStmt) {
        $endStmt->close();
    }
    $conn->close();
    exit;
}

$startStmt->bind_param("sss", $device, $startDateTime, $endDateTime);
$startStmt->execute();
$startResult = $startStmt->get_result();
$startPoint = $startResult->fetch_assoc();

$endStmt->bind_param("sss", $device, $startDateTime, $endDateTime);
$endStmt->execute();
$endResult = $endStmt->get_result();
$endPoint = $endResult->fetch_assoc();

if (!$startPoint || !$endPoint) {
    echo json_encode([
        "success" => false,
        "message" => "No route data found for this visitor on selected date"
    ]);
    $startStmt->close();
    $endStmt->close();
    $conn->close();
    exit;
}

echo json_encode([
    "success" => true,
    "date" => $date,
    "visitor" => $device,
    "data" => [
        "start_point" => $startPoint,
        "end_point" => $endPoint
    ]
]);

$startStmt->close();
$endStmt->close();
$conn->close();
?>

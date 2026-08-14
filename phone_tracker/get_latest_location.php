<?php
header("Content-Type: application/json");
require_once "db.php";

$sql = "SELECT id, device_name, latitude, longitude, accuracy, recorded_at 
        FROM locations 
        ORDER BY id DESC 
        LIMIT 1";

$result = $conn->query($sql);

if ($result && $row = $result->fetch_assoc()) {
    echo json_encode([
        "success" => true,
        "data" => $row
    ]);
} else {
    echo json_encode([
        "success" => false,
        "message" => "No location data found"
    ]);
}

$conn->close();
?>
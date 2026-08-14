<?php
header("Content-Type: application/json");
require_once "db.php";

$device = isset($_GET["device"]) ? trim($_GET["device"]) : "";

if ($device === "") {
    echo json_encode([
        "success" => false,
        "message" => "Visitor is required",
        "data" => []
    ]);
    $conn->close();
    exit;
}

$stmt = $conn->prepare("SELECT DISTINCT DATE(recorded_at) AS visit_date FROM locations WHERE device_name = ? ORDER BY visit_date DESC");
if (!$stmt) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare visitor date query",
        "data" => []
    ]);
    $conn->close();
    exit;
}

$stmt->bind_param("s", $device);
$stmt->execute();
$result = $stmt->get_result();

$dates = [];
while ($row = $result->fetch_assoc()) {
    $dates[] = $row["visit_date"];
}

echo json_encode([
    "success" => true,
    "data" => $dates
]);

$stmt->close();
$conn->close();
?>

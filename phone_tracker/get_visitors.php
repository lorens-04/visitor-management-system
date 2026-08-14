<?php
header("Content-Type: application/json");
require_once "db.php";

$sql = "SELECT DISTINCT device_name FROM locations ORDER BY device_name ASC";
$result = $conn->query($sql);

$visitors = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $visitors[] = $row["device_name"];
    }
}

echo json_encode([
    "success" => true,
    "data" => $visitors
]);

$conn->close();
?>

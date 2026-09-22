<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";

require_roles_json(["security", "admin"]);

$appointmentId = isset($_GET["appointment_id"]) ? (int) $_GET["appointment_id"] : 0;
if ($appointmentId <= 0) {
    echo json_encode(["success" => false, "message" => "Select a valid visitor", "data" => []]);
    exit;
}

$appointmentStmt = $conn->prepare(
    "SELECT id, visitor_full_name, status, checked_in_at, completed_at
     FROM appointments
     WHERE id = ? AND status IN ('checked_in', 'completed') AND checked_in_at IS NOT NULL
     LIMIT 1"
);
$appointmentStmt->bind_param("i", $appointmentId);
$appointmentStmt->execute();
$appointment = $appointmentStmt->get_result()->fetch_assoc();
$appointmentStmt->close();
if (!$appointment) {
    $conn->close();
    echo json_encode(["success" => false, "message" => "Visitor route is unavailable", "data" => []]);
    exit;
}

$dateStmt = $conn->prepare(
    "SELECT DATE(recorded_at) AS route_date, COUNT(*) AS point_count,
            MIN(recorded_at) AS first_point_at, MAX(recorded_at) AS last_point_at
     FROM locations
     WHERE appointment_id = ?
       AND recorded_at >= ?
       AND (? IS NULL OR recorded_at <= ?)
     GROUP BY DATE(recorded_at)
     ORDER BY route_date DESC"
);
$completedAt = $appointment["completed_at"] ?: null;
$dateStmt->bind_param("isss", $appointmentId, $appointment["checked_in_at"], $completedAt, $completedAt);
$dateStmt->execute();
$result = $dateStmt->get_result();
$dates = [];
while ($row = $result->fetch_assoc()) {
    $dates[] = [
        "date" => (string) $row["route_date"],
        "point_count" => (int) $row["point_count"],
        "first_point_at" => $row["first_point_at"],
        "last_point_at" => $row["last_point_at"],
    ];
}
$dateStmt->close();
$conn->close();

echo json_encode([
    "success" => true,
    "appointment_id" => $appointmentId,
    "visitor_full_name" => $appointment["visitor_full_name"],
    "status" => $appointment["status"],
    "data" => $dates,
]);

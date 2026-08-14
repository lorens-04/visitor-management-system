<?php
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/appointment_offices.php";

require_roles_json(["visitor"]);

$visitorUserId = (int) $_SESSION["user_id"];

$stmt = $conn->prepare(
    "SELECT id, public_token, office_code, visitor_full_name, visitor_email, device_name, appointment_at, status, status_updated_at, checked_in_at, completed_at, created_at
     FROM appointments
     WHERE visitor_user_id = ?
     ORDER BY appointment_at DESC, id DESC"
);
if (!$stmt) {
    echo json_encode(["success" => false, "message" => "Server error"]);
    exit;
}

$stmt->bind_param("i", $visitorUserId);
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
$map = appointment_office_map();
while ($row = $res->fetch_assoc()) {
    $code = (string) $row["office_code"];
    $row["office_label"] = $map[$code] ?? $code;
    $rows[] = $row;
}

$stmt->close();
$conn->close();

echo json_encode([
    "success" => true,
    "data" => $rows,
]);

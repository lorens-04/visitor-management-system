<?php
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/appointment_offices.php";

require_roles_json(["offices", "admin"]);

$sql = "SELECT a.id, a.public_token, a.office_code, a.visitor_full_name, a.visitor_email, a.device_name,
        a.appointment_at, a.status, a.checked_in_at, a.created_at,
        u.username AS visitor_username
        FROM appointments a
        LEFT JOIN app_users u ON u.id = a.visitor_user_id
        ORDER BY a.appointment_at DESC, a.id DESC";

$result = $conn->query($sql);
$rows = [];
$map = appointment_office_map();

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $code = (string) $row["office_code"];
        $row["office_label"] = $map[$code] ?? $code;
        $rows[] = $row;
    }
}

$conn->close();

echo json_encode([
    "success" => true,
    "data" => $rows,
]);

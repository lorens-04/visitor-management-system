<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/appointment_offices.php";

require_roles_json(["security", "admin"]);

$sql =
    "SELECT a.id AS appointment_id, a.public_token, a.visitor_full_name,
            a.office_code, a.device_name, a.checked_in_at,
            l.latitude, l.longitude, l.accuracy, l.recorded_at
     FROM appointments a
     LEFT JOIN locations l ON l.id = (
         SELECT l2.id
         FROM locations l2
         WHERE l2.device_name = a.device_name
           AND l2.recorded_at >= a.checked_in_at
         ORDER BY l2.recorded_at DESC, l2.id DESC
         LIMIT 1
     )
     WHERE a.status = 'checked_in'
     ORDER BY a.checked_in_at DESC, a.id DESC";

$rows = [];
$officeMap = appointment_office_map();
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $officeCode = (string) ($row["office_code"] ?? "");
        $row["appointment_id"] = (int) $row["appointment_id"];
        $row["office_label"] = $officeMap[$officeCode] ?? $officeCode;
        $row["has_location"] = $row["latitude"] !== null && $row["longitude"] !== null;
        if ($row["has_location"]) {
            $row["latitude"] = (float) $row["latitude"];
            $row["longitude"] = (float) $row["longitude"];
            $row["accuracy"] = $row["accuracy"] === null ? null : (float) $row["accuracy"];
        }
        $rows[] = $row;
    }
}

$conn->close();

echo json_encode([
    "success" => true,
    "data" => $rows,
]);

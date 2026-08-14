<?php
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/session_bootstrap.php";

require_admin_json();

$stmt = $conn->prepare(
    "SELECT id, username, display_name, role, is_active, created_at FROM app_users ORDER BY id ASC"
);
$stmt->execute();
$result = $stmt->get_result();
$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = [
        "id" => (int) $row["id"],
        "username" => $row["username"],
        "display_name" => $row["display_name"],
        "role" => $row["role"],
        "is_active" => (int) $row["is_active"],
        "created_at" => $row["created_at"],
    ];
}
$stmt->close();

echo json_encode(["success" => true, "data" => $rows]);

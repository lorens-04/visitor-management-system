<?php
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/session_bootstrap.php";

require_admin_json();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);
if (!is_array($input) || !isset($input["id"])) {
    echo json_encode(["success" => false, "message" => "Invalid body"]);
    exit;
}

$id = (int) $input["id"];
$selfId = (int) $_SESSION["user_id"];

if ($id <= 0) {
    echo json_encode(["success" => false, "message" => "Invalid user"]);
    exit;
}

if ($id === $selfId) {
    echo json_encode(["success" => false, "message" => "You cannot delete your own account"]);
    exit;
}

$stmt = $conn->prepare("DELETE FROM app_users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

if ($affected === 0) {
    echo json_encode(["success" => false, "message" => "User not found"]);
    exit;
}

echo json_encode(["success" => true]);

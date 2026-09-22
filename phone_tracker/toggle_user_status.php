<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/session_bootstrap.php";

require_admin_json();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);
if (!is_array($input) || !isset($input["id"], $input["is_active"])) {
    echo json_encode(["success" => false, "message" => "Invalid request body"]);
    exit;
}

$userId = (int) $input["id"];
$isActive = (int) ((bool) $input["is_active"]);
$currentAdminId = (int) $_SESSION["user_id"];

if ($userId <= 0) {
    echo json_encode(["success" => false, "message" => "Invalid user"]);
    exit;
}

if ($userId === $currentAdminId && $isActive === 0) {
    echo json_encode(["success" => false, "message" => "You cannot suspend your own account"]);
    exit;
}

$lookup = $conn->prepare("SELECT id, username FROM app_users WHERE id = ? LIMIT 1");
$lookup->bind_param("i", $userId);
$lookup->execute();
$user = $lookup->get_result()->fetch_assoc();
$lookup->close();

if (!$user) {
    echo json_encode(["success" => false, "message" => "User not found"]);
    exit;
}

$stmt = $conn->prepare("UPDATE app_users SET is_active = ? WHERE id = ? LIMIT 1");
$stmt->bind_param("ii", $isActive, $userId);
$stmt->execute();
$stmt->close();
$conn->close();

echo json_encode([
    "success" => true,
    "user" => [
        "id" => $userId,
        "username" => $user["username"],
        "is_active" => $isActive,
    ],
    "message" => $isActive === 1 ? "Account activated" : "Account suspended",
]);

<?php
session_start();
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
}

require_once __DIR__ . "/db.php";

$raw = file_get_contents("php://input");
$input = json_decode($raw, true);
if (!is_array($input)) {
    echo json_encode(["success" => false, "message" => "Invalid request body"]);
    exit;
}

$username = isset($input["username"]) ? trim((string) $input["username"]) : "";
$password = isset($input["password"]) ? (string) $input["password"] : "";

if ($username === "" || $password === "") {
    echo json_encode(["success" => false, "message" => "Enter username and password"]);
    exit;
}

$stmt = $conn->prepare(
    "SELECT id, username, password_hash, display_name, role, office_code, is_active FROM app_users WHERE username = ? LIMIT 1"
);
if (!$stmt) {
    $stmt = $conn->prepare(
        "SELECT id, username, password_hash, display_name, role, is_active FROM app_users WHERE username = ? LIMIT 1"
    );
}
if (!$stmt) {
    echo json_encode(["success" => false, "message" => "Server error"]);
    exit;
}

$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user || !(int) $user["is_active"]) {
    echo json_encode(["success" => false, "message" => "Invalid username or password"]);
    exit;
}

if (!password_verify($password, $user["password_hash"])) {
    echo json_encode(["success" => false, "message" => "Invalid username or password"]);
    exit;
}

$_SESSION["user_id"] = (int) $user["id"];
$_SESSION["role"] = $user["role"];
$_SESSION["username"] = $user["username"];
$_SESSION["display_name"] = $user["display_name"];
$_SESSION["office_code"] = isset($user["office_code"]) ? (string) $user["office_code"] : "";

echo json_encode([
    "success" => true,
    "role" => $user["role"],
    "username" => $user["username"],
    "display_name" => $user["display_name"],
    "user_id" => (int) $user["id"],
    "office_code" => isset($user["office_code"]) ? (string) $user["office_code"] : "",
]);

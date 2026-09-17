<?php
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/appointment_offices.php";

require_admin_json();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);
if (!is_array($input)) {
    echo json_encode(["success" => false, "message" => "Invalid body"]);
    exit;
}

$username = isset($input["username"]) ? trim((string) $input["username"]) : "";
$password = isset($input["password"]) ? (string) $input["password"] : "";
$displayName = isset($input["display_name"]) ? trim((string) $input["display_name"]) : "";
$role = isset($input["role"]) ? (string) $input["role"] : "";
$officeCode = isset($input["office_code"]) ? strtoupper(trim((string) $input["office_code"])) : "";

$allowed = ["security", "visitor", "offices", "admin"];
if ($username === "" || $password === "" || !in_array($role, $allowed, true)) {
    echo json_encode(["success" => false, "message" => "Username, password, and valid role are required"]);
    exit;
}

if (strlen($password) < 6) {
    echo json_encode(["success" => false, "message" => "Password must be at least 6 characters"]);
    exit;
}

if ($displayName === "") {
    $displayName = $username;
}

if ($role === "offices") {
    $offices = appointment_office_map();
    if (!isset($offices[$officeCode])) {
        echo json_encode(["success" => false, "message" => "Select a valid office"]);
        exit;
    }
} else {
    $officeCode = "";
}

$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $conn->prepare(
    "INSERT INTO app_users (username, password_hash, display_name, role, office_code) VALUES (?, ?, ?, ?, ?)"
);
if (!$stmt) {
    echo json_encode(["success" => false, "message" => "Server error"]);
    exit;
}

$stmt->bind_param("sssss", $username, $hash, $displayName, $role, $officeCode);
if (!$stmt->execute()) {
    if ($conn->errno === 1062) {
        echo json_encode(["success" => false, "message" => "Username already exists"]);
    } else {
        echo json_encode(["success" => false, "message" => "Could not create user"]);
    }
    $stmt->close();
    exit;
}

$newId = (int) $conn->insert_id;
$stmt->close();

echo json_encode([
    "success" => true,
    "user" => [
        "id" => $newId,
        "username" => $username,
        "display_name" => $displayName,
        "role" => $role,
        "office_code" => $officeCode,
        "is_active" => 1,
    ],
]);

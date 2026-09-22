<?php
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
$displayName = isset($input["display_name"]) ? trim((string) $input["display_name"]) : "";
$role = "visitor";
$officeForDb = "";

if ($username === "" || $password === "") {
    echo json_encode(["success" => false, "message" => "Enter username and password"]);
    exit;
}

if (strlen($username) < 3 || strlen($username) > 64) {
    echo json_encode(["success" => false, "message" => "Username must be 3–64 characters"]);
    exit;
}

if (!preg_match('/^[a-zA-Z0-9_]+$/', $username) && !filter_var($username, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(["success" => false, "message" => "Enter a valid email address"]);
    exit;
}

if (strlen($password) < 8) {
    echo json_encode(["success" => false, "message" => "Password must be at least 8 characters"]);
    exit;
}

if ($displayName === "") {
    $displayName = $username;
}
if (strlen($displayName) > 100) {
    $displayName = substr($displayName, 0, 100);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
if ($hash === false) {
    echo json_encode(["success" => false, "message" => "Could not hash password"]);
    exit;
}

$stmt = $conn->prepare(
    "INSERT INTO app_users (username, password_hash, display_name, role, office_code, is_active) VALUES (?, ?, ?, ?, ?, 1)"
);
if (!$stmt) {
    $fallback = $conn->prepare(
        "INSERT INTO app_users (username, password_hash, display_name, role, is_active) VALUES (?, ?, ?, ?, 1)"
    );
    if (!$fallback) {
        echo json_encode(["success" => false, "message" => "Server error"]);
        exit;
    }
    $fallback->bind_param("ssss", $username, $hash, $displayName, $role);
    if (!$fallback->execute()) {
        $err = $fallback->errno;
        $fallback->close();
        $conn->close();
        if ($err === 1062) {
            echo json_encode(["success" => false, "message" => "That username is already taken"]);
            exit;
        }
        echo json_encode(["success" => false, "message" => "Could not create account"]);
        exit;
    }
    $newId = (int) $conn->insert_id;
    $fallback->close();
    $conn->close();
    echo json_encode([
        "success" => true,
        "message" => "Account created. You can sign in now.",
        "user_id" => $newId,
        "role" => $role,
        "username" => $username,
    ]);
    exit;
}

$stmt->bind_param("sssss", $username, $hash, $displayName, $role, $officeForDb);
if (!$stmt->execute()) {
    $err = $stmt->errno;
    $stmt->close();
    $conn->close();
    if ($err === 1062) {
        echo json_encode(["success" => false, "message" => "That username is already taken"]);
        exit;
    }
    echo json_encode(["success" => false, "message" => "Could not create account"]);
    exit;
}

$newId = (int) $conn->insert_id;
$stmt->close();
$conn->close();

echo json_encode([
    "success" => true,
    "message" => "Account created. You can sign in now.",
    "user_id" => $newId,
    "role" => $role,
    "username" => $username,
]);

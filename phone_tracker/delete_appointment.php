<?php
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
}

require_roles_json(["visitor"]);

$input = json_decode(file_get_contents("php://input"), true);
if (!is_array($input) || !isset($input["id"])) {
    echo json_encode(["success" => false, "message" => "Invalid request body"]);
    exit;
}

$appointmentId = (int) $input["id"];
$visitorUserId = (int) $_SESSION["user_id"];
if ($appointmentId <= 0) {
    echo json_encode(["success" => false, "message" => "Invalid appointment"]);
    exit;
}

$stmt = $conn->prepare("DELETE FROM appointments WHERE id = ? AND visitor_user_id = ? LIMIT 1");
if (!$stmt) {
    echo json_encode(["success" => false, "message" => "Server error"]);
    exit;
}

$stmt->bind_param("ii", $appointmentId, $visitorUserId);
$stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();
$conn->close();

if ($affected < 1) {
    echo json_encode(["success" => false, "message" => "Appointment not found"]);
    exit;
}

echo json_encode(["success" => true]);

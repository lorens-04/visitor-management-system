<?php
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";

require_roles_json(["offices"]);
$userId = (int) $_SESSION["user_id"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $input = json_decode(file_get_contents("php://input"), true);
    if (!is_array($input)) {
        echo json_encode(["success" => false, "message" => "Invalid request body"]);
        exit;
    }
    $action = (string) ($input["action"] ?? "");
    if ($action === "mark_all_read") {
        $stmt = $conn->prepare(
            "UPDATE app_notifications SET read_at = COALESCE(read_at, NOW())
             WHERE recipient_user_id = ?"
        );
        $stmt->bind_param("i", $userId);
    } elseif ($action === "mark_read") {
        $notificationId = (int) ($input["notification_id"] ?? 0);
        $stmt = $conn->prepare(
            "UPDATE app_notifications SET read_at = COALESCE(read_at, NOW())
             WHERE id = ? AND recipient_user_id = ?"
        );
        $stmt->bind_param("ii", $notificationId, $userId);
    } else {
        $conn->close();
        echo json_encode(["success" => false, "message" => "Unknown notification action"]);
        exit;
    }
    $stmt->execute();
    $stmt->close();
    $conn->close();
    echo json_encode(["success" => true]);
    exit;
}

http_response_code(405);
$conn->close();
echo json_encode(["success" => false, "message" => "Method not allowed"]);


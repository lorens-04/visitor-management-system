<?php
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/appointment_offices.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
}

require_roles_json(["visitor"]);

$raw = file_get_contents("php://input");
$input = json_decode($raw, true);
if (!is_array($input)) {
    echo json_encode(["success" => false, "message" => "Invalid request body"]);
    exit;
}

$officeCode = isset($input["office_code"]) ? strtoupper(trim((string) $input["office_code"])) : "";
$visitorName = isset($input["visitor_full_name"]) ? trim((string) $input["visitor_full_name"]) : "";
$visitorEmail = isset($input["visitor_email"]) ? trim((string) $input["visitor_email"]) : "";
$appointmentAtRaw = isset($input["appointment_at"]) ? trim((string) $input["appointment_at"]) : "";
$deviceName = isset($input["device_name"]) ? trim((string) $input["device_name"]) : "";

$offices = appointment_office_map();
if ($officeCode === "" || !isset($offices[$officeCode])) {
    echo json_encode(["success" => false, "message" => "Select a valid office"]);
    exit;
}

if ($visitorName === "") {
    echo json_encode(["success" => false, "message" => "Enter your full name"]);
    exit;
}

if ($appointmentAtRaw === "") {
    echo json_encode(["success" => false, "message" => "Choose date and time"]);
    exit;
}

$appointmentDt = DateTime::createFromFormat("Y-m-d", $appointmentAtRaw);
if (!$appointmentDt) {
    $appointmentDt = DateTime::createFromFormat("Y-m-d\TH:i", $appointmentAtRaw);
}
if (!$appointmentDt) {
    $appointmentDt = DateTime::createFromFormat("Y-m-d H:i:s", $appointmentAtRaw);
}
if (!$appointmentDt) {
    echo json_encode(["success" => false, "message" => "Invalid date or time"]);
    exit;
}

$appointmentMysql = $appointmentDt->format("Y-m-d 00:00:00");

if ($deviceName === "") {
    $deviceName = $visitorName;
}
if (strlen($deviceName) > 100) {
    $deviceName = substr($deviceName, 0, 100);
}

$visitorUserId = (int) $_SESSION["user_id"];

try {
    $token = bin2hex(random_bytes(32));
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => "Could not generate pass"]);
    exit;
}

$stmt = $conn->prepare(
    "INSERT INTO appointments (public_token, office_code, visitor_full_name, visitor_email, device_name, appointment_at, visitor_user_id) VALUES (?, ?, ?, ?, ?, ?, ?)"
);
if (!$stmt) {
    echo json_encode(["success" => false, "message" => "Server error"]);
    exit;
}

$stmt->bind_param(
    "ssssssi",
    $token,
    $officeCode,
    $visitorName,
    $visitorEmail,
    $deviceName,
    $appointmentMysql,
    $visitorUserId
);

if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();
    echo json_encode(["success" => false, "message" => "Could not save appointment"]);
    exit;
}

$newId = (int) $conn->insert_id;

$hist = $conn->prepare(
    "INSERT INTO appointment_status_history (appointment_id, from_status, to_status, changed_by_user_id, note) VALUES (?, NULL, 'pending', ?, 'Appointment created')"
);
if ($hist) {
    $hist->bind_param("ii", $newId, $visitorUserId);
    $hist->execute();
    $hist->close();
}

$stmt->close();
$conn->close();

echo json_encode([
    "success" => true,
    "appointment_id" => $newId,
    "public_token" => $token,
    "office_code" => $officeCode,
    "office_label" => $offices[$officeCode],
    "visitor_full_name" => $visitorName,
    "device_name" => $deviceName,
    "appointment_at" => $appointmentMysql,
]);

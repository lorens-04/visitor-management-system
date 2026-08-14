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

require_roles_json(["security", "admin"]);

$raw = file_get_contents("php://input");
$input = json_decode($raw, true);
if (!is_array($input)) {
    echo json_encode(["success" => false, "message" => "Invalid request body"]);
    exit;
}

$token = isset($input["token"]) ? preg_replace("/[^a-f0-9]/i", "", (string) $input["token"]) : "";
if (strlen($token) !== 64) {
    echo json_encode(["success" => false, "message" => "Invalid QR code"]);
    exit;
}

$securityUserId = (int) $_SESSION["user_id"];

$stmt = $conn->prepare(
    "SELECT id, status, visitor_full_name, device_name, office_code, appointment_at FROM appointments WHERE public_token = ? LIMIT 1"
);
if (!$stmt) {
    echo json_encode(["success" => false, "message" => "Server error"]);
    exit;
}

$stmt->bind_param("s", $token);
$stmt->execute();
$res = $stmt->get_result();
$appt = $res->fetch_assoc();
$stmt->close();

if (!$appt) {
    $conn->close();
    echo json_encode(["success" => false, "message" => "No appointment found for this code"]);
    exit;
}

if ($appt["status"] === "cancelled") {
    $conn->close();
    echo json_encode(["success" => false, "message" => "This appointment was cancelled"]);
    exit;
}

$map = appointment_office_map();
$officeLabel = $map[$appt["office_code"]] ?? $appt["office_code"];

if ($appt["status"] === "checked_in" || $appt["status"] === "completed") {
    $conn->close();
    echo json_encode([
        "success" => true,
        "message" => "Already checked in",
        "already_checked_in" => true,
        "device_name" => $appt["device_name"],
        "visitor_full_name" => $appt["visitor_full_name"],
        "office_label" => $officeLabel,
    ]);
    exit;
}

// Backward compatible: if migration columns are missing, fallback query still works.
$upd = $conn->prepare(
    "UPDATE appointments SET status = 'checked_in', status_updated_at = NOW(), checked_in_at = NOW(), checked_in_by_user_id = ? WHERE id = ? AND status = 'pending'"
);
if (!$upd) {
    $upd = $conn->prepare(
        "UPDATE appointments SET status = 'checked_in', checked_in_at = NOW(), checked_in_by_user_id = ? WHERE id = ? AND status = 'pending'"
    );
}
if (!$upd) {
    $conn->close();
    echo json_encode(["success" => false, "message" => "Server error"]);
    exit;
}

$apptId = (int) $appt["id"];
$upd->bind_param("ii", $securityUserId, $apptId);
$upd->execute();
$affected = $upd->affected_rows;
$upd->close();

if ($affected < 1) {
    $conn->close();
    echo json_encode(["success" => false, "message" => "Could not check in"]);
    exit;
}

$hist = $conn->prepare(
    "INSERT INTO appointment_status_history (appointment_id, from_status, to_status, changed_by_user_id, note) VALUES (?, 'pending', 'checked_in', ?, 'Checked in by security scan')"
);
if ($hist) {
    $hist->bind_param("ii", $apptId, $securityUserId);
    $hist->execute();
    $hist->close();
}
$conn->close();

echo json_encode([
    "success" => true,
    "message" => "Check-in recorded. Visitor GPS can start.",
    "device_name" => $appt["device_name"],
    "visitor_full_name" => $appt["visitor_full_name"],
    "office_label" => $officeLabel,
]);

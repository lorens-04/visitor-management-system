<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/appointment_offices.php";
require_once __DIR__ . "/appointment_maintenance.php";

require_roles_json(["security", "admin"]);
refresh_appointment_time_states($conn);

function security_appointment_column_exists(mysqli $conn, string $column): bool
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'appointments'
           AND COLUMN_NAME = ?"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("s", $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row["total"] ?? 0) > 0;
}

$hasVisitType = security_appointment_column_exists($conn, "visit_type");
$visitTypeSelect = $hasVisitType ? "a.visit_type" : "'Appointment' AS visit_type";
$purposeSelect = security_appointment_column_exists($conn, "purpose")
    ? "a.purpose"
    : "NULL AS purpose";
$subjectSelect = security_appointment_column_exists($conn, "subject")
    ? "a.subject"
    : "NULL AS subject";

$walkInTodayExpression = $hasVisitType
    ? "SUM(DATE(COALESCE(checked_in_at, appointment_at)) = CURDATE()
           AND LOWER(REPLACE(visit_type, '_', '-')) = 'walk-in'
           AND status IN ('checked_in', 'completed'))"
    : "0";
$appointmentTodayExpression = $hasVisitType
    ? "SUM(DATE(appointment_at) = CURDATE()
           AND LOWER(REPLACE(visit_type, '_', '-')) <> 'walk-in'
           AND status IN ('approved', 'checked_in', 'completed'))"
    : "SUM(DATE(appointment_at) = CURDATE() AND status IN ('approved', 'checked_in', 'completed'))";

$summary = [
    "inside_campus" => 0,
    "walk_in_today" => 0,
    "appointment_today" => 0,
    "checked_out_today" => 0,
];

$summaryResult = $conn->query(
    "SELECT
        SUM(status = 'checked_in') AS inside_campus,
        {$walkInTodayExpression} AS walk_in_today,
        {$appointmentTodayExpression} AS appointment_today,
        SUM(status = 'completed' AND DATE(completed_at) = CURDATE()) AS checked_out_today
     FROM appointments"
);
if ($summaryResult) {
    $row = $summaryResult->fetch_assoc();
    foreach ($summary as $key => $unused) {
        $summary[$key] = (int) ($row[$key] ?? 0);
    }
}

$rows = [];
$officeMap = appointment_office_map();
$result = $conn->query(
    "SELECT a.id, a.public_token, a.visitor_full_name, a.visitor_email,
            a.registration_code, a.office_code, a.device_name, a.appointment_at,
            a.scheduled_start_at, a.scheduled_end_at, a.status,
            a.checked_in_at, a.completed_at, a.cancelled_at, a.created_at,
            {$visitTypeSelect}, {$purposeSelect}, {$subjectSelect}
     FROM appointments a
     ORDER BY COALESCE(a.status_updated_at, a.created_at) DESC, a.id DESC
     LIMIT 100"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $id = (int) $row["id"];
        $officeCode = (string) ($row["office_code"] ?? "");
        $createdYear = $row["created_at"] ? date("Y", strtotime($row["created_at"])) : date("Y");
        $row["id"] = $id;
        $row["registration_id"] = $row["registration_code"] ?: ("V-" . $createdYear . "-" . str_pad((string) $id, 6, "0", STR_PAD_LEFT));
        $row["office_label"] = $officeMap[$officeCode] ?? $officeCode;
        $rows[] = $row;
    }
}

$conn->close();

echo json_encode([
    "success" => true,
    "summary" => $summary,
    "visitors" => $rows,
]);

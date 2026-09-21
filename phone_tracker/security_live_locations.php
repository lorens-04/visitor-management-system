<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/appointment_offices.php";
require_once __DIR__ . "/appointment_maintenance.php";

require_roles_json(["security", "admin"]);
refresh_appointment_time_states($conn);

$sql =
    "SELECT a.id AS appointment_id, a.public_token, a.visitor_full_name,
            a.office_code, a.device_name, a.checked_in_at,
            l.latitude, l.longitude, l.accuracy, l.recorded_at
     FROM appointments a
     LEFT JOIN locations l ON l.id = (
         SELECT l2.id
         FROM locations l2
         WHERE l2.appointment_id = a.id
           AND l2.recorded_at >= a.checked_in_at
         ORDER BY l2.recorded_at DESC, l2.id DESC
         LIMIT 1
     )
     WHERE a.status = 'checked_in'
     ORDER BY a.checked_in_at DESC, a.id DESC";

$rows = [];
$officeMap = appointment_office_map();
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $officeCode = (string) ($row["office_code"] ?? "");
        $row["appointment_id"] = (int) $row["appointment_id"];
        $row["office_label"] = $officeMap[$officeCode] ?? $officeCode;
        $row["has_location"] = $row["latitude"] !== null && $row["longitude"] !== null;
        $row["location_state"] = "waiting";
        $row["seconds_since_update"] = null;
        if ($row["has_location"]) {
            $row["latitude"] = (float) $row["latitude"];
            $row["longitude"] = (float) $row["longitude"];
            $row["accuracy"] = $row["accuracy"] === null ? null : (float) $row["accuracy"];
            $recordedAt = strtotime((string) $row["recorded_at"]);
            $age = $recordedAt ? max(0, time() - $recordedAt) : null;
            $row["seconds_since_update"] = $age;
            $row["location_state"] = $age === null ? "offline" : ($age <= 45 ? "live" : ($age <= 180 ? "stale" : "offline"));
        }
        $rows[] = $row;
    }
}

$routeVisits = [];
$routeResult = $conn->query(
    "SELECT a.id AS appointment_id, a.visitor_full_name, a.office_code, a.device_name,
            a.status, a.checked_in_at, a.completed_at,
            COUNT(l.id) AS point_count, MAX(l.recorded_at) AS last_location_at
     FROM appointments a
     INNER JOIN locations l ON l.appointment_id = a.id
       AND l.recorded_at >= a.checked_in_at
       AND (a.completed_at IS NULL OR l.recorded_at <= a.completed_at)
     WHERE a.status IN ('checked_in', 'completed')
       AND a.checked_in_at IS NOT NULL
     GROUP BY a.id, a.visitor_full_name, a.office_code, a.device_name,
              a.status, a.checked_in_at, a.completed_at
     ORDER BY COALESCE(a.completed_at, a.checked_in_at) DESC, a.id DESC
     LIMIT 100"
);
if ($routeResult) {
    while ($route = $routeResult->fetch_assoc()) {
        $officeCode = (string) ($route["office_code"] ?? "");
        $route["appointment_id"] = (int) $route["appointment_id"];
        $route["point_count"] = (int) $route["point_count"];
        $route["office_label"] = $officeMap[$officeCode] ?? $officeCode;
        $routeVisits[] = $route;
    }
}

$conn->close();

echo json_encode([
    "success" => true,
    "data" => $rows,
    "route_visits" => $routeVisits,
]);

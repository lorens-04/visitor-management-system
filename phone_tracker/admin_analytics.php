<?php
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/appointment_offices.php";

require_admin_json();

// Match the local MySQL/system clock used by the ISATU installation.
date_default_timezone_set("Asia/Manila");

$allowedPeriods = ["today", "week", "month", "quarter"];
$period = isset($_GET["period"]) ? strtolower(trim((string) $_GET["period"])) : "month";
if (!in_array($period, $allowedPeriods, true)) {
    $period = "month";
}

$now = new DateTimeImmutable("now");
switch ($period) {
    case "today":
        $start = $now->setTime(0, 0, 0);
        break;
    case "week":
        $start = $now->modify("monday this week")->setTime(0, 0, 0);
        break;
    case "quarter":
        $quarterMonth = ((int) floor(((int) $now->format("n") - 1) / 3) * 3) + 1;
        $start = $now->setDate((int) $now->format("Y"), $quarterMonth, 1)->setTime(0, 0, 0);
        break;
    case "month":
    default:
        $start = $now->modify("first day of this month")->setTime(0, 0, 0);
        break;
}

$startSql = $start->format("Y-m-d H:i:s");
$endSql = $now->format("Y-m-d H:i:s");
$officeMap = appointment_office_map();

function analytics_column_exists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row["total"] ?? 0) > 0;
}

$hasPurpose = analytics_column_exists($conn, "appointments", "purpose");
$hasSubject = analytics_column_exists($conn, "appointments", "subject");
$hasVisitType = analytics_column_exists($conn, "appointments", "visit_type");
$hasZoneName = analytics_column_exists($conn, "locations", "zone_name");

$purposeSelect = $hasPurpose ? "a.purpose" : "NULL AS purpose";
$subjectSelect = $hasSubject ? "a.subject" : "NULL AS subject";
$visitTypeSelect = $hasVisitType ? "a.visit_type" : "'Appointment' AS visit_type";

if (isset($_GET["format"]) && $_GET["format"] === "csv") {
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=visitor-analytics-" . $period . "-" . $now->format("Y-m-d") . ".csv");
    $output = fopen("php://output", "w");
    fputcsv($output, [
        "Registration ID", "Visitor", "Visit Type", "Purpose", "Destination", "Subject",
        "Status", "Appointment", "Check In", "Check Out", "Duration Minutes",
    ]);
    $exportStmt = $conn->prepare(
        "SELECT a.id, a.visitor_full_name, a.office_code, a.status, a.appointment_at,
                a.checked_in_at, a.completed_at, a.created_at,
                {$visitTypeSelect}, {$purposeSelect}, {$subjectSelect}
         FROM appointments a
         WHERE COALESCE(a.checked_in_at, a.appointment_at, a.created_at) BETWEEN ? AND ?
         ORDER BY COALESCE(a.checked_in_at, a.appointment_at, a.created_at) DESC"
    );
    $exportStmt->bind_param("ss", $startSql, $endSql);
    $exportStmt->execute();
    $exportResult = $exportStmt->get_result();
    while ($row = $exportResult->fetch_assoc()) {
        $year = $row["created_at"] ? date("Y", strtotime($row["created_at"])) : $now->format("Y");
        $duration = "";
        if ($row["checked_in_at"] && $row["completed_at"]) {
            $duration = max(0, (int) round((strtotime($row["completed_at"]) - strtotime($row["checked_in_at"])) / 60));
        }
        $officeCode = (string) ($row["office_code"] ?? "");
        fputcsv($output, [
            "V-" . $year . "-" . str_pad((string) $row["id"], 6, "0", STR_PAD_LEFT),
            $row["visitor_full_name"],
            $row["visit_type"],
            $row["purpose"],
            $officeMap[$officeCode] ?? $officeCode,
            $row["subject"],
            $row["status"],
            $row["appointment_at"],
            $row["checked_in_at"],
            $row["completed_at"],
            $duration,
        ]);
    }
    $exportStmt->close();
    fclose($output);
    $conn->close();
    exit;
}

header("Content-Type: application/json; charset=utf-8");

$records = [];
$recordStmt = $conn->prepare(
    "SELECT a.id, a.office_code, a.status, a.appointment_at, a.checked_in_at,
            a.completed_at, a.cancelled_at, {$visitTypeSelect}, {$purposeSelect}, {$subjectSelect}
     FROM appointments a
     WHERE (a.checked_in_at BETWEEN ? AND ?)
        OR (a.completed_at BETWEEN ? AND ?)
     ORDER BY COALESCE(a.checked_in_at, a.completed_at) ASC, a.id ASC"
);
$recordStmt->bind_param("ssss", $startSql, $endSql, $startSql, $endSql);
$recordStmt->execute();
$recordResult = $recordStmt->get_result();
while ($row = $recordResult->fetch_assoc()) {
    $records[] = $row;
}
$recordStmt->close();

$labels = [];
$bucketKeys = [];
if ($period === "today") {
    for ($hour = 0; $hour < 24; $hour++) {
        $bucket = $start->modify("+" . $hour . " hours");
        $key = $bucket->format("Y-m-d H");
        $labels[] = $bucket->format("g A");
        $bucketKeys[$key] = count($labels) - 1;
    }
} elseif ($period === "quarter") {
    $cursor = $start;
    for ($monthIndex = 0; $monthIndex < 3; $monthIndex++) {
        $key = $cursor->format("Y-m");
        $labels[] = $cursor->format("M");
        $bucketKeys[$key] = count($labels) - 1;
        $cursor = $cursor->modify("first day of next month");
    }
} else {
    $cursor = $start;
    $dayCount = $period === "week" ? 7 : (int) $start->format("t");
    for ($dayIndex = 0; $dayIndex < $dayCount; $dayIndex++) {
        $key = $cursor->format("Y-m-d");
        $labels[] = $period === "week" ? $cursor->format("D") : $cursor->format("M j");
        $bucketKeys[$key] = count($labels) - 1;
        $cursor = $cursor->modify("+1 day");
    }
}

$checkIns = array_fill(0, count($labels), 0);
$checkOuts = array_fill(0, count($labels), 0);
$heatmap = array_fill(0, 7, array_fill(0, 11, 0));
$purposeCounts = [];
$officeDurations = [];
$lateExits = 0;
$completedDurations = [];
$totalVisitors = 0;

function analytics_bucket_key(string $value, string $period): string
{
    $timestamp = strtotime($value);
    if ($period === "today") {
        return date("Y-m-d H", $timestamp);
    }
    if ($period === "quarter") {
        return date("Y-m", $timestamp);
    }
    return date("Y-m-d", $timestamp);
}

foreach ($records as $record) {
    if ($record["checked_in_at"] && $record["checked_in_at"] >= $startSql && $record["checked_in_at"] <= $endSql) {
        $totalVisitors++;
        $key = analytics_bucket_key($record["checked_in_at"], $period);
        if (isset($bucketKeys[$key])) {
            $checkIns[$bucketKeys[$key]]++;
        }
        $timestamp = strtotime($record["checked_in_at"]);
        $dayIndex = (int) date("N", $timestamp) - 1;
        $hour = (int) date("G", $timestamp);
        if ($hour >= 8 && $hour <= 18) {
            $heatmap[$dayIndex][$hour - 8]++;
        }
        if ($hasPurpose) {
            $purpose = trim((string) ($record["purpose"] ?? ""));
            if ($purpose !== "") {
                $purposeCounts[$purpose] = ($purposeCounts[$purpose] ?? 0) + 1;
            }
        }
    }

    if ($record["completed_at"] && $record["completed_at"] >= $startSql && $record["completed_at"] <= $endSql) {
        $key = analytics_bucket_key($record["completed_at"], $period);
        if (isset($bucketKeys[$key])) {
            $checkOuts[$bucketKeys[$key]]++;
        }
        if ((int) date("G", strtotime($record["completed_at"])) >= 21) {
            $lateExits++;
        }
    }

    if ($record["checked_in_at"] && $record["completed_at"]) {
        $minutes = max(0, (int) round((strtotime($record["completed_at"]) - strtotime($record["checked_in_at"])) / 60));
        $completedDurations[] = $minutes;
        $officeCode = (string) ($record["office_code"] ?? "");
        if (!isset($officeDurations[$officeCode])) {
            $officeDurations[$officeCode] = [];
        }
        $officeDurations[$officeCode][] = $minutes;
    }
}

arsort($purposeCounts);
$purposeDistribution = [];
foreach ($purposeCounts as $label => $count) {
    $purposeDistribution[] = ["label" => $label, "count" => (int) $count];
}

$activeResult = $conn->query(
    "SELECT COUNT(*) AS active_total,
            SUM(EXISTS(
                SELECT 1 FROM locations l
                WHERE l.device_name = a.device_name AND l.recorded_at >= a.checked_in_at
            )) AS tracked_total
     FROM appointments a
     WHERE a.status = 'checked_in'"
);
$activeRow = $activeResult ? $activeResult->fetch_assoc() : [];
$activeTotal = (int) ($activeRow["active_total"] ?? 0);
$trackedNow = (int) ($activeRow["tracked_total"] ?? 0);

$locationStmt = $conn->prepare(
    "SELECT COUNT(*) AS update_total, COUNT(DISTINCT l.device_name) AS device_total
     FROM locations l
     WHERE l.recorded_at BETWEEN ? AND ?
       AND EXISTS (
           SELECT 1 FROM appointments a
           WHERE a.device_name = l.device_name
             AND a.checked_in_at IS NOT NULL
             AND l.recorded_at >= a.checked_in_at
             AND (a.completed_at IS NULL OR l.recorded_at <= a.completed_at)
       )"
);
$locationStmt->bind_param("ss", $startSql, $endSql);
$locationStmt->execute();
$locationRow = $locationStmt->get_result()->fetch_assoc();
$locationStmt->close();

$zones = [];
if ($hasZoneName) {
    $zoneStmt = $conn->prepare(
        "SELECT l.zone_name, COUNT(DISTINCT l.device_name) AS visitor_count, COUNT(*) AS update_count
         FROM locations l
         WHERE l.recorded_at BETWEEN ? AND ? AND NULLIF(TRIM(l.zone_name), '') IS NOT NULL
           AND EXISTS (
               SELECT 1 FROM appointments a
               WHERE a.device_name = l.device_name
                 AND a.checked_in_at IS NOT NULL
                 AND l.recorded_at >= a.checked_in_at
                 AND (a.completed_at IS NULL OR l.recorded_at <= a.completed_at)
           )
         GROUP BY l.zone_name
         ORDER BY visitor_count DESC, update_count DESC"
    );
    $zoneStmt->bind_param("ss", $startSql, $endSql);
    $zoneStmt->execute();
    $zoneResult = $zoneStmt->get_result();
    while ($row = $zoneResult->fetch_assoc()) {
        $zones[] = [
            "name" => $row["zone_name"],
            "visitors" => (int) $row["visitor_count"],
            "updates" => (int) $row["update_count"],
        ];
    }
    $zoneStmt->close();
}

$averageDuration = count($completedDurations) > 0
    ? (int) round(array_sum($completedDurations) / count($completedDurations))
    : 0;

$insights = [];
$hourTotals = array_fill(0, 11, 0);
foreach ($heatmap as $day) {
    foreach ($day as $index => $count) {
        $hourTotals[$index] += $count;
    }
}
$peakCount = count($hourTotals) ? max($hourTotals) : 0;
if ($peakCount > 0) {
    $peakIndex = array_search($peakCount, $hourTotals, true);
    $peakHour = 8 + (int) $peakIndex;
    $insights[] = [
        "type" => "info",
        "title" => "Peak hour: " . date("g A", mktime($peakHour, 0)),
        "message" => $peakCount . " check-in" . ($peakCount === 1 ? "" : "s") . " were recorded during the busiest hour.",
    ];
}

$longestOfficeCode = "";
$longestOfficeAverage = 0;
foreach ($officeDurations as $officeCode => $durations) {
    $average = count($durations) ? (int) round(array_sum($durations) / count($durations)) : 0;
    if ($average > $longestOfficeAverage) {
        $longestOfficeAverage = $average;
        $longestOfficeCode = $officeCode;
    }
}
if ($longestOfficeCode !== "") {
    $insights[] = [
        "type" => "success",
        "title" => "Longest average stay",
        "message" => ($officeMap[$longestOfficeCode] ?? $longestOfficeCode) . " averaged " . $longestOfficeAverage . " minutes.",
    ];
}

if ($lateExits > 0) {
    $insights[] = [
        "type" => "warning",
        "title" => "Late-exit activity",
        "message" => $lateExits . " visit" . ($lateExits === 1 ? " ended" : "s ended") . " at or after 9:00 PM.",
    ];
}

if ($activeTotal > 0) {
    $coverage = (int) round(($trackedNow / $activeTotal) * 100);
    $insights[] = [
        "type" => $coverage === 100 ? "success" : "info",
        "title" => "Live GPS coverage: " . $coverage . "%",
        "message" => $trackedNow . " of " . $activeTotal . " active visitor" . ($activeTotal === 1 ? " is" : "s are") . " currently reporting a location.",
    ];
}

if (count($insights) === 0) {
    $insights[] = [
        "type" => "neutral",
        "title" => "Collecting visitor data",
        "message" => "Insights will appear after visitors check in and complete visits during this period.",
    ];
}

$conn->close();

echo json_encode([
    "success" => true,
    "period" => $period,
    "range" => ["start" => $startSql, "end" => $endSql],
    "summary" => [
        "total_visitors" => $totalVisitors,
        "gps_tracked_now" => $trackedNow,
        "average_duration_minutes" => $averageDuration,
    ],
    "trend" => [
        "labels" => $labels,
        "check_ins" => $checkIns,
        "check_outs" => $checkOuts,
    ],
    "purpose_distribution" => $purposeDistribution,
    "heatmap" => [
        "days" => ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"],
        "hours" => ["8 AM", "9 AM", "10 AM", "11 AM", "12 PM", "1 PM", "2 PM", "3 PM", "4 PM", "5 PM", "6 PM"],
        "values" => $heatmap,
    ],
    "location_overview" => [
        "active_visitors" => $activeTotal,
        "tracked_now" => $trackedNow,
        "awaiting_gps" => max(0, $activeTotal - $trackedNow),
        "devices_seen" => (int) ($locationRow["device_total"] ?? 0),
        "updates_recorded" => (int) ($locationRow["update_total"] ?? 0),
        "zones" => $zones,
    ],
    "insights" => $insights,
    "capabilities" => [
        "purpose_collected" => $hasPurpose,
        "subject_collected" => $hasSubject,
        "visit_type_collected" => $hasVisitType,
        "zones_configured" => $hasZoneName,
    ],
]);

<?php
declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . "/db.php";

$apply = in_array("--apply", $argv, true);
$days = 90;
$setting = $conn->query("SELECT setting_value FROM mobile_api_settings WHERE setting_key = 'location_retention_days' LIMIT 1");
if ($setting && ($row = $setting->fetch_assoc())) {
    $days = max(1, min(3650, (int) $row["setting_value"]));
}
$count = $conn->query(
    "SELECT COUNT(*) AS total
     FROM locations l
     LEFT JOIN appointments a ON a.id = l.appointment_id
     WHERE l.recorded_at < NOW() - INTERVAL {$days} DAY
       AND (a.id IS NULL OR a.status <> 'checked_in')"
);
$total = $count ? (int) ($count->fetch_assoc()["total"] ?? 0) : 0;
if (!$apply) {
    echo "Dry run: {$total} location point(s) are older than {$days} days and eligible for deletion. Use --apply to delete them.\n";
    exit(0);
}
$deleted = 0;
do {
    $conn->query(
        "DELETE FROM locations
         WHERE id IN (
           SELECT id FROM (
             SELECT l.id
             FROM locations l
             LEFT JOIN appointments a ON a.id = l.appointment_id
             WHERE l.recorded_at < NOW() - INTERVAL {$days} DAY
               AND (a.id IS NULL OR a.status <> 'checked_in')
             ORDER BY l.id
             LIMIT 5000
           ) AS expired_locations
         )"
    );
    $batch = max(0, $conn->affected_rows);
    $deleted += $batch;
} while ($batch === 5000);
echo "Deleted {$deleted} location point(s) under the {$days}-day retention policy.\n";

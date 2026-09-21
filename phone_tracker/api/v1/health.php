<?php
declare(strict_types=1);
require_once __DIR__ . "/_bootstrap.php";

api_require_method("GET");
$migrationReady = false;
$result = $conn->query("SHOW TABLES LIKE 'api_access_tokens'");
if ($result) {
    $migrationReady = $result->num_rows > 0;
}
api_success([
    "status" => $migrationReady ? "ready" : "migration_required",
    "database" => "connected",
    "timezone" => date_default_timezone_get(),
    "server_time" => date(DATE_ATOM),
]);


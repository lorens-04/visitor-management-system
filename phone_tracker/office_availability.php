<?php
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/office_availability_service.php";

require_roles_json(["offices"]);
$officeCode = strtoupper(trim((string) ($_SESSION["office_code"] ?? "")));
$actorId = (int) $_SESSION["user_id"];
if ($officeCode === "") {
    echo json_encode(["success" => false, "message" => "This account has no office assignment"]);
    exit;
}

function office_availability_payload(mysqli $conn, string $officeCode): array
{
    $settings = office_availability_get_settings($conn, $officeCode);
    $rules = [];
    $ruleStmt = $conn->prepare(
        "SELECT id, day_of_week, start_time, end_time, capacity_override, is_active
         FROM office_availability_rules WHERE office_code = ?
         ORDER BY day_of_week ASC, start_time ASC"
    );
    if ($ruleStmt) {
        $ruleStmt->bind_param("s", $officeCode);
        $ruleStmt->execute();
        $result = $ruleStmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $row["id"] = (int) $row["id"];
            $row["day_of_week"] = (int) $row["day_of_week"];
            $row["is_active"] = (int) $row["is_active"];
            $rules[] = $row;
        }
        $ruleStmt->close();
    }

    $exceptions = [];
    $exceptionStmt = $conn->prepare(
        "SELECT id, starts_at, ends_at, is_available, capacity_override, reason, created_at
         FROM office_availability_exceptions
         WHERE office_code = ? AND ends_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         ORDER BY starts_at ASC, id ASC LIMIT 100"
    );
    if ($exceptionStmt) {
        $exceptionStmt->bind_param("s", $officeCode);
        $exceptionStmt->execute();
        $result = $exceptionStmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $row["id"] = (int) $row["id"];
            $row["is_available"] = (int) $row["is_available"];
            $exceptions[] = $row;
        }
        $exceptionStmt->close();
    }
    return ["settings" => $settings, "rules" => $rules, "exceptions" => $exceptions];
}

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    try {
        $payload = office_availability_payload($conn, $officeCode);
        $conn->close();
        echo json_encode(array_merge(["success" => true], $payload));
    } catch (Throwable $error) {
        $conn->close();
        echo json_encode(["success" => false, "message" => "Run the Phase 1 database migration before configuring availability."]);
    }
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);
if (!is_array($input)) {
    echo json_encode(["success" => false, "message" => "Invalid request body"]);
    exit;
}
$action = strtolower(trim((string) ($input["action"] ?? "")));

try {
    if ($action === "save_schedule") {
        $accepting = !empty($input["accepting_visitors"]) ? 1 : 0;
        $slotDuration = (int) ($input["slot_duration_minutes"] ?? 30);
        $maximum = (int) ($input["maximum_visitors_per_slot"] ?? 1);
        $reason = trim((string) ($input["unavailable_reason"] ?? ""));
        $availableAgainRaw = trim((string) ($input["available_again_at"] ?? ""));
        $schedules = isset($input["schedules"]) && is_array($input["schedules"]) ? $input["schedules"] : [];

        if (!in_array($slotDuration, [15, 30, 45, 60, 90, 120], true)) {
            throw new RuntimeException("Choose a valid appointment duration");
        }
        if ($maximum < 1 || $maximum > 50) {
            throw new RuntimeException("Visitor capacity must be between 1 and 50");
        }
        if (!$accepting && strlen($reason) < 5) {
            throw new RuntimeException("Explain why the office is not accepting visitors");
        }
        $availableAgainSql = null;
        if ($availableAgainRaw !== "") {
            $availableAgain = new DateTime($availableAgainRaw);
            $availableAgainSql = $availableAgain->format("Y-m-d H:i:s");
        }

        $normalizedRules = [];
        foreach ($schedules as $schedule) {
            if (!is_array($schedule) || empty($schedule["enabled"])) {
                continue;
            }
            $day = (int) ($schedule["day_of_week"] ?? 0);
            $start = trim((string) ($schedule["start_time"] ?? ""));
            $end = trim((string) ($schedule["end_time"] ?? ""));
            if ($day < 1 || $day > 7 || !preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end) || $start >= $end) {
                throw new RuntimeException("Every open day needs valid opening and closing times");
            }
            $normalizedRules[] = [$day, $start . ":00", $end . ":00"];
        }
        if ($accepting && count($normalizedRules) === 0) {
            throw new RuntimeException("Select at least one open day, or turn off accepting visitors");
        }

        $reason = substr($reason, 0, 500);
        $conn->begin_transaction();
        $settings = $conn->prepare(
            "INSERT INTO office_availability_settings
             (office_code, accepting_visitors, slot_duration_minutes, maximum_visitors_per_slot,
              unavailable_reason, available_again_at, updated_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE accepting_visitors = VALUES(accepting_visitors),
                 slot_duration_minutes = VALUES(slot_duration_minutes),
                 maximum_visitors_per_slot = VALUES(maximum_visitors_per_slot),
                 unavailable_reason = VALUES(unavailable_reason),
                 available_again_at = VALUES(available_again_at),
                 updated_by_user_id = VALUES(updated_by_user_id)"
        );
        $settings->bind_param("siiissi", $officeCode, $accepting, $slotDuration, $maximum, $reason, $availableAgainSql, $actorId);
        $settings->execute();
        $settings->close();

        $deleteRules = $conn->prepare("DELETE FROM office_availability_rules WHERE office_code = ?");
        $deleteRules->bind_param("s", $officeCode);
        $deleteRules->execute();
        $deleteRules->close();
        if ($normalizedRules) {
            $insertRule = $conn->prepare(
                "INSERT INTO office_availability_rules
                 (office_code, day_of_week, start_time, end_time, is_active)
                 VALUES (?, ?, ?, ?, 1)"
            );
            foreach ($normalizedRules as [$day, $start, $end]) {
                $insertRule->bind_param("siss", $officeCode, $day, $start, $end);
                $insertRule->execute();
            }
            $insertRule->close();
        }

        $audit = $conn->prepare(
            "INSERT INTO audit_logs
             (actor_user_id, action, entity_type, entity_id, details_json, ip_address)
             VALUES (?, 'office.availability_updated', 'office', ?, ?, ?)"
        );
        if ($audit) {
            $details = json_encode(["accepting_visitors" => (bool) $accepting, "weekly_rule_count" => count($normalizedRules), "slot_duration_minutes" => $slotDuration, "capacity" => $maximum]);
            $ipAddress = substr((string) ($_SERVER["REMOTE_ADDR"] ?? ""), 0, 45);
            $audit->bind_param("isss", $actorId, $officeCode, $details, $ipAddress);
            $audit->execute();
            $audit->close();
        }
        $conn->commit();
        $message = "Availability settings saved";
    } elseif ($action === "add_exception") {
        $startsRaw = trim((string) ($input["starts_at"] ?? ""));
        $endsRaw = trim((string) ($input["ends_at"] ?? ""));
        $isAvailable = !empty($input["is_available"]) ? 1 : 0;
        $reason = trim((string) ($input["reason"] ?? ""));
        $capacityRaw = $input["capacity_override"] ?? null;
        $capacity = ($capacityRaw === null || $capacityRaw === "") ? null : (int) $capacityRaw;
        $starts = new DateTime($startsRaw);
        $ends = new DateTime($endsRaw);
        if ($ends <= $starts) {
            throw new RuntimeException("The exception end must be after its start");
        }
        if (strlen($reason) < 3) {
            throw new RuntimeException("Add a short reason for this availability exception");
        }
        if ($capacity !== null && ($capacity < 1 || $capacity > 50)) {
            throw new RuntimeException("Exception capacity must be between 1 and 50");
        }
        $startsSql = $starts->format("Y-m-d H:i:s");
        $endsSql = $ends->format("Y-m-d H:i:s");
        $reason = substr($reason, 0, 500);
        $stmt = $conn->prepare(
            "INSERT INTO office_availability_exceptions
             (office_code, starts_at, ends_at, is_available, capacity_override, reason, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param("sssiisi", $officeCode, $startsSql, $endsSql, $isAvailable, $capacity, $reason, $actorId);
        $stmt->execute();
        $exceptionId = (int) $conn->insert_id;
        $stmt->close();
        $audit = $conn->prepare(
            "INSERT INTO audit_logs
             (actor_user_id, action, entity_type, entity_id, details_json, ip_address)
             VALUES (?, 'office.availability_exception_added', 'office_availability_exception', ?, ?, ?)"
        );
        if ($audit) {
            $entityId = (string) $exceptionId;
            $details = json_encode(["office_code" => $officeCode, "starts_at" => $startsSql, "ends_at" => $endsSql, "is_available" => (bool) $isAvailable, "reason" => $reason]);
            $ipAddress = substr((string) ($_SERVER["REMOTE_ADDR"] ?? ""), 0, 45);
            $audit->bind_param("isss", $actorId, $entityId, $details, $ipAddress);
            $audit->execute();
            $audit->close();
        }
        $message = "Availability exception added";
    } elseif ($action === "delete_exception") {
        $exceptionId = (int) ($input["exception_id"] ?? 0);
        $stmt = $conn->prepare(
            "DELETE FROM office_availability_exceptions WHERE id = ? AND office_code = ?"
        );
        $stmt->bind_param("is", $exceptionId, $officeCode);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            throw new RuntimeException("Availability exception not found");
        }
        $stmt->close();
        $audit = $conn->prepare(
            "INSERT INTO audit_logs
             (actor_user_id, action, entity_type, entity_id, details_json, ip_address)
             VALUES (?, 'office.availability_exception_removed', 'office_availability_exception', ?, ?, ?)"
        );
        if ($audit) {
            $entityId = (string) $exceptionId;
            $details = json_encode(["office_code" => $officeCode]);
            $ipAddress = substr((string) ($_SERVER["REMOTE_ADDR"] ?? ""), 0, 45);
            $audit->bind_param("isss", $actorId, $entityId, $details, $ipAddress);
            $audit->execute();
            $audit->close();
        }
        $message = "Availability exception removed";
    } else {
        throw new RuntimeException("Unknown availability action");
    }

    $payload = office_availability_payload($conn, $officeCode);
    $conn->close();
    echo json_encode(array_merge(["success" => true, "message" => $message], $payload));
} catch (Throwable $error) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    $conn->close();
    echo json_encode(["success" => false, "message" => $error->getMessage()]);
}

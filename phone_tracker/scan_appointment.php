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
$input = json_decode(file_get_contents("php://input"), true);
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
    "SELECT id, registration_code, status, visitor_full_name, device_name, office_code, visit_type,
            scheduled_start_at, scheduled_end_at, checked_in_at, completed_at
     FROM appointments WHERE public_token = ? LIMIT 1"
);
if (!$stmt) {
    echo json_encode(["success" => false, "message" => "Database update required. Run phase1_workflow_migration.sql."]);
    exit;
}
$stmt->bind_param("s", $token);
$stmt->execute();
$appt = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$appt) {
    $conn->close();
    echo json_encode(["success" => false, "message" => "No appointment found for this QR code"]);
    exit;
}

$appointmentId = (int) $appt["id"];
$officeMap = appointment_office_map();
$officeLabel = $officeMap[$appt["office_code"]] ?? $appt["office_code"];
$details = [
    "appointment_id" => $appointmentId,
    "registration_code" => $appt["registration_code"],
    "device_name" => $appt["device_name"],
    "visitor_full_name" => $appt["visitor_full_name"],
    "visit_type" => $appt["visit_type"],
    "office_label" => $officeLabel,
    "scheduled_start_at" => $appt["scheduled_start_at"],
    "scheduled_end_at" => $appt["scheduled_end_at"],
    "status" => $appt["status"],
    "can_override" => false,
];

if ($appt["status"] === "checked_in") {
    $conn->close();
    echo json_encode(array_merge($details, [
        "success" => true,
        "message" => "This visitor is already checked in.",
        "already_checked_in" => true,
    ]));
    exit;
}
if ($appt["status"] === "completed") {
    $conn->close();
    echo json_encode(array_merge($details, ["success" => false, "message" => "This visit has already been completed."]));
    exit;
}

$blockedMessages = [
    "pending_approval" => "This appointment is still waiting for office approval.",
    "reschedule_proposed" => "The visitor must respond to the proposed new schedule first.",
    "rejected" => "This appointment was declined by the office.",
    "cancelled" => "This appointment was cancelled.",
    "unanswered" => "The office did not respond to this appointment request.",
];
if (isset($blockedMessages[$appt["status"]])) {
    $conn->close();
    echo json_encode(array_merge($details, ["success" => false, "message" => $blockedMessages[$appt["status"]]]));
    exit;
}
if ($appt["status"] === "window_closed") {
    $conn->close();
    echo json_encode(array_merge($details, [
        "success" => false,
        "message" => "Appointment Done. The scheduled check-in window has ended.",
        "can_override" => true,
        "window_state" => "closed",
    ]));
    exit;
}
if ($appt["status"] !== "approved") {
    $conn->close();
    echo json_encode(array_merge($details, ["success" => false, "message" => "This QR pass is not valid for check-in."]));
    exit;
}

$now = new DateTime("now");
$scheduledStart = new DateTime($appt["scheduled_start_at"]);
$scheduledEnd = new DateTime($appt["scheduled_end_at"]);
$validFrom = clone $scheduledStart;
$validFrom->modify("-30 minutes");
$hasOverride = false;
$overrideId = 0;
$overrideValidUntil = null;

$override = $conn->prepare(
    "SELECT id, override_valid_until FROM appointment_qr_overrides
     WHERE appointment_id = ? AND used_at IS NULL AND override_valid_until >= NOW()
     ORDER BY id DESC LIMIT 1"
);
if ($override) {
    $override->bind_param("i", $appointmentId);
    $override->execute();
    $overrideRow = $override->get_result()->fetch_assoc();
    $override->close();
    if ($overrideRow) {
        $hasOverride = true;
        $overrideId = (int) $overrideRow["id"];
        $overrideValidUntil = (string) $overrideRow["override_valid_until"];
    }
}

if (!$hasOverride && $now < $validFrom) {
    $conn->close();
    echo json_encode(array_merge($details, [
        "success" => false,
        "message" => "Too early. This QR pass becomes valid at " . $validFrom->format("M j, Y g:i A") . ".",
        "can_override" => true,
        "window_state" => "too_early",
        "qr_valid_from" => $validFrom->format("Y-m-d H:i:s"),
        "qr_valid_until" => $scheduledEnd->format("Y-m-d H:i:s"),
    ]));
    exit;
}

if (!$hasOverride && $now > $scheduledEnd) {
    $conn->begin_transaction();
    try {
        $close = $conn->prepare(
            "UPDATE appointments SET status = 'window_closed', status_updated_at = NOW(), window_closed_at = NOW()
             WHERE id = ? AND status = 'approved'"
        );
        $close->bind_param("i", $appointmentId);
        $close->execute();
        $changed = $close->affected_rows;
        $close->close();
        if ($changed > 0) {
            $history = $conn->prepare(
                "INSERT INTO appointment_status_history
                 (appointment_id, from_status, to_status, changed_by_user_id, note)
                 VALUES (?, 'approved', 'window_closed', NULL, 'Appointment window ended before check-in')"
            );
            $history->bind_param("i", $appointmentId);
            $history->execute();
            $history->close();
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
    }
    $conn->close();
    echo json_encode(array_merge($details, [
        "success" => false,
        "message" => "Appointment Done. The scheduled check-in window has ended.",
        "can_override" => true,
        "window_state" => "closed",
        "status" => "window_closed",
        "qr_valid_from" => $validFrom->format("Y-m-d H:i:s"),
        "qr_valid_until" => $scheduledEnd->format("Y-m-d H:i:s"),
    ]));
    exit;
}

$conn->begin_transaction();
try {
    $updateSql =
        "UPDATE appointments
         SET status = 'checked_in', status_updated_at = NOW(), checked_in_at = NOW(), checked_in_by_user_id = ?";
    if ($hasOverride && $overrideValidUntil) {
        $updateSql .= ", scheduled_end_at = GREATEST(scheduled_end_at, ?)";
    } elseif ($appt["visit_type"] === "walk_in") {
        $updateSql .= ", scheduled_end_at = GREATEST(scheduled_end_at, DATE_ADD(NOW(), INTERVAL 4 HOUR))";
    }
    $updateSql .= " WHERE id = ? AND status = 'approved'";
    $update = $conn->prepare($updateSql);
    if (!$update) {
        throw new RuntimeException("Could not prepare check-in");
    }
    if ($hasOverride && $overrideValidUntil) {
        $update->bind_param("isi", $securityUserId, $overrideValidUntil, $appointmentId);
    } else {
        $update->bind_param("ii", $securityUserId, $appointmentId);
    }
    $update->execute();
    if ($update->affected_rows < 1) {
        throw new RuntimeException("Appointment status changed before check-in");
    }
    $update->close();

    $historyNote = $hasOverride ? "Checked in using an authorized QR time override" : "Checked in by security scan";
    $history = $conn->prepare(
        "INSERT INTO appointment_status_history
         (appointment_id, from_status, to_status, changed_by_user_id, note)
         VALUES (?, 'approved', 'checked_in', ?, ?)"
    );
    $history->bind_param("iis", $appointmentId, $securityUserId, $historyNote);
    $history->execute();
    $history->close();

    if ($overrideId > 0) {
        $used = $conn->prepare("UPDATE appointment_qr_overrides SET used_at = NOW() WHERE id = ?");
        $used->bind_param("i", $overrideId);
        $used->execute();
        $used->close();
    }

    $audit = $conn->prepare(
        "INSERT INTO audit_logs
         (actor_user_id, appointment_id, action, entity_type, entity_id, details_json, ip_address)
         VALUES (?, ?, 'appointment.checked_in', 'appointment', ?, ?, ?)"
    );
    if ($audit) {
        $entityId = (string) $appointmentId;
        $auditDetails = json_encode([
            "used_time_override" => $hasOverride,
            "override_id" => $overrideId ?: null,
            "effective_visit_end" => $hasOverride ? $overrideValidUntil : $appt["scheduled_end_at"],
        ]);
        $ipAddress = isset($_SERVER["REMOTE_ADDR"]) ? substr((string) $_SERVER["REMOTE_ADDR"], 0, 45) : "";
        $audit->bind_param("iisss", $securityUserId, $appointmentId, $entityId, $auditDetails, $ipAddress);
        $audit->execute();
        $audit->close();
    }
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    $conn->close();
    echo json_encode(["success" => false, "message" => "Could not check in this visitor."]);
    exit;
}

$conn->close();
echo json_encode(array_merge($details, [
    "success" => true,
    "message" => "Check-in recorded. Visitor GPS can start.",
    "used_time_override" => $hasOverride,
]));

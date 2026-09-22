<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . "/appointment_offices.php";
require_once dirname(__DIR__, 2) . "/office_availability_service.php";
require_once dirname(__DIR__, 2) . "/appointment_maintenance.php";

function mobile_parse_datetime(string $value): ?DateTime
{
    foreach (["Y-m-d H:i:s", "Y-m-d\TH:i:s", "Y-m-d\TH:i"] as $format) {
        $date = DateTime::createFromFormat($format, $value);
        $errors = DateTime::getLastErrors();
        if ($date && ($errors === false || ((int) $errors["warning_count"] === 0 && (int) $errors["error_count"] === 0))) {
            return $date;
        }
    }
    return null;
}

function mobile_appointment_proposal(mysqli $conn, int $appointmentId): ?array
{
    $stmt = $conn->prepare(
        "SELECT id, reason, message, status, response_deadline, responded_at, created_at
         FROM appointment_reschedule_proposals
         WHERE appointment_id = ? ORDER BY id DESC LIMIT 1"
    );
    $stmt->bind_param("i", $appointmentId);
    $stmt->execute();
    $proposal = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$proposal) {
        return null;
    }
    $proposalId = (int) $proposal["id"];
    $slots = [];
    $slotStmt = $conn->prepare(
        "SELECT id, scheduled_start_at, scheduled_end_at, is_selected
         FROM appointment_reschedule_slots WHERE proposal_id = ? ORDER BY scheduled_start_at"
    );
    $slotStmt->bind_param("i", $proposalId);
    $slotStmt->execute();
    $result = $slotStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $slots[] = [
            "id" => (int) $row["id"],
            "scheduled_start_at" => $row["scheduled_start_at"],
            "scheduled_end_at" => $row["scheduled_end_at"],
            "selected" => (bool) $row["is_selected"],
        ];
    }
    $slotStmt->close();
    return [
        "id" => $proposalId,
        "reason" => (string) $proposal["reason"],
        "message" => (string) $proposal["message"],
        "status" => (string) $proposal["status"],
        "response_deadline" => $proposal["response_deadline"],
        "responded_at" => $proposal["responded_at"],
        "created_at" => $proposal["created_at"],
        "slots" => $slots,
    ];
}

function mobile_appointment_payload(mysqli $conn, array $row, bool $withDetails = false): array
{
    $status = (string) $row["status"];
    $officeCode = (string) $row["office_code"];
    $payload = [
        "id" => (int) $row["id"],
        "registration_code" => $row["registration_code"],
        "visit_type" => (string) ($row["visit_type"] ?? "appointment"),
        "office" => ["code" => $officeCode, "name" => appointment_office_label($officeCode)],
        "purpose" => (string) $row["purpose"],
        "subject" => (string) $row["subject"],
        "scheduled_start_at" => $row["scheduled_start_at"],
        "scheduled_end_at" => $row["scheduled_end_at"],
        "status" => $status,
        "status_updated_at" => $row["status_updated_at"],
        "rejection_reason" => (string) ($row["rejection_reason"] ?? ""),
        "checked_in_at" => $row["checked_in_at"] ?? null,
        "completed_at" => $row["completed_at"] ?? null,
        "created_at" => $row["created_at"],
        "qr_pass" => null,
    ];
    if (in_array($status, ["approved", "checked_in"], true) && !empty($row["public_token"])) {
        $validFrom = new DateTime((string) $row["scheduled_start_at"]);
        $validFrom->modify("-30 minutes");
        $payload["qr_pass"] = [
            "payload" => "isatu-visitor://pass?token=" . $row["public_token"],
            "token" => $row["public_token"],
            "valid_from" => $validFrom->format("Y-m-d H:i:s"),
            "valid_until" => $row["scheduled_end_at"],
            "currently_valid" => $status === "approved" && new DateTime("now") >= $validFrom
                && new DateTime("now") <= new DateTime((string) $row["scheduled_end_at"]),
        ];
    }
    if ($withDetails) {
        $payload["visitor"] = [
            "full_name" => (string) $row["visitor_full_name"],
            "email" => (string) $row["visitor_email"],
            "contact_number" => (string) $row["contact_number"],
        ];
        $payload["additional_details"] = (string) ($row["additional_details"] ?? "");
        $payload["reschedule_proposal"] = mobile_appointment_proposal($conn, (int) $row["id"]);
    }
    return $payload;
}

function mobile_owned_appointment(mysqli $conn, int $appointmentId, int $visitorUserId, bool $forUpdate = false): ?array
{
    $sql =
        "SELECT id, registration_code, public_token, office_code, visitor_full_name, visitor_email,
                contact_number, device_name, visit_type, purpose, destination, subject, additional_details,
                scheduled_start_at, scheduled_end_at, visitor_user_id, status, status_updated_at,
                rejection_reason, checked_in_at, completed_at, created_at
         FROM appointments WHERE id = ? AND visitor_user_id = ? LIMIT 1" . ($forUpdate ? " FOR UPDATE" : "");
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $appointmentId, $visitorUserId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function mobile_create_appointment(mysqli $conn, array $user, array $input): array
{
    $visitType = strtolower(api_text($input, "visit_type", 16));
    if (!in_array($visitType, ["appointment", "walk_in"], true)) {
        $visitType = "appointment";
    }
    $officeCode = strtoupper(api_text($input, "office_code", 16));
    $purpose = api_text($input, "purpose", 100);
    $subject = api_text($input, "subject", 150);
    $additionalDetails = api_text($input, "additional_details", 3000);
    $startText = api_text($input, "scheduled_start_at", 32);
    $endText = api_text($input, "scheduled_end_at", 32);
    $deviceName = api_text($input, "device_name", 100);
    $consentInput = isset($input["location_consent"]) && is_array($input["location_consent"])
        ? $input["location_consent"] : [];
    $consentGranted = !empty($consentInput["granted"]);
    $consentVersion = trim((string) ($consentInput["version"] ?? ""));
    $offices = appointment_office_map();
    $errors = [];
    if (!isset($offices[$officeCode])) {
        $errors["office_code"] = "Select a valid office";
    }
    if ($purpose === "") {
        $errors["purpose"] = "Select or enter the purpose of the visit";
    }
    if ($subject === "") {
        $errors["subject"] = "Enter the subject or concern";
    }
    if (!$consentGranted || $consentVersion === "") {
        $errors["location_consent"] = "Location consent and its displayed policy version are required";
    }
    $start = $visitType === "walk_in" ? new DateTime("now") : mobile_parse_datetime($startText);
    if ($visitType === "appointment" && (!$start || $start <= new DateTime("now"))) {
        $errors["scheduled_start_at"] = "Choose a valid future appointment time";
    }
    if ($errors) {
        throw new InvalidArgumentException(json_encode($errors));
    }
    $settings = office_availability_get_settings($conn, $officeCode);
    if ($visitType === "walk_in" && empty($settings["accepting_visitors"])) {
        throw new DomainException(
            trim((string) ($settings["unavailable_reason"] ?? "")) ?: "This office is not accepting visitors right now"
        );
    }
    $end = $visitType === "appointment" && $endText !== "" ? mobile_parse_datetime($endText) : null;
    if ($visitType === "walk_in") {
        $end = clone $start;
        $end->modify("+60 minutes");
    } elseif (!$end) {
        $end = clone $start;
        $end->modify("+" . max(15, (int) $settings["slot_duration_minutes"]) . " minutes");
    }
    if ($end <= $start || $start->format("Y-m-d") !== $end->format("Y-m-d")) {
        throw new InvalidArgumentException(json_encode(["scheduled_end_at" => "Appointment end time is invalid"]));
    }
    if ($visitType === "appointment") {
        $availability = office_availability_check($conn, $officeCode, $start, $end);
        if (!$availability["available"]) {
            throw new DomainException((string) $availability["message"]);
        }
    }
    $visitorUserId = (int) $user["id"];
    $visitorName = trim((string) $user["display_name"]);
    $visitorEmail = trim((string) ($user["email"] ?? ""));
    $contactNumber = trim((string) ($user["contact_number"] ?? ""));
    if ($visitorName === "" || $visitorEmail === "") {
        throw new DomainException("Complete the visitor profile before requesting an appointment");
    }
    if ($deviceName === "") {
        $deviceName = trim((string) ($user["device_name"] ?? "Android phone")) ?: "Android phone";
    }
    $startSql = $start->format("Y-m-d H:i:s");
    $endSql = $end->format("Y-m-d H:i:s");
    $publicToken = bin2hex(random_bytes(32));
    $destination = $offices[$officeCode];
    $initialStatus = $visitType === "walk_in" ? "approved" : "pending_approval";

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            "INSERT INTO appointments
             (public_token, office_code, visitor_full_name, visitor_email, contact_number,
              device_name, visit_type, purpose, destination, subject, additional_details,
              appointment_at, scheduled_start_at, scheduled_end_at, visitor_user_id, status,
              approved_at, qr_issued_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                     IF(? = 'approved', NOW(), NULL), IF(? = 'approved', NOW(), NULL))"
        );
        $stmt->bind_param(
            "ssssssssssssssisss",
            $publicToken, $officeCode, $visitorName, $visitorEmail, $contactNumber,
            $deviceName, $visitType, $purpose, $destination, $subject, $additionalDetails,
            $startSql, $startSql, $endSql, $visitorUserId, $initialStatus, $initialStatus, $initialStatus
        );
        $stmt->execute();
        $appointmentId = (int) $conn->insert_id;
        $stmt->close();
        $registrationCode = "V-" . $start->format("Y") . "-" . str_pad((string) $appointmentId, 6, "0", STR_PAD_LEFT);
        $code = $conn->prepare("UPDATE appointments SET registration_code = ? WHERE id = ?");
        $code->bind_param("si", $registrationCode, $appointmentId);
        $code->execute();
        $code->close();
        $history = $conn->prepare(
            "INSERT INTO appointment_status_history
             (appointment_id, from_status, to_status, changed_by_user_id, note)
             VALUES (?, NULL, ?, ?, ?)"
        );
        $historyNote = $visitType === "walk_in"
            ? "Walk-in pass created from the Android app"
            : "Appointment request created from the Android app";
        $history->bind_param("isis", $appointmentId, $initialStatus, $visitorUserId, $historyNote);
        $history->execute();
        $history->close();
        $consent = $conn->prepare(
            "INSERT INTO visitor_consents
             (appointment_id, visitor_user_id, consent_type, consent_version, device_info)
             VALUES (?, ?, 'location_tracking', ?, ?)"
        );
        $deviceInfo = substr((string) ($_SERVER["HTTP_USER_AGENT"] ?? $deviceName), 0, 255);
        $consent->bind_param("iiss", $appointmentId, $visitorUserId, $consentVersion, $deviceInfo);
        $consent->execute();
        $consent->close();
        $notification = $conn->prepare(
            "INSERT INTO app_notifications
             (recipient_user_id, appointment_id, notification_type, title, message)
             SELECT id, ?, ?, ?, ?
             FROM app_users WHERE role = 'offices' AND office_code = ? AND is_active = 1"
        );
        $notificationType = $visitType === "walk_in" ? "walk_in_created" : "appointment_request";
        $notificationTitle = $visitType === "walk_in" ? "Walk-in visitor arriving" : "New appointment request";
        $notificationText = $visitType === "walk_in"
            ? $visitorName . " created a walk-in pass for " . $destination . "."
            : $visitorName . " requested " . $start->format("M j, Y g:i A") . ".";
        $notification->bind_param("issss", $appointmentId, $notificationType, $notificationTitle, $notificationText, $officeCode);
        $notification->execute();
        $notification->close();
        api_audit($conn, $visitorUserId, "mobile.appointment_created", "appointment", (string) $appointmentId, [
            "office_code" => $officeCode,
            "visit_type" => $visitType,
            "scheduled_start_at" => $startSql,
            "scheduled_end_at" => $endSql,
            "consent_version" => $consentVersion,
        ], $appointmentId);
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
    $created = mobile_owned_appointment($conn, $appointmentId, $visitorUserId);
    return mobile_appointment_payload($conn, $created, true);
}

function mobile_cancel_appointment(mysqli $conn, int $appointmentId, int $visitorUserId, string $reason): array
{
    $reason = substr(trim($reason), 0, 500);
    $conn->begin_transaction();
    try {
        $appointment = mobile_owned_appointment($conn, $appointmentId, $visitorUserId, true);
        if (!$appointment) {
            throw new DomainException("Appointment not found");
        }
        $from = (string) $appointment["status"];
        if (!in_array($from, ["pending_approval", "approved", "reschedule_proposed"], true)) {
            throw new DomainException("This appointment can no longer be cancelled");
        }
        $update = $conn->prepare(
            "UPDATE appointments SET status = 'cancelled', status_updated_at = NOW(),
             cancelled_at = NOW(), cancelled_by_user_id = ? WHERE id = ? AND visitor_user_id = ?"
        );
        $update->bind_param("iii", $visitorUserId, $appointmentId, $visitorUserId);
        $update->execute();
        $update->close();
        $note = "Cancelled by visitor" . ($reason !== "" ? ": " . $reason : "");
        $history = $conn->prepare(
            "INSERT INTO appointment_status_history
             (appointment_id, from_status, to_status, changed_by_user_id, note)
             VALUES (?, ?, 'cancelled', ?, ?)"
        );
        $history->bind_param("isis", $appointmentId, $from, $visitorUserId, $note);
        $history->execute();
        $history->close();
        $officeCode = (string) $appointment["office_code"];
        $notify = $conn->prepare(
            "INSERT INTO app_notifications
             (recipient_user_id, appointment_id, notification_type, title, message)
             SELECT id, ?, 'appointment.cancelled', 'Visitor cancelled appointment', ?
             FROM app_users WHERE role = 'offices' AND office_code = ? AND is_active = 1"
        );
        $message = $appointment["visitor_full_name"] . " cancelled " . $appointment["scheduled_start_at"] . ".";
        $notify->bind_param("iss", $appointmentId, $message, $officeCode);
        $notify->execute();
        $notify->close();
        api_audit($conn, $visitorUserId, "mobile.appointment_cancelled", "appointment", (string) $appointmentId, ["reason" => $reason], $appointmentId);
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
    return ["appointment_id" => $appointmentId, "status" => "cancelled"];
}

<?php

/**
 * Shared availability rules used by visitor booking, office approval, and
 * reschedule proposals. Keeping this in one file prevents the UI and API from
 * disagreeing about whether a time is bookable.
 */

function office_availability_get_settings(mysqli $conn, string $officeCode): array
{
    $insert = $conn->prepare(
        "INSERT IGNORE INTO office_availability_settings (office_code) VALUES (?)"
    );
    if ($insert) {
        $insert->bind_param("s", $officeCode);
        $insert->execute();
        $insert->close();
    }

    $stmt = $conn->prepare(
        "SELECT office_code, accepting_visitors, slot_duration_minutes,
                maximum_visitors_per_slot, unavailable_reason, available_again_at,
                updated_at
         FROM office_availability_settings
         WHERE office_code = ? LIMIT 1"
    );
    if (!$stmt) {
        throw new RuntimeException("Availability settings are not installed");
    }
    $stmt->bind_param("s", $officeCode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: [
        "office_code" => $officeCode,
        "accepting_visitors" => 1,
        "slot_duration_minutes" => 30,
        "maximum_visitors_per_slot" => 1,
        "unavailable_reason" => "",
        "available_again_at" => null,
        "updated_at" => null,
    ];
}

/**
 * @return array{available:bool,message:string,capacity:int,used:int,remaining:int,source:string}
 */
function office_availability_check(
    mysqli $conn,
    string $officeCode,
    DateTimeInterface $start,
    DateTimeInterface $end,
    int $excludeAppointmentId = 0
): array {
    $result = [
        "available" => false,
        "message" => "This time is not available.",
        "capacity" => 0,
        "used" => 0,
        "remaining" => 0,
        "source" => "settings",
    ];

    if ($end <= $start || $start->format("Y-m-d") !== $end->format("Y-m-d")) {
        $result["message"] = "Appointments must start and end on the same day.";
        return $result;
    }

    $settings = office_availability_get_settings($conn, $officeCode);
    if (!(int) $settings["accepting_visitors"]) {
        $message = trim((string) $settings["unavailable_reason"]);
        $message = $message !== "" ? $message : "This office is not accepting visitors right now.";
        if (!empty($settings["available_again_at"])) {
            $message .= " Available again: " . date("M j, Y g:i A", strtotime((string) $settings["available_again_at"])) . ".";
        }
        $result["message"] = $message;
        return $result;
    }

    $slotDurationMinutes = max(15, (int) $settings["slot_duration_minutes"]);
    $requestedDurationMinutes = (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60);
    if ($requestedDurationMinutes !== $slotDurationMinutes) {
        $result["message"] = "Appointments for this office use {$slotDurationMinutes}-minute time slots.";
        return $result;
    }

    $startSql = $start->format("Y-m-d H:i:s");
    $endSql = $end->format("Y-m-d H:i:s");
    $capacity = max(1, (int) $settings["maximum_visitors_per_slot"]);
    $scheduleAllowed = false;
    $source = "default";
    $slotAnchorTimestamp = null;

    $exception = $conn->prepare(
        "SELECT id, starts_at, ends_at, is_available, capacity_override, reason
         FROM office_availability_exceptions
         WHERE office_code = ? AND starts_at < ? AND ends_at > ?
         ORDER BY id DESC LIMIT 1"
    );
    if ($exception) {
        $exception->bind_param("sss", $officeCode, $endSql, $startSql);
        $exception->execute();
        $exceptionRow = $exception->get_result()->fetch_assoc();
        $exception->close();
        if ($exceptionRow) {
            $exceptionStarts = strtotime((string) $exceptionRow["starts_at"]);
            $exceptionEnds = strtotime((string) $exceptionRow["ends_at"]);
            $coversRequest = $exceptionStarts <= $start->getTimestamp() && $exceptionEnds >= $end->getTimestamp();
            if (!(int) $exceptionRow["is_available"] || !$coversRequest) {
                $reason = trim((string) $exceptionRow["reason"]);
                $result["message"] = $reason !== "" ? $reason : "The office is unavailable during this time.";
                $result["source"] = "exception";
                return $result;
            }
            $scheduleAllowed = true;
            $source = "exception";
            $slotAnchorTimestamp = $exceptionStarts;
            if ($exceptionRow["capacity_override"] !== null) {
                $capacity = max(1, (int) $exceptionRow["capacity_override"]);
            }
        }
    }

    if (!$scheduleAllowed) {
        $countStmt = $conn->prepare(
            "SELECT COUNT(*) AS total FROM office_availability_rules
             WHERE office_code = ? AND is_active = 1"
        );
        $ruleCount = 0;
        if ($countStmt) {
            $countStmt->bind_param("s", $officeCode);
            $countStmt->execute();
            $ruleCount = (int) ($countStmt->get_result()->fetch_assoc()["total"] ?? 0);
            $countStmt->close();
        }

        // Existing installations had no weekly rules. Until an office saves a
        // schedule, remain backward compatible and allow times under the global setting.
        if ($ruleCount === 0) {
            $scheduleAllowed = true;
            $source = "default";
        } else {
            $dayOfWeek = (int) $start->format("N");
            $startTime = $start->format("H:i:s");
            $endTime = $end->format("H:i:s");
            $rule = $conn->prepare(
                "SELECT start_time, end_time, capacity_override
                 FROM office_availability_rules
                 WHERE office_code = ? AND day_of_week = ? AND is_active = 1
                   AND start_time <= ? AND end_time >= ?
                 ORDER BY start_time ASC LIMIT 1"
            );
            if ($rule) {
                $rule->bind_param("siss", $officeCode, $dayOfWeek, $startTime, $endTime);
                $rule->execute();
                $ruleRow = $rule->get_result()->fetch_assoc();
                $rule->close();
                if ($ruleRow) {
                    $scheduleAllowed = true;
                    $source = "weekly_schedule";
                    $slotAnchorTimestamp = strtotime($start->format("Y-m-d") . " " . (string) $ruleRow["start_time"]);
                    if ($ruleRow["capacity_override"] !== null) {
                        $capacity = max(1, (int) $ruleRow["capacity_override"]);
                    }
                }
            }
        }
    }

    if (!$scheduleAllowed) {
        $result["message"] = "The selected time is outside this office's visitor hours.";
        $result["source"] = "weekly_schedule";
        return $result;
    }

    if ($slotAnchorTimestamp !== null) {
        $offsetSeconds = $start->getTimestamp() - $slotAnchorTimestamp;
        if ($offsetSeconds < 0 || $offsetSeconds % ($slotDurationMinutes * 60) !== 0) {
            $result["message"] = "Choose a time that follows the office's {$slotDurationMinutes}-minute appointment slots.";
            $result["source"] = $source;
            return $result;
        }
    }

    $used = 0;
    $appointments = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM appointments
         WHERE office_code = ?
           AND status IN ('pending_approval', 'reschedule_proposed', 'approved', 'checked_in')
           AND scheduled_start_at < ? AND scheduled_end_at > ?
           AND id <> ?"
    );
    if ($appointments) {
        $appointments->bind_param("sssi", $officeCode, $endSql, $startSql, $excludeAppointmentId);
        $appointments->execute();
        $used += (int) ($appointments->get_result()->fetch_assoc()["total"] ?? 0);
        $appointments->close();
    }

    // Pending proposed slots temporarily hold capacity so the office does not
    // offer the same last slot to several visitors.
    $proposals = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM appointment_reschedule_slots s
         INNER JOIN appointment_reschedule_proposals p ON p.id = s.proposal_id AND p.status = 'pending'
         INNER JOIN appointments a ON a.id = p.appointment_id
         WHERE a.office_code = ? AND a.id <> ?
           AND s.scheduled_start_at < ? AND s.scheduled_end_at > ?"
    );
    if ($proposals) {
        $proposals->bind_param("siss", $officeCode, $excludeAppointmentId, $endSql, $startSql);
        $proposals->execute();
        $used += (int) ($proposals->get_result()->fetch_assoc()["total"] ?? 0);
        $proposals->close();
    }

    $remaining = max(0, $capacity - $used);
    return [
        "available" => $remaining > 0,
        "message" => $remaining > 0 ? "Time is available." : "This appointment time is already full.",
        "capacity" => $capacity,
        "used" => $used,
        "remaining" => $remaining,
        "source" => $source,
    ];
}

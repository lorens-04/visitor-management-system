-- Phase 1 fresh-install schema for appointments and visitor workflow.
-- Import phone_tracker.sql and login_users.sql first so locations/app_users exist.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `appointments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `registration_code` varchar(24) DEFAULT NULL,
  `public_token` char(64) NOT NULL,
  `office_code` varchar(16) NOT NULL,
  `visitor_full_name` varchar(150) NOT NULL,
  `visitor_email` varchar(190) NOT NULL DEFAULT '',
  `contact_number` varchar(32) NOT NULL DEFAULT '',
  `device_name` varchar(100) NOT NULL,
  `visit_type` enum('appointment','walk_in') NOT NULL DEFAULT 'appointment',
  `purpose` varchar(100) NOT NULL DEFAULT '',
  `destination` varchar(150) NOT NULL DEFAULT '',
  `subject` varchar(150) NOT NULL DEFAULT '',
  `additional_details` text DEFAULT NULL,
  `appointment_at` datetime NOT NULL COMMENT 'Legacy start-time alias retained for compatibility',
  `scheduled_start_at` datetime NOT NULL,
  `scheduled_end_at` datetime NOT NULL,
  `visitor_user_id` int(11) NOT NULL,
  `status` enum('pending_approval','approved','rejected','cancelled','unanswered','reschedule_proposed','checked_in','completed','window_closed') NOT NULL DEFAULT 'pending_approval',
  `status_updated_at` datetime NOT NULL DEFAULT current_timestamp(),
  `approved_at` datetime DEFAULT NULL,
  `approved_by_user_id` int(11) DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `rejected_by_user_id` int(11) DEFAULT NULL,
  `rejection_reason` varchar(500) NOT NULL DEFAULT '',
  `unanswered_at` datetime DEFAULT NULL,
  `window_closed_at` datetime DEFAULT NULL,
  `qr_issued_at` datetime DEFAULT NULL,
  `checked_in_at` datetime DEFAULT NULL,
  `checked_in_by_user_id` int(11) DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `completed_by_user_id` int(11) DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancelled_by_user_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_appointments_public_token` (`public_token`),
  UNIQUE KEY `uq_appointments_registration_code` (`registration_code`),
  KEY `idx_visitor_user` (`visitor_user_id`),
  KEY `idx_appointment_at` (`appointment_at`),
  KEY `idx_scheduled_window` (`scheduled_start_at`,`scheduled_end_at`),
  KEY `idx_office_schedule` (`office_code`,`scheduled_start_at`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_appointments_visitor_user` FOREIGN KEY (`visitor_user_id`) REFERENCES `app_users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_appointments_approved_by` FOREIGN KEY (`approved_by_user_id`) REFERENCES `app_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_appointments_rejected_by` FOREIGN KEY (`rejected_by_user_id`) REFERENCES `app_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_appointments_checked_in_by` FOREIGN KEY (`checked_in_by_user_id`) REFERENCES `app_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_appointments_completed_by` FOREIGN KEY (`completed_by_user_id`) REFERENCES `app_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_appointments_cancelled_by` FOREIGN KEY (`cancelled_by_user_id`) REFERENCES `app_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `appointment_status_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `appointment_id` int(11) NOT NULL,
  `from_status` enum('pending_approval','approved','rejected','cancelled','unanswered','reschedule_proposed','checked_in','completed','window_closed') DEFAULT NULL,
  `to_status` enum('pending_approval','approved','rejected','cancelled','unanswered','reschedule_proposed','checked_in','completed','window_closed') NOT NULL,
  `changed_by_user_id` int(11) DEFAULT NULL,
  `changed_at` datetime NOT NULL DEFAULT current_timestamp(),
  `note` varchar(500) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_hist_appt` (`appointment_id`),
  KEY `idx_hist_status` (`to_status`),
  CONSTRAINT `fk_hist_appt` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hist_changed_by` FOREIGN KEY (`changed_by_user_id`) REFERENCES `app_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `visitor_consents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `appointment_id` int(11) NOT NULL,
  `visitor_user_id` int(11) NOT NULL,
  `consent_type` varchar(64) NOT NULL DEFAULT 'location_tracking',
  `consent_version` varchar(32) NOT NULL,
  `consented_at` datetime NOT NULL DEFAULT current_timestamp(),
  `withdrawn_at` datetime DEFAULT NULL,
  `device_info` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_consent_appointment` (`appointment_id`),
  KEY `idx_consent_visitor` (`visitor_user_id`),
  CONSTRAINT `fk_consent_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_consent_visitor` FOREIGN KEY (`visitor_user_id`) REFERENCES `app_users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `app_notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `recipient_user_id` int(11) NOT NULL,
  `appointment_id` int(11) DEFAULT NULL,
  `notification_type` varchar(64) NOT NULL,
  `title` varchar(150) NOT NULL,
  `message` varchar(1000) NOT NULL DEFAULT '',
  `data_json` longtext DEFAULT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notification_recipient` (`recipient_user_id`,`read_at`,`created_at`),
  KEY `idx_notification_appointment` (`appointment_id`),
  CONSTRAINT `fk_notification_recipient` FOREIGN KEY (`recipient_user_id`) REFERENCES `app_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notification_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `actor_user_id` int(11) DEFAULT NULL,
  `appointment_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `entity_type` varchar(64) NOT NULL DEFAULT '',
  `entity_id` varchar(64) NOT NULL DEFAULT '',
  `details_json` longtext DEFAULT NULL,
  `ip_address` varchar(45) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_actor` (`actor_user_id`,`created_at`),
  KEY `idx_audit_appointment` (`appointment_id`,`created_at`),
  KEY `idx_audit_action` (`action`,`created_at`),
  CONSTRAINT `fk_audit_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `app_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_audit_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `appointment_qr_overrides` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `appointment_id` int(11) NOT NULL,
  `authorized_by_user_id` int(11) NOT NULL,
  `reason` varchar(500) NOT NULL,
  `original_valid_from` datetime NOT NULL,
  `original_valid_until` datetime NOT NULL,
  `override_valid_until` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_override_appointment` (`appointment_id`,`override_valid_until`),
  CONSTRAINT `fk_override_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_override_authorized_by` FOREIGN KEY (`authorized_by_user_id`) REFERENCES `app_users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `appointment_reschedule_proposals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `appointment_id` int(11) NOT NULL,
  `proposed_by_user_id` int(11) NOT NULL,
  `reason` varchar(255) NOT NULL DEFAULT '',
  `message` varchar(1000) NOT NULL DEFAULT '',
  `status` enum('pending','accepted','declined','withdrawn','closed') NOT NULL DEFAULT 'pending',
  `response_deadline` datetime DEFAULT NULL,
  `responded_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_reschedule_appointment` (`appointment_id`,`status`),
  CONSTRAINT `fk_reschedule_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_reschedule_proposed_by` FOREIGN KEY (`proposed_by_user_id`) REFERENCES `app_users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `appointment_reschedule_slots` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `proposal_id` int(11) NOT NULL,
  `scheduled_start_at` datetime NOT NULL,
  `scheduled_end_at` datetime NOT NULL,
  `is_selected` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_reschedule_slot` (`proposal_id`,`scheduled_start_at`),
  CONSTRAINT `fk_reschedule_slot_proposal` FOREIGN KEY (`proposal_id`) REFERENCES `appointment_reschedule_proposals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `office_availability_settings` (
  `office_code` varchar(16) NOT NULL,
  `accepting_visitors` tinyint(1) NOT NULL DEFAULT 1,
  `slot_duration_minutes` smallint(5) unsigned NOT NULL DEFAULT 30,
  `maximum_visitors_per_slot` smallint(5) unsigned NOT NULL DEFAULT 1,
  `unavailable_reason` varchar(500) NOT NULL DEFAULT '',
  `available_again_at` datetime DEFAULT NULL,
  `updated_by_user_id` int(11) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`office_code`),
  CONSTRAINT `fk_availability_settings_updated_by` FOREIGN KEY (`updated_by_user_id`) REFERENCES `app_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `office_availability_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `office_code` varchar(16) NOT NULL,
  `day_of_week` tinyint(3) unsigned NOT NULL COMMENT '1=Monday through 7=Sunday',
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `capacity_override` smallint(5) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_availability_rule` (`office_code`,`day_of_week`,`start_time`,`end_time`),
  KEY `idx_availability_rule_lookup` (`office_code`,`day_of_week`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `office_availability_exceptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `office_code` varchar(16) NOT NULL,
  `starts_at` datetime NOT NULL,
  `ends_at` datetime NOT NULL,
  `is_available` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0=closed period, 1=extra available period',
  `capacity_override` smallint(5) unsigned DEFAULT NULL,
  `reason` varchar(500) NOT NULL DEFAULT '',
  `created_by_user_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_availability_exception_lookup` (`office_code`,`starts_at`,`ends_at`),
  CONSTRAINT `fk_availability_exception_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `app_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `office_availability_settings` (`office_code`)
VALUES ('IT'), ('IS'), ('CS'), ('DEANS'), ('TECH_SUPPORT')
ON DUPLICATE KEY UPDATE `office_code` = VALUES(`office_code`);

ALTER TABLE `locations`
  ADD CONSTRAINT `fk_locations_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_locations_visitor` FOREIGN KEY (`visitor_user_id`) REFERENCES `app_users` (`id`) ON DELETE SET NULL;

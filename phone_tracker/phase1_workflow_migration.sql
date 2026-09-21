-- Phase 1 migration for an existing phone_tracker database.
-- Prerequisites: login_users.sql and the original appointments.sql were already imported.
-- This file is additive and keeps appointment_at/device_name for compatibility.
SET NAMES utf8mb4;

ALTER TABLE `appointments`
  ADD COLUMN IF NOT EXISTS `registration_code` varchar(24) DEFAULT NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `contact_number` varchar(32) NOT NULL DEFAULT '' AFTER `visitor_email`,
  ADD COLUMN IF NOT EXISTS `visit_type` enum('appointment','walk_in') NOT NULL DEFAULT 'appointment' AFTER `device_name`,
  ADD COLUMN IF NOT EXISTS `purpose` varchar(100) NOT NULL DEFAULT '' AFTER `visit_type`,
  ADD COLUMN IF NOT EXISTS `destination` varchar(150) NOT NULL DEFAULT '' AFTER `purpose`,
  ADD COLUMN IF NOT EXISTS `subject` varchar(150) NOT NULL DEFAULT '' AFTER `destination`,
  ADD COLUMN IF NOT EXISTS `additional_details` text DEFAULT NULL AFTER `subject`,
  ADD COLUMN IF NOT EXISTS `scheduled_start_at` datetime DEFAULT NULL AFTER `appointment_at`,
  ADD COLUMN IF NOT EXISTS `scheduled_end_at` datetime DEFAULT NULL AFTER `scheduled_start_at`,
  ADD COLUMN IF NOT EXISTS `approved_at` datetime DEFAULT NULL AFTER `status_updated_at`,
  ADD COLUMN IF NOT EXISTS `approved_by_user_id` int(11) DEFAULT NULL AFTER `approved_at`,
  ADD COLUMN IF NOT EXISTS `rejected_at` datetime DEFAULT NULL AFTER `approved_by_user_id`,
  ADD COLUMN IF NOT EXISTS `rejected_by_user_id` int(11) DEFAULT NULL AFTER `rejected_at`,
  ADD COLUMN IF NOT EXISTS `rejection_reason` varchar(500) NOT NULL DEFAULT '' AFTER `rejected_by_user_id`,
  ADD COLUMN IF NOT EXISTS `unanswered_at` datetime DEFAULT NULL AFTER `rejection_reason`,
  ADD COLUMN IF NOT EXISTS `window_closed_at` datetime DEFAULT NULL AFTER `unanswered_at`,
  ADD COLUMN IF NOT EXISTS `qr_issued_at` datetime DEFAULT NULL AFTER `window_closed_at`;

UPDATE `appointments`
SET `scheduled_start_at` = COALESCE(`scheduled_start_at`, `appointment_at`),
    `scheduled_end_at` = COALESCE(`scheduled_end_at`, DATE_ADD(`appointment_at`, INTERVAL 30 MINUTE));

UPDATE `appointments`
SET `registration_code` = CONCAT('V-', YEAR(`created_at`), '-', LPAD(`id`, 6, '0'))
WHERE `registration_code` IS NULL OR `registration_code` = '';

ALTER TABLE `appointments`
  MODIFY COLUMN `scheduled_start_at` datetime NOT NULL,
  MODIFY COLUMN `scheduled_end_at` datetime NOT NULL,
  MODIFY COLUMN `status` enum('pending','pending_approval','approved','rejected','cancelled','unanswered','reschedule_proposed','checked_in','completed','window_closed') NOT NULL DEFAULT 'pending_approval';

UPDATE `appointments` SET `status` = 'pending_approval' WHERE `status` = 'pending';

ALTER TABLE `appointments`
  MODIFY COLUMN `status` enum('pending_approval','approved','rejected','cancelled','unanswered','reschedule_proposed','checked_in','completed','window_closed') NOT NULL DEFAULT 'pending_approval',
  ADD UNIQUE INDEX IF NOT EXISTS `uq_appointments_registration_code` (`registration_code`),
  ADD INDEX IF NOT EXISTS `idx_scheduled_window` (`scheduled_start_at`,`scheduled_end_at`),
  ADD INDEX IF NOT EXISTS `idx_office_schedule` (`office_code`,`scheduled_start_at`);

ALTER TABLE `appointment_status_history`
  MODIFY COLUMN `from_status` enum('pending','pending_approval','approved','rejected','cancelled','unanswered','reschedule_proposed','checked_in','completed','window_closed') DEFAULT NULL,
  MODIFY COLUMN `to_status` enum('pending','pending_approval','approved','rejected','cancelled','unanswered','reschedule_proposed','checked_in','completed','window_closed') NOT NULL,
  MODIFY COLUMN `note` varchar(500) NOT NULL DEFAULT '';

UPDATE `appointment_status_history` SET `from_status` = 'pending_approval' WHERE `from_status` = 'pending';
UPDATE `appointment_status_history` SET `to_status` = 'pending_approval' WHERE `to_status` = 'pending';

ALTER TABLE `appointment_status_history`
  MODIFY COLUMN `from_status` enum('pending_approval','approved','rejected','cancelled','unanswered','reschedule_proposed','checked_in','completed','window_closed') DEFAULT NULL,
  MODIFY COLUMN `to_status` enum('pending_approval','approved','rejected','cancelled','unanswered','reschedule_proposed','checked_in','completed','window_closed') NOT NULL;

ALTER TABLE `locations`
  ADD COLUMN IF NOT EXISTS `appointment_id` int(11) DEFAULT NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `visitor_user_id` int(11) DEFAULT NULL AFTER `appointment_id`,
  ADD INDEX IF NOT EXISTS `idx_location_appointment_time` (`appointment_id`,`recorded_at`),
  ADD INDEX IF NOT EXISTS `idx_location_visitor_time` (`visitor_user_id`,`recorded_at`);

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

-- Add foreign keys only when they do not already exist so the migration can be rerun safely.
DELIMITER $$
DROP PROCEDURE IF EXISTS `phase1_add_fk`$$
CREATE PROCEDURE `phase1_add_fk`(
  IN p_table_name varchar(64),
  IN p_constraint_name varchar(64),
  IN p_ddl text
)
BEGIN
  IF NOT EXISTS (
    SELECT 1
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = p_table_name
      AND CONSTRAINT_NAME = p_constraint_name
  ) THEN
    SET @phase1_ddl = p_ddl;
    PREPARE phase1_stmt FROM @phase1_ddl;
    EXECUTE phase1_stmt;
    DEALLOCATE PREPARE phase1_stmt;
  END IF;
END$$
DELIMITER ;

CALL `phase1_add_fk`('appointments', 'fk_appointments_approved_by',
  'ALTER TABLE `appointments` ADD CONSTRAINT `fk_appointments_approved_by` FOREIGN KEY (`approved_by_user_id`) REFERENCES `app_users` (`id`) ON DELETE SET NULL');
CALL `phase1_add_fk`('appointments', 'fk_appointments_rejected_by',
  'ALTER TABLE `appointments` ADD CONSTRAINT `fk_appointments_rejected_by` FOREIGN KEY (`rejected_by_user_id`) REFERENCES `app_users` (`id`) ON DELETE SET NULL');
CALL `phase1_add_fk`('locations', 'fk_locations_appointment',
  'ALTER TABLE `locations` ADD CONSTRAINT `fk_locations_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE');
CALL `phase1_add_fk`('locations', 'fk_locations_visitor',
  'ALTER TABLE `locations` ADD CONSTRAINT `fk_locations_visitor` FOREIGN KEY (`visitor_user_id`) REFERENCES `app_users` (`id`) ON DELETE SET NULL');

DROP PROCEDURE IF EXISTS `phase1_add_fk`;

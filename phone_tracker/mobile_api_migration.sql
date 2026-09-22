-- Phase 4 additive schema for the native visitor application API.
-- Run after phase1_workflow_migration.sql. Safe to run more than once on MariaDB 10.4+.
SET NAMES utf8mb4;

ALTER TABLE `app_users`
  ADD COLUMN IF NOT EXISTS `email` varchar(190) DEFAULT NULL AFTER `username`,
  ADD COLUMN IF NOT EXISTS `contact_number` varchar(32) NOT NULL DEFAULT '' AFTER `display_name`,
  ADD COLUMN IF NOT EXISTS `email_verified_at` datetime DEFAULT NULL AFTER `contact_number`,
  ADD COLUMN IF NOT EXISTS `password_changed_at` datetime DEFAULT NULL AFTER `email_verified_at`;

ALTER TABLE `app_users`
  ADD UNIQUE KEY IF NOT EXISTS `uq_app_users_email` (`email`);

CREATE TABLE IF NOT EXISTS `api_access_tokens` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `installation_id` varchar(191) NOT NULL DEFAULT '',
  `device_name` varchar(100) NOT NULL DEFAULT '',
  `user_agent` varchar(255) NOT NULL DEFAULT '',
  `last_used_at` datetime DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_api_access_token_hash` (`token_hash`),
  KEY `idx_api_access_user` (`user_id`,`revoked_at`,`expires_at`),
  CONSTRAINT `fk_api_access_user` FOREIGN KEY (`user_id`) REFERENCES `app_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `account_action_tokens` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `purpose` enum('verify_email','password_reset') NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `consumed_at` datetime DEFAULT NULL,
  `requested_ip` varchar(45) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_account_action_token_hash` (`token_hash`),
  KEY `idx_account_action_user` (`user_id`,`purpose`,`consumed_at`,`expires_at`),
  CONSTRAINT `fk_account_action_user` FOREIGN KEY (`user_id`) REFERENCES `app_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `outbound_emails` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `recipient_email` varchar(190) NOT NULL,
  `template_key` varchar(64) NOT NULL,
  `subject` varchar(190) NOT NULL,
  `payload_json` longtext DEFAULT NULL,
  `status` enum('queued','sending','sent','failed') NOT NULL DEFAULT 'queued',
  `attempt_count` smallint(5) unsigned NOT NULL DEFAULT 0,
  `next_attempt_at` datetime NOT NULL DEFAULT current_timestamp(),
  `sent_at` datetime DEFAULT NULL,
  `last_error` varchar(1000) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_outbound_email_queue` (`status`,`next_attempt_at`),
  CONSTRAINT `fk_outbound_email_user` FOREIGN KEY (`user_id`) REFERENCES `app_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `api_rate_limits` (
  `bucket_key` char(64) NOT NULL,
  `attempt_count` smallint(5) unsigned NOT NULL DEFAULT 0,
  `window_started_at` datetime NOT NULL,
  `blocked_until` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`bucket_key`),
  KEY `idx_api_rate_limit_cleanup` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `mobile_devices` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `visitor_user_id` int(11) NOT NULL,
  `installation_id` varchar(191) NOT NULL,
  `platform` enum('android') NOT NULL DEFAULT 'android',
  `fcm_token` varchar(2048) NOT NULL,
  `fcm_token_hash` char(64) NOT NULL,
  `app_version` varchar(32) NOT NULL DEFAULT '',
  `device_model` varchar(100) NOT NULL DEFAULT '',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_seen_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mobile_device_installation` (`visitor_user_id`,`installation_id`),
  UNIQUE KEY `uq_mobile_device_fcm_hash` (`fcm_token_hash`),
  KEY `idx_mobile_device_active` (`visitor_user_id`,`is_active`),
  CONSTRAINT `fk_mobile_device_visitor` FOREIGN KEY (`visitor_user_id`) REFERENCES `app_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `notification_deliveries` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `notification_id` int(11) NOT NULL,
  `device_id` bigint(20) NOT NULL,
  `status` enum('queued','sending','sent','failed','invalid_token') NOT NULL DEFAULT 'queued',
  `attempt_count` smallint(5) unsigned NOT NULL DEFAULT 0,
  `next_attempt_at` datetime NOT NULL DEFAULT current_timestamp(),
  `provider_message_id` varchar(255) NOT NULL DEFAULT '',
  `last_error` varchar(1000) NOT NULL DEFAULT '',
  `sent_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_notification_device` (`notification_id`,`device_id`),
  KEY `idx_notification_delivery_queue` (`status`,`next_attempt_at`),
  CONSTRAINT `fk_notification_delivery_notification` FOREIGN KEY (`notification_id`) REFERENCES `app_notifications` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notification_delivery_device` FOREIGN KEY (`device_id`) REFERENCES `mobile_devices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `location_tracking_sessions` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `appointment_id` int(11) NOT NULL,
  `visitor_user_id` int(11) NOT NULL,
  `consent_id` int(11) NOT NULL,
  `access_token_id` bigint(20) DEFAULT NULL,
  `installation_id` varchar(191) NOT NULL DEFAULT '',
  `client_session_id` varchar(64) NOT NULL DEFAULT '',
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_upload_at` datetime DEFAULT NULL,
  `ended_at` datetime DEFAULT NULL,
  `ended_reason` enum('completed','consent_withdrawn','window_ended','manual') DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tracking_appointment` (`appointment_id`),
  KEY `idx_tracking_visitor` (`visitor_user_id`,`ended_at`),
  CONSTRAINT `fk_tracking_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tracking_visitor` FOREIGN KEY (`visitor_user_id`) REFERENCES `app_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tracking_consent` FOREIGN KEY (`consent_id`) REFERENCES `visitor_consents` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_tracking_access_token` FOREIGN KEY (`access_token_id`) REFERENCES `api_access_tokens` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `locations`
  ADD COLUMN IF NOT EXISTS `tracking_session_id` bigint(20) DEFAULT NULL AFTER `visitor_user_id`,
  ADD COLUMN IF NOT EXISTS `client_event_id` varchar(64) DEFAULT NULL AFTER `tracking_session_id`,
  ADD COLUMN IF NOT EXISTS `captured_at` datetime DEFAULT NULL AFTER `accuracy`,
  ADD COLUMN IF NOT EXISTS `received_at` datetime NOT NULL DEFAULT current_timestamp() AFTER `captured_at`,
  ADD UNIQUE KEY IF NOT EXISTS `uq_location_client_event` (`tracking_session_id`,`client_event_id`),
  ADD KEY IF NOT EXISTS `idx_location_session_time` (`tracking_session_id`,`recorded_at`);

DROP PROCEDURE IF EXISTS `phase4_add_location_tracking_fk`;
DELIMITER //
CREATE PROCEDURE `phase4_add_location_tracking_fk`()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_locations_tracking_session'
  ) THEN
    ALTER TABLE `locations`
      ADD CONSTRAINT `fk_locations_tracking_session`
      FOREIGN KEY (`tracking_session_id`) REFERENCES `location_tracking_sessions` (`id`) ON DELETE SET NULL;
  END IF;
END//
DELIMITER ;
CALL `phase4_add_location_tracking_fk`();
DROP PROCEDURE IF EXISTS `phase4_add_location_tracking_fk`;

CREATE TABLE IF NOT EXISTS `mobile_api_settings` (
  `setting_key` varchar(64) NOT NULL,
  `setting_value` varchar(255) NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `mobile_api_settings` (`setting_key`,`setting_value`) VALUES
  ('access_token_days','30'),
  ('location_upload_interval_seconds','15'),
  ('location_offline_upload_grace_hours','24'),
  ('location_retention_days','90'),
  ('location_batch_max_points','100')
ON DUPLICATE KEY UPDATE `setting_key` = VALUES(`setting_key`);

DROP TRIGGER IF EXISTS `trg_queue_mobile_notification`;
DELIMITER //
CREATE TRIGGER `trg_queue_mobile_notification`
AFTER INSERT ON `app_notifications`
FOR EACH ROW
BEGIN
  INSERT IGNORE INTO `notification_deliveries` (`notification_id`,`device_id`)
  SELECT NEW.id, d.id
  FROM `mobile_devices` d
  WHERE d.visitor_user_id = NEW.recipient_user_id AND d.is_active = 1;
END//
DELIMITER ;


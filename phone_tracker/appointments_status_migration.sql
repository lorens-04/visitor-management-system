-- LEGACY migration for pre-Phase-1 databases.
-- Do not run this after phase1_workflow_migration.sql.
SET NAMES utf8mb4;

ALTER TABLE `appointments`
  ADD COLUMN `status_updated_at` datetime NOT NULL DEFAULT current_timestamp() AFTER `status`,
  ADD COLUMN `completed_at` datetime DEFAULT NULL AFTER `checked_in_by_user_id`,
  ADD COLUMN `completed_by_user_id` int(11) DEFAULT NULL AFTER `completed_at`,
  ADD COLUMN `cancelled_at` datetime DEFAULT NULL AFTER `completed_by_user_id`,
  ADD COLUMN `cancelled_by_user_id` int(11) DEFAULT NULL AFTER `cancelled_at`;

ALTER TABLE `appointments`
  ADD CONSTRAINT `fk_appointments_completed_by` FOREIGN KEY (`completed_by_user_id`) REFERENCES `app_users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_appointments_cancelled_by` FOREIGN KEY (`cancelled_by_user_id`) REFERENCES `app_users` (`id`) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS `appointment_status_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `appointment_id` int(11) NOT NULL,
  `from_status` enum('pending','checked_in','completed','cancelled') DEFAULT NULL,
  `to_status` enum('pending','checked_in','completed','cancelled') NOT NULL,
  `changed_by_user_id` int(11) DEFAULT NULL,
  `changed_at` datetime NOT NULL DEFAULT current_timestamp(),
  `note` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_hist_appt` (`appointment_id`),
  KEY `idx_hist_status` (`to_status`),
  CONSTRAINT `fk_hist_appt` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hist_changed_by` FOREIGN KEY (`changed_by_user_id`) REFERENCES `app_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

UPDATE `appointments`
SET `status_updated_at` = COALESCE(`checked_in_at`, `created_at`)
WHERE `status_updated_at` IS NULL;

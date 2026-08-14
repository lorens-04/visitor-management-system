-- Appointments for office visits (run on `phone_tracker` after app_users exists)
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `appointments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `public_token` char(64) NOT NULL,
  `office_code` varchar(16) NOT NULL,
  `visitor_full_name` varchar(150) NOT NULL,
  `visitor_email` varchar(190) NOT NULL DEFAULT '',
  `device_name` varchar(100) NOT NULL,
  `appointment_at` datetime NOT NULL,
  `visitor_user_id` int(11) NOT NULL,
  `status` enum('pending','checked_in','completed','cancelled') NOT NULL DEFAULT 'pending',
  `status_updated_at` datetime NOT NULL DEFAULT current_timestamp(),
  `checked_in_at` datetime DEFAULT NULL,
  `checked_in_by_user_id` int(11) DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `completed_by_user_id` int(11) DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancelled_by_user_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `public_token` (`public_token`),
  KEY `idx_visitor_user` (`visitor_user_id`),
  KEY `idx_appointment_at` (`appointment_at`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_appointments_visitor_user` FOREIGN KEY (`visitor_user_id`) REFERENCES `app_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_appointments_checked_in_by` FOREIGN KEY (`checked_in_by_user_id`) REFERENCES `app_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_appointments_completed_by` FOREIGN KEY (`completed_by_user_id`) REFERENCES `app_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_appointments_cancelled_by` FOREIGN KEY (`cancelled_by_user_id`) REFERENCES `app_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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

-- Login users for Phone GPS Tracker (import into `phone_tracker` or run after USE phone_tracker)
-- Default password for all seed accounts: password
-- Change passwords in production; use PHP: password_hash('your_password', PASSWORD_DEFAULT)

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `app_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(64) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `display_name` varchar(100) NOT NULL DEFAULT '',
  `role` enum('security','visitor','offices','admin') NOT NULL,
  `office_code` varchar(16) NOT NULL DEFAULT '' COMMENT 'Department code for offices role; empty for other roles',
  `profile_image` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `idx_role` (`role`),
  KEY `idx_office_code` (`office_code`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Password for each row: password (bcrypt via PHP password_hash)
INSERT INTO `app_users` (`username`, `password_hash`, `display_name`, `role`, `office_code`) VALUES
('admin', '$2y$10$joZ8DFdUSvotBXFFQhPQkO0uyZthbNB3.mVREmRbhIeGRNp7CngKe', 'Administrator', 'admin', ''),
('security', '$2y$10$joZ8DFdUSvotBXFFQhPQkO0uyZthbNB3.mVREmRbhIeGRNp7CngKe', 'Security Desk', 'security', ''),
('visitor', '$2y$10$joZ8DFdUSvotBXFFQhPQkO0uyZthbNB3.mVREmRbhIeGRNp7CngKe', 'Demo Visitor', 'visitor', ''),
('offices', '$2y$10$joZ8DFdUSvotBXFFQhPQkO0uyZthbNB3.mVREmRbhIeGRNp7CngKe', 'Office Staff', 'offices', 'IT')
ON DUPLICATE KEY UPDATE `username` = `username`;

SET FOREIGN_KEY_CHECKS = 1;

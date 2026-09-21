-- Adds an optional server-managed profile photo path for staff accounts.
-- Safe to run repeatedly on MariaDB used by XAMPP.

ALTER TABLE `app_users`
  ADD COLUMN IF NOT EXISTS `profile_image` varchar(255) DEFAULT NULL AFTER `office_code`;

-- Add college/office assignment for `offices` role users (aligns with appointment office codes).
SET NAMES utf8mb4;

ALTER TABLE `app_users`
  ADD COLUMN `office_code` varchar(16) NOT NULL DEFAULT '' AFTER `role`;

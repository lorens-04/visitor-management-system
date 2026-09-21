-- Restrict new appointments and Office Personnel assignments to the approved
-- department-level catalog. Run once after phase1_workflow_migration.sql.
-- Historical appointments using old college codes are intentionally preserved.

SET NAMES utf8mb4;

INSERT INTO `office_availability_settings` (`office_code`)
VALUES ('IT'), ('IS'), ('CS'), ('DEANS'), ('TECH_SUPPORT')
ON DUPLICATE KEY UPDATE `office_code` = VALUES(`office_code`);

-- Reassign only the original seeded Office Personnel account. Other existing Office
-- accounts should be assigned deliberately by an administrator rather than guessed.
UPDATE `app_users`
SET `office_code` = 'IT'
WHERE `username` = 'offices'
  AND `role` = 'offices'
  AND `office_code` = 'CCI';

-- ============================================================
--  DisinfEntry - migration 008: compliance and pump-malfunction alerts
--
--  Two new notification types:
--    disinfection_incomplete  someone was GRANTED entry without finishing the spray sequence
--    pump_malfunction         the booth's pump data suggests the pump may be failing
--  and one new setting, pump_fault_streak: how many granted cycles in a row with fewer
--  than two spray bursts count as a possible pump fault (1-10, default 3).
--
--  Needed for those two alerts to be stored; everything else runs without it. Safe to
--  re-run, on MariaDB and MySQL 8 alike.
--    C:\xampp\mysql\bin\mysql.exe -u root disinfentry < database\migrations\008_compliance_pump_alerts.sql
--
--  A fresh install does not need this file - database/disinfentry.sql carries it.
-- ============================================================

USE `railway`;

-- Extend notifications.type, keeping every existing value. Skipped once the new values exist.
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications'
                AND COLUMN_NAME = 'type' AND COLUMN_TYPE LIKE '%pump_malfunction%');
SET @ddl := IF(@has = 0,
  'ALTER TABLE `notifications` MODIFY COLUMN `type` ENUM(''high_temperature'',''denied_entry'',''device_offline'',''low_disinfectant'',''system'',''disinfection_incomplete'',''pump_malfunction'') NOT NULL',
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- The streak setting; an existing value is left alone.
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('pump_fault_streak', '3');

-- ============================================================
--  DisinfEntry - migration 005: end other sessions on a password change
--
--  A stolen session cookie used to survive its owner changing their password.
--  users.password_changed_at records when a password last changed; every request
--  compares it with when its session started (see session_problem() in
--  includes/auth.php), so older sessions are signed out.
--
--  Optional: the app runs without it (a password change then simply does not end
--  other sessions). Safe to re-run, on MariaDB and MySQL 8 alike - the check uses
--  information_schema rather than "ADD COLUMN IF NOT EXISTS".
--    C:\xampp\mysql\bin\mysql.exe -u root disinfentry < database\migrations\005_password_changed_at.sql
--
--  A fresh install does not need this file - database/disinfentry.sql carries it.
-- ============================================================

USE `disinfentry`;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
                AND COLUMN_NAME = 'password_changed_at');
SET @ddl := IF(@has = 0,
  'ALTER TABLE `users` ADD COLUMN `password_changed_at` DATETIME DEFAULT NULL',
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

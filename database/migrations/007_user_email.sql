-- ============================================================
--  DisinfEntry - migration 007: a registered e-mail address per user
--
--  Temporary passwords generated on the User Management page are e-mailed to the
--  user's registered address, so every account needs one. The column allows NULL
--  because the accounts that already exist have none yet; the User Management
--  page requires an address for every new account and for any edit, and refuses
--  to reset a password for an account that still has none.
--
--  Safe to re-run, on MariaDB and MySQL 8 alike.
--    C:\xampp\mysql\bin\mysql.exe -u root disinfentry < database\migrations\007_user_email.sql
--
--  A fresh install does not need this file - database/disinfentry.sql carries it.
-- ============================================================

USE `railway`;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email');
SET @ddl := IF(@has = 0,
  'ALTER TABLE `users` ADD COLUMN `email` VARCHAR(190) DEFAULT NULL AFTER `contact_number`',
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- One account per address (NULLs are allowed more than once).
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'uq_users_email');
SET @ddl := IF(@has = 0,
  'ALTER TABLE `users` ADD UNIQUE KEY `uq_users_email` (`email`)',
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

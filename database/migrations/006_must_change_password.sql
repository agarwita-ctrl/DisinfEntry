-- ============================================================
--  DisinfEntry - migration 006: per-user "must change password"
--
--  The User Management page can create an account or reset a password with a
--  temporary one. users.must_change_password marks such an account: at sign-in
--  the user is held on My Profile until they choose their own password (the
--  same hold the shipped default passwords already get).
--
--  Needed for the User Management page to create users and reset passwords;
--  everything else runs without it. Safe to re-run, on MariaDB and MySQL 8
--  alike.
--    C:\xampp\mysql\bin\mysql.exe -u root disinfentry < database\migrations\006_must_change_password.sql
--
--  A fresh install does not need this file - database/disinfentry.sql carries it.
-- ============================================================

USE `railway`;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
                AND COLUMN_NAME = 'must_change_password');
SET @ddl := IF(@has = 0,
  'ALTER TABLE `users` ADD COLUMN `must_change_password` TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

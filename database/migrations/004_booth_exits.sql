-- ============================================================
--  DisinfEntry - migration 004: exit log
--
--  The booth's second ultrasonic sensor opens the door for someone leaving.
--  Each exit is now reported and stored here, one row per exit, so the
--  dashboard can show an exit id and when it happened.
--
--  Safe to run against an existing database, and safe to re-run.
--    C:\xampp\mysql\bin\mysql.exe -u root disinfentry < database\migrations\004_booth_exits.sql
--
--  A fresh install does not need this file - database/disinfentry.sql
--  already carries it.
-- ============================================================

USE `railway`;

CREATE TABLE IF NOT EXISTS `booth_exits` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `device_id`          VARCHAR(60)  NOT NULL,
  `boot_id`            VARCHAR(36)  NOT NULL,
  -- "<boot_id>:<uptime_ms>": the key a resent batch updates instead of duplicating.
  `exit_ref`           VARCHAR(80)  NOT NULL,
  `detected_uptime_ms` BIGINT UNSIGNED NOT NULL,
  `distance_cm`        DECIMAL(6,1) DEFAULT NULL,
  `exited_at`          DATETIME     NOT NULL,
  `created_at`         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_exit_ref` (`exit_ref`),
  KEY `idx_exit_time` (`exited_at`),
  KEY `idx_exit_device` (`device_id`,`exited_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

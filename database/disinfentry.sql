-- ============================================================
--  DisinfEntry - Smart Disinfection and Entry Monitoring System
--  Database schema + seed data
--  MySQL 8.0 / MariaDB 10.4+ (XAMPP)
-- ============================================================

CREATE DATABASE IF NOT EXISTS `disinfentry`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `disinfentry`;

-- ------------------------------------------------------------
-- Users
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `booth_cycle_states`;
DROP TABLE IF EXISTS `booth_pump_bursts`;
DROP TABLE IF EXISTS `booth_door_actions`;
DROP TABLE IF EXISTS `booth_cycles`;
DROP TABLE IF EXISTS `booth_distance_samples`;
DROP TABLE IF EXISTS `booth_temperature_samples`;
DROP TABLE IF EXISTS `booth_config`;
DROP TABLE IF EXISTS `entries`;
DROP TABLE IF EXISTS `password_resets`;
DROP TABLE IF EXISTS `devices`;
DROP TABLE IF EXISTS `settings`;
DROP TABLE IF EXISTS `users`;

CREATE TABLE `users` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id`    VARCHAR(30)  NOT NULL,
  `full_name`      VARCHAR(120) NOT NULL,
  `contact_number` VARCHAR(30)  DEFAULT NULL,
  `username`       VARCHAR(60)  NOT NULL,
  `password_hash`  VARCHAR(255) NOT NULL,
  `role`           ENUM('administrator','farm_manager') NOT NULL DEFAULT 'farm_manager',
  `status`         ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `last_login`     DATETIME     DEFAULT NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  UNIQUE KEY `uq_users_employee` (`employee_id`),
  KEY `ix_users_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Password reset tokens (Forgot Password)
-- ------------------------------------------------------------
CREATE TABLE `password_resets` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `token_hash` CHAR(64)     NOT NULL,
  `expires_at` DATETIME     NOT NULL,
  `used_at`    DATETIME     DEFAULT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_pr_token` (`token_hash`),
  CONSTRAINT `fk_pr_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Devices (ESP32 booths) - heartbeat / online detection
-- ------------------------------------------------------------
CREATE TABLE `devices` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `device_id`          VARCHAR(60)  NOT NULL,
  `device_name`        VARCHAR(120) NOT NULL DEFAULT 'Disinfection Booth',
  `location`           VARCHAR(120) DEFAULT NULL,
  `firmware`           VARCHAR(30)  DEFAULT NULL,
  `boot_id`            CHAR(36)     DEFAULT NULL,   -- new on every booth reset
  `state`              VARCHAR(24)  DEFAULT NULL,   -- the sketch's state machine
  `uptime_ms`          BIGINT UNSIGNED DEFAULT NULL,
  `mlx_ok`             TINYINT(1)   DEFAULT NULL,   -- thermometer found at boot
  `ip_address`         VARCHAR(45)  DEFAULT NULL,
  -- Percent. NULL means no sensor has reported one, which is the case for the
  -- current firmware: it has a ranger and a thermometer and measures no tank
  -- level. NULL keeps "unknown" distinct from "full" so the console can say so
  -- instead of displaying a number nothing measured.
  `disinfectant_level` TINYINT UNSIGNED DEFAULT NULL,
  `last_seen`          DATETIME     DEFAULT NULL,
  `last_cycle_at`      DATETIME     DEFAULT NULL,
  `created_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_device_id` (`device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Entries - records pushed by the ESP32 booth
-- ------------------------------------------------------------
CREATE TABLE `entries` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`             INT UNSIGNED DEFAULT NULL,
  `employee_id`         VARCHAR(30)  DEFAULT NULL,
  `person_name`         VARCHAR(120) NOT NULL DEFAULT 'Unidentified',
  `temperature`         DECIMAL(4,1) NOT NULL,
  `entry_date`          DATE         NOT NULL,
  `entry_time`          TIME         NOT NULL,
  `disinfection_status` ENUM('completed','incomplete','skipped') NOT NULL DEFAULT 'completed',
  `misting_status`      ENUM('on','off') NOT NULL DEFAULT 'off',
  `access_status`       ENUM('granted','denied') NOT NULL DEFAULT 'granted',
  `remarks`             VARCHAR(255) DEFAULT NULL,
  `device_id`           VARCHAR(60)  DEFAULT NULL,
  `distance_cm`         DECIMAL(6,1) DEFAULT NULL,
  `motion_detected`     TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  -- Reports, the dashboard and the entry log filter a date range and an access
  -- status together. A composite serves both that pair and date alone (its
  -- leading prefix); separate single-column indexes let the optimiser pick the
  -- two-value access index and scan half the table.
  KEY `ix_entries_date_access` (`entry_date`, `access_status`),
  KEY `ix_entries_access` (`access_status`),
  KEY `ix_entries_name` (`person_name`),
  KEY `ix_entries_created` (`created_at`),
  CONSTRAINT `fk_entries_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- booth_config - the sketch's compile-time constants
--
-- Mirrors buildConfigJson() in the firmware one-for-one, so an operator can
-- read the wiring and timings actually flashed to the booth without opening
-- the Arduino IDE. Rewritten whenever the booth reports a change.
-- ------------------------------------------------------------
CREATE TABLE `booth_config` (
  `device_id`                VARCHAR(60)  NOT NULL,
  `trig_pin`                 SMALLINT     DEFAULT NULL,
  `echo_pin`                 SMALLINT     DEFAULT NULL,
  `relay_pin`                SMALLINT     DEFAULT NULL,
  `servo_pin`                SMALLINT     DEFAULT NULL,
  `i2c_sda_pin`              SMALLINT     DEFAULT NULL,
  `i2c_scl_pin`              SMALLINT     DEFAULT NULL,
  `relay_active_low`         TINYINT(1)   DEFAULT NULL,
  `door_closed_angle`        SMALLINT     DEFAULT NULL,
  `door_open_angle`          SMALLINT     DEFAULT NULL,
  `servo_min_us`             INT UNSIGNED DEFAULT NULL,
  `servo_max_us`             INT UNSIGNED DEFAULT NULL,
  `detection_distance_cm`    DECIMAL(6,1) DEFAULT NULL,
  `ultrasonic_timeout_us`    INT UNSIGNED DEFAULT NULL,
  `fever_threshold_c`        DECIMAL(5,2) DEFAULT NULL,
  `presence_confirm_time_ms` INT UNSIGNED DEFAULT NULL,
  `presence_glitch_grace_ms` INT UNSIGNED DEFAULT NULL,
  `sample_interval_ms`       INT UNSIGNED DEFAULT NULL,
  `print_interval_ms`        INT UNSIGNED DEFAULT NULL,
  `pump_on_time_ms`          INT UNSIGNED DEFAULT NULL,
  `pump_off_time_ms`         INT UNSIGNED DEFAULT NULL,
  `door_open_time_ms`        INT UNSIGNED DEFAULT NULL,
  `temp_print_interval_ms`   INT UNSIGNED DEFAULT NULL,
  `reported_at`              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- booth_cycles - one detection-to-reset pass through the booth
--
-- `cycle_ref` ("<boot_id>:<detected_uptime_ms>") is the firmware's idempotency
-- key. It is UNIQUE precisely so a resent batch - which the booth does after
-- any failed POST - updates the same row instead of recording a second person.
--
-- `entry_id` links to the entries row published for this cycle. It stays NULL
-- for cycles that never produced a temperature reading (aborted, or denied
-- because the MLX90614 failed), which cannot become entries.
-- ------------------------------------------------------------
CREATE TABLE `booth_cycles` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `device_id`           VARCHAR(60)  NOT NULL,
  `boot_id`             CHAR(36)     NOT NULL,
  `cycle_ref`           VARCHAR(80)  NOT NULL,
  `entry_id`            INT UNSIGNED DEFAULT NULL,
  `detected_uptime_ms`  BIGINT UNSIGNED NOT NULL,
  `duration_ms`         BIGINT UNSIGNED DEFAULT NULL,
  `trigger_distance_cm` DECIMAL(6,1) DEFAULT NULL,
  `screening_temp_c`    DECIMAL(5,2) DEFAULT NULL,
  `ambient_temp_c`      DECIMAL(5,2) DEFAULT NULL,
  `threshold_c`         DECIMAL(5,2) DEFAULT NULL,
  `outcome`             ENUM('granted','denied','aborted','in_progress') NOT NULL DEFAULT 'in_progress',
  `pump_bursts`         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `pump_total_ms`       INT UNSIGNED NOT NULL DEFAULT 0,
  `door_opened`         TINYINT(1)   NOT NULL DEFAULT 0,
  `remarks`             VARCHAR(255) DEFAULT NULL,
  `detected_at`         DATETIME     NOT NULL,
  `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cycle_ref` (`cycle_ref`),
  KEY `ix_cycles_device` (`device_id`),
  KEY `ix_cycles_detected` (`detected_at`),
  KEY `ix_cycles_outcome` (`outcome`),
  CONSTRAINT `fk_cycles_entry` FOREIGN KEY (`entry_id`) REFERENCES `entries`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `booth_cycle_states` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cycle_id`   INT UNSIGNED NOT NULL,
  `seq`        TINYINT UNSIGNED NOT NULL,
  `from_state` VARCHAR(24)  NOT NULL,
  `to_state`   VARCHAR(24)  NOT NULL,
  `uptime_ms`  BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cycle_seq` (`cycle_id`,`seq`),
  CONSTRAINT `fk_states_cycle` FOREIGN KEY (`cycle_id`) REFERENCES `booth_cycles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `booth_pump_bursts` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cycle_id`    INT UNSIGNED NOT NULL,
  `burst_no`    TINYINT UNSIGNED NOT NULL,
  `duration_ms` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_burst` (`cycle_id`,`burst_no`),
  CONSTRAINT `fk_bursts_cycle` FOREIGN KEY (`cycle_id`) REFERENCES `booth_cycles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `booth_door_actions` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cycle_id`  INT UNSIGNED NOT NULL,
  `action`    ENUM('open','close') NOT NULL,
  `angle`     SMALLINT     DEFAULT NULL,
  `uptime_ms` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_door_action` (`cycle_id`,`action`),
  CONSTRAINT `fk_door_cycle` FOREIGN KEY (`cycle_id`) REFERENCES `booth_cycles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Raw sensor streams
--
-- High volume and low value once they age: the booth pushes a distance reading
-- a second and a temperature reading every two. They are pruned to
-- `telemetry_retention_hours` on every sync, so nothing here is the system of
-- record - booth_cycles and entries are.
--
-- distance_cm is NULL for an ultrasonic timeout and the temperatures are NULL
-- for a failed MLX read; neither is ever stored as 0.
-- ------------------------------------------------------------
CREATE TABLE `booth_distance_samples` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `device_id`   VARCHAR(60)  NOT NULL,
  `boot_id`     CHAR(36)     NOT NULL,
  `distance_cm` DECIMAL(6,1) DEFAULT NULL,
  `in_range`    TINYINT(1)   NOT NULL DEFAULT 0,
  `uptime_ms`   BIGINT UNSIGNED NOT NULL,
  `recorded_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dist_sample` (`boot_id`,`uptime_ms`),
  KEY `ix_dist_recorded` (`recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `booth_temperature_samples` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `device_id`      VARCHAR(60)  NOT NULL,
  `boot_id`        CHAR(36)     NOT NULL,
  `object_temp_c`  DECIMAL(5,2) DEFAULT NULL,
  `ambient_temp_c` DECIMAL(5,2) DEFAULT NULL,
  `is_screening`   TINYINT(1)   NOT NULL DEFAULT 0,
  `uptime_ms`      BIGINT UNSIGNED NOT NULL,
  `recorded_at`    DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_temp_sample` (`boot_id`,`uptime_ms`),
  KEY `ix_temp_recorded` (`recorded_at`),
  KEY `ix_temp_screening` (`is_screening`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Notifications
-- ------------------------------------------------------------
CREATE TABLE `notifications` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type`       ENUM('high_temperature','denied_entry','device_offline','low_disinfectant','system') NOT NULL,
  `severity`   ENUM('info','warning','danger') NOT NULL DEFAULT 'info',
  `title`      VARCHAR(120) NOT NULL,
  `message`    VARCHAR(255) NOT NULL,
  `entry_id`   INT UNSIGNED DEFAULT NULL,
  `is_read`    TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_notif_read` (`is_read`),
  KEY `ix_notif_created` (`created_at`),
  -- SET NULL, not CASCADE: deleting an entry must not destroy the alert that
  -- was raised for it. The alert text stands on its own; only the link to the
  -- removed row is dropped. booth_cycles.entry_id behaves the same way.
  CONSTRAINT `fk_notif_entry` FOREIGN KEY (`entry_id`) REFERENCES `entries`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Audit logs
-- ------------------------------------------------------------
CREATE TABLE `audit_logs` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED DEFAULT NULL,
  `username`   VARCHAR(60)  DEFAULT NULL,
  `module`     VARCHAR(50)  NOT NULL DEFAULT 'System',
  `activity`   VARCHAR(255) NOT NULL,
  `ip_address` VARCHAR(45)  DEFAULT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_audit_created` (`created_at`),
  KEY `ix_audit_user` (`user_id`),
  -- The login throttle counts recent failed sign-ins by username or IP on every
  -- attempt, on the unauthenticated path. created_at leads because the 15-minute
  -- window is the selective predicate.
  KEY `ix_audit_throttle` (`created_at`, `username`, `ip_address`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Settings (key/value)
-- ------------------------------------------------------------
CREATE TABLE `settings` (
  `setting_key`   VARCHAR(60)  NOT NULL,
  `setting_value` TEXT         DEFAULT NULL,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  Seed data
-- ============================================================

INSERT INTO `settings` (`setting_key`,`setting_value`) VALUES
  ('farm_name',             'Sunrise Poultry Farm'),
  ('system_name',           'DisinfEntry'),
  ('temperature_threshold', '37.8'),
  ('disinfection_duration', '8'),
  ('refresh_interval',      '3'),
  ('timezone',              'Asia/Manila'),
  ('logo',                  ''),
  ('device_offline_after',  '60'),
  ('low_disinfectant_at',   '20'),
  ('api_key',               'DISINF-ESP32-2024-CHANGE-ME'),
  -- The sequence the booth adopts over the air, in the sketch's own units.
  -- The fever threshold is not repeated here: it is temperature_threshold above.
  ('detection_distance_cm',     '50'),
  ('presence_confirm_ms',       '5000'),
  ('pump_on_ms',                '2000'),
  ('pump_off_ms',               '2000'),
  ('door_open_ms',              '5000'),
  ('telemetry_retention_hours', '24');

-- Default accounts.
--   admin   / Admin@123
--   manager / Manager@123
INSERT INTO `users`
  (`employee_id`,`full_name`,`contact_number`,`username`,`password_hash`,`role`,`status`) VALUES
  ('EMP-0001','System Administrator','09171234567','admin',
   '$2y$12$y41UboqQkf3n0iXDeGbLMeu5ln4okjypRo0ITH6UjyDalV3BzRjva','administrator','active'),
  ('EMP-0002','Farm Manager','09179876543','manager',
   '$2y$12$sMjKUZyUz7HJEDUCvWiO5.HJBCTxHGHSWYf0Od.8k50F/eWv4Vkza','farm_manager','active'),
  ('EMP-0003','Juan Dela Cruz','09181112222','jdelacruz',
   '$2y$12$sMjKUZyUz7HJEDUCvWiO5.HJBCTxHGHSWYf0Od.8k50F/eWv4Vkza','farm_manager','active');

-- Registered but never heard from: no last_seen, and no disinfectant level,
-- because nothing has reported either yet. The console shows it Offline until
-- the booth's first sync, which is the truth.
INSERT INTO `devices` (`device_id`,`device_name`,`location`,`firmware`) VALUES
  ('ESP32-BOOTH-01','Main Gate Booth','Farm Entrance','1.0.0');

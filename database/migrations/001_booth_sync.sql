-- ============================================================
--  DisinfEntry - migration 001: booth sync
--
--  Adds the tables api/booth_sync.php needs to record what the ESP32
--  firmware (esp32/DisinfEntry_Booth, 1.1.0+) actually reports: whole
--  disinfection cycles, their state machine, and the raw sensor streams.
--
--  Safe to run against an existing database, and safe to re-run.
--    C:\xampp\mysql\bin\mysql.exe -u root disinfentry < database\migrations\001_booth_sync.sql
--
--  A fresh install does not need this file - database/disinfentry.sql
--  already carries everything below.
-- ============================================================

USE `disinfentry`;

-- ------------------------------------------------------------
-- Devices: what the booth reports about itself on every sync
-- ------------------------------------------------------------
ALTER TABLE `devices`
  ADD COLUMN IF NOT EXISTS `boot_id`       CHAR(36)     DEFAULT NULL AFTER `firmware`,
  ADD COLUMN IF NOT EXISTS `state`         VARCHAR(24)  DEFAULT NULL AFTER `boot_id`,
  ADD COLUMN IF NOT EXISTS `uptime_ms`     BIGINT UNSIGNED DEFAULT NULL AFTER `state`,
  ADD COLUMN IF NOT EXISTS `mlx_ok`        TINYINT(1)   DEFAULT NULL AFTER `uptime_ms`,
  ADD COLUMN IF NOT EXISTS `last_cycle_at` DATETIME     DEFAULT NULL AFTER `last_seen`;

-- ------------------------------------------------------------
-- booth_config - the sketch's compile-time constants
--
-- Mirrors buildConfigJson() in the firmware one-for-one, so an operator
-- can read the wiring and timings actually flashed to the booth without
-- opening the Arduino IDE. Rewritten whenever the booth reports a change.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `booth_config` (
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
-- `cycle_ref` ("<boot_id>:<detected_uptime_ms>") is the firmware's
-- idempotency key. It is UNIQUE precisely so a resent batch - which the
-- booth does after any failed POST - updates the same row instead of
-- recording a second person.
--
-- `entry_id` links to the entries row published for this cycle. It stays
-- NULL for cycles that never produced a temperature reading (aborted, or
-- denied because the MLX90614 failed), which cannot become entries.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `booth_cycles` (
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

-- ------------------------------------------------------------
-- booth_cycle_states - the state machine transitions of one cycle
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `booth_cycle_states` (
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

-- ------------------------------------------------------------
-- booth_pump_bursts - the two spray bursts of one cycle
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `booth_pump_bursts` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cycle_id`    INT UNSIGNED NOT NULL,
  `burst_no`    TINYINT UNSIGNED NOT NULL,
  `duration_ms` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_burst` (`cycle_id`,`burst_no`),
  CONSTRAINT `fk_bursts_cycle` FOREIGN KEY (`cycle_id`) REFERENCES `booth_cycles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- booth_door_actions - servo open/close of one cycle
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `booth_door_actions` (
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
-- High volume and low value once they age: the booth pushes a distance
-- reading a second and a temperature reading every two. They are pruned
-- to `telemetry_retention_hours` on every sync, so nothing here is the
-- system of record - booth_cycles and entries are.
--
-- distance_cm is NULL for an ultrasonic timeout and the temperatures are
-- NULL for a failed MLX read; neither is ever stored as 0.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `booth_distance_samples` (
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

CREATE TABLE IF NOT EXISTS `booth_temperature_samples` (
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
-- Settings the booth adopts over the air
--
-- These are the six values adoptDirectives() accepts, in the firmware's
-- own units, so System Settings can retune the sequence without a
-- reflash. Stored in milliseconds to match the sketch exactly.
--
-- The fever threshold is NOT here: it stays `temperature_threshold`, the
-- one the whole application already screens against.
-- ------------------------------------------------------------
INSERT IGNORE INTO `settings` (`setting_key`,`setting_value`) VALUES
  ('detection_distance_cm',      '50'),
  ('presence_confirm_ms',        '5000'),
  ('pump_on_ms',                 '2000'),
  ('pump_off_ms',                '2000'),
  ('door_open_ms',               '5000'),
  ('telemetry_retention_hours',  '24');

-- ============================================================
--  DisinfEntry - database for hosted deployments (Railway, InfinityFree, any
--  host that gives you a pre-created, empty database).
--
--  Import this into that database. It has no CREATE DATABASE / USE statements on
--  purpose: hosted databases come with their own name.
--
--  Schema = database/disinfentry.sql with migrations 001-003 already applied,
--  tables created in foreign-key order. Seed = default settings, three default
--  accounts (admin / manager / jdelacruz) and one booth.
--
--  After the first sign-in: change the default passwords (the app forces this) and
--  regenerate the booth API key in System Settings - the key seeded below is the
--  public default.
-- ============================================================
SET NAMES utf8mb4;

-- Drop in reverse dependency order, create in dependency order.
DROP TABLE IF EXISTS `booth_pump_bursts`;
DROP TABLE IF EXISTS `booth_door_actions`;
DROP TABLE IF EXISTS `booth_cycle_states`;
DROP TABLE IF EXISTS `booth_cycles`;
DROP TABLE IF EXISTS `password_resets`;
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `entries`;
DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `settings`;
DROP TABLE IF EXISTS `devices`;
DROP TABLE IF EXISTS `booth_temperature_samples`;
DROP TABLE IF EXISTS `booth_distance_samples`;
DROP TABLE IF EXISTS `booth_config`;

-- ----------------------------------------------------
-- booth_config
-- ----------------------------------------------------
CREATE TABLE `booth_config` (
  `device_id` varchar(60) NOT NULL,
  `trig_pin` smallint(6) DEFAULT NULL,
  `echo_pin` smallint(6) DEFAULT NULL,
  `relay_pin` smallint(6) DEFAULT NULL,
  `servo_pin` smallint(6) DEFAULT NULL,
  `i2c_sda_pin` smallint(6) DEFAULT NULL,
  `i2c_scl_pin` smallint(6) DEFAULT NULL,
  `relay_active_low` tinyint(1) DEFAULT NULL,
  `door_closed_angle` smallint(6) DEFAULT NULL,
  `door_open_angle` smallint(6) DEFAULT NULL,
  `servo_min_us` int(10) unsigned DEFAULT NULL,
  `servo_max_us` int(10) unsigned DEFAULT NULL,
  `detection_distance_cm` decimal(6,1) DEFAULT NULL,
  `ultrasonic_timeout_us` int(10) unsigned DEFAULT NULL,
  `fever_threshold_c` decimal(5,2) DEFAULT NULL,
  `presence_confirm_time_ms` int(10) unsigned DEFAULT NULL,
  `presence_glitch_grace_ms` int(10) unsigned DEFAULT NULL,
  `sample_interval_ms` int(10) unsigned DEFAULT NULL,
  `print_interval_ms` int(10) unsigned DEFAULT NULL,
  `pump_on_time_ms` int(10) unsigned DEFAULT NULL,
  `pump_off_time_ms` int(10) unsigned DEFAULT NULL,
  `door_open_time_ms` int(10) unsigned DEFAULT NULL,
  `temp_print_interval_ms` int(10) unsigned DEFAULT NULL,
  `reported_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------
-- booth_distance_samples
-- ----------------------------------------------------
CREATE TABLE `booth_distance_samples` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `device_id` varchar(60) NOT NULL,
  `boot_id` char(36) NOT NULL,
  `distance_cm` decimal(6,1) DEFAULT NULL,
  `in_range` tinyint(1) NOT NULL DEFAULT 0,
  `uptime_ms` bigint(20) unsigned NOT NULL,
  `recorded_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dist_sample` (`boot_id`,`uptime_ms`),
  KEY `ix_dist_recorded` (`recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------
-- booth_temperature_samples
-- ----------------------------------------------------
CREATE TABLE `booth_temperature_samples` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `device_id` varchar(60) NOT NULL,
  `boot_id` char(36) NOT NULL,
  `object_temp_c` decimal(5,2) DEFAULT NULL,
  `ambient_temp_c` decimal(5,2) DEFAULT NULL,
  `is_screening` tinyint(1) NOT NULL DEFAULT 0,
  `uptime_ms` bigint(20) unsigned NOT NULL,
  `recorded_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_temp_sample` (`boot_id`,`uptime_ms`),
  KEY `ix_temp_recorded` (`recorded_at`),
  KEY `ix_temp_screening` (`is_screening`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------
-- devices
-- ----------------------------------------------------
CREATE TABLE `devices` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `device_id` varchar(60) NOT NULL,
  `device_name` varchar(120) NOT NULL DEFAULT 'Disinfection Booth',
  `location` varchar(120) DEFAULT NULL,
  `firmware` varchar(30) DEFAULT NULL,
  `boot_id` char(36) DEFAULT NULL,
  `state` varchar(24) DEFAULT NULL,
  `uptime_ms` bigint(20) unsigned DEFAULT NULL,
  `mlx_ok` tinyint(1) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `disinfectant_level` tinyint(3) unsigned DEFAULT NULL,
  `last_seen` datetime DEFAULT NULL,
  `last_cycle_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_device_id` (`device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------
-- settings
-- ----------------------------------------------------
CREATE TABLE `settings` (
  `setting_key` varchar(60) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------
-- users
-- ----------------------------------------------------
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` varchar(30) NOT NULL,
  `full_name` varchar(120) NOT NULL,
  `contact_number` varchar(30) DEFAULT NULL,
  `username` varchar(60) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('administrator','farm_manager') NOT NULL DEFAULT 'farm_manager',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `last_login` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  UNIQUE KEY `uq_users_employee` (`employee_id`),
  KEY `ix_users_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------
-- audit_logs
-- ----------------------------------------------------
CREATE TABLE `audit_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `username` varchar(60) DEFAULT NULL,
  `module` varchar(50) NOT NULL DEFAULT 'System',
  `activity` varchar(255) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_audit_created` (`created_at`),
  KEY `ix_audit_user` (`user_id`),
  KEY `ix_audit_throttle` (`created_at`,`username`,`ip_address`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------
-- entries
-- ----------------------------------------------------
CREATE TABLE `entries` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `employee_id` varchar(30) DEFAULT NULL,
  `person_name` varchar(120) NOT NULL DEFAULT 'Unidentified',
  `temperature` decimal(4,1) NOT NULL,
  `entry_date` date NOT NULL,
  `entry_time` time NOT NULL,
  `disinfection_status` enum('completed','incomplete','skipped') NOT NULL DEFAULT 'completed',
  `misting_status` enum('on','off') NOT NULL DEFAULT 'off',
  `access_status` enum('granted','denied') NOT NULL DEFAULT 'granted',
  `remarks` varchar(255) DEFAULT NULL,
  `device_id` varchar(60) DEFAULT NULL,
  `distance_cm` decimal(6,1) DEFAULT NULL,
  `motion_detected` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_entries_date_access` (`entry_date`,`access_status`),
  KEY `ix_entries_access` (`access_status`),
  KEY `ix_entries_name` (`person_name`),
  KEY `ix_entries_created` (`created_at`),
  KEY `fk_entries_user` (`user_id`),
  CONSTRAINT `fk_entries_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------
-- notifications
-- ----------------------------------------------------
CREATE TABLE `notifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `type` enum('high_temperature','denied_entry','device_offline','low_disinfectant','system') NOT NULL,
  `severity` enum('info','warning','danger') NOT NULL DEFAULT 'info',
  `title` varchar(120) NOT NULL,
  `message` varchar(255) NOT NULL,
  `entry_id` int(10) unsigned DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_notif_read` (`is_read`),
  KEY `ix_notif_created` (`created_at`),
  KEY `fk_notif_entry` (`entry_id`),
  CONSTRAINT `fk_notif_entry` FOREIGN KEY (`entry_id`) REFERENCES `entries` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------
-- password_resets
-- ----------------------------------------------------
CREATE TABLE `password_resets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_pr_token` (`token_hash`),
  KEY `fk_pr_user` (`user_id`),
  CONSTRAINT `fk_pr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------
-- booth_cycles
-- ----------------------------------------------------
CREATE TABLE `booth_cycles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `device_id` varchar(60) NOT NULL,
  `boot_id` char(36) NOT NULL,
  `cycle_ref` varchar(80) NOT NULL,
  `entry_id` int(10) unsigned DEFAULT NULL,
  `detected_uptime_ms` bigint(20) unsigned NOT NULL,
  `duration_ms` bigint(20) unsigned DEFAULT NULL,
  `trigger_distance_cm` decimal(6,1) DEFAULT NULL,
  `screening_temp_c` decimal(5,2) DEFAULT NULL,
  `ambient_temp_c` decimal(5,2) DEFAULT NULL,
  `threshold_c` decimal(5,2) DEFAULT NULL,
  `outcome` enum('granted','denied','aborted','in_progress') NOT NULL DEFAULT 'in_progress',
  `pump_bursts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `pump_total_ms` int(10) unsigned NOT NULL DEFAULT 0,
  `door_opened` tinyint(1) NOT NULL DEFAULT 0,
  `remarks` varchar(255) DEFAULT NULL,
  `detected_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cycle_ref` (`cycle_ref`),
  KEY `ix_cycles_device` (`device_id`),
  KEY `ix_cycles_detected` (`detected_at`),
  KEY `ix_cycles_outcome` (`outcome`),
  KEY `fk_cycles_entry` (`entry_id`),
  CONSTRAINT `fk_cycles_entry` FOREIGN KEY (`entry_id`) REFERENCES `entries` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------
-- booth_cycle_states
-- ----------------------------------------------------
CREATE TABLE `booth_cycle_states` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `cycle_id` int(10) unsigned NOT NULL,
  `seq` tinyint(3) unsigned NOT NULL,
  `from_state` varchar(24) NOT NULL,
  `to_state` varchar(24) NOT NULL,
  `uptime_ms` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cycle_seq` (`cycle_id`,`seq`),
  CONSTRAINT `fk_states_cycle` FOREIGN KEY (`cycle_id`) REFERENCES `booth_cycles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------
-- booth_door_actions
-- ----------------------------------------------------
CREATE TABLE `booth_door_actions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `cycle_id` int(10) unsigned NOT NULL,
  `action` enum('open','close') NOT NULL,
  `angle` smallint(6) DEFAULT NULL,
  `uptime_ms` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_door_action` (`cycle_id`,`action`),
  CONSTRAINT `fk_door_cycle` FOREIGN KEY (`cycle_id`) REFERENCES `booth_cycles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------
-- booth_pump_bursts
-- ----------------------------------------------------
CREATE TABLE `booth_pump_bursts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `cycle_id` int(10) unsigned NOT NULL,
  `burst_no` tinyint(3) unsigned NOT NULL,
  `duration_ms` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_burst` (`cycle_id`,`burst_no`),
  CONSTRAINT `fk_bursts_cycle` FOREIGN KEY (`cycle_id`) REFERENCES `booth_cycles` (`id`) ON DELETE CASCADE
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

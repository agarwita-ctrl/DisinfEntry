-- ============================================================
--  DisinfEntry - migration 002: schema tuning
--
--  Two corrections found by auditing the schema against how the
--  application actually queries and writes it.
--
--  Safe to run against an existing database, and safe to re-run. No data
--  is deleted or rewritten.
--    C:\xampp\mysql\bin\mysql.exe -u root disinfentry < database\migrations\002_schema_tuning.sql
--
--  A fresh install does not need this file - database/disinfentry.sql
--  already carries everything below.
-- ============================================================

USE `disinfentry`;

-- ------------------------------------------------------------
-- 1. entries: index the pair that is actually filtered together
--
-- Reports, the dashboard and the entry log all filter a date range and an
-- access status in the same query. With separate single-column indexes the
-- optimiser picks ix_entries_access - two values across the whole table, so
-- it scans about half of it.
--
-- Measured on 21,900 entries (a year at 60/day):
--   date range + access   35 ms  ->  5 ms
--   exact date            unchanged (the composite's prefix covers it)
--
-- The composite replaces ix_entries_date rather than joining it: a leading
-- prefix serves every query the old index served, so keeping both would only
-- cost write throughput and space.
-- ------------------------------------------------------------
ALTER TABLE `entries`
  DROP KEY IF EXISTS `ix_entries_date`,
  ADD KEY IF NOT EXISTS `ix_entries_date_access` (`entry_date`, `access_status`);

-- ------------------------------------------------------------
-- 2. devices: let "never reported" be representable
--
-- No sensor on the booth measures disinfectant level - the sketch has an
-- ultrasonic ranger and a thermometer, and reports neither a tank level nor
-- anything to derive one from. The column was NOT NULL DEFAULT 100, so a
-- booth that has never reported one was indistinguishable from a full tank,
-- and the console displayed a number nothing had measured.
--
-- Worse, device_states() casts the value to int for its low-level test, so a
-- missing reading became 0 and raised a false "empty tank" alarm.
--
-- NULL now means "not reported": the console says so instead of inventing a
-- percentage, and the low-disinfectant alert stays silent until something
-- actually measures. Existing values are left untouched - an installation
-- that fed levels through the legacy heartbeat keeps them.
-- ------------------------------------------------------------
ALTER TABLE `devices`
  MODIFY COLUMN `disinfectant_level` TINYINT UNSIGNED DEFAULT NULL
    COMMENT 'percent; NULL = no sensor has reported one';

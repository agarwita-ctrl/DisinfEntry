-- ============================================================
--  DisinfEntry - migration 003: audit hardening
--
--  Two corrections from the security and correctness audit.
--
--  Safe to run against an existing database, and safe to re-run. No data
--  is deleted or rewritten.
--    C:\xampp\mysql\bin\mysql.exe -u root disinfentry < database\migrations\003_audit_hardening.sql
--
--  A fresh install does not need this file - database/disinfentry.sql
--  already carries everything below.
-- ============================================================

USE `disinfentry`;

-- ------------------------------------------------------------
-- 1. notifications: stop an entry deletion destroying its alerts
--
-- fk_notif_entry was ON DELETE CASCADE, so removing one entry silently removed
-- the high-temperature or denied-entry alert raised for it - the record of the
-- event disappearing along with the event. booth_cycles.entry_id already uses
-- SET NULL for exactly this reason, so the two related rows were treated
-- inconsistently.
--
-- SET NULL keeps the alert and its text; only the link back to the deleted row
-- is dropped. api/notifications.php already renders a NULL entry_id (the
-- LEFT JOIN simply contributes no person or temperature), so no code changes
-- accompany this.
-- ------------------------------------------------------------
ALTER TABLE `notifications` DROP FOREIGN KEY `fk_notif_entry`;

ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notif_entry` FOREIGN KEY (`entry_id`)
      REFERENCES `entries`(`id`) ON DELETE SET NULL;

-- ------------------------------------------------------------
-- 2. audit_logs: index what the login throttle now reads
--
-- includes/auth.php counts recent failed sign-ins for a username OR an IP on
-- every login attempt, which replaced a per-session counter an attacker could
-- reset by discarding a cookie. That query runs on the unauthenticated path, so
-- it must not turn into a scan as the trail grows.
--
-- created_at leads because it is the selective predicate: the window is 15
-- minutes of what may eventually be a very long table.
-- ------------------------------------------------------------
ALTER TABLE `audit_logs`
  ADD KEY IF NOT EXISTS `ix_audit_throttle` (`created_at`, `username`, `ip_address`);

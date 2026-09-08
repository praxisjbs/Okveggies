-- =============================================================================
-- 025_kitchen_run_staff_note.sql
-- OK Veggies. One internal note on a Kitchen Run, for the team only.
--
-- The M7 review (docs/M7_REVIEW.md, item 22) found a correctness bug rather
-- than a gap: kitchen_run_requests.admin_note is described in the code as an
-- internal note, and it is shown to the customer on their own Kitchen Run
-- screen and emailed to them verbatim when we decline a list. A colleague
-- writing something frank in that box was publishing it.
--
-- admin_note keeps its job, which is a note written FOR the customer, and its
-- label on the admin screen now says so. This column is the other kind: the
-- frank one, the team's own. It is the same shape and the same rule as
-- orders.staff_note in 020_order_staff_note.sql, and it is deliberately a
-- column on the request rather than a row in kitchen_run_status_history,
-- because that history is read back to the customer on their own screen.
--
-- Idempotent and MySQL 8 compatible: MySQL 8 has no ADD COLUMN IF NOT EXISTS
-- (that is MariaDB, and it fails the deploy with a syntax error), so the column
-- is guarded against information_schema.COLUMNS and the ALTER runs through a
-- prepared statement, the way 011, 013 and 020 do. DDL commits implicitly, so
-- this file keeps no explicit transaction of its own.
-- See docs/PRD.md Section 8.
-- =============================================================================

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'kitchen_run_requests'
     AND COLUMN_NAME  = 'staff_note'
);
SET @ddl := IF(
  @col_exists = 0,
  'ALTER TABLE `kitchen_run_requests` ADD COLUMN `staff_note` VARCHAR(2000) NULL AFTER `admin_note`',
  'DO 0'
);
PREPARE okv_025_note FROM @ddl;
EXECUTE okv_025_note;
DEALLOCATE PREPARE okv_025_note;

-- Verification:
--   SELECT COLUMN_NAME, IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH
--     FROM information_schema.COLUMNS
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kitchen_run_requests'
--      AND COLUMN_NAME = 'staff_note';
--   Expect one row, nullable, 2000.
--
--   SELECT COUNT(*) AS templates_reading_the_internal_note
--     FROM notification_templates WHERE body_template LIKE '%staff_note%';
--   Expect 0: the internal note never reaches an email.

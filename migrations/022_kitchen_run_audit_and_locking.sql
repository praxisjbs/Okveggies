-- =============================================================================
-- 022_kitchen_run_audit_and_locking.sql
-- OK Veggies. Two columns a Kitchen Run cannot work without.
--
--   original_submission_json
--     What the customer actually sent, kept verbatim. A Kitchen Run is priced
--     by hand and approved by a person, so a later disagreement about what was
--     asked for is settled by the record rather than by memory.
--
--   state_version
--     Makes quoting, approving and converting compare-and-swap operations. Two
--     colleagues working the same request in two tabs is normal, and without
--     this the second save silently overwrites the first.
--
-- Idempotent and MySQL 8 compatible: MySQL 8 has no ADD COLUMN IF NOT EXISTS,
-- so each column is guarded against information_schema.COLUMNS and applied
-- through a prepared statement. There is deliberately no transaction here:
-- MySQL commits implicitly on DDL, so START TRANSACTION around an ALTER reads
-- like a safety net that does not exist. 020_order_staff_note.sql says the same.
-- =============================================================================

SET @db_name = DATABASE();
SET @has_submission = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'kitchen_run_requests'
    AND COLUMN_NAME = 'original_submission_json');
SET @sql = IF(@has_submission = 0,
  'ALTER TABLE kitchen_run_requests ADD COLUMN original_submission_json JSON NULL AFTER attachment_url',
  'SELECT 1');
PREPARE statement FROM @sql; EXECUTE statement; DEALLOCATE PREPARE statement;

SET @has_version = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'kitchen_run_requests'
    AND COLUMN_NAME = 'state_version');
SET @sql = IF(@has_version = 0,
  'ALTER TABLE kitchen_run_requests ADD COLUMN state_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER approved_at',
  'SELECT 1');
PREPARE statement FROM @sql; EXECUTE statement; DEALLOCATE PREPARE statement;

-- Verification:
-- SELECT column_name FROM information_schema.columns
-- WHERE table_schema = DATABASE() AND table_name = 'kitchen_run_requests'
--   AND column_name IN ('original_submission_json', 'state_version');
-- Expect 2 rows. Re-running this migration must leave both columns unchanged.

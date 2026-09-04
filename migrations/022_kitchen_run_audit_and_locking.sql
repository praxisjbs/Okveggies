-- =============================================================================
-- 022_kitchen_run_audit_and_locking.sql
-- Kitchen Run submissions are retained exactly as received. The version column
-- makes quote, approval and conversion compare-and-swap operations.
-- =============================================================================

START TRANSACTION;

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

COMMIT;

-- Verification:
-- SELECT column_name FROM information_schema.columns
-- WHERE table_schema = DATABASE() AND table_name = 'kitchen_run_requests'
--   AND column_name IN ('original_submission_json', 'state_version');
-- Expect 2 rows. Re-running this migration must leave both columns unchanged.

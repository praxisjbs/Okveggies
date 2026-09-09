-- =============================================================================
-- 029. Customer-facing credit application decision reason.
-- =============================================================================
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'credit_applications' AND COLUMN_NAME = 'decision_reason'
);
SET @ddl := IF(
  @col_exists = 0,
  'ALTER TABLE `credit_applications` ADD COLUMN `decision_reason` VARCHAR(1000) NULL AFTER `status`',
  'DO 0'
);
PREPARE okv_029_decision_reason FROM @ddl;
EXECUTE okv_029_decision_reason;
DEALLOCATE PREPARE okv_029_decision_reason;

-- Verification:
--   SELECT COLUMN_NAME FROM information_schema.COLUMNS
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'credit_applications' AND COLUMN_NAME = 'decision_reason';

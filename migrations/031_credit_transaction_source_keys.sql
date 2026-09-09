-- =============================================================================
-- 031. Idempotent source keys for append-only credit journal entries.
-- =============================================================================
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='credit_transactions' AND COLUMN_NAME='source_key');
SET @ddl := IF(@col_exists=0,'ALTER TABLE `credit_transactions` ADD COLUMN `source_key` VARCHAR(120) NULL AFTER `transaction_type`','DO 0');PREPARE okv_031_col FROM @ddl;EXECUTE okv_031_col;DEALLOCATE PREPARE okv_031_col;
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='credit_transactions' AND INDEX_NAME='uq_credit_transactions_source_key');
SET @ddl := IF(@idx_exists=0,'ALTER TABLE `credit_transactions` ADD UNIQUE INDEX `uq_credit_transactions_source_key` (`source_key`)','DO 0');PREPARE okv_031_idx FROM @ddl;EXECUTE okv_031_idx;DEALLOCATE PREPARE okv_031_idx;
-- Verification:
--   SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='credit_transactions' AND COLUMN_NAME='source_key';
--   SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='credit_transactions' AND INDEX_NAME='uq_credit_transactions_source_key';

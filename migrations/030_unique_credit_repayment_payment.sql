-- =============================================================================
-- 030. One credit journal entry per supporting payment.
-- =============================================================================
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='credit_transactions' AND INDEX_NAME='uq_credit_transactions_payment');
SET @ddl := IF(@idx_exists=0,'ALTER TABLE `credit_transactions` ADD UNIQUE INDEX `uq_credit_transactions_payment` (`payment_id`)','DO 0');
PREPARE okv_030_payment FROM @ddl;
EXECUTE okv_030_payment;
DEALLOCATE PREPARE okv_030_payment;
-- Verification:
--   SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='credit_transactions' AND INDEX_NAME='uq_credit_transactions_payment';

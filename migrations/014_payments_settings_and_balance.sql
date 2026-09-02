-- =============================================================================
-- 014_payments_settings_and_balance.sql
-- OK Veggies. M5 payments, PR1.
--
-- Three things:
--
--   1. Payment Settings in site_settings, so the admin controls the fee bearer
--      and the offered channels without a deploy. Seeded with INSERT ... ON
--      DUPLICATE KEY UPDATE setting_key = setting_key, which is a deliberate
--      no-op on conflict: re-running this migration must never clobber a value
--      the admin has since changed.
--
--   2. payment_transactions.attempt_number, so every retry of a payment is a
--      numbered row rather than an anonymous one. A customer who abandons
--      Paystack and comes back gets a fresh reference and a fresh row; this
--      column is what makes the sequence readable in the audit trail.
--
--   3. A unique index on payments (order_id, payment_type). This is the
--      integrity guard behind the deposit and balance split: one deposit row
--      and one balance row per order, never two of either. Partial payments
--      are transactions against a payment row, never extra payment rows.
--
-- Idempotent and MySQL 8 compatible. MySQL 8 has no ADD COLUMN IF NOT EXISTS or
-- ADD INDEX IF NOT EXISTS, so the column is guarded against
-- information_schema.COLUMNS and the index against information_schema.STATISTICS,
-- and each ALTER runs through a prepared statement, exactly as 013 does. DDL
-- cannot be rolled back, so this file keeps no explicit transaction of its own.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. Payment Settings
-- -----------------------------------------------------------------------------
-- payment_fee_bearer
--   Records which side is meant to carry the Paystack fee, 'account' (the
--   default, OK Veggies absorbs it) or 'customer'. This is a record of intent
--   that the admin screens read, NOT a switch that changes what we send to
--   Paystack. Who actually bears the fee is settled on the Paystack side, and
--   the ledger does not depend on this value: it always credits an order with
--   requested_amount, which is the price we asked for the goods, and records
--   the fee separately in payment_transactions.provider_fee_subunit. That rule
--   is correct whichever way the fee is borne.
--
-- payment_channels
--   Empty string means send no channels parameter, which lets the Paystack
--   dashboard decide. That is the default and the recommended setting: the
--   channel list grows (apple_pay, capitec_pay, payattitude and eft are all
--   newer than this project) and the dashboard never goes stale. A comma
--   separated list here overrides it for every transaction.
--
-- payment_verify_sweep_minutes
--   How long a transaction may sit unresolved before the reconciliation sweep
--   asks Paystack directly. This is the safety net for a webhook that never
--   arrived and a customer who never came back.
INSERT INTO `site_settings` (`setting_key`, `setting_value`, `value_type`, `is_public`) VALUES
  ('payment_fee_bearer',           'account', 'string',  0),
  ('payment_channels',             '',        'string',  0),
  ('payment_verify_sweep_minutes', '15',      'integer', 0)
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

-- -----------------------------------------------------------------------------
-- 2. Column: payment_transactions.attempt_number
-- -----------------------------------------------------------------------------
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'payment_transactions'
     AND COLUMN_NAME  = 'attempt_number'
);
SET @ddl := IF(
  @col_exists = 0,
  'ALTER TABLE `payment_transactions` ADD COLUMN `attempt_number` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `payment_id`',
  'DO 0'
);
PREPARE okv_014_col_attempt FROM @ddl;
EXECUTE okv_014_col_attempt;
DEALLOCATE PREPARE okv_014_col_attempt;

-- -----------------------------------------------------------------------------
-- 3. Unique index: payments (order_id, payment_type)
-- -----------------------------------------------------------------------------
SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'payments'
     AND INDEX_NAME   = 'uq_payments_order_id_payment_type'
);
SET @ddl := IF(
  @idx_exists = 0,
  'ALTER TABLE `payments` ADD UNIQUE KEY `uq_payments_order_id_payment_type` (`order_id`, `payment_type`)',
  'DO 0'
);
PREPARE okv_014_idx_order_type FROM @ddl;
EXECUTE okv_014_idx_order_type;
DEALLOCATE PREPARE okv_014_idx_order_type;

-- -----------------------------------------------------------------------------
-- Verification
-- -----------------------------------------------------------------------------
SELECT 'payment settings seeded' AS check_name,
       COUNT(*) AS found, 3 AS expected
  FROM `site_settings`
 WHERE `setting_key` IN ('payment_fee_bearer', 'payment_channels', 'payment_verify_sweep_minutes');

SELECT 'payment_transactions.attempt_number' AS check_name,
       COUNT(*) AS found, 1 AS expected
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME   = 'payment_transactions'
   AND COLUMN_NAME  = 'attempt_number';

SELECT 'uq_payments_order_id_payment_type' AS check_name,
       COUNT(DISTINCT INDEX_NAME) AS found, 1 AS expected
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME   = 'payments'
   AND INDEX_NAME   = 'uq_payments_order_id_payment_type';

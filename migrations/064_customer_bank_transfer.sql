-- =============================================================================
-- 064_customer_bank_transfer.sql
-- OK Veggies. Direct bank transfer paid by the customer, with the receipt
-- uploaded for the team to verify (PRD Section 9.3a), and the private receipt
-- link behind the "Payment received" screen.
--
-- Until now the only way money outside Paystack reached an order was a staff
-- member recording it (ManualPayments::record, migration 016), which credits the
-- order at once. A customer submitting their own receipt must credit NOTHING
-- until staff have looked at it, so it needs its own state and its own
-- amounts. Six things:
--
--   1. manual_payment_proofs.submitted_by_customer. A proof recorded by staff
--      is already credited and is only waiting for a second pair of eyes (status
--      'pending'). A proof submitted by a customer is not credited at all
--      (status 'submitted'). The flag says which world a row lives in, so the
--      two queues can never be confused.
--
--   2. manual_payment_proofs.verified_amount_subunit. What the person who
--      verified it actually saw in the bank. amount_subunit keeps what the
--      customer said they sent, so the two are always both on record and a
--      difference is visible rather than overwritten.
--
--   3. order_receipt_links. The private link that opens the "Payment received"
--      screen without signing in. The Order Trail link deliberately shows no
--      money, so a guest needs a second credential that does. Only the SHA-256
--      hash is stored, exactly as the trail does it, so a leaked row never yields
--      a working link.
--
--   4. site_settings for the OK Veggies account the customer transfers to. The
--      Owner edits them in Settings, Payments. The switch starts off, so
--      nothing changes for customers until the Owner has entered the account.
--
--   5. Four notification templates: the customer's "receipt received", "payment
--      verified" and "receipt declined", and the staff alert to review one. Seeded
--      with a no-op update on conflict, so a re-run never overwrites words the
--      Owner has since edited.
--
--   6. No new permission. Verifying reuses payments.record, which the Owner and
--      the Manager already hold (Owner decision, 29 September 2026).
--
-- Idempotent and MySQL 8 compatible. MySQL 8 has no ADD COLUMN IF NOT EXISTS, so
-- each column is guarded against information_schema.COLUMNS and the ALTER runs
-- through a prepared statement, as 011, 013 and 016 do. DDL cannot be rolled
-- back, so the schema changes keep no transaction of their own; the seed rows at
-- the end do.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. Column: manual_payment_proofs.submitted_by_customer
-- -----------------------------------------------------------------------------
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'manual_payment_proofs'
     AND COLUMN_NAME  = 'submitted_by_customer'
);
SET @ddl := IF(
  @col_exists = 0,
  'ALTER TABLE `manual_payment_proofs` ADD COLUMN `submitted_by_customer` TINYINT(1) NOT NULL DEFAULT 0 AFTER `recorded_by`',
  'DO 0'
);
PREPARE okv_064_col_submitted FROM @ddl;
EXECUTE okv_064_col_submitted;
DEALLOCATE PREPARE okv_064_col_submitted;

-- -----------------------------------------------------------------------------
-- 2. Column: manual_payment_proofs.verified_amount_subunit
-- -----------------------------------------------------------------------------
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'manual_payment_proofs'
     AND COLUMN_NAME  = 'verified_amount_subunit'
);
SET @ddl := IF(
  @col_exists = 0,
  'ALTER TABLE `manual_payment_proofs` ADD COLUMN `verified_amount_subunit` BIGINT UNSIGNED NULL AFTER `amount_subunit`',
  'DO 0'
);
PREPARE okv_064_col_verified FROM @ddl;
EXECUTE okv_064_col_verified;
DEALLOCATE PREPARE okv_064_col_verified;

-- -----------------------------------------------------------------------------
-- 3. The private receipt link
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `order_receipt_links` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_receipt_links_hash` (`token_hash`),
  KEY `idx_order_receipt_links_order` (`order_id`, `created_at`),
  CONSTRAINT `fk_order_receipt_links_order_id` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4. Settings, 5. Notification templates
-- -----------------------------------------------------------------------------
START TRANSACTION;

INSERT INTO `site_settings` (`setting_key`, `setting_value`, `value_type`, `is_public`) VALUES
  ('bank_transfer_enabled',        'false', 'bool',   0),
  ('bank_transfer_bank_name',      '',      'string', 0),
  ('bank_transfer_account_name',   '',      'string', 0),
  ('bank_transfer_account_number', '',      'string', 0)
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

INSERT INTO `notification_templates` (template_key, channel, subject_template, body_template, is_active) VALUES
  ('transfer_receipt_received', 'email',
   'We have your transfer receipt for {{order_number}}',
   'Hi {{customer_name}}, thank you. We have your receipt for {{amount}} on order {{order_number}}.\n\nOur team is checking it against the bank now, so your payment is pending verification and nothing is confirmed yet. You will get another email the moment it is.\n\nYour delivery day is held for {{delivery_day}}.\n\nFollow your order here any time: {{order_trail_url}}',
   TRUE),
  ('transfer_verified', 'email',
   'Payment received for {{order_number}}',
   'Hi {{customer_name}}, we have checked your transfer and received {{amount}} for order {{order_number}}. Thank you.\n\n{{balance_line}}\n\nDelivery day: {{delivery_day}}. {{source_line}}\n\nOpen your receipt to see the payment and the order details: {{receipt_url}}',
   TRUE),
  ('transfer_declined', 'email',
   'We could not confirm your transfer for {{order_number}}',
   'Hi {{customer_name}}, we could not confirm the receipt you sent for order {{order_number}}.\n\nReason: {{decline_reason}}\n\nYour order is still held for {{delivery_day}}. If you have sent the money, upload a clearer receipt from your order page. If you think we have made a mistake, send us a message on WhatsApp and we will look again.\n\nUpload a new receipt here: {{pay_url}}',
   TRUE),
  ('admin_transfer_receipt', 'email',
   'Transfer receipt to verify on {{order_number}}',
   '{{customer_name}} has uploaded a bank transfer receipt for {{amount}} on order {{order_number}}.\n\nCheck it against the bank, then verify it or decline it with a reason: {{admin_url}}',
   TRUE)
ON DUPLICATE KEY UPDATE `template_key` = `template_key`;

COMMIT;

-- Verification:
--   SELECT COLUMN_NAME FROM information_schema.COLUMNS
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manual_payment_proofs'
--      AND COLUMN_NAME IN ('submitted_by_customer','verified_amount_subunit')
--    ORDER BY COLUMN_NAME;
--   Expect 2 rows.
--
--   SELECT COUNT(*) FROM manual_payment_proofs WHERE submitted_by_customer = 1;
--   Expect 0 on a database that has not taken a customer transfer yet: every
--   proof that existed before this migration was recorded by staff.
--
--   SELECT COUNT(*) FROM order_receipt_links;
--   Expect 0 on a fresh install, table exists.
--
--   SELECT setting_key, setting_value, value_type FROM site_settings
--    WHERE setting_key LIKE 'bank_transfer_%' ORDER BY setting_key;
--   Expect 4 rows; bank_transfer_enabled is false until the Owner switches it on.
--
--   SELECT template_key, is_active FROM notification_templates
--    WHERE template_key IN ('transfer_receipt_received','transfer_verified',
--                           'transfer_declined','admin_transfer_receipt')
--    ORDER BY template_key;
--   Expect 4 rows, all is_active = 1.

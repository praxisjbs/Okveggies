-- =============================================================================
-- 067_wallet_and_credit_notes.sql
-- OK Veggies. The customer wallet, its append-only ledger, and credit notes.
--
-- Money enters a wallet only from OK Veggies: a complaint credit, a cancellation
-- refund the customer keeps, a goodwill credit, a shortage credit. There are no
-- top ups. The customer spends it on an order through the Pay sheet or checkout.
--
--   wallet_accounts  one row per customer. balance_subunit is a cache of the
--                    ledger, kept in step inside the same transaction that
--                    writes each entry. UNSIGNED, so a spend that would take it
--                    below zero is refused by the database as well as by code.
--   wallet_entries   the ledger. Append only: nothing is edited, nothing is
--                    deleted. source_key is UNIQUE, which is what makes a
--                    retried credit or a double tapped spend write once.
--   credit_notes     one numbered document per credit. It is the paper for a
--                    wallet_entries credit row, tied to the order and the
--                    reason, and shown to the customer and to staff.
--
-- Also: issue_reports.resolution_wallet_entry_id, so a complaint that was made
-- right with account credit points at the wallet entry that carries it, and
-- two permissions: wallet.view (whoever can open a customer) and wallet.credit
-- (give a customer goodwill credit, Owner only).
--
-- Idempotent and MySQL 8 compatible. CREATE TABLE IF NOT EXISTS for the tables;
-- the one column on issue_reports is guarded against information_schema
-- because MySQL 8 has no ADD COLUMN IF NOT EXISTS. DDL cannot be rolled back,
-- so the schema changes keep no transaction of their own; the seed rows do.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `wallet_accounts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `balance_subunit` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wallet_accounts_user_id` (`user_id`),
  CONSTRAINT `fk_wallet_accounts_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wallet_entries` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `wallet_account_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `entry_type` VARCHAR(20) NOT NULL,
  `source` VARCHAR(30) NOT NULL,
  `source_key` VARCHAR(150) NOT NULL,
  `amount_subunit` BIGINT NOT NULL,
  `balance_after_subunit` BIGINT UNSIGNED NOT NULL,
  `order_id` BIGINT UNSIGNED NULL,
  `payment_transaction_id` BIGINT UNSIGNED NULL,
  `note` VARCHAR(255) NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wallet_entries_source_key` (`source_key`),
  KEY `ix_wallet_entries_account` (`wallet_account_id`, `id`),
  KEY `ix_wallet_entries_order` (`order_id`),
  CONSTRAINT `fk_wallet_entries_account` FOREIGN KEY (`wallet_account_id`) REFERENCES `wallet_accounts` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_wallet_entries_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_wallet_entries_order_id` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_wallet_entries_transaction` FOREIGN KEY (`payment_transaction_id`) REFERENCES `payment_transactions` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_wallet_entries_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `credit_notes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `credit_note_number` VARCHAR(40) NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `order_id` BIGINT UNSIGNED NULL,
  `wallet_entry_id` BIGINT UNSIGNED NOT NULL,
  `amount_subunit` BIGINT UNSIGNED NOT NULL,
  `reason_code` VARCHAR(30) NOT NULL,
  `reason_text` VARCHAR(255) NULL,
  `issued_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_credit_notes_number` (`credit_note_number`),
  UNIQUE KEY `uq_credit_notes_wallet_entry` (`wallet_entry_id`),
  KEY `ix_credit_notes_user` (`user_id`, `id`),
  CONSTRAINT `fk_credit_notes_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_credit_notes_order_id` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_credit_notes_wallet_entry` FOREIGN KEY (`wallet_entry_id`) REFERENCES `wallet_entries` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_credit_notes_issued_by` FOREIGN KEY (`issued_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- issue_reports.resolution_wallet_entry_id
-- -----------------------------------------------------------------------------
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'issue_reports'
     AND COLUMN_NAME  = 'resolution_wallet_entry_id'
);
SET @ddl := IF(
  @col_exists = 0,
  'ALTER TABLE `issue_reports` ADD COLUMN `resolution_wallet_entry_id` BIGINT UNSIGNED NULL AFTER `credit_transaction_id`',
  'DO 0'
);
PREPARE okv_067_col_wallet_entry FROM @ddl;
EXECUTE okv_067_col_wallet_entry;
DEALLOCATE PREPARE okv_067_col_wallet_entry;

SET @fk_exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
   WHERE TABLE_SCHEMA    = DATABASE()
     AND TABLE_NAME      = 'issue_reports'
     AND CONSTRAINT_NAME = 'fk_issue_reports_wallet_entry'
);
SET @ddl := IF(
  @fk_exists = 0,
  'ALTER TABLE `issue_reports` ADD CONSTRAINT `fk_issue_reports_wallet_entry` FOREIGN KEY (`resolution_wallet_entry_id`) REFERENCES `wallet_entries` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE',
  'DO 0'
);
PREPARE okv_067_fk_wallet_entry FROM @ddl;
EXECUTE okv_067_fk_wallet_entry;
DEALLOCATE PREPARE okv_067_fk_wallet_entry;

-- -----------------------------------------------------------------------------
-- Permissions and the notification that tells a customer they were credited
-- -----------------------------------------------------------------------------
START TRANSACTION;

INSERT INTO permissions (`key`, `module`, `description`) VALUES
  ('wallet.view',   'customers', 'See a customer wallet, its ledger and its credit notes'),
  ('wallet.credit', 'customers', 'Give a customer goodwill credit in their wallet')
ON DUPLICATE KEY UPDATE `module` = VALUES(`module`), `description` = VALUES(`description`);

-- Whoever can open a customer can see the wallet on their profile.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT rp.role_id, wallet.id
FROM role_permissions rp
JOIN permissions viewer ON viewer.id = rp.permission_id AND viewer.`key` = 'customers.view'
CROSS JOIN permissions wallet
WHERE wallet.`key` = 'wallet.view';

-- Giving credit is money leaving OK Veggies, so it is the Owner's alone.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.name = 'owner'
  AND p.`key` IN ('wallet.view', 'wallet.credit');

INSERT INTO notification_templates (template_key, channel, subject_template, body_template, is_active) VALUES
  ('wallet_credited', 'email',
   '{{amount}} has been added to your OK Veggies wallet',
   'Hi {{customer_name}}, {{amount}} has been added to your wallet.\n\n{{reason}}\n\nCredit note: {{credit_note_number}}\nWallet balance: {{wallet_balance}}\n\nYou can spend it on your next order. It is offered first when you pay, and you can use part of it or all of it.\n\n{{wallet_url}}',
   TRUE)
ON DUPLICATE KEY UPDATE
  subject_template = VALUES(subject_template),
  body_template    = VALUES(body_template),
  is_active        = VALUES(is_active);

COMMIT;

-- Verification:
--   SHOW CREATE TABLE credit_notes;                                   -- 4 foreign keys
--   SELECT `key` FROM permissions WHERE `key` LIKE 'wallet.%';        -- 2 rows
--   SELECT COUNT(*) FROM notification_templates
--    WHERE template_key = 'wallet_credited' AND is_active = 1;        -- 1

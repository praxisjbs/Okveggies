-- =============================================================================
-- 068_out_of_stock_and_manual_refunds.sql
-- OK Veggies. An order line that cannot be sourced, and the manual refund queue.
--
-- During sourcing a colleague finds an item is out of stock. Marking it short
-- takes the value of what is missing off the order (the line and the total),
-- first from whatever is still unpaid, and whatever the customer has already
-- paid beyond what they now owe is theirs to take back. They choose: into the
-- wallet at once, or to their bank account by hand. Staff can choose for them.
--
--   order_shortages  one row per item marked short: what was missing, what it
--                    was worth, how much came off what is still to pay, how much
--                    the customer is owed back, and how it was settled. The
--                    emailed link carries a token whose hash is stored here.
--   manual_refunds   money that has to leave by a bank transfer someone makes by
--                    hand: a shortage refund or a wallet cash out. The customer
--                    gives bank name, account number and account name; staff pay
--                    it and mark it paid with the bank reference. Paystack does
--                    not handle refunds for this shop.
--
-- Also: the orders.shortage.record permission (whoever can move an order through
-- its stages, and the Owner) and the four emails the flow sends. Paying a manual
-- refund is money leaving the business, so it stays behind payments.refund.
--
-- Idempotent. CREATE TABLE IF NOT EXISTS, and ON DUPLICATE KEY UPDATE for the
-- seed rows. No ALTER on an existing table.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `order_shortages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `order_item_id` BIGINT UNSIGNED NOT NULL,
  `short_quantity` DECIMAL(10,3) NOT NULL,
  `original_quantity` DECIMAL(10,3) NOT NULL,
  `original_line_total_subunit` BIGINT UNSIGNED NOT NULL,
  `amount_subunit` BIGINT UNSIGNED NOT NULL,
  `reduced_subunit` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `refund_due_subunit` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `status` VARCHAR(20) NOT NULL,
  `resolution` VARCHAR(20) NULL,
  `reason` VARCHAR(200) NULL,
  `adjustment` JSON NULL,
  `token_hash` CHAR(64) NULL,
  `decided_by_type` VARCHAR(10) NULL,
  `decided_by` BIGINT UNSIGNED NULL,
  `decided_at` DATETIME NULL,
  `wallet_entry_id` BIGINT UNSIGNED NULL,
  `recorded_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_shortages_token_hash` (`token_hash`),
  KEY `ix_order_shortages_order` (`order_id`, `id`),
  KEY `ix_order_shortages_item` (`order_item_id`),
  KEY `ix_order_shortages_status` (`status`, `id`),
  CONSTRAINT `fk_order_shortages_order_id` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_order_shortages_item_id` FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_order_shortages_decided_by` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_order_shortages_wallet_entry` FOREIGN KEY (`wallet_entry_id`) REFERENCES `wallet_entries` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_order_shortages_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `manual_refunds` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `refund_number` VARCHAR(40) NOT NULL,
  `kind` VARCHAR(20) NOT NULL,
  `user_id` BIGINT UNSIGNED NULL,
  `order_id` BIGINT UNSIGNED NULL,
  `shortage_id` BIGINT UNSIGNED NULL,
  `wallet_entry_id` BIGINT UNSIGNED NULL,
  `amount_subunit` BIGINT UNSIGNED NOT NULL,
  `bank_name` VARCHAR(100) NOT NULL,
  `account_number` VARCHAR(20) NOT NULL,
  `account_name` VARCHAR(150) NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'requested',
  `requested_by_type` VARCHAR(10) NOT NULL,
  `requested_by` BIGINT UNSIGNED NULL,
  `paid_by` BIGINT UNSIGNED NULL,
  `paid_at` DATETIME NULL,
  `payment_reference` VARCHAR(120) NULL,
  `cancelled_by` BIGINT UNSIGNED NULL,
  `cancelled_at` DATETIME NULL,
  `cancel_reason` VARCHAR(200) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_manual_refunds_number` (`refund_number`),
  UNIQUE KEY `uq_manual_refunds_shortage` (`shortage_id`),
  UNIQUE KEY `uq_manual_refunds_wallet_entry` (`wallet_entry_id`),
  KEY `ix_manual_refunds_status` (`status`, `id`),
  KEY `ix_manual_refunds_user` (`user_id`, `id`),
  KEY `ix_manual_refunds_order` (`order_id`),
  CONSTRAINT `fk_manual_refunds_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_manual_refunds_order_id` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_manual_refunds_shortage_id` FOREIGN KEY (`shortage_id`) REFERENCES `order_shortages` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_manual_refunds_wallet_entry` FOREIGN KEY (`wallet_entry_id`) REFERENCES `wallet_entries` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_manual_refunds_requested_by` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_manual_refunds_paid_by` FOREIGN KEY (`paid_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_manual_refunds_cancelled_by` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- The permission and the emails
-- -----------------------------------------------------------------------------
START TRANSACTION;

INSERT INTO permissions (`key`, `module`, `description`) VALUES
  ('orders.shortage.record', 'orders', 'Mark an order line out of stock and settle it for the customer')
ON DUPLICATE KEY UPDATE `module` = VALUES(`module`), `description` = VALUES(`description`);

-- Whoever can move an order through its stages is the person at the sourcing bench.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT rp.role_id, shortage.id
FROM role_permissions rp
JOIN permissions mover ON mover.id = rp.permission_id AND mover.`key` = 'orders.status.update'
CROSS JOIN permissions shortage
WHERE shortage.`key` = 'orders.shortage.record';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.name = 'owner'
  AND p.`key` = 'orders.shortage.record';

INSERT INTO notification_templates (template_key, channel, subject_template, body_template, is_active) VALUES

  ('shortage_choose', 'email',
   'Choose what to do about {{item_name}} on order {{order_number}}',
   'Hi {{customer_name}}, we could not source {{item_line}} for order {{order_number}}. We are sorry.\n\n{{amount}} of what you paid for it is yours. Choose how you would like it:\n\n{{choose_url}}\n\nPut it in your wallet and it is ready to spend straight away, or ask for it back in your bank account and we will send it by hand. Nothing happens until you choose.\n\nThe rest of your order is on its way as planned.',
   TRUE),

  ('shortage_reduced', 'email',
   '{{item_name}} is out of stock on order {{order_number}}',
   'Hi {{customer_name}}, we could not source {{item_line}} for order {{order_number}}. We are sorry.\n\nYou had not paid for it yet, so we have taken {{amount}} off what you owe. {{balance_line}}\n\n{{order_trail_url}}',
   TRUE),

  ('shortage_choice_received', 'email',
   'We have your choice for order {{order_number}}',
   'Hi {{customer_name}}, thank you. {{outcome_line}}\n\n{{order_trail_url}}',
   TRUE),

  ('manual_refund_paid', 'email',
   '{{amount}} has been sent to your bank account',
   'Hi {{customer_name}}, we have sent {{amount}} to {{bank_line}}.\n\nBank reference: {{bank_reference}}\n\nMost banks show it within a few hours. If it has not arrived by tomorrow, reply to this email or reach us on WhatsApp.\n\n{{wallet_url}}',
   TRUE),

  ('admin_manual_refund_requested', 'email',
   'Refund to pay by hand: {{amount}} for {{customer_name}}',
   '{{customer_name}} has asked for {{amount}} back in their bank account ({{reason}}).\n\nRefund {{refund_number}}. Open the Payments screen, send the money from the bank, then mark it paid with the bank reference.\n\n{{admin_url}}',
   TRUE)

ON DUPLICATE KEY UPDATE
  subject_template = VALUES(subject_template),
  body_template    = VALUES(body_template),
  is_active        = VALUES(is_active);

COMMIT;

-- Verification:
--   SHOW CREATE TABLE manual_refunds;                                  -- 7 foreign keys
--   SELECT COUNT(*) FROM permissions WHERE `key` = 'orders.shortage.record';   -- 1
--   SELECT COUNT(*) FROM notification_templates
--    WHERE template_key IN ('shortage_choose','shortage_reduced','shortage_choice_received',
--                           'manual_refund_paid','admin_manual_refund_requested')
--      AND is_active = 1;                                               -- 5

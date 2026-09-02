-- =============================================================================
-- 016_manual_payments_and_reversals.sql
-- OK Veggies. M5 payments, PR2. Manual money: cash and transfer recorded by
-- staff, the proof behind it, and the way a mistake is undone.
--
-- Four things:
--
--   1. manual_payment_proofs.method. The drafted schema records a proof but
--      never says how the money arrived. Cash and transfer have different
--      evidence rules (a transfer always leaves a reference or a screenshot,
--      cash leaves nothing), so the method has to be stored, not inferred.
--
--   2. manual_payment_proofs.recorded_by. The table knows who REVIEWED a proof
--      and never who RECORDED it. For the one place in this system where a
--      person asserts money exists with nothing external confirming it, the
--      author is the single most important fact to keep.
--
--   3. payment_reversals. A payment recorded against the wrong order is a data
--      error, not a refund: no money goes back to a customer. CLAUDE.md says
--      never delete a payment, reverse it, so a reversal is a request that
--      someone else approves, and the reversing entry stays visible beside the
--      entry it undoes. Refunds, where money really does travel back to the
--      customer, are a separate concern and are PR3.
--
--   4. Two permissions, so this is governed by the permission system rather
--      than by a role name baked into code. A new role tomorrow can be granted
--      either one without touching PHP.
--
-- Idempotent and MySQL 8 compatible. MySQL 8 has no ADD COLUMN IF NOT EXISTS,
-- so each column is guarded against information_schema.COLUMNS and the ALTER
-- runs through a prepared statement, as 013 and 014 do. DDL cannot be rolled
-- back, so the schema changes keep no transaction of their own; the seed rows
-- at the end do.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. Column: manual_payment_proofs.method
-- -----------------------------------------------------------------------------
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'manual_payment_proofs'
     AND COLUMN_NAME  = 'method'
);
SET @ddl := IF(
  @col_exists = 0,
  'ALTER TABLE `manual_payment_proofs` ADD COLUMN `method` VARCHAR(20) NOT NULL DEFAULT ''transfer'' AFTER `payment_transaction_id`',
  'DO 0'
);
PREPARE okv_016_col_method FROM @ddl;
EXECUTE okv_016_col_method;
DEALLOCATE PREPARE okv_016_col_method;

-- -----------------------------------------------------------------------------
-- 2. Column: manual_payment_proofs.recorded_by
-- -----------------------------------------------------------------------------
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'manual_payment_proofs'
     AND COLUMN_NAME  = 'recorded_by'
);
SET @ddl := IF(
  @col_exists = 0,
  'ALTER TABLE `manual_payment_proofs` ADD COLUMN `recorded_by` BIGINT UNSIGNED NULL AFTER `status`',
  'DO 0'
);
PREPARE okv_016_col_recorded_by FROM @ddl;
EXECUTE okv_016_col_recorded_by;
DEALLOCATE PREPARE okv_016_col_recorded_by;

SET @fk_exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
   WHERE TABLE_SCHEMA    = DATABASE()
     AND TABLE_NAME      = 'manual_payment_proofs'
     AND CONSTRAINT_NAME = 'fk_manual_payment_proofs_recorded_by'
);
SET @ddl := IF(
  @fk_exists = 0,
  'ALTER TABLE `manual_payment_proofs` ADD CONSTRAINT `fk_manual_payment_proofs_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE',
  'DO 0'
);
PREPARE okv_016_fk_recorded_by FROM @ddl;
EXECUTE okv_016_fk_recorded_by;
DEALLOCATE PREPARE okv_016_fk_recorded_by;

-- -----------------------------------------------------------------------------
-- 3. Table: payment_reversals
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payment_reversals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `payment_id` BIGINT UNSIGNED NOT NULL,
  `payment_transaction_id` BIGINT UNSIGNED NOT NULL,
  `amount_subunit` BIGINT UNSIGNED NOT NULL,
  `reason` VARCHAR(500) NOT NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'requested',
  `requested_by` BIGINT UNSIGNED NULL,
  `requested_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `decided_by` BIGINT UNSIGNED NULL,
  `decision_note` VARCHAR(500) NULL,
  `decided_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_payment_reversals_payment_id` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_payment_reversals_payment_transaction_id` FOREIGN KEY (`payment_transaction_id`) REFERENCES `payment_transactions` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_payment_reversals_requested_by` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_payment_reversals_decided_by` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  INDEX (`status`, `requested_at`),
  INDEX (`payment_transaction_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4. Permissions, and who holds them
-- -----------------------------------------------------------------------------
START TRANSACTION;

INSERT IGNORE INTO `permissions` (`key`, `module`, `description`) VALUES
  ('payments.reversal.request', 'payments', 'Ask for a recorded payment to be reversed'),
  ('payments.reversal.approve', 'payments', 'Approve a reversal of a recorded payment');

-- The Owner holds everything.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
  FROM `roles` r CROSS JOIN `permissions` p
 WHERE r.name = 'owner'
   AND p.`key` IN ('payments.reversal.request', 'payments.reversal.approve');

-- The Manager may both ask and approve. The separation that matters is
-- enforced in code, where the person who raised a request cannot be the one
-- who approves it, because that holds however the roles are later rearranged.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
  FROM `roles` r CROSS JOIN `permissions` p
 WHERE r.name = 'manager'
   AND p.`key` IN ('payments.reversal.request', 'payments.reversal.approve');

COMMIT;

-- -----------------------------------------------------------------------------
-- Verification
-- -----------------------------------------------------------------------------
SELECT 'manual_payment_proofs.method' AS check_name, COUNT(*) AS found, 1 AS expected
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manual_payment_proofs' AND COLUMN_NAME = 'method';

SELECT 'manual_payment_proofs.recorded_by' AS check_name, COUNT(*) AS found, 1 AS expected
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manual_payment_proofs' AND COLUMN_NAME = 'recorded_by';

SELECT 'payment_reversals table' AS check_name, COUNT(*) AS found, 1 AS expected
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_reversals';

SELECT 'reversal permissions' AS check_name, COUNT(*) AS found, 2 AS expected
  FROM `permissions`
 WHERE `key` IN ('payments.reversal.request', 'payments.reversal.approve');

SELECT 'reversal grants' AS check_name, COUNT(*) AS found, 4 AS expected
  FROM `role_permissions` rp
  JOIN `permissions` p ON p.id = rp.permission_id
 WHERE p.`key` IN ('payments.reversal.request', 'payments.reversal.approve');

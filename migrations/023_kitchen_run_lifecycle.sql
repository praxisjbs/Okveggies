-- =============================================================================
-- 023_kitchen_run_lifecycle.sql
-- OK Veggies. What a Kitchen Run needs before it can become a real order.
--
-- Three things were missing and each one broke something downstream.
--
--   1. The delivery address. Checkout snapshots the address into
--      order_addresses, and the M6 day manifest, the packing list, the order
--      documents and the order emails all read it from there. A Kitchen Run
--      captures the address with the list, so conversion has a snapshot to
--      copy and a converted run reaches the manifest with a recipient on it.
--      The columns mirror order_addresses field for field on purpose.
--
--   2. quoted_at. A quote on perishable goods cannot stand forever. The window
--      itself lives in site_settings (kitchen_run_quote_days, added in 024) and
--      is measured from this stamp, so the Owner can move the window without a
--      code change and without rewriting quotes already sent.
--
--   3. kitchen_run_status_history. Every other lifecycle on this platform is
--      append-only and says who moved it and when. Kitchen Runs had a status
--      column and no memory at all: nothing recorded who declined a request or
--      when a customer approved one. This is the same shape as
--      order_status_history so the two read alike.
--
-- Idempotent and MySQL 8 compatible: MySQL 8 has no ADD COLUMN IF NOT EXISTS,
-- so every column is guarded against information_schema.COLUMNS and applied
-- through a prepared statement, the way 011_users_password_changed_at.sql and
-- 020_order_staff_note.sql do. DDL cannot be rolled back, so this file keeps no
-- explicit transaction of its own.
-- See docs/PRD.md Section 8 and Section 20.
-- =============================================================================

-- --- 1. The delivery address, captured with the list -------------------------
-- Written one at a time rather than in a single ALTER so a part-applied run on
-- an older database finishes cleanly on the next attempt.

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kitchen_run_requests'
    AND COLUMN_NAME = 'delivery_recipient_name');
SET @ddl := IF(@col = 0,
  'ALTER TABLE `kitchen_run_requests` ADD COLUMN `delivery_recipient_name` VARCHAR(150) NULL AFTER `contact_email`',
  'DO 0');
PREPARE okv_023 FROM @ddl; EXECUTE okv_023; DEALLOCATE PREPARE okv_023;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kitchen_run_requests'
    AND COLUMN_NAME = 'delivery_recipient_phone');
SET @ddl := IF(@col = 0,
  'ALTER TABLE `kitchen_run_requests` ADD COLUMN `delivery_recipient_phone` VARCHAR(30) NULL AFTER `delivery_recipient_name`',
  'DO 0');
PREPARE okv_023 FROM @ddl; EXECUTE okv_023; DEALLOCATE PREPARE okv_023;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kitchen_run_requests'
    AND COLUMN_NAME = 'delivery_address_line_1');
SET @ddl := IF(@col = 0,
  'ALTER TABLE `kitchen_run_requests` ADD COLUMN `delivery_address_line_1` VARCHAR(255) NULL AFTER `delivery_recipient_phone`',
  'DO 0');
PREPARE okv_023 FROM @ddl; EXECUTE okv_023; DEALLOCATE PREPARE okv_023;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kitchen_run_requests'
    AND COLUMN_NAME = 'delivery_address_line_2');
SET @ddl := IF(@col = 0,
  'ALTER TABLE `kitchen_run_requests` ADD COLUMN `delivery_address_line_2` VARCHAR(255) NULL AFTER `delivery_address_line_1`',
  'DO 0');
PREPARE okv_023 FROM @ddl; EXECUTE okv_023; DEALLOCATE PREPARE okv_023;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kitchen_run_requests'
    AND COLUMN_NAME = 'delivery_city');
SET @ddl := IF(@col = 0,
  'ALTER TABLE `kitchen_run_requests` ADD COLUMN `delivery_city` VARCHAR(100) NULL AFTER `delivery_address_line_2`',
  'DO 0');
PREPARE okv_023 FROM @ddl; EXECUTE okv_023; DEALLOCATE PREPARE okv_023;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kitchen_run_requests'
    AND COLUMN_NAME = 'delivery_state');
SET @ddl := IF(@col = 0,
  'ALTER TABLE `kitchen_run_requests` ADD COLUMN `delivery_state` VARCHAR(100) NULL AFTER `delivery_city`',
  'DO 0');
PREPARE okv_023 FROM @ddl; EXECUTE okv_023; DEALLOCATE PREPARE okv_023;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kitchen_run_requests'
    AND COLUMN_NAME = 'delivery_landmark');
SET @ddl := IF(@col = 0,
  'ALTER TABLE `kitchen_run_requests` ADD COLUMN `delivery_landmark` VARCHAR(255) NULL AFTER `delivery_state`',
  'DO 0');
PREPARE okv_023 FROM @ddl; EXECUTE okv_023; DEALLOCATE PREPARE okv_023;

-- --- 2. When the quote was sent, so it can expire ----------------------------

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kitchen_run_requests'
    AND COLUMN_NAME = 'quoted_at');
SET @ddl := IF(@col = 0,
  'ALTER TABLE `kitchen_run_requests` ADD COLUMN `quoted_at` DATETIME NULL AFTER `quoted_by`',
  'DO 0');
PREPARE okv_023 FROM @ddl; EXECUTE okv_023; DEALLOCATE PREPARE okv_023;

-- --- 3. The append-only lifecycle trail --------------------------------------

CREATE TABLE IF NOT EXISTS `kitchen_run_status_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` BIGINT UNSIGNED NOT NULL,
  `old_status` VARCHAR(40) NULL,
  `new_status` VARCHAR(40) NOT NULL,
  `source` VARCHAR(30) NOT NULL DEFAULT 'admin',
  `note` VARCHAR(500) NULL,
  `changed_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_krsh_request` FOREIGN KEY (`request_id`) REFERENCES `kitchen_run_requests` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_krsh_changed_by` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  INDEX (`request_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verification:
--   SELECT COLUMN_NAME FROM information_schema.COLUMNS
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kitchen_run_requests'
--      AND COLUMN_NAME IN ('delivery_recipient_name','delivery_recipient_phone',
--                          'delivery_address_line_1','delivery_address_line_2',
--                          'delivery_city','delivery_state','delivery_landmark',
--                          'quoted_at')
--    ORDER BY COLUMN_NAME;
--   Expect 8 rows. Re-running this migration must still return exactly 8.
--
--   SELECT COUNT(*) FROM information_schema.TABLES
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kitchen_run_status_history';
--   Expect 1.
--
--   SELECT COUNT(*) AS runs_without_a_trail FROM kitchen_run_requests r
--    WHERE NOT EXISTS (SELECT 1 FROM kitchen_run_status_history h
--                       WHERE h.request_id = r.id);
--   Expect 0 for anything submitted after this migration. Rows created before
--   it have no trail and are left alone; history is written forward, never
--   invented backwards.

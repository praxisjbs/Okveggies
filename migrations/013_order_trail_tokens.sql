-- =============================================================================
-- 013_order_trail_tokens.sql
-- OK Veggies. M4 checkout. Two additive columns on orders, plus a unique index
-- on each:
--
--   order_trail_token_hash  The SHA-256 of the customer's public Order Trail
--                           token. Only the hash is stored, so a leaked row
--                           never yields a working share link. Unique, so a
--                           token lookup lands one order.
--   shopping_cart_id        The basket a checkout converted from. Unique, so one
--                           cart converts to at most one order, which makes
--                           order placement idempotent: a second submit of the
--                           same basket finds the order already written.
--
-- Idempotent, and MySQL 8 compatible: MySQL 8 has no ADD COLUMN IF NOT EXISTS or
-- ADD INDEX IF NOT EXISTS, so each column is guarded against
-- information_schema.COLUMNS and each index against information_schema.STATISTICS,
-- and the ALTER runs through a prepared statement, exactly as
-- 011_users_password_changed_at.sql does. The columns are added before the
-- indexes that reference them. DDL cannot be rolled back, so this file keeps no
-- explicit transaction of its own.
-- =============================================================================

-- Column: orders.order_trail_token_hash
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'orders'
     AND COLUMN_NAME  = 'order_trail_token_hash'
);
SET @ddl := IF(
  @col_exists = 0,
  'ALTER TABLE `orders` ADD COLUMN `order_trail_token_hash` CHAR(64) NULL AFTER `order_number`',
  'DO 0'
);
PREPARE okv_013_col_token FROM @ddl;
EXECUTE okv_013_col_token;
DEALLOCATE PREPARE okv_013_col_token;

-- Column: orders.shopping_cart_id
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'orders'
     AND COLUMN_NAME  = 'shopping_cart_id'
);
SET @ddl := IF(
  @col_exists = 0,
  'ALTER TABLE `orders` ADD COLUMN `shopping_cart_id` BIGINT UNSIGNED NULL AFTER `user_id`',
  'DO 0'
);
PREPARE okv_013_col_cart FROM @ddl;
EXECUTE okv_013_col_cart;
DEALLOCATE PREPARE okv_013_col_cart;

-- Unique index: orders.order_trail_token_hash
SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'orders'
     AND INDEX_NAME   = 'uq_orders_trail_token_hash'
);
SET @ddl := IF(
  @idx_exists = 0,
  'ALTER TABLE `orders` ADD UNIQUE INDEX `uq_orders_trail_token_hash` (`order_trail_token_hash`)',
  'DO 0'
);
PREPARE okv_013_idx_token FROM @ddl;
EXECUTE okv_013_idx_token;
DEALLOCATE PREPARE okv_013_idx_token;

-- Unique index: orders.shopping_cart_id
SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'orders'
     AND INDEX_NAME   = 'uq_orders_shopping_cart_id'
);
SET @ddl := IF(
  @idx_exists = 0,
  'ALTER TABLE `orders` ADD UNIQUE INDEX `uq_orders_shopping_cart_id` (`shopping_cart_id`)',
  'DO 0'
);
PREPARE okv_013_idx_cart FROM @ddl;
EXECUTE okv_013_idx_cart;
DEALLOCATE PREPARE okv_013_idx_cart;

-- Verification:
--   SELECT COLUMN_NAME FROM information_schema.COLUMNS
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'
--      AND COLUMN_NAME IN ('order_trail_token_hash', 'shopping_cart_id');
--   SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'
--      AND INDEX_NAME IN ('uq_orders_trail_token_hash', 'uq_orders_shopping_cart_id');

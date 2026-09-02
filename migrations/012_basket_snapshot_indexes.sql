-- =============================================================================
-- 012_basket_snapshot_indexes.sql
-- OK Veggies. M4 basket. Lookup indexes for the price-snapshot basket. A repeat
-- add at a changed price opens a second cart_items row rather than rewriting the
-- first, so the hot path is "find the line for this cart, this item type, this
-- item, at this exact unit price". These indexes serve that lookup and the
-- active-cart lookup by owner. No data changes here, only indexes.
--
-- Idempotent, and MySQL 8 compatible: MySQL 8 has no ADD INDEX IF NOT EXISTS, so
-- each index is guarded against information_schema.STATISTICS and the ALTER runs
-- through a prepared statement, exactly as 011_users_password_changed_at.sql
-- guards its column. DDL cannot be rolled back, so this file keeps no explicit
-- transaction of its own.
-- =============================================================================

-- shopping_carts (user_id, status): the active-cart lookup by owner.
SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'shopping_carts'
     AND INDEX_NAME   = 'idx_shopping_carts_user_status'
);
SET @ddl := IF(
  @idx_exists = 0,
  'ALTER TABLE `shopping_carts` ADD INDEX `idx_shopping_carts_user_status` (`user_id`, `status`)',
  'DO 0'
);
PREPARE okv_012_carts FROM @ddl;
EXECUTE okv_012_carts;
DEALLOCATE PREPARE okv_012_carts;

-- cart_items snapshot lookup for a product line.
SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'cart_items'
     AND INDEX_NAME   = 'idx_cart_items_snapshot_product'
);
SET @ddl := IF(
  @idx_exists = 0,
  'ALTER TABLE `cart_items` ADD INDEX `idx_cart_items_snapshot_product` (`cart_id`, `item_type`, `product_id`, `unit_price_subunit`)',
  'DO 0'
);
PREPARE okv_012_prod FROM @ddl;
EXECUTE okv_012_prod;
DEALLOCATE PREPARE okv_012_prod;

-- cart_items snapshot lookup for a combo line.
SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'cart_items'
     AND INDEX_NAME   = 'idx_cart_items_snapshot_combo'
);
SET @ddl := IF(
  @idx_exists = 0,
  'ALTER TABLE `cart_items` ADD INDEX `idx_cart_items_snapshot_combo` (`cart_id`, `item_type`, `combo_package_id`, `unit_price_subunit`)',
  'DO 0'
);
PREPARE okv_012_combo FROM @ddl;
EXECUTE okv_012_combo;
DEALLOCATE PREPARE okv_012_combo;

-- Verification:
--   SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS
--    WHERE TABLE_SCHEMA = DATABASE()
--      AND INDEX_NAME IN ('idx_shopping_carts_user_status',
--                         'idx_cart_items_snapshot_product',
--                         'idx_cart_items_snapshot_combo');

-- 012_basket_snapshot_indexes.sql
-- -----------------------------------------------------------------------------
-- M4 basket. Lookup indexes for the price-snapshot basket. A repeat add at a
-- changed price opens a second cart_items row rather than rewriting the first,
-- so the hot path is "find the line for this cart, this item type, this item,
-- at this exact unit price". These indexes serve that lookup and the active
-- cart lookup by owner. No data changes here, only indexes.
-- Idempotent: every ADD INDEX is guarded with IF NOT EXISTS.
-- -----------------------------------------------------------------------------
START TRANSACTION;

ALTER TABLE `shopping_carts`
  ADD INDEX IF NOT EXISTS `idx_shopping_carts_user_status` (`user_id`, `status`);

ALTER TABLE `cart_items`
  ADD INDEX IF NOT EXISTS `idx_cart_items_snapshot_product` (`cart_id`, `item_type`, `product_id`, `unit_price_subunit`),
  ADD INDEX IF NOT EXISTS `idx_cart_items_snapshot_combo` (`cart_id`, `item_type`, `combo_package_id`, `unit_price_subunit`);

COMMIT;

-- Verification.
SHOW INDEX FROM `shopping_carts` WHERE `Key_name` = 'idx_shopping_carts_user_status';
SHOW INDEX FROM `cart_items` WHERE `Key_name` IN ('idx_cart_items_snapshot_product', 'idx_cart_items_snapshot_combo');

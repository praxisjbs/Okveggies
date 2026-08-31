-- Basket price snapshots. Existing cart_items rows already hold one immutable
-- unit price each, so repriced additions are separate rows rather than a
-- destructive price rewrite. This migration only adds lookup indexes.
START TRANSACTION;

ALTER TABLE shopping_carts
  ADD INDEX IF NOT EXISTS idx_shopping_carts_user_status (user_id, status);

ALTER TABLE cart_items
  ADD INDEX IF NOT EXISTS idx_cart_items_snapshot_product (cart_id, item_type, product_id, unit_price_subunit),
  ADD INDEX IF NOT EXISTS idx_cart_items_snapshot_combo (cart_id, item_type, combo_package_id, unit_price_subunit);

COMMIT;

SHOW INDEX FROM shopping_carts WHERE Key_name = 'idx_shopping_carts_user_status';
SHOW INDEX FROM cart_items WHERE Key_name IN ('idx_cart_items_snapshot_product', 'idx_cart_items_snapshot_combo');

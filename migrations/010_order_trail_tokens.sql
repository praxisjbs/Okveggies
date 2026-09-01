START TRANSACTION;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS order_trail_token_hash CHAR(64) NULL AFTER order_number;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS shopping_cart_id BIGINT UNSIGNED NULL AFTER user_id;
ALTER TABLE orders ADD UNIQUE INDEX IF NOT EXISTS uq_orders_trail_token_hash (order_trail_token_hash);
ALTER TABLE orders ADD UNIQUE INDEX IF NOT EXISTS uq_orders_shopping_cart_id (shopping_cart_id);
COMMIT;
SHOW INDEX FROM orders WHERE Key_name = 'uq_orders_trail_token_hash';
SHOW INDEX FROM orders WHERE Key_name = 'uq_orders_shopping_cart_id';

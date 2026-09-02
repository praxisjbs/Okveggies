-- 013_order_trail_tokens.sql
-- -----------------------------------------------------------------------------
-- M4 checkout. Two additive columns on orders:
--
--   order_trail_token_hash  The SHA-256 of the customer's public Order Trail
--                           token. Only the hash is stored, so a leaked row
--                           never yields a working share link. Unique, so a
--                           token lookup lands one order.
--   shopping_cart_id        The basket a checkout converted from. Unique, so
--                           one cart converts to at most one order. This makes
--                           order placement idempotent: a second submit of the
--                           same basket finds the order already written rather
--                           than writing it twice.
--
-- Idempotent: ADD COLUMN and ADD INDEX are guarded with IF NOT EXISTS.
-- -----------------------------------------------------------------------------
START TRANSACTION;

ALTER TABLE `orders`
  ADD COLUMN IF NOT EXISTS `order_trail_token_hash` CHAR(64) NULL AFTER `order_number`,
  ADD COLUMN IF NOT EXISTS `shopping_cart_id` BIGINT UNSIGNED NULL AFTER `user_id`;

ALTER TABLE `orders`
  ADD UNIQUE INDEX IF NOT EXISTS `uq_orders_trail_token_hash` (`order_trail_token_hash`),
  ADD UNIQUE INDEX IF NOT EXISTS `uq_orders_shopping_cart_id` (`shopping_cart_id`);

COMMIT;

-- Verification.
SHOW INDEX FROM `orders` WHERE `Key_name` = 'uq_orders_trail_token_hash';
SHOW INDEX FROM `orders` WHERE `Key_name` = 'uq_orders_shopping_cart_id';

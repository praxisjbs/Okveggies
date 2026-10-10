-- =============================================================================
-- product_cleanup.sql
-- OK Veggies. Fix the product import: remove the two duplicates and give every
-- imported product the house PRD- SKU scheme (no "WHL-", there is one catalogue).
--
-- Duplicates removed:
--   Eforiro        -> your existing "Eforiro (Shoko)"
--   Fresh rosemary -> kept as the single "Rosemary"
--
-- SAFE whether or not you have imported the orders yet: the order_items updates
-- re-point any affected lines to the kept product, and do nothing if there are
-- no orders. Run it once.
-- =============================================================================

START TRANSACTION;

-- 1) Eforiro duplicate -> Eforiro (Shoko)
SET @eforiro_dup  := (SELECT id FROM products WHERE sku = 'WHL-EFORIRO'     LIMIT 1);
SET @eforiro_keep := (SELECT id FROM products WHERE name = 'Eforiro (Shoko)' LIMIT 1);
UPDATE order_items SET product_id = @eforiro_keep
 WHERE @eforiro_dup IS NOT NULL AND @eforiro_keep IS NOT NULL AND product_id = @eforiro_dup;
DELETE FROM products WHERE id = @eforiro_dup;

-- 2) Fresh rosemary duplicate -> the single Rosemary
SET @rose_dup  := (SELECT id FROM products WHERE sku = 'WHL-FRESH-ROSEMARY' LIMIT 1);
SET @rose_keep := (SELECT id FROM products WHERE sku = 'WHL-ROSEMARY'       LIMIT 1);
UPDATE order_items SET product_id = @rose_keep
 WHERE @rose_dup IS NOT NULL AND @rose_keep IS NOT NULL AND product_id = @rose_dup;
DELETE FROM products WHERE id = @rose_dup;

-- 3) Give every remaining imported product the house SKU scheme: PRD-<6 letters>-<id>.
UPDATE products
   SET sku = CONCAT('PRD-', LEFT(UPPER(REGEXP_REPLACE(name, '[^A-Za-z0-9]+', '')), 6), '-', LPAD(id, 3, '0'))
 WHERE sku LIKE 'WHL-%';

COMMIT;

-- Verification: no WHL- SKUs left, and no duplicate names.
--   SELECT COUNT(*) FROM products WHERE sku LIKE 'WHL-%';                 -- 0
--   SELECT name, COUNT(*) c FROM products GROUP BY name HAVING c > 1;     -- empty (no duplicate names)
--   SELECT id, name, sku FROM products WHERE sku LIKE 'PRD-%' ORDER BY id;-- the full, single catalogue

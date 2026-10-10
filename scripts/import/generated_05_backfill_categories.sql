-- OK Veggies: give the bulk-imported order lines their category snapshot.
--
-- The 69 invoices in generated_04_orders.sql were written straight to
-- order_items with product_id but no snapshot_category_id, because migration
-- 054 (which backfills that column from the product) had already run at deploy
-- time, before these rows existed. So the dashboard's "Order share by category"
-- had nothing to group on and every line read as uncategorised.
--
-- This is the same backfill 054 runs: snapshot the product's current category
-- onto any line that is still missing one. Idempotent (only touches NULL),
-- safe to re-run. Manual lines with no product (the delivery charge) stay
-- uncategorised on purpose.

START TRANSACTION;

UPDATE order_items oi
  JOIN products p ON p.id = oi.product_id
   SET oi.snapshot_category_id = p.category_id
 WHERE oi.snapshot_category_id IS NULL
   AND p.category_id IS NOT NULL;

COMMIT;

-- Verification (expect 0 product-backed lines left without a category):
--   SELECT COUNT(*) AS unsnapped_product_lines
--     FROM order_items oi
--     JOIN products p ON p.id = oi.product_id
--    WHERE oi.snapshot_category_id IS NULL
--      AND p.category_id IS NOT NULL;
--   Expect 0.
--
--   SELECT pc.name, COUNT(*) AS lines
--     FROM order_items oi
--     JOIN product_categories pc ON pc.id = oi.snapshot_category_id
--    GROUP BY pc.id ORDER BY lines DESC;
--   Expect the five shopping groups with the migrated lines spread across them.

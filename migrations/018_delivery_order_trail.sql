-- =============================================================================
-- 018_delivery_order_trail.sql
-- M6 delivery schedules and immutable order sourcing attribution.
-- =============================================================================

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'
     AND COLUMN_NAME = 'source_regions_snapshot'
);
SET @ddl := IF(
  @col_exists = 0,
  'ALTER TABLE `orders` ADD COLUMN `source_regions_snapshot` VARCHAR(255) NULL AFTER `preferred_delivery_date`',
  'DO 0'
);
PREPARE okv_018_source FROM @ddl;
EXECUTE okv_018_source;
DEALLOCATE PREPARE okv_018_source;

-- Existing orders already carry the canonical chosen date. Backfill their
-- schedule once; future checkouts write the row in the placement transaction.
INSERT INTO delivery_schedules (order_id, delivery_date, status, delivered_at)
SELECT o.id, o.preferred_delivery_date,
       CASE
         WHEN o.order_status = 'delivered' THEN 'delivered'
         WHEN o.order_status = 'cancelled' THEN 'cancelled'
         WHEN o.order_status = 'dispatched' THEN 'dispatched'
         ELSE 'scheduled'
       END,
       o.delivered_at
  FROM orders o
  LEFT JOIN delivery_schedules ds ON ds.order_id = o.id
 WHERE ds.id IS NULL;

-- Verification:
--   SELECT COLUMN_NAME FROM information_schema.COLUMNS
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'
--      AND COLUMN_NAME = 'source_regions_snapshot';
--   SELECT COUNT(*) FROM orders o LEFT JOIN delivery_schedules ds ON ds.order_id=o.id
--    WHERE ds.id IS NULL;

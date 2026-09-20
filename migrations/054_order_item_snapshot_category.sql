-- =============================================================================
-- 054_order_item_snapshot_category.sql
-- PR4 Catalogue Truth and Analytics. Order lines keep the category that was
-- true when the order was written, even if staff later move the product.
-- =============================================================================

START TRANSACTION;

SET @has_snapshot_category := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'order_items'
     AND COLUMN_NAME = 'snapshot_category_id'
);
SET @snapshot_category_ddl := IF(
  @has_snapshot_category = 0,
  'ALTER TABLE `order_items` ADD COLUMN `snapshot_category_id` BIGINT UNSIGNED NULL AFTER `product_id`',
  'SELECT 1'
);
PREPARE snapshot_category_stmt FROM @snapshot_category_ddl;
EXECUTE snapshot_category_stmt;
DEALLOCATE PREPARE snapshot_category_stmt;

SET @has_snapshot_index := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'order_items'
     AND INDEX_NAME = 'idx_order_items_snapshot_category_id'
);
SET @snapshot_index_ddl := IF(
  @has_snapshot_index = 0,
  'CREATE INDEX `idx_order_items_snapshot_category_id` ON `order_items` (`snapshot_category_id`)',
  'SELECT 1'
);
PREPARE snapshot_index_stmt FROM @snapshot_index_ddl;
EXECUTE snapshot_index_stmt;
DEALLOCATE PREPARE snapshot_index_stmt;

-- Preserve the category history already present in the database before this
-- column existed. Future writes use the application snapshot at placement.
UPDATE order_items oi
  JOIN products p ON p.id = oi.product_id
   SET oi.snapshot_category_id = p.category_id
 WHERE oi.snapshot_category_id IS NULL;

COMMIT;

-- Verification:
--   SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
--     FROM information_schema.COLUMNS
--    WHERE TABLE_SCHEMA = DATABASE()
--      AND TABLE_NAME = 'order_items'
--      AND COLUMN_NAME = 'snapshot_category_id';
--   Expect BIGINT UNSIGNED, YES.
-- =============================================================================

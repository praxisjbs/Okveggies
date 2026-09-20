-- =============================================================================
-- 053_product_source_region.sql
-- PR4 Catalogue Truth. A product may override the site's default sourcing
-- region; NULL deliberately means use site_settings.source_regions.
-- =============================================================================

START TRANSACTION;

SET @has_source_region := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'products'
     AND COLUMN_NAME = 'source_region'
);
SET @source_region_ddl := IF(
  @has_source_region = 0,
  'ALTER TABLE `products` ADD COLUMN `source_region` VARCHAR(255) NULL AFTER `description`',
  'DO 0'
);
PREPARE source_region_stmt FROM @source_region_ddl;
EXECUTE source_region_stmt;
DEALLOCATE PREPARE source_region_stmt;

COMMIT;

-- Verification:
--   SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
--     FROM information_schema.COLUMNS
--    WHERE TABLE_SCHEMA = DATABASE()
--      AND TABLE_NAME = 'products'
--      AND COLUMN_NAME = 'source_region';
--   Expect VARCHAR(255), YES.
-- =============================================================================

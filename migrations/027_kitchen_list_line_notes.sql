-- =============================================================================
-- 027. Saved Kitchen List line notes and unique names per business.
--
-- MySQL 8 has no ADD COLUMN IF NOT EXISTS or ADD INDEX IF NOT EXISTS, so both
-- changes are guarded through information_schema and prepared DDL. DDL commits
-- implicitly, which means an explicit transaction cannot make this reversible.
-- =============================================================================

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'kitchen_run_template_items'
     AND COLUMN_NAME = 'note'
);
SET @ddl := IF(
  @col_exists = 0,
  'ALTER TABLE `kitchen_run_template_items` ADD COLUMN `note` VARCHAR(255) NULL AFTER `sort_order`',
  'DO 0'
);
PREPARE okv_027_line_note FROM @ddl;
EXECUTE okv_027_line_note;
DEALLOCATE PREPARE okv_027_line_note;

SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'kitchen_run_templates'
     AND INDEX_NAME = 'uq_kitchen_run_templates_user_name'
);
SET @ddl := IF(
  @idx_exists = 0,
  'ALTER TABLE `kitchen_run_templates` ADD UNIQUE INDEX `uq_kitchen_run_templates_user_name` (`user_id`, `name`)',
  'DO 0'
);
PREPARE okv_027_unique_name FROM @ddl;
EXECUTE okv_027_unique_name;
DEALLOCATE PREPARE okv_027_unique_name;

-- Verification:
--   SELECT COLUMN_NAME FROM information_schema.COLUMNS
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kitchen_run_template_items' AND COLUMN_NAME = 'note';
--   SELECT INDEX_NAME FROM information_schema.STATISTICS
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kitchen_run_templates' AND INDEX_NAME = 'uq_kitchen_run_templates_user_name';

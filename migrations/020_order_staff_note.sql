-- =============================================================================
-- 020_order_staff_note.sql
-- OK Veggies. One internal note on an order, for the team only.
--
-- docs/M6_GUIDE.md Section 4 asks for "an internal staff note on an order,
-- visible to staff and never to the customer". It deliberately does not reuse
-- order_status_history: that table is the customer's trail as well as the
-- team's, and a note written into it would appear as an extra step on the
-- public Order Trail. A column on the order keeps the note where it belongs and
-- keeps the trail honest.
--
-- Idempotent and MySQL 8 compatible: MySQL 8 has no ADD COLUMN IF NOT EXISTS,
-- so the column is guarded against information_schema.COLUMNS and the ALTER
-- runs through a prepared statement, the way 011_users_password_changed_at.sql
-- and 013_order_trail_tokens.sql do. DDL cannot be rolled back, so this file
-- keeps no explicit transaction of its own.
-- =============================================================================

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'orders'
     AND COLUMN_NAME  = 'staff_note'
);
SET @ddl := IF(
  @col_exists = 0,
  'ALTER TABLE `orders` ADD COLUMN `staff_note` VARCHAR(2000) NULL AFTER `source_regions_snapshot`',
  'DO 0'
);
PREPARE okv_020_note FROM @ddl;
EXECUTE okv_020_note;
DEALLOCATE PREPARE okv_020_note;

-- Verification:
--   SELECT COLUMN_NAME, IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH
--     FROM information_schema.COLUMNS
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'
--      AND COLUMN_NAME = 'staff_note';
--   Expect one row, nullable, 2000.
--
--   SELECT COUNT(*) AS notes_on_public_trail
--     FROM order_status_history WHERE source = 'note';
--   Expect 0: the note never goes near the trail.

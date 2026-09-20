-- =============================================================================
-- 052_pr2_item_table_staff_initiated.sql
-- PR2 Operational Messaging + Kitchen Runs Native
-- Fixes 15 and 16.
--
-- Fix 15: admin_new_kitchen_run alert must carry the line table, not just the
-- count. The token already exists in Notifications::TOKENS, this migration
-- updates the stored template body to include {{item_table}} so fresh installs
-- and existing installs both render it.
--
-- Fix 16: staff-initiated contact thread. Adds is_staff_initiated flag so a
-- proactive thread is visible in admin list, notifies customer, and does not
-- spend the public rate limit.
-- =============================================================================

START TRANSACTION;

-- Fix 16: guarded column add, information_schema check so reruns are safe.
SET @has_is_staff := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'contact_messages'
     AND COLUMN_NAME = 'is_staff_initiated'
);
SET @ddl := IF(@has_is_staff = 0,
  'ALTER TABLE contact_messages ADD COLUMN is_staff_initiated TINYINT(1) NOT NULL DEFAULT 0 AFTER ip_address',
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_index := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'contact_messages'
     AND INDEX_NAME = 'idx_contact_messages_staff_initiated'
);
SET @idx_ddl := IF(@has_index = 0,
  'CREATE INDEX idx_contact_messages_staff_initiated ON contact_messages (is_staff_initiated, status, created_at)',
  'SELECT 1');
PREPARE idx_stmt FROM @idx_ddl;
EXECUTE idx_stmt;
DEALLOCATE PREPARE idx_stmt;

-- Fix 15: include item_table in admin_new_kitchen_run body.
-- Keeps subject, updates body to add the line list after budget_line.
UPDATE notification_templates
   SET body_template = CONCAT(
     '{{customer_name}} has sent Kitchen Run {{request_number}}.\n\n',
     '{{line_count}} on the list. How it came in: {{input_mode_label}}. Who prices it: {{pricing_mode_label}}.\n\n',
     '{{budget_line}}\n\n',
     'Lines:\n{{item_table}}\n\n',
     'Open it in the admin panel, fill in the prices and send the quote.\n\n',
     '{{admin_url}}'
   )
 WHERE template_key = 'admin_new_kitchen_run';

COMMIT;

-- Verification:
--   SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='contact_messages'
--      AND COLUMN_NAME='is_staff_initiated';
--   Expect is_staff_initiated TINYINT(1) DEFAULT 0
--
--   SELECT body_template FROM notification_templates
--    WHERE template_key='admin_new_kitchen_run';
--   Expect body contains {{item_table}} and {{line_count}}.

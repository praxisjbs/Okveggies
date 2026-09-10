-- =============================================================================
-- 045_make_it_right_reporting.sql
-- OK Veggies. M10 customer reporting foundation and editable notifications.
-- =============================================================================

START TRANSACTION;

-- Active rows carry slot 1. A terminal transition clears it to NULL in the same
-- locked transaction. MySQL permits many NULL values in a unique index, so
-- historical reports do not prevent a later genuine report while concurrent
-- active reports do. A plain column avoids MySQL's restriction on generated
-- columns derived from a foreign key that has ON UPDATE CASCADE.
SET @okv_has_active_slot := (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'issue_reports'
     AND column_name = 'active_slot'
);
SET @okv_sql := IF(
  @okv_has_active_slot = 0,
  'ALTER TABLE issue_reports ADD COLUMN active_slot TINYINT UNSIGNED NULL DEFAULT 1',
  'SELECT 1'
);
PREPARE okv_stmt FROM @okv_sql;
EXECUTE okv_stmt;
DEALLOCATE PREPARE okv_stmt;

UPDATE issue_reports
   SET active_slot = CASE WHEN resolved_at IS NULL THEN 1 ELSE NULL END;

SET @okv_has_active_unique := (
  SELECT COUNT(*) FROM information_schema.statistics
   WHERE table_schema = DATABASE()
     AND table_name = 'issue_reports'
     AND index_name = 'uq_issue_reports_one_active_order'
);
SET @okv_sql := IF(
  @okv_has_active_unique = 0,
  'ALTER TABLE issue_reports ADD UNIQUE INDEX uq_issue_reports_one_active_order (order_id, active_slot)',
  'SELECT 1'
);
PREPARE okv_stmt FROM @okv_sql;
EXECUTE okv_stmt;
DEALLOCATE PREPARE okv_stmt;

INSERT IGNORE INTO site_settings
  (setting_key, setting_value, value_type, is_public)
VALUES
  ('make_it_right_reporting_window_days', '7', 'int', FALSE);

-- All M10 notification templates are reserved together. INSERT IGNORE keeps
-- later staff edits intact if a migration is re-applied.
INSERT IGNORE INTO notification_templates
  (template_key, channel, subject_template, body_template, is_active)
VALUES
  ('issue_report_received', 'email',
   'We received your report for order {{order_number}}',
   'Hello {{customer_name}},\n\nWe received your report for order {{order_number}} on {{reported_at}}.\n\nOur team will check it and the current outcome will stay on your order page.\n\nOpen your order: {{issue_url}}\n\nOK Veggies',
   TRUE),
  ('issue_report_resolved', 'email',
   'An outcome for order {{order_number}}',
   'Hello {{customer_name}},\n\nWe have finished checking your report for order {{order_number}}.\n\n{{outcome_line}}\n{{amount_line}}\n\nOpen your order: {{issue_url}}\n\nOK Veggies',
   TRUE),
  ('admin_new_issue_report', 'email',
   'Something is not right with order {{order_number}}',
   '{{customer_name}} reported {{category}} for order {{order_number}} on {{reported_at}}.\n\n{{description_preview}}\n\nOpen the report: {{admin_url}}',
   TRUE);

COMMIT;

-- Verification:
--   SELECT column_name, column_default FROM information_schema.columns
--    WHERE table_schema = DATABASE() AND table_name = 'issue_reports'
--      AND column_name = 'active_slot';                                      -- 1 row, default 1
--   SELECT index_name, non_unique FROM information_schema.statistics
--    WHERE table_schema = DATABASE() AND table_name = 'issue_reports'
--      AND index_name = 'uq_issue_reports_one_active_order';                  -- 1 unique row
--   SELECT setting_key, setting_value, value_type FROM site_settings
--    WHERE setting_key = 'make_it_right_reporting_window_days';               -- 7, int
--   SELECT template_key, is_active FROM notification_templates
--    WHERE template_key IN
--      ('issue_report_received','issue_report_resolved','admin_new_issue_report'); -- 3 rows

-- =============================================================================
-- 048_make_it_right_notification_copy.sql
-- Add category and description to the untouched customer acknowledgement.
-- Staff-edited wording is preserved by matching the exact earlier default.
-- =============================================================================

START TRANSACTION;

UPDATE notification_templates
   SET body_template = 'Hello {{customer_name}},\n\nWe received your {{category}} report for order {{order_number}} on {{reported_at}}.\n\n{{description_preview}}\n\nOur team will check it and the current outcome will stay on your order page.\n\nOpen your order: {{issue_url}}\n\nOK Veggies'
 WHERE template_key = 'issue_report_received'
   AND body_template = 'Hello {{customer_name}},\n\nWe received your report for order {{order_number}} on {{reported_at}}.\n\nOur team will check it and the current outcome will stay on your order page.\n\nOpen your order: {{issue_url}}\n\nOK Veggies';

COMMIT;

-- Verification:
--   SELECT template_key, body_template FROM notification_templates
--    WHERE template_key = 'issue_report_received';                         -- 1 row

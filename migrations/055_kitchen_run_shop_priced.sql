-- =============================================================================
-- 055_kitchen_run_shop_priced.sql
-- PR2 Fix 30: a "Pick from shop" list is quoted the moment it is sent.
--
-- Every line on such a list carries a shop price read from the products table
-- on the server, so there is nothing for the team to price. The request goes
-- straight to Quoted and the customer only approves. The team still needs to
-- know the run exists, but with the honest instruction: check it and convert
-- it, not "fill in the prices", which is what admin_new_kitchen_run says and
-- would be wrong here.
--
-- Idempotent: INSERT ... ON DUPLICATE KEY UPDATE on the unique template_key.
-- See docs/PRD.md Section 8.3.
-- =============================================================================

START TRANSACTION;

INSERT INTO notification_templates (template_key, channel, subject_template, body_template, is_active) VALUES

  ('admin_kitchen_run_shop_priced', 'email',
   'Shop-priced Kitchen Run {{request_number}} from {{customer_name}}',
   '{{customer_name}} sent Kitchen Run {{request_number}}, every line picked from the shop.\n\n{{line_count}} on the list, total {{quote_total}} at today''s shop prices. The quote went straight to the customer to approve, so nothing needs pricing. Check the list, then convert it to an order once they say yes.\n\nDelivery day: {{delivery_day}}.\n\n{{item_table}}\n\n{{admin_url}}',
   TRUE)

ON DUPLICATE KEY UPDATE
  subject_template = VALUES(subject_template),
  body_template    = VALUES(body_template),
  is_active        = VALUES(is_active);

COMMIT;

-- Verification:
--   SELECT template_key, is_active FROM notification_templates
--    WHERE template_key = 'admin_kitchen_run_shop_priced';
--   Expect 1 row, is_active = 1.
--
--   SELECT body_template FROM notification_templates
--    WHERE template_key = 'admin_kitchen_run_shop_priced'
--      AND body_template NOT LIKE '%{{admin_url}}%';
--   Expect no rows: a staff alert always carries the way in.
-- =============================================================================

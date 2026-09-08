-- =============================================================================
-- 024_kitchen_run_settings_and_templates.sql
-- OK Veggies. The quote window, and the words for the four Kitchen Run events.
--
-- kitchen_run_quote_days
--   How long a sent quote stands before a customer has to ask for a fresh one.
--   Produce prices move, so a quote from three weeks ago is not a price we can
--   honour. Seven days on a fresh install. It is a money rule, so it lives in
--   Order Settings where the Owner can move it, not in PHP.
--
-- The four templates match the four events in PRD Section 8.3 and the admin
-- alert PRD Section 14 already asks for ("new kitchen run"). Two go to the
-- customer, two to the team:
--
--   kitchen_run_quoted     customer. Their prices, and what to do next.
--   kitchen_run_declined   customer. We are not taking this one on, and why.
--   admin_new_kitchen_run  team. A list has come in and needs pricing.
--   admin_kitchen_run_approved  team. A customer said yes. Convert it.
--
-- Conversion sends nothing of its own: it makes a real order, and the existing
-- order_placed and admin_new_order templates already say that better than a
-- fifth template would.
--
-- Mail::brandedHtml() wraps every body in the letterhead, so the copy below is
-- just the letter. Idempotent: INSERT ... ON DUPLICATE KEY UPDATE on the unique
-- template_key, and the setting leaves an existing Owner value alone.
-- See docs/PRD.md Sections 8, 14 and 15.
-- =============================================================================

START TRANSACTION;

INSERT INTO site_settings (setting_key, setting_value, value_type, is_public) VALUES
  ('kitchen_run_quote_days', '7', 'int', 0)
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

INSERT INTO notification_templates (template_key, channel, subject_template, body_template, is_active) VALUES

  ('kitchen_run_quoted', 'email',
   'Your Kitchen Run {{request_number}} is priced',
   'Hi {{customer_name}}, we have priced Kitchen Run {{request_number}}.\n\nTotal: {{quote_total}}\n{{deposit_line}}\n\nWe are holding these prices until {{quote_expiry}}. Open the request to check every line and approve it, and we will start sourcing.\n\n{{request_url}}\n\nIf a line is wrong, tell us before you approve and we will price it again.',
   TRUE),

  ('kitchen_run_declined', 'email',
   'We cannot take on Kitchen Run {{request_number}}',
   'Hi {{customer_name}}, we are sorry. We cannot take on Kitchen Run {{request_number}} this time.\n\n{{decline_reason}}\n\nNothing has been charged. Your list is still here, so you can open it, change what you need and send it again.\n\n{{request_url}}\n\nOr talk to us on WhatsApp and we will find a way.',
   TRUE),

  ('admin_new_kitchen_run', 'email',
   'New Kitchen Run {{request_number}} from {{customer_name}}',
   '{{customer_name}} has sent Kitchen Run {{request_number}}.\n\n{{line_count}} on the list. How it came in: {{input_mode_label}}. Who prices it: {{pricing_mode_label}}.\n\n{{budget_line}}\n\nOpen it in the admin panel, fill in the prices and send the quote.\n\n{{admin_url}}',
   TRUE),

  ('admin_kitchen_run_approved', 'email',
   '{{customer_name}} approved Kitchen Run {{request_number}}',
   '{{customer_name}} has approved Kitchen Run {{request_number}} at {{quote_total}}.\n\n{{deposit_line}}\n\nConvert it to an order in the admin panel and it joins the delivery day like any other order.\n\n{{admin_url}}',
   TRUE)

ON DUPLICATE KEY UPDATE
  subject_template = VALUES(subject_template),
  body_template    = VALUES(body_template),
  is_active        = VALUES(is_active);

COMMIT;

-- Verification:
--   SELECT setting_key, setting_value, value_type FROM site_settings
--    WHERE setting_key = 'kitchen_run_quote_days';
--   Expect one row, int, 7 on a fresh install, whatever the Owner set after.
--
--   SELECT template_key, is_active FROM notification_templates
--    WHERE template_key IN ('kitchen_run_quoted','kitchen_run_declined',
--                           'admin_new_kitchen_run','admin_kitchen_run_approved')
--    ORDER BY template_key;
--   Expect 4 rows, every one is_active = 1.
--
--   SELECT COUNT(*) AS bodies_with_an_unfilled_slot
--     FROM notification_templates
--    WHERE template_key LIKE 'kitchen_run%' AND body_template LIKE '%{{%}}%'
--      AND body_template NOT LIKE '%{{request_number}}%';
--   Expect 0: every Kitchen Run body names the request it is about.

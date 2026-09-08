-- =============================================================================
-- 026_kitchen_run_received_and_cancelled.sql
-- OK Veggies. Two more Kitchen Run emails, both of them a gap the M7 review
-- found (docs/M7_REVIEW.md, items 33 and 26).
--
--   kitchen_run_received  customer. Four events fired after M7 and not one of
--     them reached the person who had just sent us their list. They pressed a
--     button and heard nothing back until a quote arrived, which on a busy day
--     is hours. This says we have it, says how many items are on it, and says
--     a price is coming. It carries request_url, because every customer email
--     has to link back to the thing it is about.
--
--   admin_kitchen_run_cancelled  team. A customer may now withdraw a run they
--     have already approved (PRD 8.3, "before it is converted"). There is no
--     money to reverse, since the deposit is only taken at conversion, but the
--     produce may already be bought: a Kitchen Run is our own cash at the
--     market against a list. So the team hears about that one immediately.
--
-- Mail::brandedHtml() wraps every body in the letterhead, so the copy below is
-- just the letter. Idempotent: INSERT ... ON DUPLICATE KEY UPDATE on the unique
-- template_key, so re-applying this file restores the words rather than failing.
-- See docs/PRD.md Sections 8, 14 and 15.
-- =============================================================================

START TRANSACTION;

INSERT INTO notification_templates (template_key, channel, subject_template, body_template, is_active) VALUES

  ('kitchen_run_received', 'email',
   'We have your Kitchen Run {{request_number}}',
   'Hi {{customer_name}}, thank you. We have your list.\n\nKitchen Run {{request_number}}, {{line_count}} on it, for delivery on {{delivery_day}}.\n\nNothing is charged yet. We go through the list, put a price on every line and send it back to you here. Read the prices, and if they work for you, approve it and we start sourcing.\n\n{{request_url}}\n\nIf you left something out, withdraw this one and send it again. We have not started buying.',
   TRUE),

  ('admin_kitchen_run_cancelled', 'email',
   '{{customer_name}} withdrew Kitchen Run {{request_number}} after approving it',
   '{{customer_name}} has withdrawn Kitchen Run {{request_number}}, which they had already approved at {{quote_total}}.\n\nNothing was charged, because the deposit is only taken at conversion. Check whether anything has already been bought for this list before the next market run.\n\n{{admin_url}}',
   TRUE)

ON DUPLICATE KEY UPDATE
  subject_template = VALUES(subject_template),
  body_template    = VALUES(body_template),
  is_active        = VALUES(is_active);

COMMIT;

-- Verification:
--   SELECT template_key, is_active FROM notification_templates
--    WHERE template_key IN ('kitchen_run_received','admin_kitchen_run_cancelled')
--    ORDER BY template_key;
--   Expect 2 rows, both is_active = 1.
--
--   SELECT COUNT(*) AS customer_bodies_with_no_way_back
--     FROM notification_templates
--    WHERE template_key = 'kitchen_run_received'
--      AND body_template NOT LIKE '%{{request_url}}%';
--   Expect 0: the customer can always open the thing the email is about.
--
--   SELECT COUNT(*) AS kitchen_run_templates FROM notification_templates
--    WHERE template_key LIKE '%kitchen_run%';
--   Expect 6: the four from 024 and the two here.

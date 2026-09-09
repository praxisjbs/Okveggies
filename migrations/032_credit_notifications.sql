-- =============================================================================
-- 032_credit_notifications.sql
-- OK Veggies. Credit email templates for Task J.
--
-- Four templates match the four credit events the PRD and M8 Task J name:
--
--   credit_approved              customer. Their facility is approved, with
--                                limit and terms, and a link back to the
--                                credit page so they can see their balance.
--   credit_declined              customer. Not approved this time, with the
--                                approved staff reason only, never the
--                                customer's own application reason.
--   credit_charge_posted         customer. An order placed on account, with
--                                the amount and the due date so they know
--                                what to pay and when.
--   admin_new_credit_application staff. A business has asked for credit.
--
-- Mail::brandedHtml() wraps every body in the letterhead, so the copy below is
-- just the letter. Idempotent: INSERT ... ON DUPLICATE KEY UPDATE on the unique
-- template_key restores the words on a rerun rather than failing.
-- See docs/PRD.md Sections 12, 15 and 17.2.
-- =============================================================================

START TRANSACTION;

INSERT INTO notification_templates (template_key, channel, subject_template, body_template, is_active) VALUES

  ('credit_approved', 'email',
   'Your credit facility for {{business_name}} is approved',
   'Hi {{customer_name}}, good news. Your application for {{business_name}} has been approved.\n\nLimit: {{credit_limit}}\nTerms: {{credit_days}}\n\nYou can now place orders on account up to that limit. Each order is due on its delivery day plus your terms. Open your credit page to see your available balance and your statement.\n\n{{credit_url}}\n\nIf you have any questions, reply to this email or reach us on WhatsApp.',
   TRUE),

  ('credit_declined', 'email',
   'About your credit application for {{business_name}}',
   'Hi {{customer_name}}, thank you for your application for {{business_name}}.\n\nWe are not able to approve it at this time. This is why:\n\n{{declined_reason}}\n\nYou can apply again when you are ready. Check the reason above, adjust what you request if you need to, and send a fresh application from your credit page.\n\n{{credit_url}}',
   TRUE),

  ('credit_charge_posted', 'email',
   '{{amount}} placed on account for order {{order_number}}',
   'Hi {{customer_name}}, order {{order_number}} for {{business_name}} has been placed on account.\n\nAmount: {{amount}}\nDue date: {{due_date}}\n\nPay the amount on or before the due date so your facility stays clear. Open the order to see the delivery details and your credit page to see your current balance.\n\n{{order_trail_url}}',
   TRUE),

  ('admin_new_credit_application', 'email',
   'New credit application from {{business_name}}',
   '{{customer_name}} for {{business_name}} has applied for credit.\n\nRequested terms: {{requested_days}}\nRequested limit: {{requested_limit}}\nReason: {{reason}}\n\nReview it in the admin panel and approve or decline it.\n\n{{admin_url}}',
   TRUE)

ON DUPLICATE KEY UPDATE
  subject_template = VALUES(subject_template),
  body_template    = VALUES(body_template),
  is_active        = VALUES(is_active);

COMMIT;

-- Verification:
--   SELECT template_key, is_active FROM notification_templates
--    WHERE template_key IN ('credit_approved','credit_declined',
--                           'credit_charge_posted','admin_new_credit_application')
--    ORDER BY template_key;
--   Expect 4 rows, every one is_active = 1.
--
--   SELECT COUNT(*) AS bodies_with_unfilled_slot FROM notification_templates
--    WHERE template_key IN ('credit_approved','credit_declined',
--                           'credit_charge_posted','admin_new_credit_application')
--      AND body_template LIKE '%{{%}}%';
--   Expect 0 after rendering, every {{token}} is known to Notifications::TOKENS.

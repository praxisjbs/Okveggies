-- =============================================================================
-- 061_email_change_templates.sql
-- OK Veggies. The two codes a signed-in customer's email change sends: one to
-- the current address to authorize starting the change, one to the new
-- address to confirm they control it. Sent by api/v1/account.php through
-- Mail::sendTemplate, the same way account_activation and password_reset are.
--
-- No CTA button (unlike those two): the customer is already on the settings
-- sheet waiting for the code, so there is nowhere else useful to send them.
--
-- Idempotent: INSERT ... ON DUPLICATE KEY UPDATE on the unique template_key,
-- the same shape as migration 010. See docs/PRD.md Section 10.5.
-- =============================================================================

START TRANSACTION;

INSERT INTO notification_templates (template_key, channel, subject_template, body_template, is_active) VALUES
  ('email_change_authorize', 'email',
   'A request to change your OK Veggies email',
   'Hi {{customer_name}}, we received a request to change the email on your OK Veggies account to {{new_email}}.\n\nYour code is {{code}}. It works for the next {{minutes}} minutes.\n\nIf you did not ask for this, ignore this email and your account stays exactly as it is.',
   TRUE),

  ('email_change_confirm', 'email',
   'Confirm this is your email for OK Veggies',
   'Hi {{customer_name}}, enter this code to finish moving your OK Veggies account to this email address.\n\nYour code is {{code}}. It works for the next {{minutes}} minutes.\n\nIf you did not ask for this, you can ignore this email and nothing changes.',
   TRUE)
ON DUPLICATE KEY UPDATE
  subject_template = VALUES(subject_template),
  body_template    = VALUES(body_template),
  is_active        = VALUES(is_active);

COMMIT;

-- Verification:
--   SELECT template_key, is_active, CHAR_LENGTH(body_template) AS body_length
--     FROM notification_templates
--    WHERE template_key IN ('email_change_authorize', 'email_change_confirm')
--    ORDER BY template_key;
--   Expect 2 rows, both is_active = 1.

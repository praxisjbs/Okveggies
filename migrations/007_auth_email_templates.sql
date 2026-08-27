-- =============================================================================
-- 007_auth_email_templates.sql
-- OK Veggies. Transactional email templates for customer accounts: the account
-- activation code and the password reset code. Copy is plain, offers the next
-- step, no jargon, no em dash. Idempotent. See docs/PRD.md Section 10.
-- Tokens: {{customer_name}} {{code}} {{minutes}} {{activate_url}} {{reset_url}}.
-- =============================================================================

START TRANSACTION;

INSERT INTO notification_templates (template_key, channel, subject_template, body_template, is_active) VALUES
  ('account_activation', 'email',
   'Your OK Veggies code is {{code}}',
   'Hi {{customer_name}}, welcome to OK Veggies. Your activation code is {{code}}. It works for the next {{minutes}} minutes. You can also activate your account here: {{activate_url}}. If you did not create an account, you can ignore this email.',
   TRUE),
  ('password_reset', 'email',
   'Your OK Veggies password reset code is {{code}}',
   'Hi {{customer_name}}, we received a request to reset your OK Veggies password. Your reset code is {{code}}. It works for the next {{minutes}} minutes. Set a new password here: {{reset_url}}. If you did not ask for this, you can ignore this email and your password stays the same.',
   TRUE)
ON DUPLICATE KEY UPDATE
  subject_template = VALUES(subject_template),
  body_template    = VALUES(body_template),
  is_active        = VALUES(is_active);

COMMIT;

-- Verification:
--   SELECT template_key FROM notification_templates
--    WHERE template_key IN ('account_activation', 'password_reset');

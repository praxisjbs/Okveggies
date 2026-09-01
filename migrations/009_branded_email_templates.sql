-- =============================================================================
-- 009_branded_email_templates.sql
-- OK Veggies. The transactional email copy, rewritten for the branded HTML
-- shell that Mail::brandedHtml() wraps every message in.
--
-- What changed and why:
--   1. Each body is now plain paragraphs separated by a blank line. The shell
--      turns a blank line into a new paragraph, so the copy reads as a letter
--      rather than one long run of text.
--   2. The link is no longer buried in a sentence. The shell renders the one
--      action as a real button, and prints the address underneath it for any
--      client that will not draw a button. The {{..._url}} token stays in the
--      variables the sender passes, not in the words.
--   3. The copy itself is warmer and always offers the next step, per the house
--      voice. Plain language, numerals, no jargon, no em dash.
--
-- The chrome (letterhead, forest band, footer) is not stored here on purpose.
-- It lives in PHP, where the brand guard can see it and where a change to the
-- footer is one edit rather than seven rows.
--
-- Idempotent: every row is INSERT ... ON DUPLICATE KEY UPDATE on the unique
-- template_key, so this applies cleanly whether the row exists or not, and
-- re-running it changes nothing. Migrations 006 and 007 are untouched.
-- See docs/PRD.md Section 15.
-- =============================================================================

START TRANSACTION;

-- Order lifecycle. Senders pass {{order_trail_url}}, which becomes the button.
INSERT INTO notification_templates (template_key, channel, subject_template, body_template, is_active) VALUES
  ('order_placed', 'email',
   'We have your order {{order_number}}',
   'Hi {{customer_name}}, thank you. We have your order {{order_number}} and we are sourcing it now.\n\nYou can follow it the whole way, from the market to your door. There is nothing to sign in to.\n\nWe will write to you again the moment it is on the way.',
   TRUE),

  ('payment_confirmed', 'email',
   'Payment received for {{order_number}}',
   'Hi {{customer_name}}, your payment for order {{order_number}} has reached us. Thank you.\n\nNothing else is needed from you. We will let you know when your order is on the way.',
   TRUE),

  ('deposit_received', 'email',
   'Deposit received for {{order_number}}',
   'Hi {{customer_name}}, we have your deposit for order {{order_number}}. Thank you.\n\nThe balance is settled on delivery. You will see the exact amount on your order before anything is handed over, so there are no surprises at the door.',
   TRUE),

  ('order_dispatched', 'email',
   'Your order {{order_number}} is on the way',
   'Hi {{customer_name}}, order {{order_number}} has left us and is on the way to you.\n\nKeep your phone nearby. If we cannot reach you, we bring your produce back and come again rather than leave it at a gate.',
   TRUE),

  ('order_delivered', 'email',
   'Delivered: {{order_number}}',
   'Hi {{customer_name}}, order {{order_number}} has been delivered. Thank you for shopping with us.\n\nIf anything is not what we described, tell us today and we will make it right: a refund, a credit, or a replacement on the next run.',
   TRUE),

-- Account emails. The sender passes {{activate_url}} or {{reset_url}}, which
-- becomes the button; the code is still in the words for anyone typing it in.
  ('account_activation', 'email',
   'Your OK Veggies code is {{code}}',
   'Hi {{customer_name}}, welcome to OK Veggies.\n\nYour activation code is {{code}}. It works for the next {{minutes}} minutes.\n\nEnter it on the activation page, or use the button below and we will take you straight there.',
   TRUE),

  ('password_reset', 'email',
   'Your OK Veggies password reset code is {{code}}',
   'Hi {{customer_name}}, we received a request to reset your OK Veggies password.\n\nYour reset code is {{code}}. It works for the next {{minutes}} minutes.\n\nUse the button below to set a new one.',
   TRUE)
ON DUPLICATE KEY UPDATE
  subject_template = VALUES(subject_template),
  body_template    = VALUES(body_template),
  is_active        = VALUES(is_active);

COMMIT;

-- Verification:
--   SELECT template_key, is_active, CHAR_LENGTH(body_template) AS body_length
--     FROM notification_templates
--    WHERE template_key IN ('order_placed', 'payment_confirmed', 'deposit_received',
--                           'order_dispatched', 'order_delivered',
--                           'account_activation', 'password_reset')
--    ORDER BY template_key;
--   Expect 7 rows, every one is_active = 1.
--
--   SELECT COUNT(*) AS still_carrying_a_raw_link
--     FROM notification_templates
--    WHERE body_template LIKE '%{{order_trail_url}}%'
--       OR body_template LIKE '%{{activate_url}}%'
--       OR body_template LIKE '%{{reset_url}}%';
--   Expect 0: the link is the button now, not a token inside a sentence.
--
--   SELECT COUNT(*) AS templates_with_paragraphs
--     FROM notification_templates
--    WHERE body_template LIKE CONCAT('%', CHAR(10), CHAR(10), '%');
--   Expect 7: every body is split into paragraphs for the branded shell.

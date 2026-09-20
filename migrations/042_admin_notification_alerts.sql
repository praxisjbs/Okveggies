-- Admin bell copy for the manual payment proof alert that had none.
-- The Make It Right staff alert (admin_new_issue_report) is owned by M10's
-- migration 045; it is not defined here so this migration cannot overwrite it
-- or an Owner's edited copy. INSERT IGNORE keeps a later staff edit intact if
-- this migration is ever re-applied.
START TRANSACTION;

INSERT IGNORE INTO notification_templates
    (template_key, channel, subject_template, body_template, is_active)
VALUES
    ('admin_manual_payment_proof', 'email',
     'Payment proof to review for {{order_number}}',
     '{{recorded_by}} recorded {{amount}} against order {{order_number}}. Open Payments and check the proof against the money received.',
     TRUE);

COMMIT;

-- Verification:
--   SELECT template_key, is_active FROM notification_templates
--    WHERE template_key = 'admin_manual_payment_proof';
--   Expect 1 active row.

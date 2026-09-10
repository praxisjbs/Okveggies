-- Admin bell templates for the two PRD Section 15 alerts that had no copy.
START TRANSACTION;

INSERT INTO notification_templates
    (template_key, channel, subject_template, body_template, is_active)
VALUES
    ('admin_manual_payment_proof', 'email',
     'Payment proof to review for {{order_number}}',
     '{{recorded_by}} recorded {{amount}} against order {{order_number}}. Open Payments and check the proof against the money received.',
     TRUE),
    ('admin_new_issue_report', 'email',
     'Make It Right report for {{order_number}}',
     'A customer reported {{issue_category}} against order {{order_number}}.\n\n{{message_preview}}\n\nOpen the report and decide how we will put it right.',
     TRUE)
ON DUPLICATE KEY UPDATE
    subject_template = VALUES(subject_template),
    body_template = VALUES(body_template),
    is_active = VALUES(is_active);

COMMIT;

-- Verification:
--   SELECT template_key, is_active FROM notification_templates
--    WHERE template_key IN ('admin_manual_payment_proof','admin_new_issue_report');
--   Expect 2 active rows.

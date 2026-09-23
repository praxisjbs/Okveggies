-- =============================================================================
-- 060_stage_email_failure_alert.sql
-- OK Veggies. The words for the staff alert a failed stage email never sent.
--
-- A dispatched or delivered email that bounced used to leave only a failed
-- delivery row on Order 360, which only helps the colleague who opens that
-- exact order. A dead mail host or a missing address is fulfilment work going
-- quiet across the day, so the failure now reaches the bell of everyone who
-- may open the order, with the order number, the customer, the stage, the
-- delivery day, the sanitised reason and the admin link.
--
-- The event itself, its audience, its tokens and the orders.view permission
-- that admits it to the bell all live in PHP (`Notifications::EVENTS`,
-- `Notifications::TOKENS`, `AdminNotifications::EVENTS`), the way every other
-- staff alert does. Only the words live here, so they stay editable on the
-- Notifications settings tab behind settings.notifications.edit.
--
-- The reason in the message is one of the fixed sentences Mail records, never
-- the driver's own text, so no host name or account detail can reach the bell
-- or the email. Recipients are read at send time from active users whose role
-- carries orders.view, with the configured support address as the fallback.
--
-- Idempotent: INSERT IGNORE on the unique template_key, so a re-run leaves the
-- row alone rather than overwriting copy an Owner has since edited. That is the
-- rule 042, 045 and 059 follow. Data only, no DDL, so it sits in one
-- transaction. See docs/PRD.md Section 15.
-- =============================================================================

START TRANSACTION;

INSERT IGNORE INTO notification_templates
    (template_key, channel, subject_template, body_template, is_active)
VALUES
    ('admin_stage_email_failed', 'email',
     'The {{stage_label}} email for {{order_number}} did not go out',
     'The {{stage_label}} email to {{customer_name}} for order {{order_number}} (delivery {{delivery_day}}) did not go out.\n\nReason: {{failure_reason}}\n\nOpen the order and send it again from Messages sent.',
     TRUE);

COMMIT;

-- Verification:
--   SELECT template_key, channel, is_active FROM notification_templates
--    WHERE template_key = 'admin_stage_email_failed';
--   Expect 1 row, channel 'email', is_active = 1.
--
--   SELECT subject_template, body_template FROM notification_templates
--    WHERE template_key = 'admin_stage_email_failed';
--   Expect both to name the tokens the event declares in
--   Notifications::TOKENS, and neither to contain an em dash.
--
--   SELECT COUNT(*) AS staff_templates FROM notification_templates
--    WHERE template_key IN ('admin_new_order', 'admin_order_cancelled',
--                           'admin_stage_email_failed',
--                           'admin_manual_payment_proof', 'admin_new_contact',
--                           'admin_new_issue_report', 'refund_failed');
--   Expect 7 on a fully migrated database.

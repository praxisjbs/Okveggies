-- =============================================================================
-- 059_admin_order_cancelled_notification.sql
-- OK Veggies. The words for the staff alert a cancellation had never sent.
--
-- A cancellation told the customer and nobody else. `Notifications` fired one
-- event, `order_cancelled`, addressed to the customer on the order, so a crate
-- that was no longer coming still looked like work to the team unless somebody
-- happened to open that order. PRD Section 15 lists the admin alerts and
-- cancellation is not among them, which is why it was never built; the Owner
-- settled it on 23 September 2026, and this migration carries the copy.
--
-- The event itself, its audience, its tokens and the orders.view permission
-- that admits it to the bell all live in PHP (`Notifications::EVENTS`,
-- `Notifications::TOKENS`, `AdminNotifications::EVENTS`), the way every other
-- staff alert does. Only the words live here, so they stay editable on the
-- Notifications settings tab behind settings.notifications.edit.
--
-- The message deliberately carries no naira figure. The amounts are on Order
-- 360 behind the same orders.view gate that admits this alert, and a refund
-- that failed already reaches the payments team through refund_failed with its
-- amount attached. This one says what happened, who did it, why, and which of
-- the five money positions the order is now in.
--
-- Recipients are not stored anywhere: they are read at send time from active
-- users whose role carries orders.view, with the configured support address as
-- the fallback when nobody holds it yet. No list of names or addresses is
-- seeded here, and none is written in the controller.
--
-- Idempotent: INSERT IGNORE on the unique template_key, so a re-run leaves a
-- row alone rather than overwriting copy an Owner has since edited. That is the
-- rule 042 and 045 follow. Data only, no DDL, so it sits in one transaction.
-- See docs/PRD.md Sections 14.1 and 15.
-- =============================================================================

START TRANSACTION;

INSERT IGNORE INTO notification_templates
    (template_key, channel, subject_template, body_template, is_active)
VALUES
    ('admin_order_cancelled', 'email',
     'Order {{order_number}} from {{customer_name}} has been cancelled',
     '{{cancellation_source}}\n\nCustomer: {{customer_name}}\nOrder: {{order_number}}\nDelivery day: {{delivery_day}}\nReason: {{cancellation_reason}}\nMoney: {{refund_state}}\n\nOpen the order for the cancellation record, the refund position and every message that has gone out. Take it off the day manifest if it was on one.',
     TRUE);

COMMIT;

-- Verification:
--   SELECT template_key, channel, is_active FROM notification_templates
--    WHERE template_key = 'admin_order_cancelled';
--   Expect 1 row, channel 'email', is_active = 1.
--
--   SELECT subject_template, body_template FROM notification_templates
--    WHERE template_key = 'admin_order_cancelled';
--   Expect both to name the seven tokens the event declares in
--   Notifications::TOKENS, and neither to contain a naira figure or an em dash.
--
--   SELECT COUNT(*) AS staff_templates FROM notification_templates
--    WHERE template_key IN ('admin_new_order', 'admin_order_cancelled',
--                           'admin_manual_payment_proof', 'admin_new_contact',
--                           'admin_new_issue_report', 'refund_failed');
--   Expect 6 on a fully migrated database.

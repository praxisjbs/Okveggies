-- =============================================================================
-- 019_m6_notifications.sql
-- OK Veggies. M6 notifications: the templates for the events that had none, the
-- permission behind the resend button, and the index the in-app inbox reads.
--
-- Migrations 006, 007 and 010 seeded five order and payment templates plus the
-- two account ones. Every other event M5 and M6 fire had no words to send, so
-- this file adds them. The chrome is still not stored here: Mail::brandedHtml()
-- wraps every one of these bodies in the letterhead, so the copy below is just
-- the letter.
--
-- Idempotent three ways: every template row is INSERT ... ON DUPLICATE KEY
-- UPDATE on the unique template_key, the permission rows use INSERT IGNORE, and
-- the index is guarded against information_schema.STATISTICS and applied
-- through a prepared statement, the way 013_order_trail_tokens.sql does, because
-- MySQL 8 has no ADD INDEX IF NOT EXISTS. DDL cannot be rolled back, so the
-- index sits outside the transaction that carries the data.
-- See docs/PRD.md Section 15 and docs/M6_GUIDE.md Section 5.
-- =============================================================================

START TRANSACTION;

-- The six order and payment events that had no words, plus the packed step, so
-- a customer hears about every stage of their own order rather than three of
-- six. Senders pass {{order_trail_url}}, which the shell renders as the button.
INSERT INTO notification_templates (template_key, channel, subject_template, body_template, is_active) VALUES
  ('order_confirmed', 'email',
   'We are sourcing order {{order_number}}',
   'Hi {{customer_name}}, good news. Order {{order_number}} is confirmed and we are buying it fresh for you.\n\n{{source_line}}\n\nIt is on the list for delivery on {{delivery_day}}. We will write again the moment it is packed.',
   TRUE),

  ('order_packed', 'email',
   'Order {{order_number}} is packed',
   'Hi {{customer_name}}, order {{order_number}} is packed and waiting for the van.\n\nDelivery is set for {{delivery_day}}. Keep your phone nearby on the day so the driver can reach you.',
   TRUE),

  ('order_cancelled', 'email',
   'Order {{order_number}} has been cancelled',
   'Hi {{customer_name}}, order {{order_number}} has been cancelled.\n\n{{money_line}}\n\nIf this is not what you wanted, reply to this email or send us a message on WhatsApp and we will sort it out.',
   TRUE),

  ('payment_recorded', 'email',
   'Payment recorded for {{order_number}}',
   'Hi {{customer_name}}, we have recorded {{amount}} against order {{order_number}}. Thank you.\n\n{{balance_line}}\n\nIf that does not look right, tell us today and we will check it.',
   TRUE),

  ('refund_processed', 'email',
   'Your refund for {{order_number}} has been sent',
   'Hi {{customer_name}}, we have sent {{amount}} back for order {{order_number}}.\n\nIt goes back to the account you paid from. Most banks show it within a few working days.\n\nIf it has not landed by then, tell us and we will chase it with the bank.',
   TRUE),

-- Staff mail. No customer name, no trail link, and an admin address instead.
  ('refund_failed', 'email',
   'A refund failed on {{order_number}}',
   'A refund of {{amount}} on order {{order_number}} did not go through.\n\nThe gateway said: {{reason}}\n\nOpen the order and raise it again, or return the money another way. The customer only sees a plain line on their order trail saying we have been told.',
   TRUE),

  ('admin_new_order', 'email',
   'New order {{order_number}} from {{customer_name}}',
   '{{customer_name}} has placed order {{order_number}} for {{order_total}}.\n\nDelivery day: {{delivery_day}}. Zone: {{zone_name}}. Payment: {{payment_choice}}.\n\nOpen it in the admin panel to confirm it and start sourcing.',
   TRUE)
ON DUPLICATE KEY UPDATE
  subject_template = VALUES(subject_template),
  body_template    = VALUES(body_template),
  is_active        = VALUES(is_active);

-- Resending a failed email is a real action against a customer, so it is a
-- permission rather than a side effect of orders.view. Owner and Manager both
-- work orders, so both get it; it is not on the Owner-only list.
INSERT IGNORE INTO permissions (`key`, `module`, `description`) VALUES
  ('notifications.resend', 'settings', 'Resend a notification that failed');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
 WHERE r.name IN ('owner', 'manager') AND p.`key` = 'notifications.resend';

COMMIT;

-- The in-app inbox asks for one person's unread notifications, newest first.
-- Without this it is a full scan of every delivery row ever written.
SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'notification_deliveries'
     AND INDEX_NAME   = 'idx_notification_deliveries_inbox'
);
SET @ddl := IF(
  @idx_exists = 0,
  'ALTER TABLE `notification_deliveries` ADD INDEX `idx_notification_deliveries_inbox` (`user_id`, `channel`, `read_at`)',
  'DO 0'
);
PREPARE okv_019_inbox FROM @ddl;
EXECUTE okv_019_inbox;
DEALLOCATE PREPARE okv_019_inbox;

-- Verification:
--   SELECT template_key, is_active FROM notification_templates
--    WHERE template_key IN ('order_confirmed','order_packed','order_cancelled',
--                           'payment_recorded','refund_processed','refund_failed',
--                           'admin_new_order') ORDER BY template_key;
--   Expect 7 rows, every one is_active = 1.
--
--   SELECT r.name, COUNT(*) FROM roles r
--     JOIN role_permissions rp ON rp.role_id = r.id
--     JOIN permissions p ON p.id = rp.permission_id
--    WHERE p.`key` = 'notifications.resend' GROUP BY r.name;
--   Expect owner and manager, one row each.
--
--   SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notification_deliveries'
--      AND INDEX_NAME = 'idx_notification_deliveries_inbox';
--   Expect one row.

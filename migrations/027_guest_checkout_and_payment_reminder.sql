-- =============================================================================
-- 027_guest_checkout_and_payment_reminder.sql
-- OK Veggies. Milestone 6/7 bug fixes. Three things, all additive:
--
--   orders.contact_email          The address the customer typed at checkout.
--                                 A guest order has no users row behind it, so
--                                 without this there is nowhere to send the
--                                 receipt, the trail link or the reminder. It
--                                 is written for every order, account or not,
--                                 so one column answers "where does this
--                                 order's email go" rather than two joins that
--                                 disagree.
--   order_payment_pending         The reminder itself, and a rewritten
--   payment_confirmed             payment_confirmed, which is now the whole
--                                 receipt for a pay in full order: that order
--                                 no longer sends "we have your order" before
--                                 the money has actually moved.
--
-- Idempotent: the columns are guarded against information_schema.COLUMNS and
-- applied through a prepared statement, because MySQL 8 has no ADD COLUMN IF
-- NOT EXISTS (that is MariaDB, and it fails the deploy); the setting and the
-- templates are INSERT ... ON DUPLICATE KEY UPDATE on their unique keys. DDL
-- cannot be rolled back, so the columns sit outside the transaction that
-- carries the data.
-- See docs/PRD.md Sections 9.2, 9.3 and 15.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. Column: orders.contact_email
-- -----------------------------------------------------------------------------
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'orders'
     AND COLUMN_NAME  = 'contact_email'
);
SET @ddl := IF(
  @col_exists = 0,
  'ALTER TABLE `orders` ADD COLUMN `contact_email` VARCHAR(190) NULL AFTER `customer_type`',
  'DO 0'
);
PREPARE okv_027_col_email FROM @ddl;
EXECUTE okv_027_col_email;
DEALLOCATE PREPARE okv_027_col_email;

-- -----------------------------------------------------------------------------
-- 2. Columns: notifications.cta_url and notifications.cta_label
--
--    A notification that is written now and sent later has to remember its
--    button. The body is already stored rendered; the button was not, because
--    until now every email was sent in the same breath as it was rendered.
--    The pay in full receipt is not: it is written at checkout, while the Order
--    Trail token is still in hand, and released when the money lands.
-- -----------------------------------------------------------------------------
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'notifications'
     AND COLUMN_NAME  = 'cta_url'
);
SET @ddl := IF(
  @col_exists = 0,
  'ALTER TABLE `notifications` ADD COLUMN `cta_url` VARCHAR(500) NULL AFTER `body`',
  'DO 0'
);
PREPARE okv_027_col_cta_url FROM @ddl;
EXECUTE okv_027_col_cta_url;
DEALLOCATE PREPARE okv_027_col_cta_url;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'notifications'
     AND COLUMN_NAME  = 'cta_label'
);
SET @ddl := IF(
  @col_exists = 0,
  'ALTER TABLE `notifications` ADD COLUMN `cta_label` VARCHAR(80) NULL AFTER `cta_url`',
  'DO 0'
);
PREPARE okv_027_col_cta_label FROM @ddl;
EXECUTE okv_027_col_cta_label;
DEALLOCATE PREPARE okv_027_col_cta_label;

-- Index: the flusher asks one question, "what is due", on every cron pass.
SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'notifications'
     AND INDEX_NAME   = 'idx_notifications_status_scheduled_at'
);
SET @ddl := IF(
  @idx_exists = 0,
  'ALTER TABLE `notifications` ADD INDEX `idx_notifications_status_scheduled_at` (`status`, `scheduled_at`)',
  'DO 0'
);
PREPARE okv_027_idx_due FROM @ddl;
EXECUTE okv_027_idx_due;
DEALLOCATE PREPARE okv_027_idx_due;

-- -----------------------------------------------------------------------------
-- 3. Backfill contact_email from the account behind each existing order, so an
--    order placed before this migration still has somewhere to write to.
--    Idempotent: it only fills rows that are still empty.
-- -----------------------------------------------------------------------------
START TRANSACTION;

UPDATE `orders` o
  JOIN `users` u ON u.id = o.user_id
   SET o.contact_email = u.email
 WHERE o.contact_email IS NULL
   AND u.email IS NOT NULL
   AND u.email <> '';

-- -----------------------------------------------------------------------------
-- 4. How long an unpaid online order waits before the single reminder goes out.
-- -----------------------------------------------------------------------------
INSERT INTO `site_settings` (`setting_key`, `setting_value`, `value_type`, `is_public`) VALUES
  ('payment_reminder_minutes', '30', 'int', 0)
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

-- -----------------------------------------------------------------------------
-- 5. The templates.
--
--    order_payment_pending is new: one reminder, once, for an order that chose
--    to pay online and has not paid yet.
--
--    payment_confirmed is rewritten. It used to be a receipt sent alongside a
--    "we have your order" email the customer already had. For a pay in full
--    order that first email is now held until the money lands, so this one has
--    to carry the order itself: the day, the total, where it is sourced from,
--    and the trail link the shell renders as the button.
-- -----------------------------------------------------------------------------
INSERT INTO notification_templates (template_key, channel, subject_template, body_template, is_active) VALUES
  ('order_payment_pending', 'email',
   'Your order {{order_number}} is waiting for payment',
   'Hi {{customer_name}}, your order {{order_number}} is saved but we have not seen the payment yet.\n\nThere is {{amount_due}} to pay for delivery on {{delivery_day}}. Nothing has been taken from you, and nothing is being bought until the payment lands.\n\nUse the button below to finish paying. If you have changed your mind, ignore this and the order lapses on its own. This is the only reminder we will send.',
   TRUE),

  ('payment_confirmed', 'email',
   'Payment received for {{order_number}}',
   'Hi {{customer_name}}, your payment of {{amount}} for order {{order_number}} has reached us. Thank you.\n\nWe have your order and we are sourcing it now for delivery on {{delivery_day}}. {{source_line}}\n\nYou can follow it the whole way, from the market to your door. There is nothing to sign in to.',
   TRUE)
ON DUPLICATE KEY UPDATE
  `subject_template` = VALUES(`subject_template`),
  `body_template`    = VALUES(`body_template`),
  `is_active`        = VALUES(`is_active`);

COMMIT;

-- -----------------------------------------------------------------------------
-- Verification
-- -----------------------------------------------------------------------------
SELECT 'notification hold columns' AS check_name, COUNT(*) AS found, 2 AS expected
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME   = 'notifications'
   AND COLUMN_NAME IN ('cta_url', 'cta_label');

SELECT 'idx_notifications_status_scheduled_at' AS check_name, COUNT(DISTINCT INDEX_NAME) AS found, 1 AS expected
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME   = 'notifications'
   AND INDEX_NAME   = 'idx_notifications_status_scheduled_at';

SELECT 'orders.contact_email' AS check_name, COUNT(*) AS found, 1 AS expected
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME   = 'orders'
   AND COLUMN_NAME  = 'contact_email';

SELECT 'payment_reminder_minutes seeded' AS check_name, COUNT(*) AS found, 1 AS expected
  FROM `site_settings`
 WHERE `setting_key` = 'payment_reminder_minutes';

SELECT 'reminder and receipt templates' AS check_name, COUNT(*) AS found, 2 AS expected
  FROM `notification_templates`
 WHERE `template_key` IN ('order_payment_pending', 'payment_confirmed')
   AND `is_active` = 1;

SELECT 'orders with an account still have an email' AS check_name, COUNT(*) AS found, 0 AS expected
  FROM `orders` o
  JOIN `users` u ON u.id = o.user_id
 WHERE o.contact_email IS NULL
   AND u.email IS NOT NULL
   AND u.email <> '';

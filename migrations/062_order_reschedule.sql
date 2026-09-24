-- =============================================================================
-- 062_order_reschedule.sql
-- OK Veggies. Reschedule delivery date: append-only history, max changes
-- setting, permission, and notification templates.
--
-- A customer or staff can move an order's preferred_delivery_date to another
-- eligible delivery day. The move is recorded in order_reschedules, an
-- append-only table, so an order's delivery story is never lost. The setting
-- reschedule_max_changes caps how many times an order may be moved, and
-- reschedule_customer_allowed lets the Owner switch off self-service while
-- keeping the staff path.
--
-- Notifications:
--   order_rescheduled          customer: your delivery moved
--   admin_order_rescheduled    staff: an order was moved
--
-- Idempotent and MySQL 8 compatible:
--   - CREATE TABLE IF NOT EXISTS for the history table, guarded index check
--     for any additional index (same pattern as 017, 019)
--   - site_settings rows use INSERT ... ON DUPLICATE KEY UPDATE setting_key =
--     setting_key so a re-run never clobbers an Owner's choice (same as 017, 021)
--   - permissions rows use INSERT IGNORE
--   - role_permissions uses INSERT IGNORE SELECT
--   - notification_templates use INSERT ... ON DUPLICATE KEY UPDATE
-- Data only in transaction; DDL outside as MySQL 8 cannot roll it back.
-- See docs/PRD.md Section 9 delivery reschedule extension.
-- =============================================================================

-- ----------------------------------------------------------------------------
-- 1. History table
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `order_reschedules` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `old_delivery_date` DATE NOT NULL,
  `new_delivery_date` DATE NOT NULL,
  `actor_type` ENUM('customer','staff') NOT NULL,
  `actor_id` BIGINT UNSIGNED NULL,
  `reason` VARCHAR(500) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_order_reschedules_order_id` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_order_reschedules_actor_id` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  INDEX `idx_order_reschedules_order_id` (`order_id`),
  INDEX `idx_order_reschedules_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2. Settings
-- ----------------------------------------------------------------------------
START TRANSACTION;

INSERT INTO `site_settings` (`setting_key`, `setting_value`, `value_type`, `is_public`) VALUES
  ('reschedule_customer_allowed', 'true', 'bool', 0),
  ('reschedule_max_changes',      '2',    'int',  0)
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

-- ----------------------------------------------------------------------------
-- 3. Permission
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `permissions` (`key`, `module`, `description`) VALUES
  ('orders.reschedule', 'orders', 'Reschedule an order delivery date');

INSERT IGNORE INTO `role_permissions` (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
 WHERE r.name IN ('owner', 'manager') AND p.`key` = 'orders.reschedule';

-- ----------------------------------------------------------------------------
-- 4. Notification templates
-- ----------------------------------------------------------------------------
INSERT INTO `notification_templates` (template_key, channel, subject_template, body_template, is_active) VALUES
  ('order_rescheduled', 'email',
   'Your delivery for {{order_number}} is now {{new_delivery_day}}',
   'Hi {{customer_name}}, your delivery for order {{order_number}} has moved.\n\nFrom {{old_delivery_day}} to {{new_delivery_day}}.\n\n{{reschedule_source}}\n\nFollow it here any time: {{order_trail_url}}\n\nIf this is not what you wanted, reply to this email or send us a message on WhatsApp and we will sort it out.',
   TRUE),
  ('admin_order_rescheduled', 'email',
   'Order {{order_number}} from {{customer_name}} rescheduled to {{new_delivery_day}}',
   'Order {{order_number}} from {{customer_name}} has been rescheduled.\n\nFrom {{old_delivery_day}} to {{new_delivery_day}}.\n\n{{reschedule_source}}\n{{reason}}\n\nOpen it in the admin panel: {{admin_url}}',
   TRUE)
ON DUPLICATE KEY UPDATE
  subject_template = VALUES(subject_template),
  body_template    = VALUES(body_template),
  is_active        = VALUES(is_active);

COMMIT;

-- Verification:
--   SELECT COUNT(*) FROM order_reschedules;
--   Expect 0 on fresh install, table exists.
--
--   SELECT setting_key, setting_value, value_type FROM site_settings
--    WHERE setting_key IN ('reschedule_customer_allowed','reschedule_max_changes')
--    ORDER BY setting_key;
--   Expect 2 rows, bool true and int 2 on fresh install.
--
--   SELECT `key` FROM permissions WHERE `key` = 'orders.reschedule';
--   Expect 1 row.
--
--   SELECT r.name FROM roles r JOIN role_permissions rp ON rp.role_id = r.id
--     JOIN permissions p ON p.id = rp.permission_id
--    WHERE p.`key` = 'orders.reschedule' ORDER BY r.name;
--   Expect owner, manager.
--
--   SELECT template_key, is_active FROM notification_templates
--    WHERE template_key IN ('order_rescheduled','admin_order_rescheduled')
--    ORDER BY template_key;
--   Expect 2 rows, both is_active = 1.

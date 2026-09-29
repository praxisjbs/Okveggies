-- =============================================================================
-- 064_order_source_override_permission.sql
-- OK Veggies. Register the orders.source.override permission and grant it to the
-- Owner role.
--
-- The sourcing gate refuses to move an order from Placed to Sourced until it is
-- covered by a payment, a deposit or a credit charge. The Owner may source an
-- order the gate would refuse, with a written reason that lands in the audit log
-- and the order history. That decision belongs to the Owner alone, next to
-- payments.refund, so it is its own key and Manager does not get it.
--
-- Idempotent. One migration, one concern.
-- =============================================================================

START TRANSACTION;

INSERT INTO permissions (`key`, `module`, `description`) VALUES
  ('orders.source.override', 'orders', 'Source an order the payment gate would refuse, with a reason')
ON DUPLICATE KEY UPDATE `module` = VALUES(`module`), `description` = VALUES(`description`);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.name = 'owner'
  AND p.`key` = 'orders.source.override';

-- Verification: the key exists once, and the Owner role holds it.
SELECT COUNT(*) AS must_be_1 FROM permissions WHERE `key` = 'orders.source.override';
SELECT COUNT(*) AS owner_grants FROM role_permissions rp
JOIN roles r ON r.id = rp.role_id
JOIN permissions p ON p.id = rp.permission_id
WHERE r.name = 'owner' AND p.`key` = 'orders.source.override';

COMMIT;

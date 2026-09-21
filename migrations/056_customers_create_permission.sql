-- =============================================================================
-- 056_customers_create_permission.sql
-- OK Veggies. Register the customers.create permission and grant it to the
-- Owner role.
--
-- The api/v1/customers.php create action (the light account a first-time
-- caller needs) already gates on customers.create, but the key was never
-- registered in the catalogue or granted to a role, so only the Owner
-- wildcard could reach it. The Customers screen now carries a New customer
-- panel behind this same key. Manager does not get it: creating an account
-- is an Owner decision, next to users.create.
--
-- Idempotent. One migration, one concern.
-- =============================================================================

START TRANSACTION;

INSERT INTO permissions (`key`, `module`, `description`) VALUES
  ('customers.create', 'customers', 'Create a customer account')
ON DUPLICATE KEY UPDATE `module` = VALUES(`module`), `description` = VALUES(`description`);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.name = 'owner'
  AND p.`key` = 'customers.create';

-- Verification: the key exists once, and the Owner role holds it.
SELECT COUNT(*) AS must_be_1 FROM permissions WHERE `key` = 'customers.create';
SELECT COUNT(*) AS owner_grants FROM role_permissions rp
JOIN roles r ON r.id = rp.role_id
JOIN permissions p ON p.id = rp.permission_id
WHERE r.name = 'owner' AND p.`key` = 'customers.create';

COMMIT;

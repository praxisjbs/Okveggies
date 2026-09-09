-- =============================================================================
-- 027_manual_operations_permissions.sql
-- OK Veggies. Three permissions the admin panel needed and never had, because
-- until now nothing in the back office could start a piece of work. It could
-- only move along work a customer had started on the storefront.
--
--   orders.create        Take an order over the phone. Somebody rings, a
--                        colleague builds the order for them and the customer
--                        never touches a screen.
--   kitchen_runs.create  Type in a list that arrived by WhatsApp or on a call,
--                        against the customer it came from, so it enters the
--                        ordinary quote, approve, convert path.
--   customers.create     Make the light account those two need when the caller
--                        has never bought from us before.
--
-- Both launch roles get all three. Taking an order on the phone is the Manager's
-- daily work (PRD 17.1: sales, operations and delivery), so withholding it would
-- leave the person who answers the phone unable to answer it.
--
-- Idempotent: INSERT ... ON DUPLICATE KEY UPDATE on the unique permission key,
-- and INSERT IGNORE on the role grants, so re-applying this file is a no-op.
-- See docs/PRD.md Sections 8, 9, 13 and 17.
-- =============================================================================

START TRANSACTION;

INSERT INTO permissions (`key`, `module`, `description`) VALUES
  ('orders.create',       'orders',       'Create an order by hand, for a phone order'),
  ('kitchen_runs.create', 'kitchen_runs', 'Start a kitchen run on a customer''s behalf'),
  ('customers.create',    'customers',    'Add a customer account')
ON DUPLICATE KEY UPDATE `module` = VALUES(`module`), `description` = VALUES(`description`);

-- Owner has everything, and Manager runs the day to day, so both get all three.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r CROSS JOIN permissions p
WHERE r.name IN ('owner', 'manager')
  AND p.`key` IN ('orders.create', 'kitchen_runs.create', 'customers.create');

COMMIT;

-- Verification:
--   SELECT `key` FROM permissions
--    WHERE `key` IN ('orders.create','kitchen_runs.create','customers.create');   -- 3 rows
--   SELECT r.name, COUNT(*) FROM roles r
--     JOIN role_permissions rp ON rp.role_id = r.id
--     JOIN permissions p ON p.id = rp.permission_id
--    WHERE p.`key` IN ('orders.create','kitchen_runs.create','customers.create')
--    GROUP BY r.name;                                                             -- owner 3, manager 3

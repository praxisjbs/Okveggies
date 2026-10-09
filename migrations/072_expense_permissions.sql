-- =============================================================================
-- 072_expense_permissions.sql
-- OK Veggies. The two permissions the Expense module gates on:
--
--   expenses.view    See the expense list and its totals.
--   expenses.manage  Record an expense and void one.
--
-- Money out is sensitive, so both go to the two launch roles that run the
-- business day to day, Owner and Manager, and nobody else by default. The
-- reporting permission (reports.view) is seeded in its own PR (PR3) alongside
-- the dashboard it gates.
--
-- Idempotent: INSERT ... ON DUPLICATE KEY UPDATE on the unique permission key,
-- and INSERT IGNORE on the role grants, so re-applying this file is a no-op.
-- Follows the shape of 041_manual_operations_permissions.sql.
-- See docs/EXPENSES_AND_REPORTING_ENGINEERING_GUIDE.md and docs/PRD.md.
-- =============================================================================

START TRANSACTION;

INSERT INTO permissions (`key`, `module`, `description`) VALUES
  ('expenses.view',   'expenses', 'See the expense list and totals'),
  ('expenses.manage', 'expenses', 'Record an expense and void one')
ON DUPLICATE KEY UPDATE `module` = VALUES(`module`), `description` = VALUES(`description`);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r CROSS JOIN permissions p
WHERE r.name IN ('owner', 'manager')
  AND p.`key` IN ('expenses.view', 'expenses.manage');

COMMIT;

-- Verification:
--   SELECT `key` FROM permissions WHERE `key` IN ('expenses.view','expenses.manage'); -- 2 rows
--   SELECT r.name, COUNT(*) FROM roles r
--     JOIN role_permissions rp ON rp.role_id = r.id
--     JOIN permissions p ON p.id = rp.permission_id
--    WHERE p.`key` IN ('expenses.view','expenses.manage')
--    GROUP BY r.name;                                                                 -- owner 2, manager 2

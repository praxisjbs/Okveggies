-- =============================================================================
-- 073_reporting_permission.sql
-- OK Veggies. The permission the Reporting Dashboard gates on:
--
--   reports.view   See the financial dashboard: revenue, expenses, profit,
--                  outstanding, and the charts and tables built from them.
--
-- The dashboard shows the whole business's money, so it goes to the two launch
-- roles that run it, Owner and Manager, and nobody else by default.
--
-- Idempotent: INSERT ... ON DUPLICATE KEY UPDATE on the unique permission key,
-- and INSERT IGNORE on the role grants. Follows 072_expense_permissions.sql.
-- See docs/EXPENSES_AND_REPORTING_ENGINEERING_GUIDE.md.
-- =============================================================================

START TRANSACTION;

INSERT INTO permissions (`key`, `module`, `description`) VALUES
  ('reports.view', 'reports', 'See the financial dashboard')
ON DUPLICATE KEY UPDATE `module` = VALUES(`module`), `description` = VALUES(`description`);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r CROSS JOIN permissions p
WHERE r.name IN ('owner', 'manager')
  AND p.`key` = 'reports.view';

COMMIT;

-- Verification:
--   SELECT `key` FROM permissions WHERE `key` = 'reports.view';                  -- 1 row
--   SELECT r.name FROM roles r
--     JOIN role_permissions rp ON rp.role_id = r.id
--     JOIN permissions p ON p.id = rp.permission_id
--    WHERE p.`key` = 'reports.view';                                             -- owner, manager

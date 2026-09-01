-- =============================================================================
-- 011_users_password_changed_at.sql
-- OK Veggies. Add users.password_changed_at, the marker that lets a password
-- change (a staff reset, an owner setting a colleague's password, or a signed-in
-- change) sign every other open session for that person out. A session records
-- this value at login; the staff RBAC gate signs the session out when the stored
-- value has moved on. See includes/classes/Rbac.php and api/v1/auth.php.
--
-- Idempotent: the column is only added when it is not already present, so the
-- migration is safe to re-run. MySQL 8 has no ADD COLUMN IF NOT EXISTS, so we
-- guard it against information_schema and run the ALTER through a prepared
-- statement. DDL cannot be rolled back, so this file keeps no explicit
-- transaction of its own. See docs/PRD.md Section 10.4.
-- =============================================================================

SET @col_exists := (
  SELECT COUNT(*)
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'users'
     AND COLUMN_NAME  = 'password_changed_at'
);

SET @ddl := IF(
  @col_exists = 0,
  'ALTER TABLE `users` ADD COLUMN `password_changed_at` DATETIME NULL DEFAULT NULL AFTER `last_login_at`',
  'DO 0'
);

PREPARE okv_add_pwd_changed FROM @ddl;
EXECUTE okv_add_pwd_changed;
DEALLOCATE PREPARE okv_add_pwd_changed;

-- Verification:
--   SELECT COLUMN_NAME FROM information_schema.COLUMNS
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
--      AND COLUMN_NAME = 'password_changed_at';

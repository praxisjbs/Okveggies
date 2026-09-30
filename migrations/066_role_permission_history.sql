-- =============================================================================
-- 066_role_permission_history.sql
-- OK Veggies. The Permissions module keeps a restorable snapshot of a role's
-- permission set every time the Owner changes it.
--
-- `role_permissions` stays the live set, and stays the only thing the guard
-- reads on a request. This new table is the trail behind it: what the set was,
-- who moved it, and exactly which keys went on or off. The Owner can put a role
-- back the way it was from here, which is the reason it exists. A permission
-- change is the one edit that can lock a colleague out of their own job, and
-- "what did it look like before?" is not a question the live junction table can
-- answer.
--
-- Append-only, like every other history table (CLAUDE.md). Nothing updates or
-- deletes a row here.
--
-- DDL. MySQL 8 commits implicitly around it, so there is no transaction to wrap
-- a CREATE TABLE in. CREATE TABLE IF NOT EXISTS is the idempotent guard.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `role_permission_versions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `role_id` BIGINT UNSIGNED NOT NULL,
  `actor_user_id` BIGINT UNSIGNED NULL,
  `permissions` JSON NOT NULL,
  `permission_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `added` JSON NULL,
  `removed` JSON NULL,
  `action` VARCHAR(60) NOT NULL DEFAULT 'rbac.permission.assign',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_rpv_role_id`
    FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_rpv_actor_user_id`
    FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  INDEX `idx_rpv_role_created` (`role_id`, `created_at`, `id`),
  INDEX `idx_rpv_actor_created` (`actor_user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verification: the table exists, with the three columns the restore path reads.
SELECT COUNT(*) AS table_exists
  FROM information_schema.tables
 WHERE table_schema = DATABASE()
   AND table_name = 'role_permission_versions';

SELECT COUNT(*) AS expected_columns
  FROM information_schema.columns
 WHERE table_schema = DATABASE()
   AND table_name = 'role_permission_versions'
   AND column_name IN ('role_id', 'permissions', 'added', 'removed');

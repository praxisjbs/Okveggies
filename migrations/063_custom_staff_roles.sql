-- 063_custom_staff_roles.sql
-- Custom roles need lifecycle state and a stable slug. Existing role names remain
-- unique for backwards compatibility. This migration is idempotent on MySQL 8.
START TRANSACTION;

SET @has_status := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'roles' AND column_name = 'status');
SET @sql := IF(@has_status = 0, 'ALTER TABLE roles ADD COLUMN status ENUM(''active'',''disabled'') NOT NULL DEFAULT ''active'' AFTER description', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_slug := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'roles' AND column_name = 'slug');
SET @sql := IF(@has_slug = 0, 'ALTER TABLE roles ADD COLUMN slug VARCHAR(80) NULL AFTER name', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE roles SET slug = LOWER(TRIM(BOTH '-' FROM REGEXP_REPLACE(name, '[^a-zA-Z0-9]+', '-'))) WHERE slug IS NULL OR slug = '';
SET @has_unique_slug := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'roles' AND index_name = 'uq_roles_slug');
SET @sql := IF(@has_unique_slug = 0, 'ALTER TABLE roles ADD UNIQUE KEY uq_roles_slug (slug)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

COMMIT;
-- Verification: SELECT name, slug, status FROM roles ORDER BY name;

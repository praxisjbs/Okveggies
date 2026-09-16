-- =============================================================================
-- 049_m12_content_pages.sql
-- OK Veggies. M12 draft and published content storage.
--
-- Migration 001 shipped the fixed content_pages table and migration 006 seeded
-- the first 6 rows. Neither is changed. This additive migration gives M12 a
-- separate working draft, published SEO, the fixed homepage data, documentary
-- image metadata and publication provenance.
--
-- MySQL 8 has no ADD COLUMN IF NOT EXISTS. Each DDL statement is guarded
-- against information_schema and run through a prepared statement. DDL commits
-- implicitly in MySQL, so the data backfill and seed are the transactional part.
-- =============================================================================

-- Column: content_pages.draft_title
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'content_pages' AND COLUMN_NAME = 'draft_title'
);
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE `content_pages` ADD COLUMN `draft_title` VARCHAR(200) NULL AFTER `title`',
  'DO 0');
PREPARE okv_049_c01 FROM @ddl;
EXECUTE okv_049_c01;
DEALLOCATE PREPARE okv_049_c01;

-- Column: content_pages.draft_body
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'content_pages' AND COLUMN_NAME = 'draft_body'
);
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE `content_pages` ADD COLUMN `draft_body` MEDIUMTEXT NULL AFTER `body`',
  'DO 0');
PREPARE okv_049_c02 FROM @ddl;
EXECUTE okv_049_c02;
DEALLOCATE PREPARE okv_049_c02;

-- Column: content_pages.draft_meta_title
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'content_pages' AND COLUMN_NAME = 'draft_meta_title'
);
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE `content_pages` ADD COLUMN `draft_meta_title` VARCHAR(120) NULL AFTER `draft_body`',
  'DO 0');
PREPARE okv_049_c03 FROM @ddl;
EXECUTE okv_049_c03;
DEALLOCATE PREPARE okv_049_c03;

-- Column: content_pages.draft_meta_description
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'content_pages' AND COLUMN_NAME = 'draft_meta_description'
);
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE `content_pages` ADD COLUMN `draft_meta_description` VARCHAR(320) NULL AFTER `draft_meta_title`',
  'DO 0');
PREPARE okv_049_c04 FROM @ddl;
EXECUTE okv_049_c04;
DEALLOCATE PREPARE okv_049_c04;

-- Column: content_pages.meta_title
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'content_pages' AND COLUMN_NAME = 'meta_title'
);
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE `content_pages` ADD COLUMN `meta_title` VARCHAR(120) NULL AFTER `draft_meta_description`',
  'DO 0');
PREPARE okv_049_c05 FROM @ddl;
EXECUTE okv_049_c05;
DEALLOCATE PREPARE okv_049_c05;

-- Column: content_pages.meta_description
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'content_pages' AND COLUMN_NAME = 'meta_description'
);
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE `content_pages` ADD COLUMN `meta_description` VARCHAR(320) NULL AFTER `meta_title`',
  'DO 0');
PREPARE okv_049_c06 FROM @ddl;
EXECUTE okv_049_c06;
DEALLOCATE PREPARE okv_049_c06;

-- Column: content_pages.draft_content_data
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'content_pages' AND COLUMN_NAME = 'draft_content_data'
);
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE `content_pages` ADD COLUMN `draft_content_data` JSON NULL AFTER `meta_description`',
  'DO 0');
PREPARE okv_049_c07 FROM @ddl;
EXECUTE okv_049_c07;
DEALLOCATE PREPARE okv_049_c07;

-- Column: content_pages.content_data
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'content_pages' AND COLUMN_NAME = 'content_data'
);
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE `content_pages` ADD COLUMN `content_data` JSON NULL AFTER `draft_content_data`',
  'DO 0');
PREPARE okv_049_c08 FROM @ddl;
EXECUTE okv_049_c08;
DEALLOCATE PREPARE okv_049_c08;

-- Column: content_pages.draft_image_url
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'content_pages' AND COLUMN_NAME = 'draft_image_url'
);
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE `content_pages` ADD COLUMN `draft_image_url` VARCHAR(500) NULL AFTER `content_data`',
  'DO 0');
PREPARE okv_049_c09 FROM @ddl;
EXECUTE okv_049_c09;
DEALLOCATE PREPARE okv_049_c09;

-- Column: content_pages.draft_image_alt
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'content_pages' AND COLUMN_NAME = 'draft_image_alt'
);
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE `content_pages` ADD COLUMN `draft_image_alt` VARCHAR(255) NULL AFTER `draft_image_url`',
  'DO 0');
PREPARE okv_049_c10 FROM @ddl;
EXECUTE okv_049_c10;
DEALLOCATE PREPARE okv_049_c10;

-- Column: content_pages.image_url
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'content_pages' AND COLUMN_NAME = 'image_url'
);
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE `content_pages` ADD COLUMN `image_url` VARCHAR(500) NULL AFTER `draft_image_alt`',
  'DO 0');
PREPARE okv_049_c11 FROM @ddl;
EXECUTE okv_049_c11;
DEALLOCATE PREPARE okv_049_c11;

-- Column: content_pages.image_alt
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'content_pages' AND COLUMN_NAME = 'image_alt'
);
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE `content_pages` ADD COLUMN `image_alt` VARCHAR(255) NULL AFTER `image_url`',
  'DO 0');
PREPARE okv_049_c12 FROM @ddl;
EXECUTE okv_049_c12;
DEALLOCATE PREPARE okv_049_c12;

-- Column: content_pages.published_at
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'content_pages' AND COLUMN_NAME = 'published_at'
);
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE `content_pages` ADD COLUMN `published_at` DATETIME NULL AFTER `is_published`',
  'DO 0');
PREPARE okv_049_c13 FROM @ddl;
EXECUTE okv_049_c13;
DEALLOCATE PREPARE okv_049_c13;

-- Column: content_pages.published_by
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'content_pages' AND COLUMN_NAME = 'published_by'
);
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE `content_pages` ADD COLUMN `published_by` BIGINT UNSIGNED NULL AFTER `published_at`',
  'DO 0');
PREPARE okv_049_c14 FROM @ddl;
EXECUTE okv_049_c14;
DEALLOCATE PREPARE okv_049_c14;

-- Foreign key: content_pages.published_by -> users.id
SET @fk_exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
   WHERE CONSTRAINT_SCHEMA = DATABASE()
     AND TABLE_NAME = 'content_pages'
     AND CONSTRAINT_NAME = 'fk_content_pages_published_by'
     AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @ddl := IF(@fk_exists = 0,
  'ALTER TABLE `content_pages` ADD CONSTRAINT `fk_content_pages_published_by` FOREIGN KEY (`published_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE',
  'DO 0');
PREPARE okv_049_fk01 FROM @ddl;
EXECUTE okv_049_fk01;
DEALLOCATE PREPARE okv_049_fk01;

START TRANSACTION;

-- Existing public values become the first working draft. Re-running keeps a
-- newer draft because only null draft fields are backfilled.
UPDATE content_pages
   SET draft_title = COALESCE(draft_title, title),
       draft_body = COALESCE(draft_body, body),
       published_at = CASE
         WHEN is_published = TRUE AND published_at IS NULL THEN updated_at
         ELSE published_at
       END,
       published_by = CASE
         WHEN is_published = TRUE AND published_by IS NULL THEN updated_by
         ELSE published_by
       END;

-- The fixed homepage row. Catalogue records remain outside this JSON. The
-- insert is deliberately a no-op when the row already exists.
INSERT INTO content_pages
  (slug, title, draft_title, body, draft_body, is_published,
   draft_content_data, content_data, published_at)
VALUES
  ('home',
   'Fresh from farms we can name',
   'Fresh from farms we can name',
   'Fresh produce from farms we have checked ourselves in Ogun State and Jos. Weighed right, and brought on the day you pick.',
   'Fresh produce from farms we have checked ourselves in Ogun State and Jos. Weighed right, and brought on the day you pick.',
   TRUE,
   JSON_OBJECT(
     'hero_eyebrow', 'Est. 2026. Lagos',
     'hero_heading', 'We are bringing the other half home.',
     'hero_intro', 'Fresh produce from farms we have checked ourselves in Ogun State and Jos. Weighed right, and brought on the day you pick.',
     'primary_cta_label', 'Start shopping',
     'primary_cta_path', '/shop.php',
     'secondary_cta_label', 'See the combos',
     'secondary_cta_path', '/combos.php',
     'promise_heading', 'Sourced right. Priced right. Delivered right.',
     'promise_body', 'Farms we have visited. Prices we can explain. A delivery day you picked.',
     'combos_eyebrow', 'Cooked together, priced together',
     'combos_heading', 'This week''s combos',
     'categories_eyebrow', 'Five aisles, one stall',
     'categories_heading', 'Shop by category',
     'products_eyebrow', 'Picked this week',
     'products_heading', 'This week''s picks'
   ),
   JSON_OBJECT(
     'hero_eyebrow', 'Est. 2026. Lagos',
     'hero_heading', 'We are bringing the other half home.',
     'hero_intro', 'Fresh produce from farms we have checked ourselves in Ogun State and Jos. Weighed right, and brought on the day you pick.',
     'primary_cta_label', 'Start shopping',
     'primary_cta_path', '/shop.php',
     'secondary_cta_label', 'See the combos',
     'secondary_cta_path', '/combos.php',
     'promise_heading', 'Sourced right. Priced right. Delivered right.',
     'promise_body', 'Farms we have visited. Prices we can explain. A delivery day you picked.',
     'combos_eyebrow', 'Cooked together, priced together',
     'combos_heading', 'This week''s combos',
     'categories_eyebrow', 'Five aisles, one stall',
     'categories_heading', 'Shop by category',
     'products_eyebrow', 'Picked this week',
     'products_heading', 'This week''s picks'
   ),
   CURRENT_TIMESTAMP)
ON DUPLICATE KEY UPDATE slug = VALUES(slug);

-- The shipped legal words explicitly say they are placeholders. They remain
-- available as drafts but cannot be reached publicly until approved copy is
-- explicitly published through M12.
UPDATE content_pages
   SET is_published = FALSE,
       published_at = NULL,
       published_by = NULL
 WHERE (slug = 'terms' AND body = 'These terms cover how you use the OK Veggies website and place orders. This is placeholder copy to be replaced with the reviewed terms before launch.')
    OR (slug = 'privacy' AND body = 'How we handle your details. We collect only what we need to take your order and deliver it. This is placeholder copy to be replaced with the reviewed policy before launch.')
    OR (slug = 'delivery-policy' AND body = 'Households get Monday, Wednesday, Thursday and Saturday. Restaurant and mart supply runs on Tuesday and Friday. The delivery fee is arranged and settled on delivery. This is placeholder copy to be finalised before launch.');

COMMIT;

-- Verification:
--   SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'content_pages'
--      AND COLUMN_NAME IN
--        ('draft_title','draft_body','draft_meta_title','draft_meta_description',
--         'meta_title','meta_description','draft_content_data','content_data',
--         'draft_image_url','draft_image_alt','image_url','image_alt',
--         'published_at','published_by');
--   Expect 14 rows.
--   SELECT slug, is_published FROM content_pages
--    WHERE slug IN ('home','terms','privacy','delivery-policy') ORDER BY slug;
--   Expect home = 1 and any unchanged legal placeholders = 0. Approved legal
--   copy published after the first run remains unchanged on a forced rerun.

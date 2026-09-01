-- =============================================================================
-- 009_source_day_setting.sql
-- OK Veggies. Adds the sourcing day to site settings so the trust line can read
-- "Sourced Tuesday from Ogun State, Jos" on the product card, the product page
-- and the combo, which is the pattern the brand bible names in 6.3 and the PRD
-- repeats in Sections 4.1 and 14.2. The regions half already exists as
-- source_regions; this is the day half.
--
-- The day is a plain string, editable in the admin Settings screen alongside
-- source_regions. Left blank, the storefront falls back to "Sourced this week
-- from ...", so a site that has not set a day still reads as a whole sentence.
--
-- Idempotent: a re-run never overwrites a day the admin has since changed, it
-- only makes sure the type and visibility metadata are right.
-- =============================================================================

START TRANSACTION;

INSERT INTO site_settings (setting_key, setting_value, value_type, is_public) VALUES
  ('source_day', 'Tuesday', 'string', TRUE)
ON DUPLICATE KEY UPDATE value_type = VALUES(value_type), is_public = VALUES(is_public);

COMMIT;

-- Verification:
--   SELECT setting_key, setting_value, value_type, is_public
--     FROM site_settings WHERE setting_key IN ('source_day', 'source_regions');
--   -- expect two rows: source_day 'Tuesday' and source_regions 'Ogun State, Jos'

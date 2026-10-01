-- =============================================================================
-- 070_homepage_hero_wording_in_dashboard.sql
-- OK Veggies. The homepage hero reads from the dashboard.
--
-- Until now the hero heading and introduction were written into index.php and
-- the dashboard's copies were ignored, so what the Owner typed into Content and
-- Messages never reached the page. The page now reads the dashboard for every
-- word. The dashboard still held the very first seed text, which is not what
-- the client approved, so reading from it would have flipped the live hero back
-- to the old wording.
--
-- The client's rule: the copy that must stand is what the Owner edits in the
-- dashboard. So this changes only values that are still exactly the original
-- seed text, which nobody has ever edited, to the wording the client signed off.
-- Anything the Owner has already written is left alone. Both the published copy
-- and the draft are updated, so the editor opens on the approved wording and the
-- next publish does not undo it.
--
--   hero_heading  We are bringing the other half home.
--             to  Bringing the Best of the Farm Straight to Your Kitchen.
--   hero_intro    Freshness You Can Trust. From Farm to Your Kitchen.
--             to  Freshness You Can Trust. Sourced daily from local farms,
--                 carefully selected, and delivered perfectly to you.
--
-- Idempotent: after one run no row still holds the old text, so a second run
-- changes nothing. This is a data change to existing rows, so there is no DDL.
-- =============================================================================

START TRANSACTION;

UPDATE `content_pages`
   SET `content_data` = JSON_SET(`content_data`, '$.hero_heading', 'Bringing the Best of the Farm Straight to Your Kitchen.')
 WHERE `slug` = 'home'
   AND JSON_UNQUOTE(JSON_EXTRACT(`content_data`, '$.hero_heading')) = 'We are bringing the other half home.';

UPDATE `content_pages`
   SET `draft_content_data` = JSON_SET(`draft_content_data`, '$.hero_heading', 'Bringing the Best of the Farm Straight to Your Kitchen.')
 WHERE `slug` = 'home'
   AND JSON_UNQUOTE(JSON_EXTRACT(`draft_content_data`, '$.hero_heading')) = 'We are bringing the other half home.';

UPDATE `content_pages`
   SET `content_data` = JSON_SET(`content_data`, '$.hero_intro', 'Freshness You Can Trust. Sourced daily from local farms, carefully selected, and delivered perfectly to you.')
 WHERE `slug` = 'home'
   AND JSON_UNQUOTE(JSON_EXTRACT(`content_data`, '$.hero_intro')) = 'Freshness You Can Trust. From Farm to Your Kitchen.';

UPDATE `content_pages`
   SET `draft_content_data` = JSON_SET(`draft_content_data`, '$.hero_intro', 'Freshness You Can Trust. Sourced daily from local farms, carefully selected, and delivered perfectly to you.')
 WHERE `slug` = 'home'
   AND JSON_UNQUOTE(JSON_EXTRACT(`draft_content_data`, '$.hero_intro')) = 'Freshness You Can Trust. From Farm to Your Kitchen.';

COMMIT;

-- Verification:
--   SELECT JSON_UNQUOTE(JSON_EXTRACT(content_data, '$.hero_heading')) AS published_heading,
--          JSON_UNQUOTE(JSON_EXTRACT(draft_content_data, '$.hero_heading')) AS draft_heading
--     FROM content_pages WHERE slug = 'home';
--   -- Both read "Bringing the Best of the Farm Straight to Your Kitchen." unless the Owner changed them.
--   SELECT COUNT(*) FROM content_pages
--    WHERE slug = 'home'
--      AND (JSON_UNQUOTE(JSON_EXTRACT(content_data, '$.hero_heading')) = 'We are bringing the other half home.'
--        OR JSON_UNQUOTE(JSON_EXTRACT(draft_content_data, '$.hero_heading')) = 'We are bringing the other half home.');   -- 0

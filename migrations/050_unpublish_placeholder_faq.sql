-- =============================================================================
-- 050_unpublish_placeholder_faq.sql
-- M12 FAQ. The original seed is introductory prose, not the approved
-- question-and-answer structure. Withdraw only that untouched public snapshot.
-- A staff-edited or later published FAQ is never changed by this migration.
-- =============================================================================

START TRANSACTION;

UPDATE content_pages
   SET is_published = FALSE,
       published_at = NULL,
       published_by = NULL
 WHERE slug = 'faq'
   AND title = 'Questions and Answers'
   AND body = 'Answers to the questions we hear most, about pricing, delivery days, payment and returns. If your question is not here, tap the support button and reach us on WhatsApp.';

COMMIT;

-- Verification:
--   SELECT slug, is_published, title FROM content_pages WHERE slug = 'faq';
--   Expect the untouched seed to be unpublished. Any staff-edited FAQ keeps
--   its existing publication state.

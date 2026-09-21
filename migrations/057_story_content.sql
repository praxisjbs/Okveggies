-- =============================================================================
-- 057_story_content.sql
-- OK Veggies. The full Our Story page, published.
--
-- The one-liner seeded in 006 left the story page reading as unfinished at
-- handover. This publishes the reviewed story from the brand architecture,
-- with the founder, Kumbish Emmanuel Putleh, in it. When the team shoots the
-- real operation, the photograph is published through the Content module and
-- overrides the fallback image in page.php.
--
-- House copy rules: no em dash, no banned jargon, Nigerian English, numerals
-- for counts. Idempotent, following the 006 seed pattern.
-- =============================================================================

START TRANSACTION;

INSERT INTO content_pages (slug, title, body, meta_title, meta_description, is_published) VALUES
  ('about', 'Our Story',
   'OK Veggies started the way most good things in Lagos do: with a phone number people could trust. Before there was a website, there was a number. You called, you told us what the pot needed, and the produce arrived on the day we agreed. The website simply makes it easier to do what we were already doing.\n\n## The name\n\nOK is the word you say when something is done right. Not extra, not decorated, not trying. Just right. We chose it because it is the standard we hold the business to: sourced right, priced right, delivered right. If a crate does not meet that standard, it does not leave the farm.\n\n## The farms\n\nWe do not buy from a middleman''s list. Our produce comes from farms we have visited in Ogun State and Jos. We know the people who grow it, and we know what the land gives in each season. Tomatoes when the tomatoes are good. Peppers and okra in their weeks. The catalogue moves because the market moves, and we say so when it does.\n\nWhen a farm once gives us a crate that is not what it should be, we talk to the farmer first and leave them later. That is slower than buying fast, and it is the reason we can stand behind what we sell.\n\n## The price\n\nEvery item is weighed at the source and sold by the kilogramme, the bunch or the bag, with the unit on the label. The price you see is the price we can explain: what the crate cost, what the road cost, and what is left for the work. When a price moves, we say why. We do not run discounts to hide a cost, and we do not mark a product fresh when it is not.\n\n## The delivery day\n\nHouseholds choose their delivery day from the days we run, and we source that morning. Your basket is put together from what is on the stall that week, not from a photograph of last week''s stall. A combo works the same way: a ready basket priced below buying it piece by piece, weighed and packed before it comes to your door.\n\n## The person behind it\n\nOK Veggies is led by its founder, Kumbish Emmanuel Putleh. He started it to do the simplest thing in food retail: sell what he has checked, at a price he can explain, on the day the customer asked for. He walks the farms, weighs at the market, and answers the phone when the system is quiet. Make It Right is his rule, not a policy line: if something in your order is wrong, you report it within 7 days, and we refund, credit or replace it.\n\n## What we are not\n\nWe are not a supermarket, and we do not want to be. A short catalogue that is fresh beats a long one that is not. We do not use stock photographs, we do not promise a farm we have not visited, and when a category is still being sourced we mark it Being sourced instead of hiding it.\n\nSourced right. Priced right. Delivered right. That is the whole pitch. The basket is open.',
   'Our Story',
   'How OK Veggies sources from farms we have visited in Ogun State and Jos, prices it so we can explain it, and delivers on the day you picked.',
   TRUE)
ON DUPLICATE KEY UPDATE
  title = VALUES(title),
  body = VALUES(body),
  meta_title = VALUES(meta_title),
  meta_description = VALUES(meta_description),
  is_published = VALUES(is_published);

-- Verification: the published story is in place and carries real length.
SELECT COUNT(*) AS must_be_1
  FROM content_pages
 WHERE slug = 'about'
   AND is_published = 1
   AND CHAR_LENGTH(body) > 2000;

COMMIT;

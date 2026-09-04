-- =============================================================================
-- 021_cancellation_after_dispatch.sql
-- OK Veggies. The terms that apply when an order is cancelled after it has
-- already left on the van.
--
-- A dispatched order can still be cancelled: a customer who has changed their
-- mind at the door is a customer we want back, and refusing is a fight nobody
-- wins. But by then the produce has been bought from a farmer and a van run has
-- been paid for, so the terms are not the same as a cancellation the day
-- before. Two settings, because these are money rules and money rules on this
-- platform are editable by the Owner, not buried in PHP.
--
--   cancellation_after_dispatch_allowed
--     On, staff may cancel an order that is already dispatched. Off, a
--     dispatched order can only be refused at the door and settled by hand.
--
--   cancellation_dispatched_forfeit_deposit
--     On, the deposit is kept when the cancellation comes after dispatch,
--     whatever the clock says. This is deliberately separate from
--     cancellation_deposit_forfeit_after_cutoff, which is about the cutoff
--     hour: a business can choose to be generous about the clock and still not
--     absorb the cost of produce already on the road. Off, the usual cutoff
--     rule applies and nothing extra is kept for the dispatch itself.
--
-- Idempotent: INSERT ... ON DUPLICATE KEY UPDATE on the unique setting_key,
-- leaving an existing value alone so a re-run never overwrites an Owner's
-- choice. Wrapped in a transaction because it is data, not DDL.
-- See docs/PRD.md Sections 9 and 11, and docs/M6_GUIDE.md Section 9 question 1.
-- =============================================================================

START TRANSACTION;

INSERT INTO site_settings (setting_key, setting_value, value_type, is_public) VALUES
  ('cancellation_after_dispatch_allowed',      'true', 'bool', 0),
  ('cancellation_dispatched_forfeit_deposit',  'true', 'bool', 0)
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

COMMIT;

-- Verification:
--   SELECT setting_key, setting_value, value_type FROM site_settings
--    WHERE setting_key IN ('cancellation_after_dispatch_allowed',
--                          'cancellation_dispatched_forfeit_deposit')
--    ORDER BY setting_key;
--   Expect 2 rows, both bool, both true on a fresh install.
--
--   SELECT COUNT(*) AS order_tab_money_rules FROM site_settings
--    WHERE setting_key LIKE 'cancellation%';
--   Expect 5: the cutoff, the cutoff forfeit rule, customer self service, and
--   the two added here.

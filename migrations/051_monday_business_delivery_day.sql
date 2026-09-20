-- =============================================================================
-- 051_monday_business_delivery_day.sql
-- OK Veggies. Opens Monday as a business delivery day.
--
-- The 3 September meeting recorded, as an aligned decision, that Monday is
-- available to businesses as well as households
-- (docs/CLIENT_MEETING_AUDIT_AND_IMPROVEMENTS.md, decision D3 and Section 3
-- item 3). Migration 003 seeded businesses on Tuesday and Friday only, and no
-- later migration carried the change, so fresh environments and the live
-- Delivery screen disagreed with the decision. 003 now seeds the row directly;
-- this migration brings databases seeded before that correction up to the same
-- state.
--
-- The row is inserted when absent and activated when present, but a cutoff time
-- or lead time an admin has already set on that row is never overwritten. This
-- migration carries the yes-or-no decision, not the operating hours. The admin
-- Delivery screen stays the place those hours are managed.
--
-- Idempotent: a re-run ends in the same state, Monday active for businesses.
-- =============================================================================

START TRANSACTION;

INSERT INTO allowed_delivery_days (customer_type, day_of_week, is_active, cutoff_time, minimum_lead_days)
VALUES ('business', 1, TRUE, '16:00:00', 1)
ON DUPLICATE KEY UPDATE is_active = TRUE;

COMMIT;

-- Verification:
--   SELECT day_of_week, is_active, cutoff_time, minimum_lead_days
--     FROM allowed_delivery_days WHERE customer_type = 'business' ORDER BY day_of_week;
--   Expect Monday (1), Tuesday (2) and Friday (5) all active. Monday carries the
--   seeded cutoff 16:00:00 and lead 1 when it was inserted here; a Monday row
--   that already existed keeps the hours an admin set.
--   SELECT day_of_week FROM allowed_delivery_days WHERE customer_type = 'household' AND is_active = 1;
--   Expect 1, 3, 4, 6. Households are untouched by this migration.

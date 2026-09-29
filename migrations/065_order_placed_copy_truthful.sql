-- =============================================================================
-- 065_order_placed_copy_truthful.sql
-- OK Veggies. The order confirmation email no longer says "we are sourcing it
-- now".
--
-- An order is sourced only once it is covered: by its payment, its deposit or
-- the customer's credit line (the sourcing gate, PR1 of the 23 Sep review). The
-- confirmation goes out the moment the order is placed, before that is true for
-- a deposit or pay on delivery order, so "we are sourcing it now" was a promise
-- the shop could not always keep.
--
-- Guarded: the row is rewritten only when it still holds the exact wording that
-- migration 010 seeded. If staff have edited the template in the dashboard, their
-- words stay. Idempotent: a second run finds nothing to change.
-- =============================================================================

START TRANSACTION;

UPDATE notification_templates
   SET body_template = 'Hi {{customer_name}}, thank you. We have your order {{order_number}}.\n\nWe start sourcing it as soon as it is covered, by your payment, your deposit or your credit line. You can follow it the whole way, from the market to your door. There is nothing to sign in to.\n\nWe will write to you again the moment it is on the way.'
 WHERE template_key = 'order_placed'
   AND channel = 'email'
   AND body_template = 'Hi {{customer_name}}, thank you. We have your order {{order_number}} and we are sourcing it now.\n\nYou can follow it the whole way, from the market to your door. There is nothing to sign in to.\n\nWe will write to you again the moment it is on the way.';

-- Verification: the template still exists, is active, and no longer promises
-- sourcing "now" unless staff wrote it that way themselves.
SELECT COUNT(*) AS must_be_1 FROM notification_templates
 WHERE template_key = 'order_placed' AND channel = 'email' AND is_active = 1;

COMMIT;

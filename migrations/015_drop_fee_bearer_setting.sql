-- =============================================================================
-- 015_drop_fee_bearer_setting.sql
-- OK Veggies. M5 payments, PR1.
--
-- Removes payment_fee_bearer, added one migration ago.
--
-- Who bears the Paystack fee is settled inside the Paystack dashboard, not by
-- anything this application sends. Paystack's `bearer` request parameter is
-- scoped to subaccount splits, so an OK Veggies setting could only ever have
-- recorded an intention while implying a control it does not have. A setting
-- that cannot do what its label promises is worse than no setting, so it goes,
-- and the admin Payment Settings screen carries a short guide pointing at the
-- Paystack dashboard instead.
--
-- The ledger never depended on it. An order is credited with requested_amount,
-- the price we asked for the goods, and the fee is recorded separately in
-- payment_transactions.provider_fee_subunit. That is correct whichever way the
-- fee is borne, so nothing about the money changes here.
--
-- Idempotent: deleting a row that is already gone affects zero rows. Settings
-- are configuration, not ledger, so a delete is appropriate; the append-only
-- rule covers orders, payments and credit records.
-- =============================================================================

START TRANSACTION;

DELETE FROM `site_settings` WHERE `setting_key` = 'payment_fee_bearer';

COMMIT;

-- Verification
SELECT 'payment_fee_bearer removed' AS check_name,
       COUNT(*) AS found, 0 AS expected
  FROM `site_settings`
 WHERE `setting_key` = 'payment_fee_bearer';

SELECT 'remaining payment settings' AS check_name,
       COUNT(*) AS found, 2 AS expected
  FROM `site_settings`
 WHERE `setting_key` IN ('payment_channels', 'payment_verify_sweep_minutes');

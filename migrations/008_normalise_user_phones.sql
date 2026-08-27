-- =============================================================================
-- 008_normalise_user_phones.sql
-- OK Veggies. Bring existing users.phone values into E.164 (+234XXXXXXXXXX) so
-- customer and staff sign in by phone match what registration now stores.
-- Idempotent: numbers already in +234 form match nothing here. Only well formed
-- Nigerian mobile numbers are touched; anything else is left as it is. See the
-- Phone helper (includes/classes/Phone.php) for the same rules in code.
-- =============================================================================

START TRANSACTION;

-- 0801... (11 digits, leading zero) -> +234801...
UPDATE users
   SET phone = CONCAT('+234', SUBSTRING(phone, 2))
 WHERE phone REGEXP '^0[789][0-9]{9}$';

-- 234801... (13 digits, no plus) -> +234801...
UPDATE users
   SET phone = CONCAT('+', phone)
 WHERE phone REGEXP '^234[789][0-9]{9}$';

COMMIT;

-- Verification:
--   SELECT COUNT(*) AS not_e164 FROM users WHERE phone NOT LIKE '+234%';

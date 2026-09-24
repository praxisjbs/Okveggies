-- =============================================================================
-- 058_canonical_user_identities.sql
-- OK Veggies. Bring every users.email and users.phone value onto the one
-- canonical form that registration, staff creation, customer creation, guest
-- checkout, profile edits and sign-in all share: email lower cased, phone in
-- E.164 (+234XXXXXXXXXX). Migration 008 fixed two strict phone patterns only;
-- numbers stored with spaces, dashes, a 00 prefix, or as a 10 digit national
-- form stayed behind, and those people could not sign in by phone.
--
-- The phone rules here mirror includes/classes/Phone.php exactly. A stored
-- value that does not parse as a Nigerian mobile number is left as it is and
-- reported, never guessed at.
--
-- One person is one identity. When two rows canonicalise to the same email or
-- phone, no SQL can know whether that is one person with two accounts or two
-- people sharing a phone, so this migration refuses to finish: it writes every
-- colliding row to user_identity_conflicts, canonicalises only the rows that
-- are safe, and fails the run. Resolve each reported pair (merge the rows or
-- correct the wrong detail), then run migrations again; the file is idempotent
-- and completes once the report is clear. See PROGRESS.md for the repair run
-- book. MySQL 8. Never edit a shipped migration; this is a new one.
-- =============================================================================

-- The durable conflict report. DDL, so it commits in its own right.
CREATE TABLE IF NOT EXISTS `user_identity_conflicts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kind` VARCHAR(20) NOT NULL,
  `canonical_value` VARCHAR(255) NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `stored_value` VARCHAR(255) NOT NULL,
  `user_type` VARCHAR(30) NOT NULL,
  `status` VARCHAR(30) NOT NULL,
  `detected_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_identity_conflicts_entry` (`kind`, `canonical_value`, `user_id`),
  KEY `ix_user_identity_conflicts_user_id` (`user_id`),
  CONSTRAINT `fk_user_identity_conflicts_user_id`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A fresh report on every run. The table belongs to this migration alone.
DELETE FROM user_identity_conflicts;

-- Left over from a run that failed before reaching its own cleanup.
DROP PROCEDURE IF EXISTS okv_fail_on_identity_conflicts;
DROP TEMPORARY TABLE IF EXISTS okv_phone_canonical;
DROP TEMPORARY TABLE IF EXISTS okv_phone_canonical_dupes;

START TRANSACTION;

-- 1. Email: one casing everywhere. The unique index is case-insensitive
--    already, so this can never create a new duplicate. The comparison is
--    binary, because the table collation would call the two casings equal
--    and never match any row.
UPDATE users
   SET email = LOWER(email)
 WHERE email COLLATE utf8mb4_bin <> LOWER(email);

-- 2. The canonical phone for every row, computed the way Phone.php computes
--    it: digits only, a leading 00 treated as a plus, a 234 country code with
--    or without an extra leading 0, or a 0-leading or bare 10 digit national
--    number, and only a national number starting 7, 8 or 9 is a mobile.
CREATE TEMPORARY TABLE okv_phone_canonical AS
SELECT id, stored_phone,
       CASE
         WHEN national <> '' AND national REGEXP '^[789][0-9]{9}$' THEN CONCAT('+234', national)
         ELSE NULL
       END AS canonical
  FROM (
    SELECT id, stored_phone,
           CASE
             WHEN digits LIKE '234%' AND CHAR_LENGTH(digits) = 13 THEN SUBSTRING(digits, 4)
             WHEN digits LIKE '234%' AND CHAR_LENGTH(digits) = 14 AND SUBSTRING(digits, 4, 1) = '0' THEN SUBSTRING(digits, 5)
             WHEN digits LIKE '0%' AND CHAR_LENGTH(digits) = 11 THEN SUBSTRING(digits, 2)
             WHEN CHAR_LENGTH(digits) = 10 THEN digits
             ELSE ''
           END AS national
      FROM (
        SELECT id,
               phone AS stored_phone,
               IF(REGEXP_REPLACE(COALESCE(phone, ''), '[^0-9]', '') LIKE '00%',
                  SUBSTRING(REGEXP_REPLACE(COALESCE(phone, ''), '[^0-9]', ''), 3),
                  REGEXP_REPLACE(COALESCE(phone, ''), '[^0-9]', '')) AS digits
          FROM users
      ) AS d
  ) AS n;

-- 2b. The canonical values more than one row landed on. A second temporary
--     table, not a subquery on okv_phone_canonical repeated inside steps 3
--     and 6: MySQL refuses to reopen the same TEMPORARY TABLE twice in one
--     statement ("Can't reopen table"), which is a restriction on the table,
--     not the connection, so a second, differently named one sidesteps it
--     cleanly rather than working around it with session tricks.
CREATE TEMPORARY TABLE okv_phone_canonical_dupes AS
SELECT canonical
  FROM okv_phone_canonical
 WHERE canonical IS NOT NULL
 GROUP BY canonical
HAVING COUNT(*) > 1;

-- 3. Report every row whose canonical phone collides with another row.
INSERT INTO user_identity_conflicts (kind, canonical_value, user_id, stored_value, user_type, status)
SELECT 'phone', c.canonical, u.id, u.phone, u.user_type, u.status
  FROM okv_phone_canonical c
  JOIN users u ON u.id = c.id
  JOIN okv_phone_canonical_dupes d ON d.canonical = c.canonical
ON DUPLICATE KEY UPDATE
  stored_value = VALUES(stored_value),
  user_type    = VALUES(user_type),
  status       = VALUES(status),
  detected_at  = CURRENT_TIMESTAMP;

-- 4. Report any email collision. The case-insensitive unique index already
--    prevents these; the check is a safety net, not an expectation.
INSERT INTO user_identity_conflicts (kind, canonical_value, user_id, stored_value, user_type, status)
SELECT 'email', u.email, u.id, u.email, u.user_type, u.status
  FROM users u
 WHERE u.email IN (
        SELECT email
          FROM users
         GROUP BY email
        HAVING COUNT(*) > 1)
ON DUPLICATE KEY UPDATE
  stored_value = VALUES(stored_value),
  user_type    = VALUES(user_type),
  status       = VALUES(status),
  detected_at  = CURRENT_TIMESTAMP;

-- 5. Report the stored phones that parse to nothing. They are not conflicts,
--    so they never fail the run, but they need a person's eyes.
INSERT INTO user_identity_conflicts (kind, canonical_value, user_id, stored_value, user_type, status)
SELECT 'unparseable_phone', NULL, u.id, u.phone, u.user_type, u.status
  FROM okv_phone_canonical c
  JOIN users u ON u.id = c.id
 WHERE c.canonical IS NULL
   AND u.phone NOT LIKE '+234%'
ON DUPLICATE KEY UPDATE
  stored_value = VALUES(stored_value),
  user_type    = VALUES(user_type),
  status       = VALUES(status),
  detected_at  = CURRENT_TIMESTAMP;

-- 6. Canonicalise every phone that is safe to move. Rows in a conflict group
--    are excluded (the LEFT JOIN finding no dupe row is the exclusion, same
--    reasoning as step 3's JOIN) so this update can never hit the unique index.
UPDATE users u
  JOIN okv_phone_canonical c ON c.id = u.id
  LEFT JOIN okv_phone_canonical_dupes d ON d.canonical = c.canonical
   SET u.phone = c.canonical
 WHERE c.canonical IS NOT NULL
   AND u.phone <> c.canonical
   AND d.canonical IS NULL;

-- Commit the repairs and the report so both survive the failure check below.
COMMIT;

-- 7. Fail safe when identities still collide. The run stops, the migration is
--    not recorded, and the next run re-checks from scratch.
DELIMITER //
CREATE PROCEDURE okv_fail_on_identity_conflicts()
BEGIN
  DECLARE remaining INT DEFAULT 0;
  SELECT COUNT(*) INTO remaining
    FROM user_identity_conflicts
   WHERE kind IN ('phone', 'email');
  IF remaining > 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Users still share one canonical email or phone. Resolve every row in user_identity_conflicts, then run migrations again.';
  END IF;
END//
DELIMITER ;

CALL okv_fail_on_identity_conflicts();

-- Cleanup, reached only when no conflict stopped the run.
DROP PROCEDURE IF EXISTS okv_fail_on_identity_conflicts;
DROP TEMPORARY TABLE IF EXISTS okv_phone_canonical;
DROP TEMPORARY TABLE IF EXISTS okv_phone_canonical_dupes;

-- Verification.
SELECT COUNT(*) AS phone_conflicts      FROM user_identity_conflicts WHERE kind = 'phone';
SELECT COUNT(*) AS email_conflicts      FROM user_identity_conflicts WHERE kind = 'email';
SELECT COUNT(*) AS unparseable_phones   FROM user_identity_conflicts WHERE kind = 'unparseable_phone';
SELECT COUNT(*) AS emails_not_lowercase FROM users WHERE email COLLATE utf8mb4_bin <> LOWER(email);
SELECT COUNT(*) AS phones_not_e164      FROM users WHERE phone NOT LIKE '+234%';

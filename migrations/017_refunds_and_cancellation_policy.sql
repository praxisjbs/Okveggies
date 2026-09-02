-- =============================================================================
-- 017_refunds_and_cancellation_policy.sql
-- OK Veggies. M5 payments, PR3. Refunds, and the cancellation policy M6 reads.
--
-- Two things:
--
--   1. Cancellation policy, in Order settings beside the deposit percentage and
--      the delivery cutoff, because it is the same kind of fact: a rule that
--      changes what a customer pays or whether they can act at all. The flow
--      that reads these is M6, where the order detail screen lives. The rules
--      are set here so M6 has no money policy to invent.
--
--      cancellation_cutoff_time
--        The time of day, the day before delivery, after which a customer can
--        no longer cancel unaided. Separate from the delivery cutoff on
--        purpose: the delivery cutoff is about scheduling a van, this one is
--        about when the produce has already been bought from a farmer. They
--        start equal and will drift apart.
--
--      cancellation_deposit_forfeit_after_cutoff
--        On, a deposit is kept when a customer cancels after the cutoff, and
--        the checkout copy says so up front. Off, a deposit is always returned
--        in full. A deposit whose only outcome is a full refund is not a
--        deposit, so this defaults to on.
--
--      cancellation_customer_allowed
--        On, a customer may cancel their own unpaid order before the cutoff
--        without asking anyone. Off, every cancellation is a staff decision.
--
--   2. An index on refunds (order_id, status). Every screen that shows an order
--      asks what has been refunded against it, and the drafted table indexes
--      only the transaction.
--
-- Idempotent and MySQL 8 compatible, matching 013, 014 and 016: the index is
-- guarded against information_schema.STATISTICS and the ALTER runs through a
-- prepared statement. The settings seed uses ON DUPLICATE KEY UPDATE
-- setting_key = setting_key, a deliberate no-op, so re-running never clobbers a
-- value the admin has since changed.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. Cancellation policy
-- -----------------------------------------------------------------------------
INSERT INTO `site_settings` (`setting_key`, `setting_value`, `value_type`, `is_public`) VALUES
  ('cancellation_cutoff_time',                   '18:00', 'string',  0),
  ('cancellation_deposit_forfeit_after_cutoff',  'true',  'bool',    0),
  ('cancellation_customer_allowed',              'true',  'bool',    0)
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

-- -----------------------------------------------------------------------------
-- 2. Index: refunds (order_id, status)
-- -----------------------------------------------------------------------------
SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'refunds'
     AND INDEX_NAME   = 'idx_refunds_order_id_status'
);
SET @ddl := IF(
  @idx_exists = 0,
  'ALTER TABLE `refunds` ADD INDEX `idx_refunds_order_id_status` (`order_id`, `status`)',
  'DO 0'
);
PREPARE okv_017_idx_refund_order FROM @ddl;
EXECUTE okv_017_idx_refund_order;
DEALLOCATE PREPARE okv_017_idx_refund_order;

-- -----------------------------------------------------------------------------
-- Verification
-- -----------------------------------------------------------------------------
SELECT 'cancellation settings seeded' AS check_name, COUNT(*) AS found, 3 AS expected
  FROM `site_settings`
 WHERE `setting_key` IN (
   'cancellation_cutoff_time',
   'cancellation_deposit_forfeit_after_cutoff',
   'cancellation_customer_allowed'
 );

SELECT 'idx_refunds_order_id_status' AS check_name, COUNT(DISTINCT INDEX_NAME) AS found, 1 AS expected
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME   = 'refunds'
   AND INDEX_NAME   = 'idx_refunds_order_id_status';

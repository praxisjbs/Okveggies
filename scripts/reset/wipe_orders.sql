-- =============================================================================
-- scripts/reset/wipe_orders.sql
-- OK Veggies. One-time, pre-launch reset. The live app carries no real orders:
-- every order, and the fake payments, refunds, credit, wallet, delivery and
-- care records hanging off them, are cleared so the September and October
-- migration (PR4) lands on a clean slate.
--
-- THIS IS NOT A MIGRATION. It lives outside migrations/ on purpose, so it never
-- runs on a deploy. Run it by hand, once, against the database you have
-- confirmed is the pre-launch one.
--
-- IT IS GATED. It does nothing unless you set the confirm variable first:
--
--     SET @okv_confirm_wipe_orders = 'YES';
--     SOURCE scripts/reset/wipe_orders.sql;
--
-- Without that variable every statement matches zero rows and the whole thing
-- is a no-op. The work runs inside one transaction with foreign-key checks left
-- ON, so it is self-verifying: an unexpected survivor raises an error and the
-- transaction rolls back rather than orphaning a row.
--
-- KEEP LIST (owner decision, 9 October 2026): everything seeded stays, products,
-- the price list, delivery zones, settings, content, staff and roles; and so do
-- credit facilities and limits, non-order wallet entries, contact messages and
-- notifications. Only orders and what strictly depends on an order are removed.
--
-- Deletion is child-first because of the many ON DELETE RESTRICT keys. The
-- shared tables (manual_refunds, wallet_entries, credit_notes,
-- credit_transactions) are cleared for order-linked rows only.
-- =============================================================================

-- Default the gate so an unset variable reads as "do nothing", never NULL noise.
SET @okv_confirm_wipe_orders = IFNULL(@okv_confirm_wipe_orders, 'NO');

START TRANSACTION;

-- --- Issue-report subtree (frees order_items, credit_transactions, orders) ----
DELETE FROM issue_report_resolution_items WHERE @okv_confirm_wipe_orders = 'YES';
DELETE FROM issue_report_history           WHERE @okv_confirm_wipe_orders = 'YES';
DELETE FROM issue_report_photos            WHERE @okv_confirm_wipe_orders = 'YES';

-- --- Refunds, manual refunds, disputes, payment children ----------------------
-- refunds points at payment_transactions, orders and issue_reports, so it goes
-- before all three. manual_refunds goes before order_shortages and
-- wallet_entries, which it references.
DELETE FROM refunds                 WHERE @okv_confirm_wipe_orders = 'YES';
DELETE FROM manual_refunds          WHERE @okv_confirm_wipe_orders = 'YES' AND order_id IS NOT NULL;
DELETE FROM payment_disputes        WHERE @okv_confirm_wipe_orders = 'YES';
DELETE FROM dispute_evidence        WHERE @okv_confirm_wipe_orders = 'YES';
DELETE FROM settlement_transactions WHERE @okv_confirm_wipe_orders = 'YES';
DELETE FROM payment_webhook_events  WHERE @okv_confirm_wipe_orders = 'YES';
DELETE FROM payment_status_history  WHERE @okv_confirm_wipe_orders = 'YES';
DELETE FROM manual_payment_proofs   WHERE @okv_confirm_wipe_orders = 'YES';

-- --- Issue reports themselves (now nothing points into them) ------------------
DELETE FROM issue_reports WHERE @okv_confirm_wipe_orders = 'YES';

-- --- Payment core -------------------------------------------------------------
DELETE FROM payment_transactions WHERE @okv_confirm_wipe_orders = 'YES';
DELETE FROM payments             WHERE @okv_confirm_wipe_orders = 'YES';

-- --- Order children (full wipe; every row is order-dependent) -----------------
DELETE FROM order_item_components   WHERE @okv_confirm_wipe_orders = 'YES';
DELETE FROM order_items             WHERE @okv_confirm_wipe_orders = 'YES';
DELETE FROM order_addresses         WHERE @okv_confirm_wipe_orders = 'YES';
DELETE FROM order_status_history    WHERE @okv_confirm_wipe_orders = 'YES';
DELETE FROM order_cancellations     WHERE @okv_confirm_wipe_orders = 'YES';
DELETE FROM order_reschedules       WHERE @okv_confirm_wipe_orders = 'YES';
DELETE FROM order_receipt_links     WHERE @okv_confirm_wipe_orders = 'YES';
DELETE FROM order_trail_share_links WHERE @okv_confirm_wipe_orders = 'YES';
DELETE FROM order_shortages         WHERE @okv_confirm_wipe_orders = 'YES';
DELETE FROM delivery_schedules      WHERE @okv_confirm_wipe_orders = 'YES';

-- --- Shared tables, order-linked rows only (keep non-order history) -----------
DELETE FROM wallet_entries      WHERE @okv_confirm_wipe_orders = 'YES' AND order_id IS NOT NULL;
DELETE FROM credit_notes        WHERE @okv_confirm_wipe_orders = 'YES' AND order_id IS NOT NULL;
DELETE FROM credit_transactions WHERE @okv_confirm_wipe_orders = 'YES' AND order_id IS NOT NULL;

-- --- Let go of the kitchen-run link (ON DELETE SET NULL also does this) --------
UPDATE kitchen_run_requests
   SET converted_order_id = NULL
 WHERE @okv_confirm_wipe_orders = 'YES' AND converted_order_id IS NOT NULL;

-- --- Orders --------------------------------------------------------------------
DELETE FROM orders WHERE @okv_confirm_wipe_orders = 'YES';

-- --- Reset the order-number sequence so the migration starts clean ------------
DELETE FROM counters WHERE @okv_confirm_wipe_orders = 'YES' AND name LIKE 'order:%';

COMMIT;

-- =============================================================================
-- Verification. After a confirmed run, every count below must be 0.
--
--   SELECT
--     (SELECT COUNT(*) FROM orders)              AS orders,
--     (SELECT COUNT(*) FROM order_items)          AS order_items,
--     (SELECT COUNT(*) FROM payments)             AS payments,
--     (SELECT COUNT(*) FROM payment_transactions) AS payment_transactions,
--     (SELECT COUNT(*) FROM refunds)              AS refunds,
--     (SELECT COUNT(*) FROM issue_reports)        AS issue_reports,
--     (SELECT COUNT(*) FROM delivery_schedules)   AS delivery_schedules,
--     (SELECT COUNT(*) FROM credit_transactions WHERE order_id IS NOT NULL) AS order_credit,
--     (SELECT COUNT(*) FROM wallet_entries     WHERE order_id IS NOT NULL) AS order_wallet;
--
-- Kept data stays intact (non-zero as before): products, price_lists,
-- delivery_zones, settings, users, roles, credit facilities.
-- =============================================================================

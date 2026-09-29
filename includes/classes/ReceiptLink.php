<?php
/**
 * includes/classes/ReceiptLink.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The private link that opens the "Payment received" screen.
 *
 * The Order Trail link a customer can forward to a spouse deliberately shows no
 * money (PRD 14.2), so a guest, who has no account to sign in to, needs a
 * second credential that does. This is it. It opens one screen for one order,
 * the payment summary, and nothing else: not the address, not the phone number,
 * and not the trail.
 *
 * Only the SHA-256 hash is stored (order_receipt_links.token_hash), exactly as
 * the trail does it, so a leaked database row never yields a working link. The
 * plain token exists for the one request that puts it in an email.
 * -----------------------------------------------------------------------------
 */

final class ReceiptLink
{
    /** Shape check before a lookup, so a malformed token never reaches the database. */
    public static function isValidToken(string $token): bool
    {
        return OrderTrail::isValidToken($token);
    }

    /**
     * Issue a receipt link for an order. Never throws: null means the caller
     * sends the email without a receipt link rather than not sending it.
     */
    public static function issue(int $orderId): ?string
    {
        if ($orderId < 1) {
            return null;
        }
        try {
            if (Database::one('SELECT id FROM orders WHERE id = :id', [':id' => $orderId]) === null) {
                return null;
            }
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $token = OrderTrail::newToken();
                $hash  = OrderTrail::hashToken($token);
                if (Database::one('SELECT id FROM order_receipt_links WHERE token_hash = :h', [':h' => $hash]) !== null) {
                    continue;
                }
                Database::run(
                    'INSERT INTO order_receipt_links (order_id, token_hash) VALUES (:order, :hash)',
                    [':order' => $orderId, ':hash' => $hash]
                );
                return $token;
            }
        } catch (Throwable $e) {
            error_log('receipt link issue failed: ' . $e->getMessage());
        }
        return null;
    }

    /** The order a receipt token opens, or null. */
    public static function findOrderId(string $token): ?int
    {
        if (!self::isValidToken($token)) {
            return null;
        }
        try {
            $row = Database::one(
                'SELECT order_id FROM order_receipt_links WHERE token_hash = :h LIMIT 1',
                [':h' => OrderTrail::hashToken($token)]
            );
        } catch (Throwable $e) {
            error_log('receipt link lookup failed: ' . $e->getMessage());
            return null;
        }
        return $row === null ? null : (int) $row['order_id'];
    }

    /** The absolute address of the screen for a token. */
    public static function url(string $token): string
    {
        $base = rtrim((string) (defined('APP_URL') ? APP_URL : ''), '/');
        return $base . '/public/payment/receipt.php?token=' . rawurlencode($token);
    }

    /** The address a signed-in owner opens, which needs no token. */
    public static function urlForOrder(int $orderId): string
    {
        $base = rtrim((string) (defined('APP_URL') ? APP_URL : ''), '/');
        return $base . '/public/payment/receipt.php?order=' . $orderId;
    }
}

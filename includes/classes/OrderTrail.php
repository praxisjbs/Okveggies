<?php
/**
 * includes/classes/OrderTrail.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The public Order Trail: a share link a customer can send to a
 * spouse or a colleague so they can follow an order without an account.
 *
 * The link carries a 43-character random token. Only its SHA-256 hash is ever
 * stored (orders.order_trail_token_hash), so a leaked database row never yields
 * a working link. The public projection deliberately withholds money: it shows
 * the order number, the items, the delivery day and the status history, and
 * nothing about what was paid. The owner sees the money on their own copy.
 * -----------------------------------------------------------------------------
 */

final class OrderTrail
{
    /** A fresh, unguessable share token: 32 random bytes, URL-safe, no padding. */
    public static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Shape check before a lookup, so a malformed token never hits the database. */
    public static function isValidToken(string $token): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{43}$/', $token);
    }

    /** The order behind a public share token, or null. */
    public static function findByToken(string $token): ?array
    {
        if (!self::isValidToken($token)) {
            return null;
        }
        return self::find('o.order_trail_token_hash = :value', self::hashToken($token));
    }

    /** The order for its signed-in owner, or null when it is not theirs. */
    public static function findForCustomer(int $orderId, int $userId): ?array
    {
        return self::find('o.id = :value AND o.user_id = :user_id', $orderId, $userId);
    }

    /** One order with its items and status history, or null. */
    private static function find(string $where, $value, ?int $userId = null): ?array
    {
        $params = [':value' => $value];
        if ($userId !== null) {
            $params[':user_id'] = $userId;
        }

        $order = Database::one(
            'SELECT o.id, o.order_number, o.order_status, o.payment_option, o.payment_status,
                    o.order_total_subunit, o.deposit_required_subunit, o.balance_due_subunit,
                    o.preferred_delivery_date, o.created_at,
                    (SELECT p.expected_amount_subunit FROM payments p WHERE p.order_id = o.id ORDER BY p.id LIMIT 1) AS amount_due_subunit
               FROM orders o
              WHERE ' . $where . '
              LIMIT 1',
            $params
        );
        if (!$order) {
            return null;
        }

        $order['items'] = Database::all(
            'SELECT item_name, quantity, unit_name, unit_price_subunit, line_total_subunit
               FROM order_items WHERE order_id = :id ORDER BY id',
            [':id' => (int) $order['id']]
        );
        $order['history'] = Database::all(
            'SELECT new_status, created_at FROM order_status_history WHERE order_id = :id ORDER BY created_at, id',
            [':id' => (int) $order['id']]
        );

        return $order;
    }
}

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

    /** Where this browser keeps the trail tokens it has been handed. */
    private const SESSION_KEY = 'okv_trail_tokens';

    /**
     * Remember a token in this session. A guest order has no account, so
     * without this the customer coming back from Paystack lands on an order
     * page that cannot recognise them. Held for the browser only: nothing is
     * written, and another device still needs the link from the email.
     */
    public static function remember(int $orderId, string $token): void
    {
        if ($orderId < 1 || !self::isValidToken($token)) {
            return;
        }
        $_SESSION[self::SESSION_KEY][$orderId] = $token;
    }

    /** The token this browser holds for one order, or null. */
    public static function sessionToken(int $orderId): ?string
    {
        $token = (string) ($_SESSION[self::SESSION_KEY][$orderId] ?? '');
        return self::isValidToken($token) ? $token : null;
    }

    /** The order behind a public share token, or null. */
    public static function findByToken(string $token): ?array
    {
        if (!self::isValidToken($token)) {
            return null;
        }
        $hash = self::hashToken($token);
        $order = self::find('o.order_trail_token_hash = :value', $hash);
        if ($order !== null) {
            return $order;
        }
        try {
            $share = Database::one(
                'SELECT order_id FROM order_trail_share_links WHERE token_hash = :token_hash LIMIT 1',
                [':token_hash' => $hash]
            );
        } catch (Throwable $e) {
            error_log('order trail share lookup failed: ' . $e->getMessage());
            return null;
        }
        return $share === null ? null : self::find('o.id = :value', (int) $share['order_id']);
    }

    /** Issue another share token for an owned order without replacing earlier links. */
    public static function issueForCustomer(int $orderId, int $userId): ?string
    {
        if (self::findForCustomer($orderId, $userId) === null) {
            return null;
        }
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $token = self::newToken();
            $hash = self::hashToken($token);
            $exists = Database::one(
                'SELECT id FROM orders WHERE order_trail_token_hash = :order_hash
                 UNION ALL
                 SELECT id FROM order_trail_share_links WHERE token_hash = :share_hash
                 LIMIT 1',
                [':order_hash' => $hash, ':share_hash' => $hash]
            );
            if ($exists !== null) {
                continue;
            }
            Database::run(
                'INSERT INTO order_trail_share_links (order_id, token_hash, created_by)
                 VALUES (:order_id, :token_hash, :created_by)',
                [':order_id' => $orderId, ':token_hash' => $hash, ':created_by' => $userId]
            );
            return $token;
        }
        throw new RuntimeException('trail_token_collision');
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
                    o.user_id, o.order_total_subunit, o.deposit_required_subunit, o.balance_due_subunit,
                    o.preferred_delivery_date, o.created_at, o.confirmed_at, o.source_regions_snapshot,
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
        $order['public_trail'] = OrderLifecycle::customerTrail($order['history']);

        // PRD 14.2 makes the "Sourced [day] from [state]" line one of the three
        // places the promise is made, and the trail is where an anxious customer
        // looks first. Once the order is confirmed it reads the snapshot taken
        // at that moment, which is a fact. Before then it reads the live source
        // settings, which is the promise. It is never blank, because a pending
        // order is exactly when the reassurance is worth most.
        $confirmed = $order['confirmed_at'] !== null
            && trim((string) $order['source_regions_snapshot']) !== '';
        $order['source_line'] = $confirmed
            ? okv_sourced_line(
                (string) $order['source_regions_snapshot'],
                date('l', strtotime((string) $order['confirmed_at']))
            )
            : okv_sourced_line(Settings::str('source_regions', ''), Settings::str('source_day', ''));
        $order['source_is_promise'] = !$confirmed;
        $order['refund_lines'] = [];
        $refunds = Database::all(
            'SELECT r.status FROM refunds r WHERE r.order_id = :id ORDER BY r.id',
            [':id' => (int) $order['id']]
        );
        foreach ($refunds as $refund) {
            $order['refund_lines'][] = Refunds::customerStatusLine((string) $refund['status']);
        }

        return $order;
    }
}

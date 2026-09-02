<?php
/**
 * includes/classes/OrderDocument.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The order behind an invoice or a receipt, and who is allowed to
 * see it.
 *
 * Three ways in, checked in this order:
 *   1. The trail token emailed with the order. Only the hash is stored, so a
 *      leaked database row never yields a working link.
 *   2. A signed-in customer who owns the order.
 *   3. Staff holding payments.view.
 *
 * There is deliberately no plain "?order=12". An order id is guessable, and an
 * invoice carries a name, a phone number, an address and what someone paid.
 *
 * Every amount comes from the order snapshot written at checkout, never from
 * today's prices, so reprinting last month's invoice shows last month's
 * figures. That is the whole reason order_items exists as a snapshot.
 * -----------------------------------------------------------------------------
 */

final class OrderDocument
{
    /**
     * Resolve and authorise the order behind a document request, or null.
     * Returns the order row plus its address, lines and money.
     */
    public static function load(): ?array
    {
        $order = self::resolve();
        if (!$order) {
            return null;
        }
        $orderId = (int) $order['id'];

        $address = Database::one(
            'SELECT recipient_name, recipient_phone, address_line_1, address_line_2, city, state, landmark
               FROM order_addresses WHERE order_id = :id',
            [':id' => $orderId]
        ) ?: [];

        $items = Database::all(
            'SELECT item_name, sku, unit_name, quantity, unit_price_subunit, line_total_subunit
               FROM order_items WHERE order_id = :id ORDER BY id',
            [':id' => $orderId]
        );

        $payments = Database::all(
            'SELECT payment_number, payment_type, provider, expected_amount_subunit,
                    paid_amount_subunit, refunded_amount_subunit, status, due_at, confirmed_at
               FROM payments WHERE order_id = :id ORDER BY id',
            [':id' => $orderId]
        );

        $refunded = (int) (Database::one(
            'SELECT COALESCE(SUM(amount_subunit), 0) AS total
               FROM refunds WHERE order_id = :id AND status = :processed',
            [':id' => $orderId, ':processed' => Refunds::STATUS_PROCESSED]
        )['total'] ?? 0);

        return [
            'order'        => $order,
            'address'      => $address,
            'items'        => $items,
            'payments'     => $payments,
            'refunded_subunit' => $refunded,
        ];
    }

    /** Find the order by whichever key the caller presented, and authorise it. */
    private static function resolve(): ?array
    {
        $token = trim((string) okv_input('token', ''));
        if ($token !== '' && OrderTrail::isValidToken($token)) {
            $found = OrderTrail::findByToken($token);
            if ($found) {
                return self::readOrder((int) $found['id']);
            }
        }

        $orderId = (int) okv_input('order', 0);
        if ($orderId < 1) {
            return null;
        }

        if (Customer::isLoggedIn()) {
            $owned = OrderTrail::findForCustomer($orderId, (int) Customer::id());
            if ($owned) {
                return self::readOrder($orderId);
            }
        }

        if (Rbac::isStaff() && Rbac::can('payments.view')) {
            return self::readOrder($orderId);
        }

        return null;
    }

    private static function readOrder(int $orderId): ?array
    {
        return Database::one(
            'SELECT id, order_number, order_status, payment_option, payment_status,
                    subtotal_subunit, discount_amount_subunit, order_total_subunit,
                    deposit_percentage, deposit_required_subunit, amount_paid_subunit,
                    balance_due_subunit, preferred_delivery_date, delivery_fee_note,
                    customer_note, created_at, confirmed_at, delivered_at
               FROM orders WHERE id = :id',
            [':id' => $orderId]
        );
    }

    /** The line rows the document component expects, already formatted. */
    public static function lines(array $items): array
    {
        $lines = [];
        foreach ($items as $item) {
            $quantity = rtrim(rtrim(number_format((float) $item['quantity'], 3, '.', ''), '0'), '.');
            $lines[] = [
                'name'     => (string) $item['item_name'],
                'unit'     => (string) $item['unit_name'],
                'quantity' => $quantity === '' ? '0' : $quantity,
                'amount'   => okv_document_money((int) $item['line_total_subunit']),
            ];
        }
        return $lines;
    }

    /** The delivery address as one readable block. */
    public static function addressBlock(array $address): string
    {
        $parts = array_filter([
            $address['recipient_name']  ?? null,
            $address['address_line_1']  ?? null,
            $address['address_line_2']  ?? null,
            trim(((string) ($address['city'] ?? '')) . ', ' . ((string) ($address['state'] ?? ''))," ,"),
            $address['recipient_phone'] ?? null,
        ], static fn($v) => trim((string) $v) !== '');
        return implode("\n", $parts);
    }
}

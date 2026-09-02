<?php
/**
 * includes/classes/Checkout.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Checkout: the step-by-step bag a customer fills in, and the one
 * transactional write that turns a basket into an order.
 *
 * Placement is idempotent two ways. The session remembers the order it just
 * placed, so a refresh of the confirmation page does not place a second order.
 * The database backs that up: orders.shopping_cart_id is unique, and a basket
 * is marked converted inside the same transaction as the order, so two submits
 * racing on the same basket cannot both write an order. The second submit finds
 * the first order and returns it.
 *
 * No money moves here. M4 records the choice (pay in full, deposit, pay on
 * delivery, on account) and writes an unpaid payment row. Taking the payment is
 * M5. The delivery fee is never charged on the platform.
 *
 * The pure helpers (total, paymentAllowed, amountDue) hold no database and are
 * unit tested in scripts/tests/CheckoutTest.php.
 * -----------------------------------------------------------------------------
 */

/** A fixed code plus copy already cleared for the customer to read. */
final class CheckoutException extends DomainException
{
    private string $customerMessage;

    public function __construct(string $code, string $customerMessage)
    {
        parent::__construct($code);
        $this->customerMessage = $customerMessage;
    }

    public function customerMessage(): string
    {
        return $this->customerMessage;
    }
}

final class Checkout
{
    private const SESSION_KEY = 'okv_checkout';

    /** How long a half-finished checkout bag survives, in seconds. */
    private const BAG_TTL = 7200;

    private const REQUIRED_CUSTOMER_FIELDS = ['recipient_name', 'recipient_phone', 'address_line_1', 'city', 'state'];

    // -------------------------------------------------------------------------
    // Pure helpers. Unit tested.
    // -------------------------------------------------------------------------

    /** The basket subtotal, in subunits. */
    public static function total(array $lines): int
    {
        $total = 0;
        foreach ($lines as $line) {
            $total += Money::lineTotal($line['quantity'] ?? 0, (int) ($line['unit_price_subunit'] ?? 0));
        }
        return $total;
    }

    /**
     * Whether a payment choice is open to this customer, before the database is
     * touched. Pay on delivery needs a verified account; on account needs a
     * business account (credit approval is checked separately, against the
     * database); pay in full and deposit are open to anyone.
     */
    public static function paymentAllowed(string $option, string $customerType, bool $activated): bool
    {
        if ($option === 'pay_on_delivery') {
            return $activated;
        }
        if ($option === 'on_account') {
            return $customerType === 'business';
        }
        return in_array($option, ['pay_in_full', 'deposit'], true);
    }

    /** What is due now: the deposit for a deposit order, otherwise the full total. */
    public static function amountDue(string $option, int $total, float $percentage): int
    {
        return $option === 'deposit' ? Money::deposit($total, $percentage) : $total;
    }

    // -------------------------------------------------------------------------
    // The checkout bag (session)
    // -------------------------------------------------------------------------

    /** The saved checkout so far, or an empty bag once it has gone stale. */
    public static function bag(): array
    {
        $bag = $_SESSION[self::SESSION_KEY] ?? [];
        if (!is_array($bag) || (time() - (int) ($bag['at'] ?? 0)) > self::BAG_TTL) {
            unset($_SESSION[self::SESSION_KEY]);
            return [];
        }
        return $bag;
    }

    /** Save one step of the checkout and keep the bag alive. */
    public static function saveStep(string $step, array $data): array
    {
        $bag = self::bag();
        $bag[$step] = $data;
        $bag['at'] = time();
        $_SESSION[self::SESSION_KEY] = $bag;
        return $bag;
    }

    /** True when the bag already holds the order placed for this basket. */
    public static function placedMatchesBasket(array $bag, ?int $cartId): bool
    {
        return !empty($bag['placed'])
            && ($cartId === null || (int) ($bag['placed_cart_id'] ?? 0) === $cartId);
    }

    // -------------------------------------------------------------------------
    // Placement
    // -------------------------------------------------------------------------

    /**
     * Turn the current basket into an order. Every check that can be made
     * before the transaction is made first, so a refused order never opens one.
     * Returns the confirmation payload.
     */
    public static function place(array $input): array
    {
        $bag    = self::bag();
        $basket = Basket::state();
        $cartId = $basket['cart_id'] === null ? null : (int) $basket['cart_id'];

        if (self::placedMatchesBasket($bag, $cartId)) {
            return $bag['placed'];
        }
        if ($cartId === null || !$basket['lines']) {
            throw new DomainException('empty_cart');
        }

        $customer = self::validateCustomer($input);
        $type     = $customer['customer_type'];
        $userId   = $customer['user_id'];
        $option   = (string) ($input['payment_option'] ?? '');

        if (!self::paymentAllowed($option, $type, !empty($input['activated']))) {
            throw new DomainException('payment_not_allowed');
        }
        if ($option === 'on_account') {
            $credit = Database::one('SELECT credit_status FROM business_customers WHERE user_id = :id', [':id' => $userId]);
            if (!$credit || $credit['credit_status'] !== 'approved') {
                throw new DomainException('credit_not_approved');
            }
        }

        $eligibility = Delivery::isEligible((string) ($input['delivery_date'] ?? ''), $type);
        if (empty($eligibility['eligible'])) {
            throw new CheckoutException('delivery_unavailable', (string) $eligibility['reason']);
        }
        $zoneId = (int) ($input['delivery_zone_id'] ?? 0);
        if (!Database::one('SELECT id FROM delivery_zones WHERE id = :id AND is_active = 1', [':id' => $zoneId])) {
            throw new DomainException('zone_unavailable');
        }

        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            // Lock the basket. If it is no longer active, another submit already
            // converted it; return that order rather than placing a second.
            $cart = Database::one('SELECT id, status FROM shopping_carts WHERE id = :id FOR UPDATE', [':id' => $cartId]);
            if (!$cart) {
                throw new DomainException('empty_cart');
            }
            if ($cart['status'] !== 'active') {
                $existing = Database::one('SELECT id, order_number FROM orders WHERE shopping_cart_id = :cart LIMIT 1', [':cart' => $cartId]);
                if (!$existing) {
                    throw new DomainException('cart_converted');
                }
                $pdo->commit();
                return self::remember($bag, $cartId, self::placedResult((int) $existing['id'], (string) $existing['order_number'], ''));
            }

            // Lock the lines and read the basket once more inside the lock.
            Database::all('SELECT id FROM cart_items WHERE cart_id = :cart_id FOR UPDATE', [':cart_id' => $cartId]);
            $basket = Basket::state();
            if (!$basket['lines']) {
                throw new DomainException('empty_cart');
            }

            $result = self::writeOrder($pdo, $userId, $type, $cartId, $option, $customer, (string) $input['delivery_date'], $zoneId, $basket['lines']);
            $pdo->commit();
            return self::remember($bag, $cartId, $result);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Validate and normalise the customer and address input, or refuse it. */
    private static function validateCustomer(array $input): array
    {
        $userId = (int) ($input['user_id'] ?? 0);
        $type   = (string) ($input['customer_type'] ?? 'household');

        if ($userId < 1 || !in_array($type, ['household', 'business'], true)) {
            throw new DomainException('bad_customer');
        }
        foreach (self::REQUIRED_CUSTOMER_FIELDS as $field) {
            if (trim((string) ($input[$field] ?? '')) === '') {
                throw new DomainException('bad_customer');
            }
        }
        $phone = Phone::normalize((string) $input['recipient_phone']);
        if ($phone === null) {
            throw new DomainException('bad_customer');
        }

        return [
            'user_id'        => $userId,
            'customer_type'  => $type,
            'recipient_name' => trim((string) $input['recipient_name']),
            'recipient_phone' => $phone,
            'address_line_1' => trim((string) $input['address_line_1']),
            'address_line_2' => trim((string) ($input['address_line_2'] ?? '')) ?: null,
            'city'           => trim((string) $input['city']),
            'state'          => trim((string) $input['state']),
            'landmark'       => trim((string) ($input['landmark'] ?? '')) ?: null,
        ];
    }

    /** Write the order, its address, its lines, the first status event and the unpaid payment. */
    private static function writeOrder(PDO $pdo, int $userId, string $type, int $cartId, string $option, array $customer, string $deliveryDate, int $zoneId, array $lines): array
    {
        $total      = self::total($lines);
        $percentage = Settings::depositPercentage();
        $deposit    = $option === 'deposit' ? Money::deposit($total, $percentage) : null;
        $due        = self::amountDue($option, $total, $percentage);

        $orderNumber = OrderNumber::nextOrderNumber($pdo);
        $token       = self::freshTrailToken();

        Database::run(
            'INSERT INTO orders
                (order_number, order_trail_token_hash, user_id, shopping_cart_id, customer_type,
                 order_status, payment_option, payment_status, subtotal_subunit, order_total_subunit,
                 deposit_percentage, deposit_required_subunit, balance_due_subunit,
                 preferred_delivery_date, delivery_zone_id, delivery_fee_note, created_by)
             VALUES
                (:number, :token, :user_id, :cart_id, :type,
                 \'pending\', :option, \'unpaid\', :subtotal, :order_total,
                 :percentage, :deposit, :balance,
                 :date, :zone, :fee, :created_by)',
            [
                ':number'      => $orderNumber,
                ':token'       => self::hashToken($token),
                ':user_id'     => $userId,
                ':cart_id'     => $cartId,
                ':type'        => $type,
                ':option'      => $option,
                ':subtotal'    => $total,
                ':order_total' => $total,
                ':percentage'  => $option === 'deposit' ? $percentage : null,
                ':deposit'     => $deposit,
                ':balance'     => $total,
                ':date'        => $deliveryDate,
                ':zone'        => $zoneId,
                ':fee'         => 'Delivery fee is arranged and settled separately after we confirm your area.',
                ':created_by'  => $userId,
            ]
        );
        $orderId = (int) $pdo->lastInsertId();

        self::writeAddress($orderId, $userId, $customer);
        self::snapshotItems($orderId, $lines);

        Database::run(
            'INSERT INTO order_status_history (order_id, old_status, new_status, source, changed_by)
             VALUES (:order, NULL, \'pending\', \'customer\', :user)',
            [':order' => $orderId, ':user' => $userId]
        );
        Database::run(
            'INSERT INTO payments
                (payment_number, user_id, order_id, provider, payment_type, expected_amount_subunit, currency, status)
             VALUES (:number, :user, :order, :provider, :type, :amount, :currency, \'unpaid\')',
            [
                ':number'   => 'PAY-' . $orderNumber,
                ':user'     => $userId,
                ':order'    => $orderId,
                ':provider' => self::providerFor($option),
                ':type'     => $option,
                ':amount'   => $due,
                ':currency' => Money::CODE,
            ]
        );

        Database::run('UPDATE shopping_carts SET status = \'converted\' WHERE id = :id', [':id' => $cartId]);

        return self::placedResult($orderId, $orderNumber, $token);
    }

    /** The immutable delivery-address snapshot, plus a saved address for next time. */
    private static function writeAddress(int $orderId, int $userId, array $customer): void
    {
        Database::run(
            'INSERT INTO order_addresses
                (order_id, recipient_name, recipient_phone, address_line_1, address_line_2, city, state, landmark)
             VALUES (:order, :name, :phone, :line1, :line2, :city, :state, :landmark)',
            [
                ':order' => $orderId,
                ':name'  => $customer['recipient_name'],
                ':phone' => $customer['recipient_phone'],
                ':line1' => $customer['address_line_1'],
                ':line2' => $customer['address_line_2'],
                ':city'  => $customer['city'],
                ':state' => $customer['state'],
                ':landmark' => $customer['landmark'],
            ]
        );

        $exists = Database::one(
            'SELECT id FROM customer_addresses
              WHERE user_id = :user_id AND recipient_name = :name AND recipient_phone = :phone
                AND address_line_1 = :line1 AND city = :city AND state = :state
              LIMIT 1',
            [
                ':user_id' => $userId,
                ':name'    => $customer['recipient_name'],
                ':phone'   => $customer['recipient_phone'],
                ':line1'   => $customer['address_line_1'],
                ':city'    => $customer['city'],
                ':state'   => $customer['state'],
            ]
        );
        if ($exists) {
            return;
        }

        $hasDefault = Database::one('SELECT id FROM customer_addresses WHERE user_id = :user_id AND is_default = 1 LIMIT 1', [':user_id' => $userId]);
        Database::run(
            'INSERT INTO customer_addresses
                (user_id, label, recipient_name, recipient_phone, address_line_1, address_line_2, city, state, landmark, is_default)
             VALUES (:user_id, \'Delivery\', :name, :phone, :line1, :line2, :city, :state, :landmark, :is_default)',
            [
                ':user_id' => $userId,
                ':name'    => $customer['recipient_name'],
                ':phone'   => $customer['recipient_phone'],
                ':line1'   => $customer['address_line_1'],
                ':line2'   => $customer['address_line_2'],
                ':city'    => $customer['city'],
                ':state'   => $customer['state'],
                ':landmark' => $customer['landmark'],
                ':is_default' => $hasDefault ? 0 : 1,
            ]
        );
    }

    /**
     * Copy each basket line into the order as an immutable snapshot: the name,
     * sku, unit and price as they stood at checkout. A combo also fans out into
     * its components, so the packing list reads the parts without depending on
     * the combo definition, which the Manager may edit later.
     */
    private static function snapshotItems(int $orderId, array $lines): void
    {
        $pdo = Database::getInstance()->getConnection();
        foreach ($lines as $line) {
            $isCombo = (string) $line['item_type'] === 'combo';
            $source  = $isCombo
                ? Database::one('SELECT name, sku FROM combo_packages WHERE id = :id', [':id' => (int) $line['combo_package_id']])
                : Database::one('SELECT p.name, p.sku, u.name AS unit_name FROM products p JOIN units_of_measurement u ON u.id = p.unit_id WHERE p.id = :id', [':id' => (int) $line['product_id']]);

            Database::run(
                'INSERT INTO order_items
                    (order_id, item_type, product_id, combo_package_id, item_name, sku, unit_name, quantity, unit_price_subunit, line_total_subunit)
                 VALUES (:order, :type, :product, :combo, :name, :sku, :unit, :quantity, :price, :line_total)',
                [
                    ':order'      => $orderId,
                    ':type'       => $line['item_type'],
                    ':product'    => $line['product_id'],
                    ':combo'      => $line['combo_package_id'],
                    ':name'       => $source['name'],
                    ':sku'        => $source['sku'],
                    ':unit'       => $isCombo ? 'basket' : $source['unit_name'],
                    ':quantity'   => $line['quantity'],
                    ':price'      => $line['unit_price_subunit'],
                    ':line_total' => $line['line_total_subunit'],
                ]
            );
            if (!$isCombo) {
                continue;
            }

            $orderItemId = (int) $pdo->lastInsertId();
            $components = Database::all(
                'SELECT cpi.product_id, p.name AS product_name, cpi.quantity, u.name AS unit_name
                   FROM combo_package_items cpi
                   JOIN products p ON p.id = cpi.product_id
                   JOIN units_of_measurement u ON u.id = cpi.unit_id
                  WHERE cpi.combo_package_id = :id
                  ORDER BY cpi.id',
                [':id' => (int) $line['combo_package_id']]
            );
            foreach ($components as $component) {
                Database::run(
                    'INSERT INTO order_item_components (order_item_id, product_id, product_name, quantity, unit_name)
                     VALUES (:item, :product, :name, :quantity, :unit)',
                    [
                        ':item'     => $orderItemId,
                        ':product'  => $component['product_id'],
                        ':name'     => $component['product_name'],
                        ':quantity' => $component['quantity'],
                        ':unit'     => $component['unit_name'],
                    ]
                );
            }
        }
    }

    /** Which payment provider records the choice. No charge is made in M4. */
    private static function providerFor(string $option): string
    {
        if ($option === 'on_account') {
            return 'account';
        }
        if ($option === 'pay_on_delivery') {
            return 'manual';
        }
        return 'paystack';
    }

    /** A trail token whose hash is not already taken. */
    private static function freshTrailToken(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = self::newToken();
            if (!Database::one('SELECT id FROM orders WHERE order_trail_token_hash = :h', [':h' => self::hashToken($candidate)])) {
                return $candidate;
            }
        }
        throw new RuntimeException('trail_token_collision');
    }

    private static function newToken(): string
    {
        return OrderTrail::newToken();
    }

    private static function hashToken(string $token): string
    {
        return OrderTrail::hashToken($token);
    }

    /** Remember the placed order on the bag so a refresh never places it twice. */
    private static function remember(array $bag, int $cartId, array $result): array
    {
        $bag['placed'] = $result;
        $bag['placed_cart_id'] = $cartId;
        $bag['at'] = time();
        $_SESSION[self::SESSION_KEY] = $bag;
        return $result;
    }

    private static function placedResult(int $orderId, string $orderNumber, string $token): array
    {
        return [
            'order_id'         => $orderId,
            'order_number'     => $orderNumber,
            'trail_token'      => $token,
            'confirmation_url' => '/public/order.php?order=' . $orderId,
            'trail_url'        => $token === '' ? '' : '/public/order.php?token=' . rawurlencode($token),
        ];
    }
}

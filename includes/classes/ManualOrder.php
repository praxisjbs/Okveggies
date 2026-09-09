<?php
/**
 * includes/classes/ManualOrder.php
 * -----------------------------------------------------------------------------
 * OK Veggies. An order a colleague builds, for a customer who is on the phone.
 *
 * Until this file existed, an order could only start in one of two places: a
 * customer's own basket, or a Kitchen Run they had sent themselves. Somebody
 * ringing the shop to order 2kg of tomatoes could not be helped, which is not a
 * small gap in a business where the phone is how half the trade arrives.
 *
 * The order this writes is an ordinary order. Same order number from the same
 * helper, same address snapshot, same trail token, same status history, same
 * delivery schedule, same payment rows, all written by the same Checkout methods
 * a checkout order uses. The day manifest, the packing list, the documents and
 * the public Order Trail cannot tell the two apart, and that is deliberate:
 * PRD 14.2 promises every customer a trail, not every customer who used the
 * website.
 *
 * Four decisions are worth knowing before changing anything here.
 *
 *   A line can be anything. A catalogue product, a combo, or a free-text line
 *   for something we are sourcing that is not in the catalogue. A catalogue line
 *   carries the live price unless a colleague overrides it, and an override is
 *   recorded on the order's own history so a discount is never invisible.
 *
 *   Money is never trusted from the request for a catalogue line. The name, sku,
 *   unit and price come from the products or combo_packages row on the server.
 *   An override is the one figure a colleague may set, and it is bounded and
 *   audited rather than accepted quietly.
 *
 *   Pay on delivery does not require an activated account here, and it does on
 *   the storefront. The rule exists because a stranger placing an unpaid order
 *   from a browser is the flow most exposed to abuse (PRD 10.2). A colleague who
 *   has just spoken to the caller is the check that rule stands in for, so it
 *   would only stop the phone order it is meant to enable. Who took the order is
 *   on the status history either way.
 *
 *   Nothing is charged by creating one. The order is created unpaid, exactly as
 *   checkout does, and money taken on the call is recorded afterwards through
 *   the ordinary ManualPayments path so it still lands in the proof queue.
 *
 * The pure helpers hold no database and are unit tested in
 * scripts/tests/ManualOrderTest.php.
 * -----------------------------------------------------------------------------
 */

final class ManualOrder
{
    /** What a line on a manual order may be. */
    public const LINE_TYPES = ['product', 'combo', 'custom'];

    /** The same four choices checkout offers, in the same words. */
    public const PAYMENT_OPTIONS = ['pay_in_full', 'deposit', 'pay_on_delivery', 'on_account'];

    /** A guard against a runaway paste, not a business rule. */
    public const MAX_LINES = 100;

    /** Longest free-text item name. Matches order_items.item_name. */
    public const NAME_MAX = 180;

    /** Longest unit label on a free-text line. Matches order_items.unit_name. */
    public const UNIT_MAX = 80;

    /**
     * The sku a typed line carries. order_items.sku is NOT NULL and a line that
     * never had a catalogue product has no sku of its own, so it gets a marker,
     * the way a free-text Kitchen Run line carries KITCHEN-RUN.
     */
    public const CUSTOM_SKU = 'PHONE-ORDER';

    // -------------------------------------------------------------------------
    // Pure helpers. No database. Unit tested.
    // -------------------------------------------------------------------------

    /**
     * What one line select says, read into a type and, for a catalogue line, the
     * row it points at. One control carries both facts so the two can never
     * disagree, which is what a separate "kind" select and "which one" select
     * would allow the moment JavaScript is off.
     *
     * "product:12" and "combo:3" are catalogue lines. "custom" is a line typed
     * in by hand. Anything else is not a line.
     */
    public static function parseItem(string $value): ?array
    {
        $value = trim($value);
        if ($value === 'custom') {
            return ['type' => 'custom', 'reference_id' => null];
        }
        if (preg_match('/^(product|combo):([1-9][0-9]{0,15})$/', $value, $found) === 1) {
            return ['type' => $found[1], 'reference_id' => (int) $found[2]];
        }
        return null;
    }

    /**
     * Read the posted rows into clean line intents, or refuse them.
     *
     * Nothing here reads a price out of the database: this establishes what was
     * asked for, and resolveLines() then puts our own figures on it. A row left
     * completely blank is skipped, because a form with eight line slots is how a
     * colleague works and six of them will be empty. A row with something in it
     * but no item chosen is refused rather than skipped, so a line a colleague
     * half filled in never quietly falls off the order.
     *
     * @throws DomainException one of: no_lines, too_many_lines, bad_line_type,
     *         item_required, bad_quantity, bad_price, name_required, price_required
     */
    public static function cleanLines(array $rows): array
    {
        if (count($rows) > self::MAX_LINES) {
            throw new DomainException('too_many_lines');
        }

        $lines = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $item = trim((string) ($row['item'] ?? ''));
            if ($item === '') {
                if (self::rowIsBlank($row)) {
                    continue;
                }
                throw new DomainException('item_required');
            }

            $parsed = self::parseItem($item);
            if ($parsed === null) {
                throw new DomainException('bad_line_type');
            }

            $quantity = KitchenRuns::quantity($row['quantity'] ?? null);
            if ($quantity === null) {
                throw new DomainException('bad_quantity');
            }

            // Empty means "use our price" on a catalogue line, and is refused on
            // a typed line, where there is no price of ours to use.
            $price = KitchenRuns::nairaToKobo($row['unit_price'] ?? null);
            if ($price === false) {
                throw new DomainException('bad_price');
            }

            $line = [
                'type'               => $parsed['type'],
                'quantity'           => $quantity,
                'unit_price_subunit' => $price,
            ];

            if ($parsed['type'] === 'custom') {
                $name = trim((string) ($row['item_name'] ?? ''));
                if ($name === '') {
                    throw new DomainException('name_required');
                }
                if ($price === null) {
                    throw new DomainException('price_required');
                }
                $line['item_name'] = mb_substr($name, 0, self::NAME_MAX);
                $unit = trim((string) ($row['unit_name'] ?? ''));
                $line['unit_name'] = mb_substr($unit === '' ? 'each' : $unit, 0, self::UNIT_MAX);
            } else {
                $line['reference_id'] = (int) $parsed['reference_id'];
            }

            $lines[] = $line;
        }

        if (!$lines) {
            throw new DomainException('no_lines');
        }
        return $lines;
    }

    /** True when a row carries nothing a colleague typed. */
    public static function rowIsBlank(array $row): bool
    {
        foreach (['item', 'item_name', 'quantity', 'unit_price', 'unit_name'] as $key) {
            if (trim((string) ($row[$key] ?? '')) !== '') {
                return false;
            }
        }
        return true;
    }

    /** The order total, summed from resolved lines. */
    public static function total(array $lines): int
    {
        $total = 0;
        foreach ($lines as $line) {
            $total += (int) ($line['line_total_subunit'] ?? 0);
        }
        return $total;
    }

    /**
     * Whether a payment choice is open to this customer when staff take the
     * order. Checkout::paymentAllowed() is the storefront rule and this is its
     * counterpart: the only difference is that pay on delivery does not need an
     * activated account, for the reason set out at the top of this file. On
     * account still needs a business customer, and the approval itself is
     * checked against the database by the caller.
     */
    public static function paymentAllowed(string $option, string $customerType, bool $creditApproved): bool
    {
        if (!in_array($option, self::PAYMENT_OPTIONS, true)) {
            return false;
        }
        if ($option === 'on_account') {
            return $customerType === 'business' && $creditApproved;
        }
        return true;
    }

    /**
     * The line the order history carries when a colleague sold at a price that
     * is not the shelf price. Written so a person reading the order later can
     * see what happened without opening another screen.
     */
    public static function overrideNote(array $overrides): string
    {
        if (!$overrides) {
            return '';
        }
        $parts = [];
        foreach ($overrides as $override) {
            $parts[] = $override['item_name'] . ' at ' . Money::format((int) $override['charged'])
                     . ' rather than ' . Money::format((int) $override['listed']);
        }
        return 'Price changed on this order: ' . implode('; ', $parts) . '.';
    }

    // -------------------------------------------------------------------------
    // Resolving lines against the catalogue
    // -------------------------------------------------------------------------

    /**
     * Put our own names, units and prices on the clean lines, and work out each
     * line total. A catalogue line that has no price on the sheet is refused
     * rather than sold at nothing.
     *
     * @throws DomainException one of: product_missing, combo_missing, no_price
     */
    public static function resolveLines(array $lines): array
    {
        $resolved  = [];
        $overrides = [];

        foreach ($lines as $line) {
            $type = (string) $line['type'];

            if ($type === 'custom') {
                // A typed line is a product line that never had a catalogue
                // product, exactly as a free-text Kitchen Run line is. Same
                // item_type, a marker sku so the packing list still reads, and
                // nothing downstream has to learn a third kind of line.
                $unitPrice = (int) $line['unit_price_subunit'];
                $resolved[] = [
                    'item_type'          => 'product',
                    'product_id'         => null,
                    'combo_package_id'   => null,
                    'item_name'          => $line['item_name'],
                    'sku'                => self::CUSTOM_SKU,
                    'unit_name'          => $line['unit_name'],
                    'quantity'           => $line['quantity'],
                    'unit_price_subunit' => $unitPrice,
                    'line_total_subunit' => Money::lineTotal($line['quantity'], $unitPrice),
                ];
                continue;
            }

            $id = (int) $line['reference_id'];
            if ($type === 'product') {
                $source = Database::one(
                    'SELECT p.id, p.name, p.sku, p.current_price_subunit, u.name AS unit_name
                       FROM products p
                       JOIN units_of_measurement u ON u.id = p.unit_id
                      WHERE p.id = :id AND p.is_active = 1',
                    [':id' => $id]
                );
                if (!$source) {
                    throw new DomainException('product_missing');
                }
                $listed = ($source['current_price_subunit'] !== null
                    && (int) $source['current_price_subunit'] >= Pricing::MIN_PRICE_SUBUNIT)
                    ? (int) $source['current_price_subunit']
                    : null;
                $unitName = (string) $source['unit_name'];
                $sku      = (string) $source['sku'];
                $itemType = 'product';
                $productId = $id;
                $comboId   = null;
            } else {
                $source = Database::one(
                    'SELECT id, name, sku, price_subunit FROM combo_packages WHERE id = :id AND is_active = 1',
                    [':id' => $id]
                );
                if (!$source) {
                    throw new DomainException('combo_missing');
                }
                // combo_packages.price_subunit is NOT NULL and defaults to zero,
                // so an unpriced combo reads as zero rather than null. Treated
                // the same way Combos::publish() treats it: below the minimum
                // price is not a price.
                $listed = (int) $source['price_subunit'] >= Pricing::MIN_PRICE_SUBUNIT
                    ? (int) $source['price_subunit']
                    : null;
                $unitName = 'basket';
                $sku      = (string) $source['sku'];
                $itemType = 'combo';
                $productId = null;
                $comboId   = $id;
            }

            $typed = $line['unit_price_subunit'];
            if ($typed === null && $listed === null) {
                throw new DomainException('no_price');
            }
            $unitPrice = $typed ?? $listed;

            if ($typed !== null && $listed !== null && $typed !== $listed) {
                $overrides[] = [
                    'item_name' => (string) $source['name'],
                    'charged'   => $typed,
                    'listed'    => $listed,
                ];
            }

            $resolved[] = [
                'item_type'          => $itemType,
                'product_id'         => $productId,
                'combo_package_id'   => $comboId,
                'item_name'          => mb_substr((string) $source['name'], 0, self::NAME_MAX),
                'sku'                => $sku,
                'unit_name'          => $unitName,
                'quantity'           => $line['quantity'],
                'unit_price_subunit' => $unitPrice,
                'line_total_subunit' => Money::lineTotal($line['quantity'], $unitPrice),
            ];
        }

        return ['lines' => $resolved, 'overrides' => $overrides];
    }

    // -------------------------------------------------------------------------
    // The write
    // -------------------------------------------------------------------------

    /**
     * Create the order. One transaction covers the order, its address, its
     * lines, the first status event, the delivery schedule and the unpaid
     * payment rows, so a failure anywhere leaves nothing half written.
     *
     * @throws DomainException a code the controller turns into a sentence
     * @return array{id:int, order_number:string, total_subunit:int, trail_token:string}
     */
    public static function create(array $input, int $staffId): array
    {
        $userId = (int) ($input['user_id'] ?? 0);
        $customer = StaffCustomers::find($userId);
        if (!$customer) {
            throw new DomainException('bad_customer');
        }
        $type = (string) $customer['user_type'];

        // Two different faults wear the same "no" on the storefront, and a
        // colleague needs them apart: a household can never order on account at
        // all, while a business simply has not been approved yet, which somebody
        // can act on. So the credit read only happens once the account type has
        // cleared, and each refusal keeps its own words.
        $option = (string) ($input['payment_option'] ?? '');
        if (!in_array($option, self::PAYMENT_OPTIONS, true) || ($option === 'on_account' && $type !== 'business')) {
            throw new DomainException('payment_not_allowed');
        }
        $creditApproved = false;
        if ($option === 'on_account') {
            $credit = Database::one(
                'SELECT credit_status FROM business_customers WHERE user_id = :id',
                [':id' => $userId]
            );
            $creditApproved = ($credit['credit_status'] ?? '') === 'approved';
        }
        if (!self::paymentAllowed($option, $type, $creditApproved)) {
            throw new DomainException('credit_not_approved');
        }

        $address = self::validateAddress($input, $customer);

        $date = trim((string) ($input['delivery_date'] ?? ''));
        $eligibility = Delivery::isEligible($date, $type);
        if (empty($eligibility['eligible'])) {
            throw new DomainException('delivery_unavailable');
        }
        $zoneId = (int) ($input['delivery_zone_id'] ?? 0);
        if (!Database::one('SELECT id FROM delivery_zones WHERE id = :id AND is_active = 1', [':id' => $zoneId])) {
            throw new DomainException('zone_unavailable');
        }

        $clean    = self::cleanLines(is_array($input['lines'] ?? null) ? $input['lines'] : []);
        $resolved = self::resolveLines($clean);
        $lines    = $resolved['lines'];
        $total    = self::total($lines);
        if ($total < 1) {
            throw new DomainException('no_value');
        }

        $percentage = null;
        $deposit    = null;
        if ($option === 'deposit') {
            $percentage = Settings::depositPercentage();
            $deposit    = Money::deposit($total, $percentage);
            if ($deposit < 1 || $deposit > $total) {
                throw new DomainException('deposit_required');
            }
        }
        $due = $option === 'deposit' ? (int) $deposit : $total;

        $note = KitchenRuns::note($input['customer_note'] ?? '');

        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            $orderNumber = OrderNumber::nextOrderNumber($pdo);
            $token       = Checkout::freshTrailToken();

            Database::run(
                'INSERT INTO orders
                    (order_number, order_trail_token_hash, user_id, customer_type,
                     order_status, payment_option, payment_status, subtotal_subunit, order_total_subunit,
                     deposit_percentage, deposit_required_subunit, balance_due_subunit,
                     preferred_delivery_date, delivery_zone_id, delivery_fee_note, customer_note, created_by)
                 VALUES
                    (:number, :token, :user_id, :type,
                     \'pending\', :option, \'unpaid\', :subtotal, :order_total,
                     :percentage, :deposit, :balance,
                     :date, :zone, :fee, :note, :created_by)',
                [
                    ':number'      => $orderNumber,
                    ':token'       => Checkout::hashToken($token),
                    ':user_id'     => $userId,
                    ':type'        => $type,
                    ':option'      => $option,
                    ':subtotal'    => $total,
                    ':order_total' => $total,
                    ':percentage'  => $percentage,
                    ':deposit'     => $deposit,
                    ':balance'     => $total,
                    ':date'        => $date,
                    ':zone'        => $zoneId,
                    ':fee'         => 'Delivery fee is arranged and settled separately after we confirm your area.',
                    ':note'        => $note,
                    ':created_by'  => $staffId,
                ]
            );
            $orderId = (int) $pdo->lastInsertId();

            Checkout::writeAddress($orderId, $userId, $address);
            self::insertLines($pdo, $orderId, $lines);

            // Two history lines rather than one when a price was changed, so the
            // discount is on the record beside who took the order.
            Database::run(
                'INSERT INTO order_status_history (order_id, old_status, new_status, source, changed_by, note)
                 VALUES (:order, NULL, \'pending\', \'admin\', :staff, :note)',
                [
                    ':order' => $orderId,
                    ':staff' => $staffId,
                    ':note'  => 'Taken by our team, ' . self::channelLabel((string) ($input['channel'] ?? '')) . '.',
                ]
            );
            if ($resolved['overrides']) {
                Database::run(
                    'INSERT INTO order_status_history (order_id, old_status, new_status, source, changed_by, note)
                     VALUES (:order, \'pending\', \'pending\', \'admin\', :staff, :note)',
                    [
                        ':order' => $orderId,
                        ':staff' => $staffId,
                        ':note'  => mb_substr(self::overrideNote($resolved['overrides']), 0, 500),
                    ]
                );
            }

            Database::run(
                'INSERT INTO delivery_schedules (order_id, delivery_date, status, updated_by)
                 VALUES (:order, :date, \'scheduled\', :staff)',
                [':order' => $orderId, ':date' => $date, ':staff' => $staffId]
            );
            Checkout::writePayments($orderId, $userId, $orderNumber, $option, $total, $due, $date);

            Audit::record(
                'order.create_manual',
                'orders',
                $orderId,
                null,
                [
                    'order_number'   => $orderNumber,
                    'payment_option' => $option,
                    'total_subunit'  => $total,
                    'line_count'     => count($lines),
                    'overrides'      => count($resolved['overrides']),
                ],
                $staffId
            );

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return [
            'id'            => $orderId,
            'order_number'  => $orderNumber,
            'total_subunit' => $total,
            'trail_token'   => $token,
        ];
    }

    /** How the order reached us, for the first line of its history. */
    public static function channelLabel(string $channel): string
    {
        $labels = [
            'phone'    => 'by phone',
            'whatsapp' => 'on WhatsApp',
            'walk_in'  => 'in person',
        ];
        return $labels[$channel] ?? 'by phone';
    }

    /** The delivery details, checked the way checkout checks them. */
    private static function validateAddress(array $input, array $customer): array
    {
        $name = trim((string) ($input['recipient_name'] ?? ''));
        if ($name === '') {
            $name = trim($customer['first_name'] . ' ' . $customer['last_name']);
        }
        $phone = Phone::normalize((string) ($input['recipient_phone'] ?? '')) ?? (string) $customer['phone'];

        foreach (['address_line_1', 'city', 'state'] as $field) {
            if (trim((string) ($input[$field] ?? '')) === '') {
                throw new DomainException('address_required');
            }
        }

        return [
            'recipient_name'  => mb_substr($name, 0, 150),
            'recipient_phone' => $phone,
            'address_line_1'  => mb_substr(trim((string) $input['address_line_1']), 0, 255),
            'address_line_2'  => mb_substr(trim((string) ($input['address_line_2'] ?? '')), 0, 255) ?: null,
            'city'            => mb_substr(trim((string) $input['city']), 0, 120),
            'state'           => mb_substr(trim((string) $input['state']), 0, 120),
            'landmark'        => mb_substr(trim((string) ($input['landmark'] ?? '')), 0, 255) ?: null,
        ];
    }

    /**
     * Snapshot the lines onto the order. A combo fans out into its components,
     * exactly as a checkout order does, so the packing list reads the parts
     * without depending on a combo definition the Manager may edit next week.
     */
    private static function insertLines(PDO $pdo, int $orderId, array $lines): void
    {
        foreach ($lines as $line) {
            Database::run(
                'INSERT INTO order_items
                    (order_id, item_type, product_id, combo_package_id, item_name, sku, unit_name,
                     quantity, unit_price_subunit, line_total_subunit)
                 VALUES (:order, :type, :product, :combo, :name, :sku, :unit, :quantity, :price, :line_total)',
                [
                    ':order'      => $orderId,
                    ':type'       => $line['item_type'],
                    ':product'    => $line['product_id'],
                    ':combo'      => $line['combo_package_id'],
                    ':name'       => $line['item_name'],
                    ':sku'        => $line['sku'],
                    ':unit'       => $line['unit_name'],
                    ':quantity'   => $line['quantity'],
                    ':price'      => $line['unit_price_subunit'],
                    ':line_total' => $line['line_total_subunit'],
                ]
            );
            if ($line['item_type'] !== 'combo') {
                continue;
            }

            $orderItemId = (int) $pdo->lastInsertId();
            foreach (Database::all(
                'SELECT cpi.product_id, p.name AS product_name, cpi.quantity, u.name AS unit_name
                   FROM combo_package_items cpi
                   JOIN products p ON p.id = cpi.product_id
                   JOIN units_of_measurement u ON u.id = cpi.unit_id
                  WHERE cpi.combo_package_id = :id
                  ORDER BY cpi.id',
                [':id' => (int) $line['combo_package_id']]
            ) as $component) {
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

    /** Plain copy for each refusal code, so no exception text reaches a screen. */
    public static function message(string $code): string
    {
        $messages = [
            'bad_customer'        => 'Choose the customer this order is for.',
            'payment_not_allowed' => 'That payment choice is not open to this customer.',
            'credit_not_approved' => 'This business is not approved for credit yet, so it cannot order on account.',
            'address_required'    => 'Enter the street, the city and the state for the delivery.',
            'delivery_unavailable' => 'We do not deliver to this customer on that day. Pick one of the days offered.',
            'zone_unavailable'    => 'Choose the area this order is going to.',
            'no_lines'            => 'Add at least one item to the order.',
            'too_many_lines'      => 'That is more lines than one order can carry. Split it in two.',
            'bad_line_type'       => 'One of the lines is not a product, a combo or a typed item.',
            'item_required'       => 'One line has a quantity or a price but nothing chosen on it. Pick the item, or clear the line.',
            'bad_quantity'        => 'Enter a quantity greater than zero on every line, with at most three decimal places.',
            'bad_price'           => 'A price on one of the lines is not a number.',
            'name_required'       => 'A typed line needs the name of what we are selling.',
            'price_required'      => 'A typed line needs a price. We have none of our own for it.',
            'product_missing'     => 'One of the products is no longer on sale. Take it off the order.',
            'combo_missing'       => 'One of the combos is no longer on sale. Take it off the order.',
            'no_price'            => 'One of the items has no price on the sheet. Set this week\'s price first, or type one here.',
            'no_value'            => 'The order comes to nothing. Check the quantities and prices.',
            'deposit_required'    => 'The deposit works out at nothing. Check the total, or take the payment in full.',
            'note_too_long'       => 'That note is too long. Shorten it.',
        ];
        return $messages[$code] ?? 'We could not create that order. Check the details and try again.';
    }
}

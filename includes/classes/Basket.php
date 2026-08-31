<?php
/**
 * includes/classes/Basket.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The basket: what is in it, what it costs, and every rule about
 * how a line is added, edited, removed or merged. Products and combos share
 * one table, cart_items, and one set of rules.
 *
 * Three ideas run through this class.
 *
 * 1. A line remembers the price the customer was given. Adding the same
 *    product again after a reprice never rewrites that price. It opens a
 *    second line at the new price, so a basket can honestly hold 1kg at
 *    ₦2,700 and 1kg at ₦3,000. One row carries one price; two prices need two
 *    rows. (M4 decision Q1 and Q2.)
 * 2. The maths is integer subunits, always through Money.
 * 3. Every quantity is checked on the server against the product's own
 *    minimum and increment, and against a packing ceiling, whatever the form
 *    or the fetch call asked for.
 *
 * The pure helpers at the top (quantities, subtotal, the add plan, the merge
 * plan) hold no database and are unit tested in scripts/tests/BasketTest.php.
 * The database methods below them are covered by scripts/tests/basket_db_test.php.
 * -----------------------------------------------------------------------------
 */

final class Basket
{
    private const SESSION_TOKEN_KEY = 'okv_basket_token';

    /**
     * Packing ceilings per line. Above these it is not a shopping basket any
     * more, it is a Kitchen Run, and the customer is pointed there.
     * (M4 decision Q3.)
     */
    public const MAX_PRODUCT_QUANTITY = 100.0;
    public const MAX_COMBO_QUANTITY   = 20.0;

    /** How long a guest basket survives without a sign-in. (M4 decision Q6.) */
    public const GUEST_CART_DAYS = 30;

    /** The one notice shown at the top of a basket that holds two prices for one item. */
    public const REPRICED_NOTICE = 'Prices moved while these items sat in your basket. What you added earlier keeps the price you were given, and anything added since is at this week\'s price.';

    // -------------------------------------------------------------------------
    // Pure rules. No database, no session. Unit tested.
    // -------------------------------------------------------------------------

    /** The ceiling that applies to a line of this type. */
    public static function ceilingFor(string $itemType): float
    {
        return $itemType === 'combo' ? self::MAX_COMBO_QUANTITY : self::MAX_PRODUCT_QUANTITY;
    }

    /**
     * Read a quantity a customer typed. Returns null when it is not a number
     * at all, so the caller can refuse it rather than treat it as zero.
     */
    private static function readQuantity($value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $clean = trim($value);
        if ($clean === '' || !preg_match('/^-?\d*\.?\d+$/', $clean)) {
            return null;
        }
        return (float) $clean;
    }

    /**
     * The server's word on a product quantity. Below the minimum is refused,
     * because that is a packing rule, not a preference. Between two steps
     * rounds up to the next step, never down, so nobody is quietly given less
     * than they asked for. Above the ceiling is refused.
     *
     * Throws DomainException with one of: invalid_quantity, below_minimum,
     * over_ceiling. The caller turns that into a sentence with quantityMessage.
     */
    public static function normaliseProductQuantity($value, $minimum, $increment): float
    {
        $quantity = self::readQuantity($value);
        if ($quantity === null || $quantity <= 0) {
            throw new DomainException('invalid_quantity');
        }

        $min = round((float) $minimum, 3);
        if ($min <= 0) {
            $min = 0.001;
        }
        $step = round((float) $increment, 3);
        if ($step <= 0) {
            $step = $min;
        }

        if ($quantity + 0.0005 < $min) {
            throw new DomainException('below_minimum');
        }

        $steps = (int) ceil((($quantity - $min) / $step) - 0.0005);
        if ($steps < 0) {
            $steps = 0;
        }
        $quantity = round($min + ($steps * $step), 3);

        if ($quantity > self::MAX_PRODUCT_QUANTITY) {
            throw new DomainException('over_ceiling');
        }
        return $quantity;
    }

    /**
     * A combo is a whole basket, so its quantity is a whole number. Anything
     * fractional rounds up: half a basket cannot be packed.
     */
    public static function normaliseComboQuantity($value): float
    {
        $quantity = self::readQuantity($value);
        if ($quantity === null || $quantity <= 0) {
            throw new DomainException('invalid_quantity');
        }
        $whole = (float) ceil($quantity - 0.0005);
        if ($whole < 1.0) {
            $whole = 1.0;
        }
        if ($whole > self::MAX_COMBO_QUANTITY) {
            throw new DomainException('over_ceiling');
        }
        return $whole;
    }

    /** The catalogue id a line points at, whichever kind of line it is. */
    private static function referenceId(array $line): int
    {
        return (string) ($line['item_type'] ?? '') === 'combo'
            ? (int) ($line['combo_package_id'] ?? 0)
            : (int) ($line['product_id'] ?? 0);
    }

    /** Sum of every line total, in subunits. */
    public static function subtotal(array $lines): int
    {
        $total = 0;
        foreach ($lines as $line) {
            $total += Money::lineTotal($line['quantity'] ?? 0, (int) ($line['unit_price_subunit'] ?? 0));
        }
        return $total;
    }

    /**
     * How many lines are in the basket, which is what the badge and its label
     * say. Never the sum of the quantities: those are kilogrammes, bunches and
     * heads, so adding them together counts 2kg of tomatoes as two items.
     */
    public static function lineCount(array $lines): int
    {
        return count($lines);
    }

    /**
     * Add the money and the reprice flag to each line. When one product holds
     * two prices, the first line is the original and every later line is
     * flagged, carrying the price it moved from. That is what puts the
     * "Price changed" badge on the newer row and the notice at the top of the
     * page. (M4 decision Q2.)
     */
    public static function decorateLines(array $lines): array
    {
        $firstPrice = [];
        $lastPrice  = [];
        $decorated  = [];

        foreach ($lines as $line) {
            $type  = (string) ($line['item_type'] ?? 'product');
            $key   = $type . ':' . self::referenceId($line);
            $price = (int) ($line['unit_price_subunit'] ?? 0);

            $lineTotal = Money::lineTotal($line['quantity'] ?? 0, $price);
            $changed   = isset($firstPrice[$key]) && $price !== $firstPrice[$key];

            $line['line_total_subunit']     = $lineTotal;
            $line['line_total_display']     = Money::format($lineTotal);
            $line['unit_price_display']     = Money::format($price);
            $line['price_changed']          = $changed;
            $line['previous_price_subunit'] = $changed ? $lastPrice[$key] : null;

            if (!isset($firstPrice[$key])) {
                $firstPrice[$key] = $price;
            }
            $lastPrice[$key] = $price;

            $decorated[] = $line;
        }

        return $decorated;
    }

    /** True when any line came from a reprice, so the notice is worth showing. */
    public static function hasRepricedLines(array $lines): bool
    {
        foreach ($lines as $line) {
            if (!empty($line['price_changed'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * What a fresh add should do to the basket, decided before a single row is
     * written.
     *
     * Same item at the same price: fold into the line already there. Same item
     * at a different price: open a new line and report the reprice, leaving
     * the old line exactly as the customer left it. Anything else: a new line.
     */
    public static function planAdd(array $lines, string $itemType, int $referenceId, float $quantity, int $unitPriceSubunit): array
    {
        $ceiling      = self::ceilingFor($itemType);
        $sameLine     = null;
        $otherPriced  = null;

        foreach ($lines as $line) {
            if ((string) ($line['item_type'] ?? '') !== $itemType) {
                continue;
            }
            if (self::referenceId($line) !== $referenceId) {
                continue;
            }
            if ((int) ($line['unit_price_subunit'] ?? 0) === $unitPriceSubunit) {
                $sameLine = $line;
            } else {
                $otherPriced = $line;
            }
        }

        if ($sameLine !== null) {
            $combined = round((float) $sameLine['quantity'] + $quantity, 3);
            $capped   = $combined > $ceiling;
            return [
                'line_id'                => (int) $sameLine['id'],
                'quantity'               => $capped ? $ceiling : $combined,
                'unit_price_subunit'     => $unitPriceSubunit,
                'repriced'               => false,
                'previous_price_subunit' => null,
                'capped'                 => $capped,
            ];
        }

        $capped = $quantity > $ceiling;
        return [
            'line_id'                => null,
            'quantity'               => $capped ? $ceiling : round($quantity, 3),
            'unit_price_subunit'     => $unitPriceSubunit,
            'repriced'               => $otherPriced !== null,
            'previous_price_subunit' => $otherPriced !== null ? (int) $otherPriced['unit_price_subunit'] : null,
            'capped'                 => $capped,
        ];
    }

    /**
     * How a guest basket folds into an account basket. A line the account
     * already holds at the same price adds its quantity to that line. A line
     * at a different price moves across on its own, so both prices survive the
     * sign-in exactly as they survive a reprice. (M4 decision Q1.)
     *
     * Returns ['fold' => [['guest_line_id','account_line_id','quantity']], 'move' => [guest line ids]].
     */
    public static function planMerge(array $guestLines, array $accountLines): array
    {
        $fold    = [];
        $move    = [];
        $running = [];

        foreach ($accountLines as $line) {
            $running[(int) $line['id']] = round((float) $line['quantity'], 3);
        }

        foreach ($guestLines as $guestLine) {
            $type  = (string) ($guestLine['item_type'] ?? 'product');
            $ref   = self::referenceId($guestLine);
            $price = (int) ($guestLine['unit_price_subunit'] ?? 0);

            $targetId = null;
            foreach ($accountLines as $accountLine) {
                if ((string) ($accountLine['item_type'] ?? '') !== $type) {
                    continue;
                }
                if (self::referenceId($accountLine) !== $ref) {
                    continue;
                }
                if ((int) ($accountLine['unit_price_subunit'] ?? 0) !== $price) {
                    continue;
                }
                $targetId = (int) $accountLine['id'];
                break;
            }

            if ($targetId === null) {
                $move[] = (int) $guestLine['id'];
                continue;
            }

            $combined = round($running[$targetId] + (float) $guestLine['quantity'], 3);
            $ceiling  = self::ceilingFor($type);
            if ($combined > $ceiling) {
                $combined = $ceiling;
            }
            $running[$targetId] = $combined;

            $fold[] = [
                'guest_line_id'   => (int) $guestLine['id'],
                'account_line_id' => $targetId,
                'quantity'        => $combined,
            ];
        }

        return ['fold' => $fold, 'move' => $move];
    }

    /** The sentence a customer reads when a quantity is refused. */
    public static function quantityMessage(string $reason, array $context = []): string
    {
        if ($reason === 'below_minimum') {
            $minimum = okv_quantity($context['minimum'] ?? 1);
            $unit    = (string) ($context['unit'] ?? '');
            return 'The smallest we can pack is ' . $minimum . $unit . '. Please ask for at least that much.';
        }
        if ($reason === 'over_ceiling') {
            return 'That is more than we can pack into one order. For an order that size, send us a Kitchen Run.';
        }
        return 'Enter how much you need, for example 2.';
    }

    /** The sentence a customer reads when an item repriced between two adds. */
    public static function repricedMessage(string $name, int $oldPriceSubunit, int $newPriceSubunit, string $unit = ''): string
    {
        $per = $unit !== '' ? ' per ' . $unit : '';
        return $name . ' moved from ' . Money::format($oldPriceSubunit) . ' to ' . Money::format($newPriceSubunit) . $per
            . '. What you added earlier keeps the old price.';
    }

    // -------------------------------------------------------------------------
    // Reads
    // -------------------------------------------------------------------------

    /** Line count for the header badge and the mini-cart. */
    public static function count(): int
    {
        $cartId = self::findActiveCartId();
        if ($cartId === null) {
            return 0;
        }
        $row = Database::one(
            'SELECT COUNT(*) AS line_count FROM cart_items WHERE cart_id = :cart_id',
            [':cart_id' => $cartId]
        );
        return (int) ($row['line_count'] ?? 0);
    }

    /**
     * Everything a basket page, a mini-cart or the cart API needs, in one
     * round-trip: the lines with their money, the subtotal and whether the
     * repriced notice belongs on the page.
     */
    public static function state(): array
    {
        $lines    = self::decorateLines(self::lines());
        $subtotal = self::subtotal($lines);
        $repriced = self::hasRepricedLines($lines);

        return [
            'line_count'        => count($lines),
            'lines'             => $lines,
            'subtotal_subunit'  => $subtotal,
            'subtotal_display'  => Money::format($subtotal),
            'has_repriced'      => $repriced,
            'repriced_notice'   => $repriced ? self::REPRICED_NOTICE : '',
            'basket_url'        => '/cart.php',
            'checkout_url'      => '/checkout.php',
        ];
    }

    /**
     * The basket's lines with the catalogue detail a page needs: name, unit,
     * photo, this week's price and whether the item can still be packed. One
     * query, products and combos together, ordered by the order they were
     * added so the original price snapshot always comes before the newer one.
     */
    public static function lines(): array
    {
        $cartId = self::findActiveCartId();
        if ($cartId === null) {
            return [];
        }

        $rows = Database::all(
            'SELECT ci.id, ci.item_type, ci.product_id, ci.combo_package_id, ci.quantity, ci.unit_price_subunit,
                    p.name AS product_name, p.slug AS product_slug, p.is_active AS product_active,
                    p.current_price_subunit, p.minimum_quantity, p.quantity_increment,
                    u.symbol AS unit, u.allows_decimal,
                    COALESCE(pa.availability_status, \'available\') AS availability_status, pa.restock_date,
                    (SELECT pi.image_url FROM product_images pi
                      WHERE pi.product_id = ci.product_id
                      ORDER BY pi.is_primary DESC, pi.sort_order, pi.id LIMIT 1) AS product_image,
                    cp.name AS combo_name, cp.slug AS combo_slug, cp.is_active AS combo_active,
                    cp.price_subunit AS combo_price_subunit, cp.image_url AS combo_image,
                    cp.available_from, cp.available_until,
                    (SELECT COUNT(*) FROM combo_package_items cpi WHERE cpi.combo_package_id = ci.combo_package_id) AS component_count,
                    (SELECT pi2.image_url
                       FROM combo_package_items cpi2
                       JOIN product_images pi2 ON pi2.product_id = cpi2.product_id
                      WHERE cpi2.combo_package_id = ci.combo_package_id
                      ORDER BY cpi2.id ASC, pi2.is_primary DESC, pi2.sort_order ASC, pi2.id ASC
                      LIMIT 1) AS combo_fallback_image
               FROM cart_items ci
               LEFT JOIN products p ON p.id = ci.product_id
               LEFT JOIN units_of_measurement u ON u.id = p.unit_id
               LEFT JOIN product_availability pa ON pa.product_id = ci.product_id
               LEFT JOIN combo_packages cp ON cp.id = ci.combo_package_id
              WHERE ci.cart_id = :cart_id
              ORDER BY ci.id',
            [':cart_id' => $cartId]
        );

        $lines = [];
        foreach ($rows as $row) {
            $lines[] = self::presentLine($row);
        }
        return $lines;
    }

    /** Turn one joined row into the shape a template and the API both read. */
    private static function presentLine(array $row): array
    {
        $isCombo = (string) $row['item_type'] === 'combo';

        if ($isCombo) {
            $name      = (string) ($row['combo_name'] ?? 'Combo');
            $slug      = (string) ($row['combo_slug'] ?? '');
            $url       = $slug !== '' ? '/combo.php?slug=' . rawurlencode($slug) : '/combos.php';
            $image     = trim((string) ($row['combo_image'] ?? '')) !== ''
                ? (string) $row['combo_image']
                : (string) ($row['combo_fallback_image'] ?? '');
            $unit      = '';
            $current   = (int) ($row['combo_price_subunit'] ?? 0);
            $buyable   = Combos::isBuyableNow([
                'is_active'       => (int) ($row['combo_active'] ?? 0),
                'available_from'  => $row['available_from'] ?? null,
                'available_until' => $row['available_until'] ?? null,
            ]);
            $note      = $buyable ? '' : 'This basket has left the shop. Remove it to carry on.';
            $minimum   = 1.0;
            $increment = 1.0;
        } else {
            $name      = (string) ($row['product_name'] ?? 'Item');
            $slug      = (string) ($row['product_slug'] ?? '');
            $url       = $slug !== '' ? '/product.php?slug=' . rawurlencode($slug) : '/shop.php';
            $image     = (string) ($row['product_image'] ?? '');
            $unit      = (string) ($row['unit'] ?? '');
            $current   = (int) ($row['current_price_subunit'] ?? 0);
            $status    = (string) ($row['availability_status'] ?? 'available');
            $buyable   = ((int) ($row['product_active'] ?? 0) === 1) && $status === 'available';
            $available = okv_availability($status, $row['restock_date'] ?? null);
            $note      = $buyable ? '' : $available['label'] . '. Remove it, or keep it and we will follow up.';
            $minimum   = (float) ($row['minimum_quantity'] ?? 1);
            $increment = (float) ($row['quantity_increment'] ?? 1);
        }

        return [
            'id'                    => (int) $row['id'],
            'item_type'             => (string) $row['item_type'],
            'product_id'            => $row['product_id'] !== null ? (int) $row['product_id'] : null,
            'combo_package_id'      => $row['combo_package_id'] !== null ? (int) $row['combo_package_id'] : null,
            'name'                  => $name,
            'url'                   => $url,
            'image'                 => $image,
            'image_url'             => $image !== '' ? okv_image_url($image) : '',
            'unit'                  => $unit,
            'quantity'              => round((float) $row['quantity'], 3),
            'quantity_display'      => okv_quantity($row['quantity']),
            'unit_price_subunit'    => (int) $row['unit_price_subunit'],
            'current_price_subunit' => $current,
            'minimum_quantity'      => $minimum,
            'quantity_increment'    => $increment,
            'component_count'       => (int) ($row['component_count'] ?? 0),
            'is_orderable'          => $buyable,
            'availability_note'     => $note,
        ];
    }

    /** True when there is nothing to check out. */
    public static function isEmpty(): bool
    {
        return self::count() === 0;
    }

    // -------------------------------------------------------------------------
    // Writes
    // -------------------------------------------------------------------------

    /**
     * Add one step of a product. The first add puts the product's minimum in
     * the basket; every add after that adds one increment. A repeat add at
     * today's price folds into the line already there; a repeat add after the
     * price moved opens a second line and leaves the first alone.
     */
    public static function addProduct(int $productId): array
    {
        $pdo = Database::getInstance()->getConnection();
        try {
            $pdo->beginTransaction();

            $product = Database::one(
                'SELECT p.id, p.name, p.current_price_subunit, p.minimum_quantity, p.quantity_increment,
                        COALESCE(pa.availability_status, \'available\') AS availability_status
                   FROM products p
                   LEFT JOIN product_availability pa ON pa.product_id = p.id
                  WHERE p.id = :product_id AND p.is_active = 1
                  FOR UPDATE',
                [':product_id' => $productId]
            );
            if (!$product) {
                throw new BasketError('not_found', 'That product was not found.');
            }
            if ($product['availability_status'] !== 'available') {
                throw new BasketError('unavailable', 'That item is not available yet. Check its restock status and try again later.');
            }

            // The unit is read separately so the notice can say "per kg".
            $unitRow = Database::one(
                'SELECT u.symbol AS unit FROM products p JOIN units_of_measurement u ON u.id = p.unit_id WHERE p.id = :product_id',
                [':product_id' => $productId]
            );
            $unit = (string) ($unitRow['unit'] ?? '');

            $cartId = self::activeCartId();
            $lines  = self::linesForUpdate($cartId);

            $hasLine = false;
            foreach ($lines as $line) {
                if ($line['item_type'] === 'product' && (int) $line['product_id'] === $productId) {
                    $hasLine = true;
                    break;
                }
            }

            $step = (float) $product['quantity_increment'];
            if ($step <= 0) {
                $step = (float) $product['minimum_quantity'];
            }
            $quantityToAdd = $hasLine ? $step : (float) $product['minimum_quantity'];
            if ($quantityToAdd <= 0) {
                $quantityToAdd = 1.0;
            }

            $price = (int) $product['current_price_subunit'];
            $plan  = self::planAdd($lines, 'product', $productId, $quantityToAdd, $price);
            self::applyAdd($cartId, $plan, 'product', $productId);
            self::touchCart($cartId);

            $pdo->commit();

            return self::addResult($plan, (string) $product['name'], $unit, $quantityToAdd);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Add one combo. Same rules as a product: one basket per add, folding into
     * the line already there when the price has not moved, opening a second
     * line when it has.
     */
    public static function addCombo(int $comboId): array
    {
        $pdo = Database::getInstance()->getConnection();
        try {
            $pdo->beginTransaction();

            $combo = Database::one(
                'SELECT id, name, price_subunit, is_active, available_from, available_until
                   FROM combo_packages
                  WHERE id = :combo_id
                  FOR UPDATE',
                [':combo_id' => $comboId]
            );
            if (!$combo) {
                throw new BasketError('not_found', 'That combo was not found.');
            }
            if (!Combos::isBuyableNow($combo) || (int) $combo['price_subunit'] < 1) {
                throw new BasketError('unavailable', 'That combo is no longer on the shop.');
            }

            $cartId = self::activeCartId();
            $lines  = self::linesForUpdate($cartId);
            $price  = (int) $combo['price_subunit'];
            $plan   = self::planAdd($lines, 'combo', $comboId, 1.0, $price);
            self::applyAdd($cartId, $plan, 'combo', $comboId);
            self::touchCart($cartId);

            $pdo->commit();

            return self::addResult($plan, (string) $combo['name'], '', 1.0);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Write the plan: grow the existing line, or open a new one. Never both. */
    private static function applyAdd(int $cartId, array $plan, string $itemType, int $referenceId): void
    {
        if ($plan['line_id'] !== null) {
            // The price is deliberately left alone. A line keeps the price the
            // customer was given when they added it.
            Database::run(
                'UPDATE cart_items SET quantity = :quantity WHERE id = :id',
                [':quantity' => $plan['quantity'], ':id' => $plan['line_id']]
            );
            return;
        }

        if ($itemType === 'combo') {
            Database::run(
                'INSERT INTO cart_items (cart_id, item_type, combo_package_id, quantity, unit_price_subunit)
                 VALUES (:cart_id, \'combo\', :combo_id, :quantity, :price)',
                [
                    ':cart_id'  => $cartId,
                    ':combo_id' => $referenceId,
                    ':quantity' => $plan['quantity'],
                    ':price'    => $plan['unit_price_subunit'],
                ]
            );
            return;
        }

        Database::run(
            'INSERT INTO cart_items (cart_id, item_type, product_id, quantity, unit_price_subunit)
             VALUES (:cart_id, \'product\', :product_id, :quantity, :price)',
            [
                ':cart_id'    => $cartId,
                ':product_id' => $referenceId,
                ':quantity'   => $plan['quantity'],
                ':price'      => $plan['unit_price_subunit'],
            ]
        );
    }

    /** The payload an add returns, including the reprice notice when there is one. */
    private static function addResult(array $plan, string $name, string $unit, float $quantityAdded): array
    {
        $notice = '';
        if ($plan['repriced'] && $plan['previous_price_subunit'] !== null) {
            $notice = self::repricedMessage($name, (int) $plan['previous_price_subunit'], (int) $plan['unit_price_subunit'], $unit);
        } elseif ($plan['capped']) {
            $notice = self::quantityMessage('over_ceiling');
        }

        return [
            'quantity_added'         => $quantityAdded,
            'quantity'               => $plan['quantity'],
            'count'                  => self::count(),
            'repriced'               => (bool) $plan['repriced'],
            'previous_price_subunit' => $plan['previous_price_subunit'],
            'unit_price_subunit'     => (int) $plan['unit_price_subunit'],
            'capped'                 => (bool) $plan['capped'],
            'notice'                 => $notice,
        ];
    }

    /**
     * Set the quantity on one product line. The minimum applies to a product
     * the basket holds once; a second price-snapshot line of the same product
     * only has to respect the step, because the two lines together already
     * clear the minimum.
     */
    public static function updateProductLine(int $lineId, $quantity): array
    {
        $pdo = Database::getInstance()->getConnection();
        try {
            $pdo->beginTransaction();
            $cartId = self::requireCartId();

            $line = Database::one(
                'SELECT ci.id, ci.product_id, ci.quantity, p.name, p.minimum_quantity, p.quantity_increment,
                        u.symbol AS unit
                   FROM cart_items ci
                   JOIN products p ON p.id = ci.product_id
                   LEFT JOIN units_of_measurement u ON u.id = p.unit_id
                  WHERE ci.id = :id AND ci.cart_id = :cart_id AND ci.item_type = \'product\'
                  FOR UPDATE',
                [':id' => $lineId, ':cart_id' => $cartId]
            );
            if (!$line) {
                throw new BasketError('not_found', 'That item is no longer in your basket.');
            }

            $siblings = Database::one(
                'SELECT COUNT(*) AS line_count FROM cart_items
                  WHERE cart_id = :cart_id AND item_type = \'product\' AND product_id = :product_id',
                [':cart_id' => $cartId, ':product_id' => (int) $line['product_id']]
            );
            $isOnlyLine = (int) ($siblings['line_count'] ?? 1) <= 1;

            $step      = (float) $line['quantity_increment'];
            $minimum   = $isOnlyLine ? (float) $line['minimum_quantity'] : ($step > 0 ? $step : (float) $line['minimum_quantity']);
            $unit      = (string) ($line['unit'] ?? '');

            try {
                $clean = self::normaliseProductQuantity($quantity, $minimum, $step);
            } catch (DomainException $e) {
                throw new BasketError(
                    $e->getMessage(),
                    self::quantityMessage($e->getMessage(), ['minimum' => $minimum, 'unit' => $unit])
                );
            }

            Database::run(
                'UPDATE cart_items SET quantity = :quantity WHERE id = :id',
                [':quantity' => $clean, ':id' => (int) $line['id']]
            );
            self::touchCart($cartId);
            $pdo->commit();

            $adjusted = abs($clean - (float) self::readQuantityOrZero($quantity)) > 0.0005;
            return [
                'line_id'  => (int) $line['id'],
                'quantity' => $clean,
                'adjusted' => $adjusted,
                'message'  => $adjusted
                    ? 'Rounded up to ' . okv_quantity($clean) . $unit . ', the nearest step we can pack.'
                    : 'Basket updated.',
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Set the quantity on one combo line. Whole baskets only. */
    public static function updateComboLine(int $lineId, $quantity): array
    {
        $pdo = Database::getInstance()->getConnection();
        try {
            $pdo->beginTransaction();
            $cartId = self::requireCartId();

            $line = Database::one(
                'SELECT ci.id, ci.combo_package_id, ci.quantity, cp.name
                   FROM cart_items ci
                   JOIN combo_packages cp ON cp.id = ci.combo_package_id
                  WHERE ci.id = :id AND ci.cart_id = :cart_id AND ci.item_type = \'combo\'
                  FOR UPDATE',
                [':id' => $lineId, ':cart_id' => $cartId]
            );
            if (!$line) {
                throw new BasketError('not_found', 'That basket is no longer in your order.');
            }

            try {
                $clean = self::normaliseComboQuantity($quantity);
            } catch (DomainException $e) {
                throw new BasketError($e->getMessage(), self::quantityMessage($e->getMessage(), ['minimum' => 1, 'unit' => '']));
            }

            Database::run(
                'UPDATE cart_items SET quantity = :quantity WHERE id = :id',
                [':quantity' => $clean, ':id' => (int) $line['id']]
            );
            self::touchCart($cartId);
            $pdo->commit();

            return [
                'line_id'  => (int) $line['id'],
                'quantity' => $clean,
                'adjusted' => false,
                'message'  => 'Basket updated.',
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Take one line out of the basket. The line has to be in this basket. */
    public static function removeLine(int $lineId, string $itemType): array
    {
        $itemType = $itemType === 'combo' ? 'combo' : 'product';
        $pdo = Database::getInstance()->getConnection();
        try {
            $pdo->beginTransaction();
            $cartId = self::requireCartId();

            $line = Database::one(
                'SELECT id FROM cart_items WHERE id = :id AND cart_id = :cart_id AND item_type = :item_type FOR UPDATE',
                [':id' => $lineId, ':cart_id' => $cartId, ':item_type' => $itemType]
            );
            if (!$line) {
                throw new BasketError('not_found', 'That item is no longer in your basket.');
            }

            Database::run('DELETE FROM cart_items WHERE id = :id', [':id' => (int) $line['id']]);
            self::touchCart($cartId);
            $pdo->commit();

            return [
                'line_id' => (int) $line['id'],
                'message' => $itemType === 'combo' ? 'Basket removed from your order.' : 'Item removed from your basket.',
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Fold the guest basket for this session into the signed-in customer's
     * basket, in one transaction. Lines the account already holds at the same
     * price add up; lines at a different price move across on their own, so a
     * price the customer was given is never lost.
     *
     * The source cart is then marked merged and gives up its session token,
     * because shopping_carts.session_token is unique and a merged row holding
     * it would block the next guest cart on this browser. The session token is
     * rotated for the same reason.
     */
    public static function mergeGuestCart(?int $userId = null): array
    {
        $userId = $userId ?? Customer::id();
        $token  = $_SESSION[self::SESSION_TOKEN_KEY] ?? '';
        $idle   = ['merged' => false, 'moved' => 0, 'folded' => 0, 'count' => 0];

        if ($userId === null || !is_string($token) || $token === '') {
            return $idle;
        }

        $pdo = Database::getInstance()->getConnection();
        try {
            $pdo->beginTransaction();

            $guestCarts = Database::all(
                'SELECT id FROM shopping_carts
                  WHERE session_token = :token AND status = \'active\' AND user_id IS NULL
                  ORDER BY id
                  FOR UPDATE',
                [':token' => $token]
            );

            if (!$guestCarts) {
                // Nothing to merge, but the token may still be held by an older
                // row. Release it so the next guest cart on this browser can
                // claim a fresh one.
                Database::run(
                    'UPDATE shopping_carts SET session_token = NULL WHERE session_token = :token',
                    [':token' => $token]
                );
                $pdo->commit();
                unset($_SESSION[self::SESSION_TOKEN_KEY]);
                return $idle;
            }

            $accountCartId = self::accountCartId($userId);
            $accountLines  = self::linesForUpdate($accountCartId);
            $moved = 0;
            $folded = 0;

            foreach ($guestCarts as $guestCart) {
                $guestCartId = (int) $guestCart['id'];
                if ($guestCartId === $accountCartId) {
                    continue;
                }

                $guestLines = self::linesForUpdate($guestCartId);
                $plan = self::planMerge($guestLines, $accountLines);

                foreach ($plan['fold'] as $fold) {
                    Database::run(
                        'UPDATE cart_items SET quantity = :quantity WHERE id = :id',
                        [':quantity' => $fold['quantity'], ':id' => $fold['account_line_id']]
                    );
                    Database::run('DELETE FROM cart_items WHERE id = :id', [':id' => $fold['guest_line_id']]);
                    $folded++;
                }
                foreach ($plan['move'] as $guestLineId) {
                    Database::run(
                        'UPDATE cart_items SET cart_id = :cart_id WHERE id = :id',
                        [':cart_id' => $accountCartId, ':id' => $guestLineId]
                    );
                    $moved++;
                }

                Database::run(
                    'UPDATE shopping_carts SET status = \'merged\', session_token = NULL WHERE id = :id',
                    [':id' => $guestCartId]
                );

                // Re-read so the next guest cart folds against what is now there.
                $accountLines = self::linesForUpdate($accountCartId);
            }

            // Any other row still holding this token gives it up too.
            Database::run(
                'UPDATE shopping_carts SET session_token = NULL WHERE session_token = :token',
                [':token' => $token]
            );
            self::touchCart($accountCartId);

            $pdo->commit();
            unset($_SESSION[self::SESSION_TOKEN_KEY]);

            $countRow = Database::one(
                'SELECT COUNT(*) AS line_count FROM cart_items WHERE cart_id = :cart_id',
                [':cart_id' => $accountCartId]
            );

            return [
                'merged' => ($moved + $folded) > 0,
                'moved'  => $moved,
                'folded' => $folded,
                'count'  => (int) ($countRow['line_count'] ?? 0),
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Cart plumbing
    // -------------------------------------------------------------------------

    /** The lines of a cart, locked, in the shape the pure planners expect. */
    private static function linesForUpdate(int $cartId): array
    {
        return Database::all(
            'SELECT id, item_type, product_id, combo_package_id, quantity, unit_price_subunit
               FROM cart_items
              WHERE cart_id = :cart_id
              ORDER BY id
              FOR UPDATE',
            [':cart_id' => $cartId]
        );
    }

    /** The basket a write must act on. Refuses rather than creating one. */
    private static function requireCartId(): int
    {
        $cartId = self::findActiveCartId();
        if ($cartId === null) {
            throw new BasketError('not_found', 'Your basket is empty.');
        }
        return $cartId;
    }

    /** Keep a guest basket alive for another 30 days each time it is touched. */
    private static function touchCart(int $cartId): void
    {
        Database::run(
            'UPDATE shopping_carts SET expires_at = :expires_at WHERE id = :id AND user_id IS NULL',
            [':expires_at' => self::guestExpiryStamp(), ':id' => $cartId]
        );
    }

    private static function guestExpiryStamp(): string
    {
        return date('Y-m-d H:i:s', strtotime('+' . self::GUEST_CART_DAYS . ' days'));
    }

    private static function activeCartId(): int
    {
        $existing = self::findActiveCartId();
        if ($existing !== null) {
            return $existing;
        }

        $userId = Customer::id();
        if ($userId !== null) {
            return self::accountCartId($userId);
        }

        $token = self::guestToken();
        Database::run(
            'INSERT INTO shopping_carts (session_token, status, expires_at) VALUES (:token, \'active\', :expires_at)',
            [':token' => $token, ':expires_at' => self::guestExpiryStamp()]
        );
        return (int) Database::getInstance()->getConnection()->lastInsertId();
    }

    /** The signed-in customer's active cart, created if they do not have one. */
    private static function accountCartId(int $userId): int
    {
        $row = Database::one(
            'SELECT id FROM shopping_carts WHERE user_id = :user_id AND status = \'active\' ORDER BY id DESC LIMIT 1',
            [':user_id' => $userId]
        );
        if ($row) {
            return (int) $row['id'];
        }
        Database::run(
            'INSERT INTO shopping_carts (user_id, status) VALUES (:user_id, \'active\')',
            [':user_id' => $userId]
        );
        return (int) Database::getInstance()->getConnection()->lastInsertId();
    }

    /**
     * The active cart for whoever is asking. A guest basket past its 30 days
     * is retired rather than served: the row is marked abandoned, it gives up
     * the unique session token, and the session starts a fresh basket.
     */
    private static function findActiveCartId(): ?int
    {
        $userId = Customer::id();
        if ($userId !== null) {
            $row = Database::one(
                'SELECT id FROM shopping_carts WHERE user_id = :user_id AND status = \'active\' ORDER BY id DESC LIMIT 1',
                [':user_id' => $userId]
            );
            return $row ? (int) $row['id'] : null;
        }

        $token = $_SESSION[self::SESSION_TOKEN_KEY] ?? '';
        if (!is_string($token) || $token === '') {
            return null;
        }

        $row = Database::one(
            'SELECT id, expires_at FROM shopping_carts
              WHERE session_token = :token AND status = \'active\'
              ORDER BY id DESC LIMIT 1',
            [':token' => $token]
        );
        if (!$row) {
            return null;
        }

        $expiresAt = $row['expires_at'] ?? null;
        if ($expiresAt !== null && strtotime((string) $expiresAt) !== false && strtotime((string) $expiresAt) <= time()) {
            Database::run(
                'UPDATE shopping_carts SET status = \'abandoned\', session_token = NULL WHERE id = :id',
                [':id' => (int) $row['id']]
            );
            unset($_SESSION[self::SESSION_TOKEN_KEY]);
            return null;
        }

        return (int) $row['id'];
    }

    private static function guestToken(): string
    {
        $token = $_SESSION[self::SESSION_TOKEN_KEY] ?? '';
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::SESSION_TOKEN_KEY] = $token;
        }
        return $token;
    }

    /** Only used to tell a customer their number was rounded up. */
    private static function readQuantityOrZero($value): float
    {
        $quantity = self::readQuantity($value);
        return $quantity === null ? 0.0 : $quantity;
    }
}

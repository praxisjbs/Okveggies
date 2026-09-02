<?php
/**
 * includes/classes/Basket.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The basket: what is in it, what it costs, and every rule about
 * how a line is added, edited, removed or merged. Products and combos share one
 * table, cart_items, and one set of rules.
 *
 * Three ideas run through this class.
 *
 *   1. A line remembers the price the customer was given. Adding the same item
 *      again after a reprice never rewrites that price. It opens a second line
 *      at the new price, so a basket can honestly hold 1kg at N2,700 and 1kg at
 *      N3,000. One row carries one price, so two prices need two rows. This is
 *      the M4 carry-forward from the M3 audit.
 *   2. The money is integer subunits, always through Money.
 *   3. Quantities are integer thousandths (milli-units) so 0.1kg + 0.2kg is
 *      exactly 0.3kg, never a float that drifts. Every quantity is checked on
 *      the server against the item's own minimum and increment and against a
 *      packing ceiling, whatever the form or the fetch call asked for.
 *
 * The pure helpers near the top hold no database and no session and are unit
 * tested in scripts/tests/BasketTest.php. The database methods below them are
 * covered by scripts/tests/basket_db_test.php.
 * -----------------------------------------------------------------------------
 */

final class Basket
{
    private const SESSION_TOKEN_KEY = 'okv_basket_token';

    /**
     * Packing ceilings per line, in whole units. Above these it is no longer a
     * shopping basket, it is a Kitchen Run, and the customer is pointed there.
     */
    public const MAX_PRODUCT_QUANTITY = 100;
    public const MAX_COMBO_QUANTITY   = 20;

    /** How long a guest basket survives without a sign-in. */
    public const GUEST_CART_DAYS = 30;

    /** The one notice shown at the top of a basket that holds two prices for one item. */
    public const REPRICED_NOTICE = 'Prices moved while these items sat in your basket. What you added earlier keeps the price you were given, and anything added since is at this week\'s price.';

    // -------------------------------------------------------------------------
    // Pure quantity maths. No database, no session. Unit tested.
    // -------------------------------------------------------------------------

    /** Read a quantity as integer thousandths, or null when it is not a number. */
    private static function quantityMilli(string $quantity): ?int
    {
        $quantity = trim($quantity);
        if (!preg_match('/^([0-9]+)(?:\.([0-9]{1,3}))?$/', $quantity, $match)) {
            return null;
        }
        return ((int) $match[1]) * 1000 + (int) str_pad($match[2] ?? '', 3, '0');
    }

    /** Render integer thousandths back as a decimal string, for example 1500 -> "1.500". */
    private static function milliToQuantity(int $milli): string
    {
        return intdiv($milli, 1000) . '.' . str_pad((string) ($milli % 1000), 3, '0', STR_PAD_LEFT);
    }

    /** Add two quantity strings exactly, in thousandths. */
    private static function addQuantities(string $left, string $right): string
    {
        $leftMilli  = self::quantityMilli($left);
        $rightMilli = self::quantityMilli($right);
        if ($leftMilli === null || $rightMilli === null) {
            throw new InvalidArgumentException('invalid_quantity');
        }
        return self::milliToQuantity($leftMilli + $rightMilli);
    }

    /** Normalise a customer-typed quantity to a canonical decimal string, or refuse it. */
    private static function normaliseQuantity(string $quantity): string
    {
        $milli = self::quantityMilli($quantity);
        if ($milli === null) {
            throw new InvalidArgumentException('invalid_quantity');
        }
        return self::milliToQuantity($milli);
    }

    /** The ceiling for a line of this type, in thousandths. */
    private static function ceilingMilli(string $itemType): int
    {
        return ($itemType === 'combo' ? self::MAX_COMBO_QUANTITY : self::MAX_PRODUCT_QUANTITY) * 1000;
    }

    /**
     * The server's word on a product quantity. Sits on or above the minimum,
     * lands exactly on a packing step, and does not pass the ceiling.
     */
    public static function isValidProductQuantity(string $quantity, string $minimum, string $increment): bool
    {
        $value = self::quantityMilli($quantity);
        $min   = self::quantityMilli($minimum);
        $step  = self::quantityMilli($increment);
        if ($value === null || $min === null || $step === null) {
            return false;
        }
        return $value >= $min
            && $step > 0
            && (($value - $min) % $step === 0)
            && $value <= self::ceilingMilli('product');
    }

    /** A combo is a whole basket, so its quantity is a whole number within the ceiling. */
    public static function isValidComboQuantity(string $quantity): bool
    {
        $value = self::quantityMilli($quantity);
        return $value !== null
            && $value >= 1000
            && $value <= self::ceilingMilli('combo')
            && $value % 1000 === 0;
    }

    /** What an update to a product line asks for: remove (blank/zero), update or refuse. */
    public static function productUpdateAction(string $quantity, string $minimum, string $increment): string
    {
        if (trim($quantity) === '' || self::quantityMilli($quantity) === 0) {
            return 'remove';
        }
        return self::isValidProductQuantity($quantity, $minimum, $increment) ? 'update' : 'invalid';
    }

    /** What an update to a combo line asks for: remove (blank/zero), update or refuse. */
    public static function comboUpdateAction(string $quantity): string
    {
        if (trim($quantity) === '' || self::quantityMilli($quantity) === 0) {
            return 'remove';
        }
        return self::isValidComboQuantity($quantity) ? 'update' : 'invalid';
    }

    /**
     * What a fresh add does to the basket, decided before a row is written.
     *
     * Same item at the same price: fold into the line already there, capped at
     * the ceiling. Same item at a different price: open a new line at the new
     * price and report the reprice, leaving the old line exactly as it was.
     * Nothing there yet: a new line.
     */
    public static function repeatAddPlan(?array $existing, int $currentPrice, string $addQuantity, string $itemType = 'product'): array
    {
        $ceiling = self::ceilingMilli($itemType);

        if ($existing !== null && (int) $existing['unit_price_subunit'] === $currentPrice) {
            $combined = self::quantityMilli(self::addQuantities((string) $existing['quantity'], $addQuantity));
            $capped   = $combined > $ceiling;
            return [
                'operation'          => 'increment',
                'quantity'           => self::milliToQuantity($capped ? $ceiling : $combined),
                'unit_price_subunit' => $currentPrice,
                'repriced'           => false,
                'capped'             => $capped,
            ];
        }

        $added  = self::quantityMilli(self::normaliseQuantity($addQuantity));
        $capped = $added > $ceiling;
        return [
            'operation'          => 'append',
            'quantity'           => self::milliToQuantity($capped ? $ceiling : $added),
            'unit_price_subunit' => $currentPrice,
            'repriced'           => $existing !== null,
            'capped'             => $capped,
        ];
    }

    /** Two lines are the same snapshot when type, item and price all match. */
    private static function sameSnapshot(array $left, array $right): bool
    {
        return (string) ($left['item_type'] ?? '') === (string) ($right['item_type'] ?? '')
            && (int) ($left['product_id'] ?? 0) === (int) ($right['product_id'] ?? 0)
            && (int) ($left['combo_package_id'] ?? 0) === (int) ($right['combo_package_id'] ?? 0)
            && (int) ($left['unit_price_subunit'] ?? 0) === (int) ($right['unit_price_subunit'] ?? 0);
    }

    /**
     * How a guest basket folds into an account basket. A line the account holds
     * at the same price adds its quantity to that line, capped at the ceiling.
     * A line at a different price moves across on its own, so both price
     * snapshots survive the sign-in exactly as they survive a reprice. Pure, so
     * the transactional merge below and the tests can share one rule.
     */
    public static function mergeSnapshotLines(array $accountLines, array $guestLines): array
    {
        $merged = array_values($accountLines);
        foreach ($guestLines as $line) {
            $found = null;
            foreach ($merged as $index => $candidate) {
                if (self::sameSnapshot($candidate, $line)) {
                    $found = $index;
                    break;
                }
            }
            if ($found === null) {
                $merged[] = $line;
                continue;
            }
            $type     = (string) ($merged[$found]['item_type'] ?? 'product');
            $combined = self::quantityMilli(self::addQuantities((string) $merged[$found]['quantity'], (string) $line['quantity']));
            $ceiling  = self::ceilingMilli($type);
            $merged[$found]['quantity'] = self::milliToQuantity(min($combined, $ceiling));
        }
        return $merged;
    }

    /** Sum of every line total, in subunits. */
    public static function subtotalFromLines(array $lines): int
    {
        $total = 0;
        foreach ($lines as $line) {
            $total += Money::lineTotal($line['quantity'] ?? 0, (int) ($line['unit_price_subunit'] ?? 0));
        }
        return $total;
    }

    /**
     * Add the money and the reprice flag to each line. When one item holds two
     * prices, the first line is the original and every later line is flagged,
     * carrying the price it moved from. That is what badges the newer row and
     * puts the notice at the top of the basket page.
     */
    public static function decorateLines(array $lines): array
    {
        $firstPrice = [];
        $lastPrice  = [];
        $decorated  = [];

        foreach ($lines as $line) {
            $type  = (string) ($line['item_type'] ?? 'product');
            $key   = $type . ':' . ($type === 'combo' ? (int) ($line['combo_package_id'] ?? 0) : (int) ($line['product_id'] ?? 0));
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

    // -------------------------------------------------------------------------
    // Reads
    // -------------------------------------------------------------------------

    /**
     * Line count for the header badge and the bottom tab bar. A cheap COUNT, so
     * every storefront page can call it without pulling the whole basket. Never
     * the sum of the quantities: those are kilogrammes, bunches and heads, so
     * adding them together would count 2kg of tomatoes as two items.
     */
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
     * Everything a basket page, the mini-cart or the cart API needs in one
     * round-trip: the decorated lines, the subtotal, the line count and whether
     * the repriced notice belongs on the page.
     */
    public static function state(): array
    {
        $cartId = self::findActiveCartId();
        if ($cartId === null) {
            return [
                'cart_id'          => null,
                'lines'            => [],
                'count'            => 0,
                'subtotal_subunit' => 0,
                'subtotal_display' => Money::format(0),
                'has_repriced'     => false,
                'repriced_notice'  => '',
                'basket_url'       => '/cart.php',
                'checkout_url'     => '/checkout.php',
            ];
        }

        $lines    = self::decorateLines(self::lines($cartId));
        $subtotal = self::subtotalFromLines($lines);
        $repriced = self::hasRepricedLines($lines);

        return [
            'cart_id'          => $cartId,
            'lines'            => $lines,
            'count'            => count($lines),
            'subtotal_subunit' => $subtotal,
            'subtotal_display' => Money::format($subtotal),
            'has_repriced'     => $repriced,
            'repriced_notice'  => $repriced ? self::REPRICED_NOTICE : '',
            'basket_url'       => '/cart.php',
            'checkout_url'     => '/checkout.php',
        ];
    }

    /**
     * The basket's lines with the catalogue detail a page needs: name, unit,
     * photo, this week's price and whether the item can still be packed. One
     * query, products and combos together, ordered by when they were added so
     * the original price snapshot always comes before the newer one.
     */
    private static function lines(int $cartId): array
    {
        $rows = Database::all(
            'SELECT ci.id, ci.item_type, ci.product_id, ci.combo_package_id, ci.quantity, ci.unit_price_subunit,
                    p.name AS product_name, p.slug AS product_slug, p.is_active AS product_active,
                    p.current_price_subunit, p.minimum_quantity, p.quantity_increment,
                    u.symbol AS product_unit,
                    COALESCE(pa.availability_status, \'available\') AS availability_status, pa.restock_date,
                    (SELECT pi.image_url FROM product_images pi
                      WHERE pi.product_id = ci.product_id
                      ORDER BY pi.is_primary DESC, pi.sort_order, pi.id LIMIT 1) AS product_image,
                    c.name AS combo_name, c.slug AS combo_slug, c.is_active AS combo_active,
                    c.price_subunit AS combo_price_subunit, c.image_url AS combo_image,
                    c.available_from, c.available_until,
                    (SELECT pi2.image_url
                       FROM combo_package_items cpi
                       JOIN product_images pi2 ON pi2.product_id = cpi.product_id
                      WHERE cpi.combo_package_id = ci.combo_package_id
                      ORDER BY cpi.id, pi2.is_primary DESC, pi2.sort_order, pi2.id
                      LIMIT 1) AS combo_fallback_image
               FROM cart_items ci
               LEFT JOIN products p ON p.id = ci.product_id
               LEFT JOIN units_of_measurement u ON u.id = p.unit_id
               LEFT JOIN product_availability pa ON pa.product_id = ci.product_id
               LEFT JOIN combo_packages c ON c.id = ci.combo_package_id
              WHERE ci.cart_id = :cart_id
              ORDER BY ci.created_at, ci.id',
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
            $name    = (string) ($row['combo_name'] ?? 'Combo');
            $slug    = (string) ($row['combo_slug'] ?? '');
            $url     = $slug !== '' ? '/combo.php?slug=' . rawurlencode($slug) : '/combos.php';
            $rawImage = trim((string) ($row['combo_image'] ?? '')) !== ''
                ? (string) $row['combo_image']
                : (string) ($row['combo_fallback_image'] ?? '');
            $unit    = 'basket';
            $current = (int) ($row['combo_price_subunit'] ?? 0);
            $buyable = Combos::isBuyableNow([
                'is_active'       => (int) ($row['combo_active'] ?? 0),
                'available_from'  => $row['available_from'] ?? null,
                'available_until' => $row['available_until'] ?? null,
            ]);
            $note    = $buyable ? '' : 'This basket has left the shop. Remove it to carry on.';
        } else {
            $name    = (string) ($row['product_name'] ?? 'Item');
            $slug    = (string) ($row['product_slug'] ?? '');
            $url     = $slug !== '' ? '/product.php?slug=' . rawurlencode($slug) : '/shop.php';
            $rawImage = (string) ($row['product_image'] ?? '');
            $unit    = (string) ($row['product_unit'] ?? '');
            $current = (int) ($row['current_price_subunit'] ?? 0);
            $status  = (string) ($row['availability_status'] ?? 'available');
            $buyable = ((int) ($row['product_active'] ?? 0) === 1) && $status === 'available';
            $note    = $buyable ? '' : okv_availability($status, $row['restock_date'] ?? null)['label'] . '. Remove it, or keep it and we will follow up.';
        }

        $price = (int) $row['unit_price_subunit'];

        return [
            'id'                    => (int) $row['id'],
            'item_type'             => (string) $row['item_type'],
            'product_id'            => $row['product_id'] !== null ? (int) $row['product_id'] : null,
            'combo_package_id'      => $row['combo_package_id'] !== null ? (int) $row['combo_package_id'] : null,
            'name'                  => $name,
            'slug'                  => $slug,
            'url'                   => $url,
            'image'                 => $rawImage,
            'image_url'             => $rawImage !== '' ? okv_image_url($rawImage) : '',
            'unit'                  => $unit,
            'quantity'              => (string) $row['quantity'],
            'quantity_display'      => okv_quantity($row['quantity']),
            'unit_price_subunit'    => $price,
            'unit_price_display'    => Money::format($price),
            'current_price_subunit' => $current,
            'minimum_quantity'      => (string) ($row['minimum_quantity'] ?? '1.000'),
            'quantity_increment'    => (string) ($row['quantity_increment'] ?? '1.000'),
            'line_total_subunit'    => Money::lineTotal($row['quantity'], $price),
            'is_orderable'          => $buyable,
            'availability_note'     => $note,
        ];
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
                'SELECT p.id, p.current_price_subunit, p.minimum_quantity, p.quantity_increment,
                        COALESCE(pa.availability_status, \'available\') AS availability_status
                   FROM products p
                   LEFT JOIN product_availability pa ON pa.product_id = p.id
                  WHERE p.id = :id AND p.is_active = 1
                  FOR UPDATE',
                [':id' => $productId]
            );
            if (!$product) {
                throw new DomainException('not_found');
            }
            if ($product['availability_status'] !== 'available') {
                throw new DomainException('unavailable');
            }

            $cartId = self::activeCartId();
            $step   = self::quantityMilli((string) $product['quantity_increment']) > 0
                ? (string) $product['quantity_increment']
                : (string) $product['minimum_quantity'];
            $result = self::addSnapshot(
                $cartId,
                'product',
                $productId,
                (int) $product['current_price_subunit'],
                (string) $product['minimum_quantity'],
                $step
            );
            self::touchCart($cartId);

            $pdo->commit();
            return $result + ['count' => self::count()];
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
                'SELECT id, price_subunit, is_active, available_from, available_until
                   FROM combo_packages
                  WHERE id = :id
                  FOR UPDATE',
                [':id' => $comboId]
            );
            if (!$combo) {
                throw new DomainException('not_found');
            }
            if (!Combos::isBuyableNow($combo) || (int) $combo['price_subunit'] < Pricing::MIN_PRICE_SUBUNIT) {
                throw new DomainException('unavailable');
            }

            $cartId = self::activeCartId();
            $result = self::addSnapshot($cartId, 'combo', $comboId, (int) $combo['price_subunit'], '1.000', '1.000');
            self::touchCart($cartId);

            $pdo->commit();
            return $result + ['count' => self::count()];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Apply an add to the locked basket. Looks for a line at the exact price
     * first; failing that, any line of the same item, so the plan can tell an
     * increment from a reprice. Grows the existing line or opens a new one,
     * never both, and never rewrites a price already on a line.
     */
    private static function addSnapshot(int $cartId, string $type, int $itemId, int $price, string $initialQuantity, string $increment): array
    {
        $column = $type === 'product' ? 'product_id' : 'combo_package_id';

        $same = self::snapshotLine($cartId, $type, $itemId, $price);
        $any  = $same ?: Database::one(
            'SELECT id, quantity, unit_price_subunit FROM cart_items
              WHERE cart_id = :cart_id AND item_type = :type AND ' . $column . ' = :item_id
              ORDER BY id LIMIT 1 FOR UPDATE',
            [':cart_id' => $cartId, ':type' => $type, ':item_id' => $itemId]
        );

        $plan = self::repeatAddPlan($any, $price, $same ? $increment : $initialQuantity, $type);

        if ($same) {
            Database::run(
                'UPDATE cart_items SET quantity = :quantity WHERE id = :id',
                [':quantity' => $plan['quantity'], ':id' => (int) $same['id']]
            );
        } else {
            Database::run(
                'INSERT INTO cart_items (cart_id, item_type, ' . $column . ', quantity, unit_price_subunit)
                 VALUES (:cart_id, :type, :item_id, :quantity, :price)',
                [':cart_id' => $cartId, ':type' => $type, ':item_id' => $itemId, ':quantity' => $plan['quantity'], ':price' => $price]
            );
        }

        return [
            'quantity_added'    => $plan['quantity'],
            'repriced'          => $plan['repriced'],
            'capped'            => $plan['capped'],
            'new_price_subunit' => $price,
        ];
    }

    public static function updateProduct(int $lineId, string $quantity): void { self::updateLine($lineId, $quantity, 'product'); }
    public static function updateCombo(int $lineId, string $quantity): void { self::updateLine($lineId, $quantity, 'combo'); }
    public static function removeProduct(int $lineId): void { self::removeLine($lineId, 'product'); }
    public static function removeCombo(int $lineId): void { self::removeLine($lineId, 'combo'); }

    /**
     * Set the quantity on one line of this basket. Clearing the box (a blank or
     * zero quantity) is read as "take this out". Anything off the packing grid
     * or below the minimum is refused, so the server never stores a quantity the
     * packing team cannot weigh.
     */
    private static function updateLine(int $lineId, string $quantity, string $type): void
    {
        $pdo = Database::getInstance()->getConnection();
        try {
            $pdo->beginTransaction();
            $cartId = self::findActiveCartId();
            if ($cartId === null) {
                throw new DomainException('not_found');
            }

            $line = Database::one(
                'SELECT ci.id, p.minimum_quantity, p.quantity_increment
                   FROM cart_items ci
                   LEFT JOIN products p ON p.id = ci.product_id
                  WHERE ci.id = :id AND ci.cart_id = :cart_id AND ci.item_type = :type
                  FOR UPDATE',
                [':id' => $lineId, ':cart_id' => $cartId, ':type' => $type]
            );
            if (!$line) {
                throw new DomainException('not_found');
            }

            $action = $type === 'product'
                ? self::productUpdateAction($quantity, (string) $line['minimum_quantity'], (string) $line['quantity_increment'])
                : self::comboUpdateAction($quantity);

            if ($action === 'remove') {
                Database::run('DELETE FROM cart_items WHERE id = :id', [':id' => (int) $line['id']]);
            } elseif ($action === 'update') {
                Database::run(
                    'UPDATE cart_items SET quantity = :quantity WHERE id = :id',
                    [':quantity' => self::normaliseQuantity($quantity), ':id' => (int) $line['id']]
                );
            } else {
                throw new DomainException('invalid_quantity');
            }

            self::touchCart($cartId);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Take one line out of the basket. The line has to be in this basket. */
    private static function removeLine(int $lineId, string $type): void
    {
        $pdo = Database::getInstance()->getConnection();
        try {
            $pdo->beginTransaction();
            $cartId = self::findActiveCartId();
            if ($cartId === null) {
                throw new DomainException('not_found');
            }
            $changed = Database::run(
                'DELETE FROM cart_items WHERE id = :id AND cart_id = :cart_id AND item_type = :type',
                [':id' => $lineId, ':cart_id' => $cartId, ':type' => $type]
            );
            if ($changed === 0) {
                throw new DomainException('not_found');
            }
            self::touchCart($cartId);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Fold this browser's guest basket into a just-signed-in customer's basket,
     * in one transaction. A line the account already holds at the same price
     * adds up; a line at a different price moves across on its own, so a price
     * the customer was given is never lost. The source cart is then marked
     * merged and gives up its session token, because shopping_carts.session_token
     * is unique and a merged row holding it would block the next guest basket on
     * this browser. The session token is dropped for the same reason.
     */
    public static function mergeGuestIntoAccount(int $userId): void
    {
        $token = $_SESSION[self::SESSION_TOKEN_KEY] ?? '';
        if (!is_string($token) || $token === '') {
            return;
        }

        $pdo = Database::getInstance()->getConnection();
        try {
            $pdo->beginTransaction();

            $guest = Database::one(
                'SELECT id FROM shopping_carts WHERE session_token = :token AND status = \'active\' AND user_id IS NULL FOR UPDATE',
                [':token' => $token]
            );
            if (!$guest) {
                // Nothing to merge, but release the token from any lingering row
                // so the next guest basket on this browser can claim a fresh one.
                Database::run('UPDATE shopping_carts SET session_token = NULL WHERE session_token = :token', [':token' => $token]);
                $pdo->commit();
                unset($_SESSION[self::SESSION_TOKEN_KEY]);
                return;
            }

            $targetId    = self::accountCartId($userId);
            $guestLines  = self::snapshotLinesForUpdate((int) $guest['id']);
            foreach ($guestLines as $line) {
                $match = self::snapshotLine($targetId, (string) $line['item_type'], (int) ($line['product_id'] ?? $line['combo_package_id']), (int) $line['unit_price_subunit']);
                if ($match) {
                    $type     = (string) $line['item_type'];
                    $combined = self::quantityMilli(self::addQuantities((string) $match['quantity'], (string) $line['quantity']));
                    $capped   = min($combined, self::ceilingMilli($type));
                    Database::run('UPDATE cart_items SET quantity = :quantity WHERE id = :id', [':quantity' => self::milliToQuantity($capped), ':id' => (int) $match['id']]);
                    Database::run('DELETE FROM cart_items WHERE id = :id', [':id' => (int) $line['id']]);
                } else {
                    Database::run('UPDATE cart_items SET cart_id = :target WHERE id = :id', [':target' => $targetId, ':id' => (int) $line['id']]);
                }
            }

            Database::run('UPDATE shopping_carts SET status = \'merged\', session_token = NULL WHERE id = :id', [':id' => (int) $guest['id']]);
            Database::run('UPDATE shopping_carts SET session_token = NULL WHERE session_token = :token', [':token' => $token]);

            $pdo->commit();
            unset($_SESSION[self::SESSION_TOKEN_KEY]);
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

    /** The line for this cart, item and exact price, locked. Null when there is none. */
    private static function snapshotLine(int $cartId, string $type, int $itemId, int $price): ?array
    {
        $column = $type === 'product' ? 'product_id' : 'combo_package_id';
        return Database::one(
            'SELECT id, quantity, unit_price_subunit FROM cart_items
              WHERE cart_id = :cart_id AND item_type = :type AND ' . $column . ' = :item_id AND unit_price_subunit = :price
              ORDER BY id LIMIT 1 FOR UPDATE',
            [':cart_id' => $cartId, ':type' => $type, ':item_id' => $itemId, ':price' => $price]
        );
    }

    /** Every line of a cart, locked, in the shape the merge rule reads. */
    private static function snapshotLinesForUpdate(int $cartId): array
    {
        return Database::all(
            'SELECT id, item_type, product_id, combo_package_id, quantity, unit_price_subunit
               FROM cart_items WHERE cart_id = :cart_id ORDER BY id FOR UPDATE',
            [':cart_id' => $cartId]
        );
    }

    /** The active cart for whoever is asking, created if there is not one yet. */
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

        Database::run(
            'INSERT INTO shopping_carts (session_token, status, expires_at) VALUES (:token, \'active\', :expires_at)',
            [':token' => self::guestToken(), ':expires_at' => self::guestExpiryStamp()]
        );
        return (int) Database::getInstance()->getConnection()->lastInsertId();
    }

    /** The signed-in customer's active cart, created if they do not have one. */
    private static function accountCartId(int $userId): int
    {
        $row = Database::one(
            'SELECT id FROM shopping_carts WHERE user_id = :user_id AND status = \'active\' ORDER BY id DESC LIMIT 1 FOR UPDATE',
            [':user_id' => $userId]
        );
        if ($row) {
            return (int) $row['id'];
        }
        Database::run('INSERT INTO shopping_carts (user_id, status) VALUES (:user_id, \'active\')', [':user_id' => $userId]);
        return (int) Database::getInstance()->getConnection()->lastInsertId();
    }

    /**
     * The active cart for whoever is asking. A guest basket past its 30 days is
     * retired rather than served: the row is marked abandoned, it gives up the
     * unique session token, and the session starts a fresh basket.
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
            'SELECT id, expires_at FROM shopping_carts WHERE session_token = :token AND status = \'active\' ORDER BY id DESC LIMIT 1',
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

    private static function guestToken(): string
    {
        $token = $_SESSION[self::SESSION_TOKEN_KEY] ?? '';
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::SESSION_TOKEN_KEY] = $token;
        }
        return $token;
    }
}

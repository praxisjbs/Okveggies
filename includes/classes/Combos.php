<?php
/**
 * includes/classes/Combos.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The combos domain. This is the one place a combo is created,
 * edited, priced, published or composed, so the maths and the history rules
 * can never be bypassed. See docs/PRD.md Section 7.
 *
 * Built in milestone M3 PR1. Its two companions build on this class:
 *   PR2, admin/combos.php and api/v1/combos.php, calls it to run the builder.
 *   PR3, combos.php and combo.php, calls Catalogue::combos() and the read-only
 *   helpers here to render the storefront.
 *
 * Money is integer subunits (kobo) throughout. Nothing here takes a float price.
 *
 * On repricing. A combo's sell price is fixed by the Manager and never
 * auto-recomputed when component prices move (PRD 7.1). The builder shows the
 * live component total for reference: when it climbs above the sell price the
 * combo is loss-making, and isLossMaking() lets the admin screen flag that in
 * red to the Manager only. The customer never sees the component total.
 *
 * On removing a combo. Same rule as products: a combo that anything else refers
 * to (an order, a basket, or its own price history) is switched off, never
 * deleted. Only a combo that never carried a price and nothing points at can be
 * deleted outright, in practice one added by mistake and caught before it was
 * priced or shipped. See referenceCount() and delete().
 *
 * On publishing. There is no separate draft column: a draft is a combo with
 * is_active = 0. Publishing (setting is_active = 1) refuses when the combo has
 * no components, or a sell price of zero, so a half-built combo never leaks
 * onto the shop.
 * -----------------------------------------------------------------------------
 */

final class Combos
{
    /** A sanity ceiling, same as Pricing. Anything above this is a typo. */
    public const MAX_PRICE_SUBUNIT = Pricing::MAX_PRICE_SUBUNIT;

    /** The smallest allowed component quantity. Below this is not a real amount. */
    public const MIN_COMPONENT_QUANTITY = 0.001;

    // --- Reads (admin side) --------------------------------------------------

    /** Every combo for the admin list, filterable by name or SKU and by status. */
    public static function all(string $search = '', string $status = ''): array
    {
        $search = Catalogue::cleanSearch($search);
        $like = '%' . Catalogue::escapeLike($search) . '%';
        $status = in_array($status, ['active', 'inactive'], true) ? $status : '';

        return Database::all(
            'SELECT c.id, c.name, c.slug, c.sku, c.description, c.price_subunit,
                    c.image_url, c.is_featured, c.is_active,
                    c.available_from, c.available_until,
                    (SELECT COUNT(*) FROM combo_package_items ci WHERE ci.combo_package_id = c.id) AS component_count
               FROM combo_packages c
              WHERE (:status_empty = \'\' OR (:status_active = \'active\') = (c.is_active = 1))
                AND (:search_empty = \'\' OR c.name LIKE :search_name OR c.sku LIKE :search_sku)
              ORDER BY c.is_featured DESC, c.name',
            [
                ':status_empty'  => $status,
                ':status_active' => $status,
                ':search_empty'  => $search,
                ':search_name'   => $like,
                ':search_sku'    => $like,
            ]
        );
    }

    public static function find(int $id): ?array
    {
        return Database::one(
            'SELECT id, name, slug, sku, description, price_subunit, currency,
                    image_url, is_featured, is_active,
                    available_from, available_until,
                    created_at, updated_at
               FROM combo_packages
              WHERE id = :id',
            [':id' => $id]
        );
    }

    public static function findBySlug(string $slug): ?array
    {
        $slug = Catalogue::cleanSlug($slug);
        if ($slug === '') {
            return null;
        }
        return Database::one(
            'SELECT id, name, slug, sku, description, price_subunit, currency,
                    image_url, is_featured, is_active,
                    available_from, available_until
               FROM combo_packages
              WHERE slug = :slug',
            [':slug' => $slug]
        );
    }

    // --- Slug ----------------------------------------------------------------

    /**
     * A slug that is unique across combos. "The Stew Combo" becomes
     * the-stew-combo, and a second one becomes the-stew-combo-2.
     */
    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = okv_slug($name);
        if ($base === '') {
            $base = 'combo';
        }
        $slug = $base;
        $n = 1;
        while (true) {
            $row = Database::one(
                'SELECT id FROM combo_packages WHERE slug = :slug'
                    . ($ignoreId ? ' AND id <> :ignore' : '') . ' LIMIT 1',
                $ignoreId ? [':slug' => $slug, ':ignore' => $ignoreId] : [':slug' => $slug]
            );
            if (!$row) {
                return $slug;
            }
            $n++;
            $slug = $base . '-' . $n;
        }
    }

    // --- Validation ----------------------------------------------------------

    /**
     * Validate a combo form. Returns [$clean, $errors] so the controller can
     * answer with every problem at once rather than one at a time. Passes on an
     * empty (draft) price: a combo can be composed without a price, and later
     * priced through changePrice().
     */
    public static function validate(array $input, ?int $ignoreId = null): array
    {
        $errors = [];

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            $errors['name'] = 'Give the combo a name.';
        } elseif (mb_strlen($name) > 180) {
            $errors['name'] = 'That name is too long. Keep it under 180 characters.';
        }

        $sku = strtoupper(trim((string) ($input['sku'] ?? '')));
        if ($sku === '') {
            $errors['sku'] = 'Give the combo a SKU.';
        } elseif (!preg_match('/^[A-Z0-9-]{3,80}$/', $sku)) {
            $errors['sku'] = 'A SKU is letters, digits and hyphens, 3 to 80 characters.';
        } else {
            $clash = Database::one(
                'SELECT id FROM combo_packages WHERE sku = :sku'
                    . ($ignoreId ? ' AND id <> :ignore' : '') . ' LIMIT 1',
                $ignoreId ? [':sku' => $sku, ':ignore' => $ignoreId] : [':sku' => $sku]
            );
            if ($clash) {
                $errors['sku'] = 'Another combo already uses that SKU.';
            }
        }

        // A price of nothing means "not priced yet", which is a legitimate draft.
        // Any other price has to sit in the range Pricing already agreed on.
        $priceSubunit = Money::toSubunit((string) ($input['price'] ?? '0'));
        if ($priceSubunit !== 0 && !Pricing::isValidPrice($priceSubunit)) {
            $errors['price'] = 'That price is outside the range we allow. Leave it empty to save a draft.';
        }

        $from = self::cleanDate($input['available_from'] ?? '');
        $until = self::cleanDate($input['available_until'] ?? '');
        if ($from !== null && $until !== null && $from > $until) {
            $errors['available_until'] = 'The end date is before the start date.';
        }

        $clean = [
            'name'            => $name,
            'sku'             => $sku,
            'description'     => trim((string) ($input['description'] ?? '')),
            'price_subunit'   => $priceSubunit,
            'image_url'       => mb_substr(trim((string) ($input['image_url'] ?? '')), 0, 500),
            'is_featured'     => !empty($input['is_featured']) ? 1 : 0,
            'is_active'       => !empty($input['is_active']) ? 1 : 0,
            'available_from'  => $from,
            'available_until' => $until,
        ];

        return [$clean, $errors];
    }

    private static function cleanDate($value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        $timestamp = strtotime($raw);
        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }

    /**
     * A component quantity as the unit allows it. Only the kilogramme takes a
     * decimal (per Products); a bunch, head or tuber rounds up. Combo internals
     * are the Manager's choice, so the product's own minimum and increment do
     * not apply here: the seed uses 0.25 kg ginger with a 1 kg minimum on
     * purpose (PRD 7.1).
     */
    public static function cleanComponentQuantity($value, ?array $unit): float
    {
        $number = (float) preg_replace('/[^0-9.]/', '', (string) $value);
        if ($unit && empty($unit['allows_decimal'])) {
            $number = ceil($number);
        }
        return round($number, 3);
    }

    // --- Create / update -----------------------------------------------------

    /**
     * Add a combo. When it arrives with a price, that price opens its history
     * with a null old price, which is what the history's first row means.
     *
     * A caller that already holds a transaction (for instance the create action
     * that atomically writes the combo, its first component and its publish
     * gate) passes $ownTransaction = false, so the whole thing rolls back
     * together if any step fails.
     */
    public static function create(array $clean, ?int $userId, bool $ownTransaction = true): int
    {
        $pdo = Database::getInstance()->getConnection();
        if ($ownTransaction) {
            $pdo->beginTransaction();
        }
        try {
            Database::run(
                'INSERT INTO combo_packages
                    (name, slug, sku, description, price_subunit, currency,
                     image_url, is_featured, is_active, available_from, available_until)
                 VALUES (:name, :slug, :sku, :description, :price, :currency,
                     :image_url, :is_featured, :is_active, :available_from, :available_until)',
                [
                    ':name'            => $clean['name'],
                    ':slug'            => self::uniqueSlug($clean['name']),
                    ':sku'             => $clean['sku'],
                    ':description'     => $clean['description'],
                    ':price'           => (int) $clean['price_subunit'],
                    ':currency'        => Money::CODE,
                    ':image_url'       => $clean['image_url'] === '' ? null : $clean['image_url'],
                    ':is_featured'     => (int) $clean['is_featured'],
                    ':is_active'       => (int) $clean['is_active'],
                    ':available_from'  => $clean['available_from'],
                    ':available_until' => $clean['available_until'],
                ]
            );
            $id = (int) $pdo->lastInsertId();

            if ((int) $clean['price_subunit'] > 0) {
                self::writeHistory($id, null, (int) $clean['price_subunit'], 'Opening price', $userId);
            }

            if ($ownTransaction) {
                $pdo->commit();
            }
            return $id;
        } catch (Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Edit a combo. The sell price is deliberately not part of this: it moves
     * through changePrice() so no edit can ever slip past the history.
     */
    public static function update(int $id, array $clean, ?int $userId): void
    {
        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            $existing = Database::one(
                'SELECT id, name, slug FROM combo_packages WHERE id = :id FOR UPDATE',
                [':id' => $id]
            );
            if (!$existing) {
                throw new DomainException('not_found');
            }

            // Keep the slug stable unless the name actually changed, so links
            // that already point at this combo keep working.
            $slug = (string) $existing['slug'];
            if ($existing['name'] !== $clean['name']) {
                $slug = self::uniqueSlug($clean['name'], $id);
            }

            Database::run(
                'UPDATE combo_packages
                    SET name = :name, slug = :slug, sku = :sku, description = :description,
                        image_url = :image_url, is_featured = :is_featured, is_active = :is_active,
                        available_from = :available_from, available_until = :available_until
                  WHERE id = :id',
                [
                    ':name'            => $clean['name'],
                    ':slug'            => $slug,
                    ':sku'             => $clean['sku'],
                    ':description'     => $clean['description'],
                    ':image_url'       => $clean['image_url'] === '' ? null : $clean['image_url'],
                    ':is_featured'     => (int) $clean['is_featured'],
                    ':is_active'       => (int) $clean['is_active'],
                    ':available_from'  => $clean['available_from'],
                    ':available_until' => $clean['available_until'],
                    ':id'              => $id,
                ]
            );

            // A price bundled with the edit still flows through changePrice, so
            // the history stays the source of truth for every movement.
            $newPrice = (int) $clean['price_subunit'];
            if ($newPrice > 0) {
                self::changePrice($id, $newPrice, 'Changed while editing the combo', $userId, false);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // --- Publishing ----------------------------------------------------------

    /**
     * Put the combo on the shop. Refuses when the combo has no components or
     * no sell price, so a half-built combo never leaks past the builder. Same
     * transaction guarantees as the rest: nothing is switched on unless the
     * checks pass.
     *
     * Throws DomainException('not_found'), DomainException('no_components'),
     * DomainException('no_price').
     */
    public static function publish(int $id): void
    {
        $combo = self::find($id);
        if (!$combo) {
            throw new DomainException('not_found');
        }
        if ((int) $combo['price_subunit'] < Pricing::MIN_PRICE_SUBUNIT) {
            throw new DomainException('no_price');
        }
        $count = self::componentCount($id);
        if ($count < 1) {
            throw new DomainException('no_components');
        }
        self::setActive($id, true);
    }

    /** Take a combo off the shop without touching anything else. */
    public static function unpublish(int $id): void
    {
        self::setActive($id, false);
    }

    /** Set the active flag. Kept small so publish() can layer its checks on top. */
    public static function setActive(int $id, bool $active): void
    {
        $changed = Database::run(
            'UPDATE combo_packages SET is_active = :active WHERE id = :id',
            [':active' => $active ? 1 : 0, ':id' => $id]
        );
        if ($changed === 0 && !Database::one('SELECT id FROM combo_packages WHERE id = :id', [':id' => $id])) {
            throw new DomainException('not_found');
        }
    }

    // --- Availability window and buyability ---------------------------------

    /**
     * Is the combo one a customer can add today. Per the answer to Q2 for
     * milestone M3: active plus inside the availability window is enough. We
     * do not gate on component availability here; a combo that includes an
     * out-of-stock item is still buyable, and packing time is where a
     * substitution or a Make It Right kicks in.
     *
     * $today defaults to now. It is a parameter so tests can pin a date.
     */
    public static function isBuyableNow(array $combo, ?string $today = null): bool
    {
        if ((int) ($combo['is_active'] ?? 0) !== 1) {
            return false;
        }
        $today = $today ?? date('Y-m-d');
        $from = $combo['available_from'] ?? null;
        $until = $combo['available_until'] ?? null;
        if ($from !== null && $from !== '' && $today < $from) {
            return false;
        }
        if ($until !== null && $until !== '' && $today > $until) {
            return false;
        }
        return true;
    }

    // --- Components ----------------------------------------------------------

    /**
     * The components of one combo, with the product's current price so a caller
     * can compute a line and a total in one query.
     */
    public static function components(int $comboId): array
    {
        return Database::all(
            'SELECT ci.id AS component_id, ci.combo_package_id, ci.product_id, ci.quantity, ci.unit_id,
                    p.name AS product_name, p.slug AS product_slug, p.sku AS product_sku,
                    p.current_price_subunit, p.is_active AS product_is_active,
                    u.symbol AS unit, u.name AS unit_name, u.allows_decimal
               FROM combo_package_items ci
               JOIN products p ON p.id = ci.product_id
               JOIN units_of_measurement u ON u.id = ci.unit_id
              WHERE ci.combo_package_id = :combo_id
              ORDER BY ci.id',
            [':combo_id' => $comboId]
        );
    }

    public static function componentCount(int $comboId): int
    {
        $row = Database::one(
            'SELECT COUNT(*) AS c FROM combo_package_items WHERE combo_package_id = :combo_id',
            [':combo_id' => $comboId]
        );
        return (int) ($row['c'] ?? 0);
    }

    /**
     * Add a component to a combo. Refuses a quantity below the smallest amount
     * we are willing to store, and refuses a repeat of the same product + unit
     * pair (the unique key would catch it too; catching it here gives a plain
     * message rather than a database error).
     *
     * Throws DomainException with a message the controller maps to a plain
     * answer: not_found, bad_quantity, bad_product, bad_unit, already_in_combo.
     */
    public static function addComponent(int $comboId, int $productId, float $quantity, int $unitId): int
    {
        if ($quantity < self::MIN_COMPONENT_QUANTITY) {
            throw new DomainException('bad_quantity');
        }
        if (!Database::one('SELECT id FROM combo_packages WHERE id = :id', [':id' => $comboId])) {
            throw new DomainException('not_found');
        }
        if (!Database::one('SELECT id FROM products WHERE id = :id', [':id' => $productId])) {
            throw new DomainException('bad_product');
        }
        if (!Database::one('SELECT id FROM units_of_measurement WHERE id = :id', [':id' => $unitId])) {
            throw new DomainException('bad_unit');
        }

        $clash = Database::one(
            'SELECT id FROM combo_package_items
              WHERE combo_package_id = :combo_id AND product_id = :product_id AND unit_id = :unit_id',
            [':combo_id' => $comboId, ':product_id' => $productId, ':unit_id' => $unitId]
        );
        if ($clash) {
            throw new DomainException('already_in_combo');
        }

        Database::run(
            'INSERT INTO combo_package_items (combo_package_id, product_id, quantity, unit_id)
             VALUES (:combo_id, :product_id, :quantity, :unit_id)',
            [
                ':combo_id'   => $comboId,
                ':product_id' => $productId,
                ':quantity'   => $quantity,
                ':unit_id'    => $unitId,
            ]
        );
        return (int) Database::getInstance()->getConnection()->lastInsertId();
    }

    /** Change the quantity on one component line, keeping the same product and unit. */
    public static function updateComponent(int $componentId, float $quantity): void
    {
        if ($quantity < self::MIN_COMPONENT_QUANTITY) {
            throw new DomainException('bad_quantity');
        }
        $changed = Database::run(
            'UPDATE combo_package_items SET quantity = :quantity WHERE id = :id',
            [':quantity' => $quantity, ':id' => $componentId]
        );
        if ($changed === 0 && !Database::one('SELECT id FROM combo_package_items WHERE id = :id', [':id' => $componentId])) {
            throw new DomainException('not_found');
        }
    }

    public static function removeComponent(int $componentId): void
    {
        $changed = Database::run(
            'DELETE FROM combo_package_items WHERE id = :id',
            [':id' => $componentId]
        );
        if ($changed === 0) {
            throw new DomainException('not_found');
        }
    }

    // --- Component-total maths (pure, testable) -----------------------------

    /**
     * The current subunit total of the components of one combo. Round-trip
     * through Money::lineTotal, so a fractional-kg line pays the same
     * arithmetic every price screen does.
     */
    public static function componentTotal(int $comboId): int
    {
        return self::sumComponents(self::components($comboId));
    }

    /**
     * Sum a list of components. Pure: takes rows shaped like components()
     * returns and produces a subunit total. Unit tests call this directly, no
     * database needed. A component whose product has no price yet contributes
     * zero, so a half-priced catalogue does not throw here.
     */
    public static function sumComponents(array $components): int
    {
        $total = 0;
        foreach ($components as $row) {
            $price = (int) ($row['current_price_subunit'] ?? 0);
            $quantity = (float) ($row['quantity'] ?? 0);
            if ($price <= 0 || $quantity <= 0) {
                continue;
            }
            $total += Money::lineTotal($quantity, $price);
        }
        return $total;
    }

    /**
     * Breakdown suitable for the builder: every component with its line total
     * plus the overall component total. Nothing here changes state.
     */
    public static function componentTotalDetailed(int $comboId): array
    {
        $components = self::components($comboId);
        $lines = [];
        $total = 0;
        foreach ($components as $row) {
            $price = (int) ($row['current_price_subunit'] ?? 0);
            $quantity = (float) ($row['quantity'] ?? 0);
            $line = ($price > 0 && $quantity > 0) ? Money::lineTotal($quantity, $price) : 0;
            $total += $line;
            $lines[] = $row + ['line_subunit' => $line];
        }
        return ['components' => $lines, 'total_subunit' => $total];
    }

    /**
     * The one-line answer the admin builder needs when it renders a combo: does
     * this combo currently sell for less than the sum of its components. Pure:
     * takes the two integer amounts and answers a boolean.
     *
     * A combo with no sell price (a draft) is not counted as loss-making,
     * because there is no sell price to compare with yet.
     */
    public static function isLossMaking(int $sellSubunit, int $componentSubunit): bool
    {
        return $sellSubunit > 0 && $componentSubunit > $sellSubunit;
    }

    /**
     * The subunit saving a customer gets against buying the components
     * separately. Zero when the combo sells for the same or more than its
     * components; never negative, because the customer never sees the number
     * when it would be.
     */
    public static function customerSaving(int $sellSubunit, int $componentSubunit): int
    {
        $saving = $componentSubunit - $sellSubunit;
        return $saving > 0 ? $saving : 0;
    }

    // --- Sell price ----------------------------------------------------------

    /**
     * Change a combo's sell price and write its history. Mirrors Pricing::change
     * on the product side.
     *
     * Returns ['changed' => bool, 'old' => int, 'new' => int]. Setting the
     * price it already has is not an error and writes no history row, so
     * saving a builder without changing the price adds no noise.
     *
     * Throws DomainException('not_found'), DomainException('invalid_price').
     * Callers that already hold a transaction pass $ownTransaction = false.
     */
    public static function changePrice(
        int $comboId,
        int $newPriceSubunit,
        ?string $reason = null,
        ?int $userId = null,
        bool $ownTransaction = true
    ): array {
        if (!Pricing::isValidPrice($newPriceSubunit)) {
            throw new DomainException('invalid_price');
        }

        $pdo = Database::getInstance()->getConnection();
        if ($ownTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $combo = Database::one(
                'SELECT id, price_subunit FROM combo_packages WHERE id = :id FOR UPDATE',
                [':id' => $comboId]
            );
            if (!$combo) {
                throw new DomainException('not_found');
            }

            $old = (int) $combo['price_subunit'];
            if ($old === $newPriceSubunit) {
                if ($ownTransaction) {
                    $pdo->commit();
                }
                return ['changed' => false, 'old' => $old, 'new' => $old];
            }

            self::writeHistory($comboId, $old, $newPriceSubunit, $reason, $userId);

            Database::run(
                'UPDATE combo_packages SET price_subunit = :price WHERE id = :id',
                [':price' => $newPriceSubunit, ':id' => $comboId]
            );

            if ($ownTransaction) {
                $pdo->commit();
            }
            return ['changed' => true, 'old' => $old, 'new' => $newPriceSubunit];
        } catch (Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Close the open history row and open a new one. An opening price (a combo
     * priced for the first time) passes $oldSubunit as null, which is what the
     * nullable old_price_subunit column is for. Mirrors Pricing::writeHistory.
     */
    public static function writeHistory(
        int $comboId,
        ?int $oldSubunit,
        int $newSubunit,
        ?string $reason,
        ?int $userId
    ): void {
        Database::run(
            'UPDATE combo_price_history
                SET effective_to = NOW()
              WHERE combo_package_id = :combo_id AND effective_to IS NULL',
            [':combo_id' => $comboId]
        );

        $reason = $reason === null ? null : mb_substr(trim($reason), 0, 255);

        Database::run(
            'INSERT INTO combo_price_history
                (combo_package_id, old_price_subunit, new_price_subunit, currency, effective_from, change_reason, changed_by)
             VALUES (:combo_id, :old_price, :new_price, :currency, NOW(), :reason, :changed_by)',
            [
                ':combo_id'   => $comboId,
                ':old_price'  => $oldSubunit,
                ':new_price'  => $newSubunit,
                ':currency'   => Money::CODE,
                ':reason'     => ($reason === '' ? null : $reason),
                ':changed_by' => $userId,
            ]
        );
    }

    /** The price history for one combo, newest first, with who changed it. */
    public static function history(int $comboId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        return Database::all(
            'SELECT h.id, h.old_price_subunit, h.new_price_subunit,
                    h.effective_from, h.effective_to, h.change_reason,
                    TRIM(CONCAT(COALESCE(u.first_name, \'\'), \' \', COALESCE(u.last_name, \'\'))) AS changed_by_name
               FROM combo_price_history h
               LEFT JOIN users u ON u.id = h.changed_by
              WHERE h.combo_package_id = :combo_id
              ORDER BY h.effective_from DESC, h.id DESC
              LIMIT ' . $limit,
            [':combo_id' => $comboId]
        );
    }

    // --- Deletion ------------------------------------------------------------

    /**
     * What is holding on to this combo, and how hard. Anything here means the
     * combo is switched off rather than deleted.
     */
    public static function referenceCount(int $id): array
    {
        $counts = [
            'orders'  => ['SELECT COUNT(*) AS c FROM order_items WHERE combo_package_id = :id', [':id' => $id]],
            'baskets' => ['SELECT COUNT(*) AS c FROM cart_items WHERE combo_package_id = :id', [':id' => $id]],
            'prices'  => ['SELECT COUNT(*) AS c FROM combo_price_history WHERE combo_package_id = :id', [':id' => $id]],
        ];
        $out = [];
        foreach ($counts as $key => [$sql, $params]) {
            $row = Database::one($sql, $params);
            $out[$key] = (int) ($row['c'] ?? 0);
        }
        $out['total'] = array_sum($out);
        return $out;
    }

    /**
     * Remove a combo outright. Only allowed when nothing refers to it and it
     * never carried a price, so no history row is ever destroyed. Anything else
     * throws and the caller offers to switch it off instead.
     *
     * combo_package_items cascades from combo_packages (schema), so the
     * component rows go with it when the delete is allowed.
     */
    public static function delete(int $id): void
    {
        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            if (!Database::one('SELECT id FROM combo_packages WHERE id = :id FOR UPDATE', [':id' => $id])) {
                throw new DomainException('not_found');
            }
            $refs = self::referenceCount($id);
            if ($refs['total'] > 0) {
                throw new DomainException('in_use');
            }
            Database::run('DELETE FROM combo_packages WHERE id = :id', [':id' => $id]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Plain words for why a combo cannot be deleted. */
    public static function inUseMessage(array $refs): string
    {
        $parts = [];
        if ($refs['orders'] > 0)  { $parts[] = $refs['orders'] === 1 ? 'an order' : $refs['orders'] . ' orders'; }
        if ($refs['baskets'] > 0) { $parts[] = $refs['baskets'] === 1 ? 'a basket' : $refs['baskets'] . ' baskets'; }
        if ($refs['prices'] > 0)  { $parts[] = 'its price history'; }

        if (!$parts) {
            return 'Something still refers to this combo.';
        }
        $last = array_pop($parts);
        $list = $parts ? implode(', ', $parts) . ' and ' . $last : $last;
        return 'This combo is held by ' . $list . ', so it cannot be removed. Switch it off instead and it leaves the shop straight away.';
    }
}

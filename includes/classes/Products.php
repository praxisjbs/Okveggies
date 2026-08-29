<?php
/**
 * includes/classes/Products.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The admin side of the catalogue: create a product, edit it, set
 * its availability, manage its photos, and take it off the shop. Reading the
 * catalogue for customers is Catalogue.php; price changes are Pricing.php, and
 * nothing here writes a price except through it.
 *
 * See docs/PRD.md Sections 5 and 17.2.
 *
 * On removing a product. A product that anything else refers to is deactivated,
 * never deleted: an order, a basket, a combo or a pairing that points at it has
 * to keep pointing at something, and a priced product owns history rows that are
 * append-only. Only a product nothing refers to and that never carried a price
 * can be deleted outright, which in practice means one added by mistake and
 * caught before it was priced. See referenceCount() and delete().
 * -----------------------------------------------------------------------------
 */
final class Products
{
    public const STATUSES = ['available', 'out_of_stock', 'restocking'];

    /** Every product for the admin list, newest first within a category. */
    public static function all(string $search = '', string $category = '', string $status = ''): array
    {
        $search = Catalogue::cleanSearch($search);
        $like = '%' . Catalogue::escapeLike($search) . '%';
        $category = Catalogue::cleanSlug($category);
        $status = in_array($status, ['active', 'inactive'], true) ? $status : '';

        return Database::all(
            'SELECT p.id, p.name, p.slug, p.sku, p.short_description, p.current_price_subunit,
                    p.minimum_quantity, p.quantity_increment, p.is_featured, p.is_active,
                    p.category_id, p.unit_id,
                    c.name AS category_name, c.slug AS category_slug,
                    u.symbol AS unit,
                    COALESCE(pa.availability_status, \'available\') AS availability_status,
                    pa.restock_date,
                    (SELECT pi.image_url FROM product_images pi
                      WHERE pi.product_id = p.id
                      ORDER BY pi.is_primary DESC, pi.sort_order, pi.id LIMIT 1) AS image,
                    (SELECT COUNT(*) FROM product_images pi WHERE pi.product_id = p.id) AS image_count
               FROM products p
               JOIN product_categories c ON c.id = p.category_id
               JOIN units_of_measurement u ON u.id = p.unit_id
               LEFT JOIN product_availability pa ON pa.product_id = p.id
              WHERE (:category_empty = \'\' OR c.slug = :category_slug)
                AND (:status_empty = \'\' OR (:status_active = \'active\') = (p.is_active = 1))
                AND (:search_empty = \'\' OR p.name LIKE :search_name OR p.sku LIKE :search_sku)
              ORDER BY c.sort_order, p.name',
            [
                ':category_empty' => $category,
                ':category_slug'  => $category,
                ':status_empty'   => $status,
                ':status_active'  => $status,
                ':search_empty'   => $search,
                ':search_name'    => $like,
                ':search_sku'     => $like,
            ]
        );
    }

    public static function find(int $id): ?array
    {
        return Database::one(
            'SELECT p.*, COALESCE(pa.availability_status, \'available\') AS availability_status, pa.restock_date
               FROM products p
               LEFT JOIN product_availability pa ON pa.product_id = p.id
              WHERE p.id = :id',
            [':id' => $id]
        );
    }

    /**
     * A slug that is unique across products. "Fresh Tomatoes" becomes
     * fresh-tomatoes, and a second one becomes fresh-tomatoes-2.
     */
    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = okv_slug($name);
        if ($base === '') {
            $base = 'product';
        }
        $slug = $base;
        $n = 1;
        while (true) {
            $row = Database::one(
                'SELECT id FROM products WHERE slug = :slug' . ($ignoreId ? ' AND id <> :ignore' : '') . ' LIMIT 1',
                $ignoreId ? [':slug' => $slug, ':ignore' => $ignoreId] : [':slug' => $slug]
            );
            if (!$row) {
                return $slug;
            }
            $n++;
            $slug = $base . '-' . $n;
        }
    }

    /**
     * Validate the fields a product form sends. Returns [$clean, $errors] so the
     * controller can answer with every problem at once rather than one at a time.
     */
    public static function validate(array $input, ?int $ignoreId = null): array
    {
        $errors = [];

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            $errors['name'] = 'Give the product a name.';
        } elseif (mb_strlen($name) > 180) {
            $errors['name'] = 'That name is too long. Keep it under 180 characters.';
        }

        $sku = strtoupper(trim((string) ($input['sku'] ?? '')));
        if ($sku === '') {
            $errors['sku'] = 'Give the product a SKU.';
        } elseif (!preg_match('/^[A-Z0-9-]{3,80}$/', $sku)) {
            $errors['sku'] = 'A SKU is letters, digits and hyphens, 3 to 80 characters.';
        } else {
            $clash = Database::one(
                'SELECT id FROM products WHERE sku = :sku' . ($ignoreId ? ' AND id <> :ignore' : '') . ' LIMIT 1',
                $ignoreId ? [':sku' => $sku, ':ignore' => $ignoreId] : [':sku' => $sku]
            );
            if ($clash) {
                $errors['sku'] = 'Another product already uses that SKU.';
            }
        }

        $categoryId = (int) ($input['category_id'] ?? 0);
        if (!Database::one('SELECT id FROM product_categories WHERE id = :id', [':id' => $categoryId])) {
            $errors['category_id'] = 'Pick a category.';
        }

        $unitId = (int) ($input['unit_id'] ?? 0);
        $unit = Database::one('SELECT id, allows_decimal FROM units_of_measurement WHERE id = :id', [':id' => $unitId]);
        if (!$unit) {
            $errors['unit_id'] = 'Pick a unit.';
        }

        // A price of nothing means "not priced yet", which is a legitimate draft.
        // Any other price has to be one we are willing to store.
        $priceSubunit = Money::toSubunit((string) ($input['price'] ?? '0'));
        if ($priceSubunit !== 0 && !Pricing::isValidPrice($priceSubunit)) {
            $errors['price'] = 'That price is outside the range we allow. Leave it empty to save a draft.';
        }

        $minimum = self::cleanQuantity($input['minimum_quantity'] ?? '1', $unit);
        $increment = self::cleanQuantity($input['quantity_increment'] ?? '1', $unit);
        if ($minimum <= 0) {
            $errors['minimum_quantity'] = 'The minimum has to be more than nothing.';
        }
        if ($increment <= 0) {
            $errors['quantity_increment'] = 'The step has to be more than nothing.';
        }

        $clean = [
            'name'               => $name,
            'sku'                => $sku,
            'category_id'        => $categoryId,
            'unit_id'            => $unitId,
            'short_description'  => mb_substr(trim((string) ($input['short_description'] ?? '')), 0, 300),
            'description'        => trim((string) ($input['description'] ?? '')),
            'price_subunit'      => $priceSubunit,
            'minimum_quantity'   => $minimum,
            'quantity_increment' => $increment,
            'is_featured'        => !empty($input['is_featured']) ? 1 : 0,
            'is_active'          => !empty($input['is_active']) ? 1 : 0,
        ];

        return [$clean, $errors];
    }

    /**
     * A quantity as the unit allows it. Only the kilogramme takes a decimal, so
     * "1.5 bunch" becomes 2 rather than being stored as something unsellable.
     */
    private static function cleanQuantity($value, ?array $unit): float
    {
        $number = (float) preg_replace('/[^0-9.]/', '', (string) $value);
        if ($unit && empty($unit['allows_decimal'])) {
            $number = ceil($number);
        }
        return round($number, 3);
    }

    /**
     * Add a product. When it arrives with a price, that price opens its history
     * with a null old price, which is what the history's first row means.
     */
    public static function create(array $clean, ?int $userId): int
    {
        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            Database::run(
                'INSERT INTO products
                    (category_id, unit_id, name, slug, sku, short_description, description,
                     current_price_subunit, minimum_quantity, quantity_increment, is_featured, is_active)
                 VALUES (:category_id, :unit_id, :name, :slug, :sku, :short_description, :description,
                     :price, :minimum_quantity, :quantity_increment, :is_featured, :is_active)',
                [
                    ':category_id'        => $clean['category_id'],
                    ':unit_id'            => $clean['unit_id'],
                    ':name'               => $clean['name'],
                    ':slug'               => self::uniqueSlug($clean['name']),
                    ':sku'                => $clean['sku'],
                    ':short_description'  => $clean['short_description'],
                    ':description'        => $clean['description'],
                    ':price'              => $clean['price_subunit'],
                    ':minimum_quantity'   => $clean['minimum_quantity'],
                    ':quantity_increment' => $clean['quantity_increment'],
                    ':is_featured'        => $clean['is_featured'],
                    ':is_active'          => $clean['is_active'],
                ]
            );
            $id = (int) $pdo->lastInsertId();

            Database::run(
                'INSERT INTO product_availability (product_id, availability_status) VALUES (:id, \'available\')',
                [':id' => $id]
            );

            if ($clean['price_subunit'] > 0) {
                Pricing::writeHistory($id, null, $clean['price_subunit'], 'Opening price', $userId);
            }

            $pdo->commit();
            return $id;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Edit a product. The price is deliberately not part of this: it moves
     * through Pricing::change() so that no edit can ever slip past the history.
     */
    public static function update(int $id, array $clean, ?int $userId): void
    {
        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            $existing = Database::one('SELECT id, name, slug, current_price_subunit FROM products WHERE id = :id FOR UPDATE', [':id' => $id]);
            if (!$existing) {
                throw new DomainException('not_found');
            }

            // Keep the slug stable unless the name actually changed, so links
            // and search results that already point at this product survive.
            $slug = (string) $existing['slug'];
            if ($existing['name'] !== $clean['name']) {
                $slug = self::uniqueSlug($clean['name'], $id);
            }

            Database::run(
                'UPDATE products
                    SET category_id = :category_id, unit_id = :unit_id, name = :name, slug = :slug,
                        sku = :sku, short_description = :short_description, description = :description,
                        minimum_quantity = :minimum_quantity, quantity_increment = :quantity_increment,
                        is_featured = :is_featured, is_active = :is_active
                  WHERE id = :id',
                [
                    ':category_id'        => $clean['category_id'],
                    ':unit_id'            => $clean['unit_id'],
                    ':name'               => $clean['name'],
                    ':slug'               => $slug,
                    ':sku'                => $clean['sku'],
                    ':short_description'  => $clean['short_description'],
                    ':description'        => $clean['description'],
                    ':minimum_quantity'   => $clean['minimum_quantity'],
                    ':quantity_increment' => $clean['quantity_increment'],
                    ':is_featured'        => $clean['is_featured'],
                    ':is_active'          => $clean['is_active'],
                    ':id'                 => $id,
                ]
            );

            $current = (int) $existing['current_price_subunit'];
            if ($clean['price_subunit'] > 0 && $clean['price_subunit'] !== $current) {
                Pricing::change($id, $clean['price_subunit'], 'Changed while editing the product', $userId, false);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Set availability. A restock date only means anything while restocking. */
    public static function setAvailability(int $id, string $status, ?string $restockDate, ?int $userId): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new DomainException('bad_status');
        }
        if ($status !== 'restocking') {
            $restockDate = null;
        } elseif ($restockDate !== null && $restockDate !== '') {
            $timestamp = strtotime($restockDate);
            $restockDate = $timestamp === false ? null : date('Y-m-d', $timestamp);
        } else {
            $restockDate = null;
        }

        if (!Database::one('SELECT id FROM products WHERE id = :id', [':id' => $id])) {
            throw new DomainException('not_found');
        }

        Database::run(
            'INSERT INTO product_availability (product_id, availability_status, restock_date, updated_by)
             VALUES (:id, :status, :restock_date, :updated_by)
             ON DUPLICATE KEY UPDATE
                availability_status = VALUES(availability_status),
                restock_date = VALUES(restock_date),
                updated_by = VALUES(updated_by)',
            [':id' => $id, ':status' => $status, ':restock_date' => $restockDate, ':updated_by' => $userId]
        );
    }

    /** Switch a product on or off the shop without touching anything else. */
    public static function setActive(int $id, bool $active): void
    {
        $changed = Database::run(
            'UPDATE products SET is_active = :active WHERE id = :id',
            [':active' => $active ? 1 : 0, ':id' => $id]
        );
        if ($changed === 0 && !Database::one('SELECT id FROM products WHERE id = :id', [':id' => $id])) {
            throw new DomainException('not_found');
        }
    }

    /**
     * What is holding on to this product, and how hard. Anything here means the
     * product is deactivated rather than deleted.
     */
    public static function referenceCount(int $id): array
    {
        // A named placeholder cannot be reused in one statement without emulated
        // prepares, so the pairings query binds the id twice under two names.
        $counts = [
            'orders'   => ['SELECT COUNT(*) AS c FROM order_items WHERE product_id = :id', [':id' => $id]],
            'baskets'  => ['SELECT COUNT(*) AS c FROM cart_items WHERE product_id = :id', [':id' => $id]],
            'combos'   => ['SELECT COUNT(*) AS c FROM combo_package_items WHERE product_id = :id', [':id' => $id]],
            'pairings' => [
                'SELECT COUNT(*) AS c FROM product_pairings WHERE product_id = :id OR paired_product_id = :paired_id',
                [':id' => $id, ':paired_id' => $id],
            ],
            'prices'   => ['SELECT COUNT(*) AS c FROM product_price_history WHERE product_id = :id', [':id' => $id]],
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
     * Remove a product outright. Only allowed when nothing refers to it and it
     * never carried a price, so no history row is ever destroyed. Anything else
     * throws and the caller offers to deactivate instead.
     */
    public static function delete(int $id): void
    {
        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            if (!Database::one('SELECT id FROM products WHERE id = :id FOR UPDATE', [':id' => $id])) {
                throw new DomainException('not_found');
            }
            $refs = self::referenceCount($id);
            if ($refs['total'] > 0) {
                throw new DomainException('in_use');
            }
            // product_images and product_availability cascade from here.
            Database::run('DELETE FROM products WHERE id = :id', [':id' => $id]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Plain words for why a product cannot be deleted. */
    public static function inUseMessage(array $refs): string
    {
        $parts = [];
        if ($refs['orders'] > 0)   { $parts[] = $refs['orders'] === 1 ? 'an order' : $refs['orders'] . ' orders'; }
        if ($refs['combos'] > 0)   { $parts[] = $refs['combos'] === 1 ? 'a combo' : $refs['combos'] . ' combos'; }
        if ($refs['baskets'] > 0)  { $parts[] = $refs['baskets'] === 1 ? 'a basket' : $refs['baskets'] . ' baskets'; }
        if ($refs['pairings'] > 0) { $parts[] = 'a "goes well with" pairing'; }
        if ($refs['prices'] > 0)   { $parts[] = 'its price history'; }

        if (!$parts) {
            return 'Something still refers to this product.';
        }
        $last = array_pop($parts);
        $list = $parts ? implode(', ', $parts) . ' and ' . $last : $last;
        return 'This product is held by ' . $list . ', so it cannot be removed. Switch it off instead and it leaves the shop straight away.';
    }

    // --- Photos --------------------------------------------------------------

    public static function images(int $productId): array
    {
        return Database::all(
            'SELECT id, image_url, alt_text, sort_order, is_primary
               FROM product_images
              WHERE product_id = :id
              ORDER BY is_primary DESC, sort_order, id',
            [':id' => $productId]
        );
    }

    /**
     * Attach an uploaded photo. The first photo on a product becomes its primary
     * one, because a product with photos and no primary renders nothing.
     */
    public static function addImage(int $productId, string $imageUrl): int
    {
        $pdo = Database::getInstance()->getConnection();
        $row = Database::one(
            'SELECT COUNT(*) AS c, COALESCE(MAX(sort_order), -1) AS top FROM product_images WHERE product_id = :id',
            [':id' => $productId]
        );
        $isFirst = ((int) ($row['c'] ?? 0)) === 0;

        Database::run(
            'INSERT INTO product_images (product_id, image_url, sort_order, is_primary)
             VALUES (:product_id, :image_url, :sort_order, :is_primary)',
            [
                ':product_id' => $productId,
                ':image_url'  => $imageUrl,
                ':sort_order' => ((int) ($row['top'] ?? -1)) + 1,
                ':is_primary' => $isFirst ? 1 : 0,
            ]
        );
        return (int) $pdo->lastInsertId();
    }

    /** Exactly one photo is primary at a time. */
    public static function setPrimaryImage(int $productId, int $imageId): void
    {
        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            $image = Database::one(
                'SELECT id FROM product_images WHERE id = :id AND product_id = :product_id',
                [':id' => $imageId, ':product_id' => $productId]
            );
            if (!$image) {
                throw new DomainException('not_found');
            }
            Database::run('UPDATE product_images SET is_primary = 0 WHERE product_id = :product_id', [':product_id' => $productId]);
            Database::run('UPDATE product_images SET is_primary = 1 WHERE id = :id', [':id' => $imageId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Reorder photos from a list of ids. Anything not named keeps its place. */
    public static function reorderImages(int $productId, array $imageIds): void
    {
        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            $position = 0;
            foreach ($imageIds as $imageId) {
                Database::run(
                    'UPDATE product_images SET sort_order = :sort_order WHERE id = :id AND product_id = :product_id',
                    [':sort_order' => $position, ':id' => (int) $imageId, ':product_id' => $productId]
                );
                $position++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Remove a photo. If it was the primary one, the next photo takes over, so a
     * product never ends up with photos and no primary.
     */
    public static function deleteImage(int $productId, int $imageId): void
    {
        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            $image = Database::one(
                'SELECT id, image_url, is_primary FROM product_images WHERE id = :id AND product_id = :product_id',
                [':id' => $imageId, ':product_id' => $productId]
            );
            if (!$image) {
                throw new DomainException('not_found');
            }
            Database::run('DELETE FROM product_images WHERE id = :id', [':id' => $imageId]);

            if (!empty($image['is_primary'])) {
                $next = Database::one(
                    'SELECT id FROM product_images WHERE product_id = :product_id ORDER BY sort_order, id LIMIT 1',
                    [':product_id' => $productId]
                );
                if ($next) {
                    Database::run('UPDATE product_images SET is_primary = 1 WHERE id = :id', [':id' => (int) $next['id']]);
                }
            }
            $pdo->commit();

            // Only once the row is gone: an orphaned file is untidy, a missing
            // file with a row still pointing at it is a broken product page.
            self::removeUploadedFile((string) $image['image_url']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Delete an uploaded file, but only one we put in uploads/. The seeded
     * photos live in assets/ and are left alone.
     */
    private static function removeUploadedFile(string $imageUrl): void
    {
        $relative = ltrim($imageUrl, '/');
        if (!str_starts_with($relative, 'uploads/')) {
            return;
        }
        $root = realpath(dirname(__DIR__, 2) . '/uploads');
        $path = realpath(dirname(__DIR__, 2) . '/' . $relative);
        if ($root && $path && str_starts_with($path, $root) && is_file($path)) {
            @unlink($path);
        }
    }
}

<?php
/**
 * includes/classes/CatalogueSettings.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Manage produce categories and units of measurement.
 *
 * Allows staff with products.edit permission to add new categories and selling
 * units, and edit existing records.
 *
 * Slugs for categories are immutable once created (Option C) to protect active
 * storefront links, sitemaps, and category filters.
 *
 * Units of measurement allow updating decimal permissions and active status,
 * with explicit confirmation required if products or combos currently use the unit.
 *
 * Every change is audited to audit_logs in the same database transaction.
 * -----------------------------------------------------------------------------
 */

final class CatalogueSettings
{
    public const CATEGORY_NAME_MAX = 120;
    public const CATEGORY_DESC_MAX = 2000;
    public const UNIT_NAME_MAX = 80;
    public const UNIT_SYMBOL_MAX = 20;

    /**
     * Return all product categories with product counts.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function categories(bool $activeOnly = false): array
    {
        $where = $activeOnly ? ' WHERE c.is_active = 1' : '';
        return Database::all(
            'SELECT c.id, c.name, c.slug, c.description, c.sort_order, c.is_active,
                    COUNT(p.id) AS product_count,
                    COUNT(CASE WHEN p.is_active = 1 THEN 1 END) AS active_product_count
               FROM product_categories c
          LEFT JOIN products p ON p.category_id = c.id'
            . $where . '
           GROUP BY c.id, c.name, c.slug, c.description, c.sort_order, c.is_active
           ORDER BY c.sort_order ASC, c.name ASC'
        );
    }

    /**
     * Find one category by its id.
     */
    public static function findCategory(int $id): ?array
    {
        return Database::one(
            'SELECT c.id, c.name, c.slug, c.description, c.sort_order, c.is_active,
                    COUNT(p.id) AS product_count,
                    COUNT(CASE WHEN p.is_active = 1 THEN 1 END) AS active_product_count
               FROM product_categories c
          LEFT JOIN products p ON p.category_id = c.id
              WHERE c.id = :id
           GROUP BY c.id, c.name, c.slug, c.description, c.sort_order, c.is_active',
            [':id' => $id]
        );
    }

    /**
     * Validate input for adding or updating a category.
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public static function validateCategory(array $input, ?int $id = null): array
    {
        $clean = [];
        $errors = [];

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            $errors['name'] = 'Enter a category name.';
        } elseif (mb_strlen($name) > self::CATEGORY_NAME_MAX) {
            $errors['name'] = 'Category name must be ' . self::CATEGORY_NAME_MAX . ' characters or fewer.';
        } else {
            $clean['name'] = $name;
        }

        $description = trim((string) ($input['description'] ?? ''));
        if (mb_strlen($description) > self::CATEGORY_DESC_MAX) {
            $errors['description'] = 'Description must be ' . self::CATEGORY_DESC_MAX . ' characters or fewer.';
        } else {
            $clean['description'] = $description;
        }

        $clean['is_active'] = (!isset($input['is_active']) || (string) $input['is_active'] === '1' || $input['is_active'] === true) ? 1 : 0;

        if ($id !== null && $id > 0) {
            if (defined('DB_HOST')) {
                $existing = self::findCategory($id);
                if (!$existing) {
                    $errors['category_id'] = 'We could not find that category.';
                } else {
                    $clean['slug'] = (string) $existing['slug'];
                }
            }
        } else {
            $baseSlug = okv_slug($name);
            if ($baseSlug === '') {
                $errors['name'] = 'Name must produce a valid address slug (letters or numbers).';
            } else {
                $clean['slug'] = self::uniqueCategorySlug($baseSlug);
            }
        }

        return [$clean, $errors];
    }

    /**
     * Generate a unique category slug.
     */
    public static function uniqueCategorySlug(string $base, ?int $ignoreId = null): string
    {
        if (!defined('DB_HOST')) {
            return $base;
        }
        $slug = $base;
        $n = 2;
        while (true) {
            $exists = Database::one(
                'SELECT id FROM product_categories WHERE slug = :slug' . ($ignoreId ? ' AND id <> :ignore' : '') . ' LIMIT 1',
                $ignoreId ? [':slug' => $slug, ':ignore' => $ignoreId] : [':slug' => $slug]
            );
            if (!$exists) {
                return $slug;
            }
            $slug = $base . '-' . $n;
            $n++;
        }
    }

    /**
     * Save a category (create when $id is null/0, update when $id > 0).
     */
    public static function saveCategory(array $clean, ?int $id = null, ?int $actorId = null): array
    {
        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            if ($id !== null && $id > 0) {
                $old = self::findCategory($id);
                if (!$old) {
                    throw new DomainException('not_found');
                }
                Database::run(
                    'UPDATE product_categories
                        SET name = :name,
                            description = :description,
                            is_active = :is_active
                      WHERE id = :id',
                    [
                        ':id'          => $id,
                        ':name'        => $clean['name'],
                        ':description' => $clean['description'] !== '' ? $clean['description'] : null,
                        ':is_active'   => (int) $clean['is_active'],
                    ]
                );
                $new = [
                    'name'        => $clean['name'],
                    'slug'        => $old['slug'],
                    'description' => $clean['description'],
                    'is_active'   => (int) $clean['is_active'],
                ];
                Audit::record('catalogue.category.update', 'product_categories', $id, $old, $new, $actorId);
                $pdo->commit();
                return (array) self::findCategory($id);
            }

            $orderRow = Database::one('SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_order FROM product_categories');
            $nextOrder = (int) ($orderRow['next_order'] ?? 1);

            Database::run(
                'INSERT INTO product_categories (name, slug, description, sort_order, is_active)
                 VALUES (:name, :slug, :description, :sort_order, :is_active)',
                [
                    ':name'        => $clean['name'],
                    ':slug'        => $clean['slug'],
                    ':description' => $clean['description'] !== '' ? $clean['description'] : null,
                    ':sort_order'  => $nextOrder,
                    ':is_active'   => (int) $clean['is_active'],
                ]
            );
            $newId = (int) $pdo->lastInsertId();
            $new = [
                'name'        => $clean['name'],
                'slug'        => $clean['slug'],
                'description' => $clean['description'],
                'sort_order'  => $nextOrder,
                'is_active'   => (int) $clean['is_active'],
            ];
            Audit::record('catalogue.category.create', 'product_categories', $newId, null, $new, $actorId);
            $pdo->commit();
            return (array) self::findCategory($newId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Return all units of measurement with product reference counts.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function units(bool $activeOnly = false): array
    {
        $where = $activeOnly ? ' WHERE u.is_active = 1' : '';
        return Database::all(
            'SELECT u.id, u.name, u.symbol, u.allows_decimal, u.is_active,
                    (SELECT COUNT(*) FROM products p WHERE p.unit_id = u.id) AS product_count,
                    (SELECT COUNT(*) FROM combo_package_items cpi WHERE cpi.unit_id = u.id) AS combo_item_count
               FROM units_of_measurement u'
            . $where . '
              ORDER BY u.id ASC'
        );
    }

    /**
     * Find one unit of measurement by its id.
     */
    public static function findUnit(int $id): ?array
    {
        return Database::one(
            'SELECT u.id, u.name, u.symbol, u.allows_decimal, u.is_active,
                    (SELECT COUNT(*) FROM products p WHERE p.unit_id = u.id) AS product_count,
                    (SELECT COUNT(*) FROM combo_package_items cpi WHERE cpi.unit_id = u.id) AS combo_item_count
               FROM units_of_measurement u
              WHERE u.id = :id',
            [':id' => $id]
        );
    }

    /**
     * Validate input for adding or updating a unit of measurement.
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public static function validateUnit(array $input, ?int $id = null): array
    {
        $clean = [];
        $errors = [];

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            $errors['name'] = 'Enter a unit name.';
        } elseif (mb_strlen($name) > self::UNIT_NAME_MAX) {
            $errors['name'] = 'Unit name must be ' . self::UNIT_NAME_MAX . ' characters or fewer.';
        } else {
            if (defined('DB_HOST')) {
                $dupName = Database::one(
                    'SELECT id FROM units_of_measurement WHERE LOWER(name) = LOWER(:name)' . ($id ? ' AND id <> :id' : '') . ' LIMIT 1',
                    $id ? [':name' => $name, ':id' => $id] : [':name' => $name]
                );
                if ($dupName) {
                    $errors['name'] = 'Another unit already uses that name.';
                } else {
                    $clean['name'] = $name;
                }
            } else {
                $clean['name'] = $name;
            }
        }

        $symbol = trim((string) ($input['symbol'] ?? ''));
        if ($symbol === '') {
            $errors['symbol'] = 'Enter a unit symbol (such as kg or bunch).';
        } elseif (mb_strlen($symbol) > self::UNIT_SYMBOL_MAX) {
            $errors['symbol'] = 'Unit symbol must be ' . self::UNIT_SYMBOL_MAX . ' characters or fewer.';
        } else {
            if (defined('DB_HOST')) {
                $dupSymbol = Database::one(
                    'SELECT id FROM units_of_measurement WHERE LOWER(symbol) = LOWER(:symbol)' . ($id ? ' AND id <> :id' : '') . ' LIMIT 1',
                    $id ? [':symbol' => $symbol, ':id' => $id] : [':symbol' => $symbol]
                );
                if ($dupSymbol) {
                    $errors['symbol'] = 'Another unit already uses that symbol.';
                } else {
                    $clean['symbol'] = $symbol;
                }
            } else {
                $clean['symbol'] = $symbol;
            }
        }

        $clean['allows_decimal'] = (!empty($input['allows_decimal']) && (string) $input['allows_decimal'] !== '0') ? 1 : 0;
        $clean['is_active'] = (!isset($input['is_active']) || (string) $input['is_active'] === '1' || $input['is_active'] === true) ? 1 : 0;

        return [$clean, $errors];
    }

    /**
     * Check if editing a unit requires an explicit confirmation step.
     */
    public static function checkUnitSafety(int $id, array $clean): array
    {
        $existing = self::findUnit($id);
        if (!$existing) {
            return ['in_use' => false, 'count' => 0, 'requires_confirmation' => false];
        }
        $productCount = (int) ($existing['product_count'] ?? 0);
        $comboCount   = (int) ($existing['combo_item_count'] ?? 0);
        $inUseCount   = $productCount + $comboCount;

        $decimalChanged  = (int) $existing['allows_decimal'] !== (int) $clean['allows_decimal'];
        $deactivated     = (int) $existing['is_active'] === 1 && (int) $clean['is_active'] === 0;

        $requiresConfirm = $inUseCount > 0 && ($decimalChanged || $deactivated);

        return [
            'in_use'                => $inUseCount > 0,
            'count'                 => $inUseCount,
            'product_count'         => $productCount,
            'combo_count'           => $comboCount,
            'decimal_changed'       => $decimalChanged,
            'deactivated'           => $deactivated,
            'requires_confirmation' => $requiresConfirm,
        ];
    }

    /**
     * Save a unit of measurement.
     */
    public static function saveUnit(array $clean, ?int $id = null, ?int $actorId = null, bool $confirmed = false): array
    {
        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            if ($id !== null && $id > 0) {
                $old = self::findUnit($id);
                if (!$old) {
                    throw new DomainException('not_found');
                }
                $safety = self::checkUnitSafety($id, $clean);
                if ($safety['requires_confirmation'] && !$confirmed) {
                    throw new DomainException('confirmation_required');
                }

                Database::run(
                    'UPDATE units_of_measurement
                        SET name = :name,
                            symbol = :symbol,
                            allows_decimal = :allows_decimal,
                            is_active = :is_active
                      WHERE id = :id',
                    [
                        ':id'             => $id,
                        ':name'           => $clean['name'],
                        ':symbol'         => $clean['symbol'],
                        ':allows_decimal' => (int) $clean['allows_decimal'],
                        ':is_active'      => (int) $clean['is_active'],
                    ]
                );
                $new = [
                    'name'           => $clean['name'],
                    'symbol'         => $clean['symbol'],
                    'allows_decimal' => (int) $clean['allows_decimal'],
                    'is_active'      => (int) $clean['is_active'],
                ];
                Audit::record('catalogue.unit.update', 'units_of_measurement', $id, $old, $new, $actorId);
                $pdo->commit();
                return (array) self::findUnit($id);
            }

            Database::run(
                'INSERT INTO units_of_measurement (name, symbol, allows_decimal, is_active)
                 VALUES (:name, :symbol, :allows_decimal, :is_active)',
                [
                    ':name'           => $clean['name'],
                    ':symbol'         => $clean['symbol'],
                    ':allows_decimal' => (int) $clean['allows_decimal'],
                    ':is_active'      => (int) $clean['is_active'],
                ]
            );
            $newId = (int) $pdo->lastInsertId();
            $new = [
                'name'           => $clean['name'],
                'symbol'         => $clean['symbol'],
                'allows_decimal' => (int) $clean['allows_decimal'],
                'is_active'      => (int) $clean['is_active'],
            ];
            Audit::record('catalogue.unit.create', 'units_of_measurement', $newId, null, $new, $actorId);
            $pdo->commit();
            return (array) self::findUnit($newId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

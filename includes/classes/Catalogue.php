<?php
/**
 * Read-only catalogue queries shared by storefront pages and the public API.
 */
final class Catalogue
{
    public const SUGGESTION_LIMIT = 4;

    /** One page of the shop grid, and of any listing paginated the same way. */
    public const PER_PAGE = 25;

    public static function cleanSearch(string $search): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', $search) ?? ''), 0, 80);
    }

    /** A slug is lowercase letters, digits and hyphens, or it is not a slug. */
    public static function cleanSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        return preg_match('/^[a-z0-9-]{1,140}$/', $slug) ? $slug : '';
    }

    public static function cleanCategory(string $category): string
    {
        return self::cleanSlug($category);
    }

    /**
     * Make a search term safe to drop inside a LIKE pattern. Without this a
     * customer searching for "%" matches every product and "t%o" matches most
     * of them, because LIKE reads those as wildcards.
     */
    public static function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }

    public static function categories(): array
    {
        return Database::all(
            'SELECT c.id, c.name, c.slug, c.description,
                    (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.is_active = 1) AS product_count
               FROM product_categories c
              WHERE c.is_active = 1
              ORDER BY c.sort_order, c.name'
        );
    }

    /**
     * The products a customer can see, featured first within a category.
     *
     * With $perPage set, one page of the list comes back instead of the whole
     * thing. The page figures are clamped ints interpolated into the LIMIT on
     * purpose: under native prepared statements a bound string cannot sit
     * inside a LIMIT clause, and a clamped int can never carry injection.
     */
    public static function products(string $search = '', string $category = '', int $page = 1, ?int $perPage = null): array
    {
        [$where, $params] = self::productsWhere($search, $category);

        return Database::all(
            'SELECT p.id, p.name, p.slug, p.sku, p.short_description, p.current_price_subunit,
                    p.minimum_quantity, p.quantity_increment, p.is_featured,
                    c.name AS category_name, c.slug AS category_slug,
                    u.name AS unit_name, u.symbol AS unit,
                    COALESCE(pa.availability_status, \'available\') AS availability_status,
                    pa.restock_date,
                    (SELECT pi.image_url FROM product_images pi
                      WHERE pi.product_id = p.id
                      ORDER BY pi.is_primary DESC, pi.sort_order, pi.id LIMIT 1) AS image
               FROM products p
               JOIN product_categories c ON c.id = p.category_id AND c.is_active = 1
               JOIN units_of_measurement u ON u.id = p.unit_id AND u.is_active = 1
               LEFT JOIN product_availability pa ON pa.product_id = p.id'
            . $where . '
              ORDER BY p.is_featured DESC, c.sort_order, p.name'
            . okv_limit_clause($page, $perPage),
            $params
        );
    }

    /**
     * The week's picks for the home page: featured products only, in the same
     * row shape products() returns. Same shape matters, because the home page
     * renders them through okv_product_card, which needs the id, the unit, the
     * availability and the primary photo to draw a card a customer can add
     * from. Falls back to nothing when no product is flagged featured, and the
     * caller then drops the section rather than showing an empty grid.
     */
    public static function featuredProducts(int $limit = 8): array
    {
        $limit = max(1, min(24, $limit));
        return Database::all(
            'SELECT p.id, p.name, p.slug, p.sku, p.short_description, p.current_price_subunit,
                    p.minimum_quantity, p.quantity_increment, p.is_featured,
                    c.name AS category_name, c.slug AS category_slug,
                    u.name AS unit_name, u.symbol AS unit,
                    COALESCE(pa.availability_status, \'available\') AS availability_status,
                    pa.restock_date,
                    (SELECT pi.image_url FROM product_images pi
                      WHERE pi.product_id = p.id
                      ORDER BY pi.is_primary DESC, pi.sort_order, pi.id LIMIT 1) AS image
               FROM products p
               JOIN product_categories c ON c.id = p.category_id AND c.is_active = 1
               JOIN units_of_measurement u ON u.id = p.unit_id AND u.is_active = 1
               LEFT JOIN product_availability pa ON pa.product_id = p.id
              WHERE p.is_active = 1 AND p.is_featured = 1
              ORDER BY c.sort_order, p.name'
            . okv_limit_clause(1, $limit)
        );
    }

    /**
     * How many products match the filters, so a page count and a "Showing 26
     * to 50 of 87" line can be drawn without fetching the rows themselves.
     * Same joins as the list, so a product on an inactive category or unit is
     * counted exactly the way it is listed.
     */
    public static function countProducts(string $search = '', string $category = ''): int
    {
        [$where, $params] = self::productsWhere($search, $category);

        $row = Database::one(
            'SELECT COUNT(*) AS matched
               FROM products p
               JOIN product_categories c ON c.id = p.category_id AND c.is_active = 1
               JOIN units_of_measurement u ON u.id = p.unit_id AND u.is_active = 1'
            . $where,
            $params
        );

        return (int) ($row['matched'] ?? 0);
    }

    /**
     * The WHERE fragment the product list and its count share, built up in
     * PHP so a filter the customer did not use never leaves a placeholder in
     * the SQL. The search term stays data: it is escaped for LIKE and bound,
     * never interpolated.
     */
    private static function productsWhere(string $search, string $category): array
    {
        $search = self::cleanSearch($search);
        $category = self::cleanCategory($category);

        $filters = ['p.is_active = 1'];
        $params = [];
        if ($category !== '') {
            $filters[] = 'c.slug = :category_slug';
            $params[':category_slug'] = $category;
        }
        if ($search !== '') {
            $filters[] = '(p.name LIKE :search_name OR p.short_description LIKE :search_short OR p.description LIKE :search_description)';
            $like = '%' . self::escapeLike($search) . '%';
            $params[':search_name'] = $like;
            $params[':search_short'] = $like;
            $params[':search_description'] = $like;
        }

        return [' WHERE ' . implode(' AND ', $filters), $params];
    }

    public static function productBySlug(string $slug): ?array
    {
        $slug = self::cleanSlug($slug);
        if ($slug === '') {
            return null;
        }

        return Database::one(
            'SELECT p.id, p.category_id, p.name, p.slug, p.sku, p.short_description, p.description,
                    p.current_price_subunit, p.minimum_quantity, p.quantity_increment,
                    c.name AS category_name, c.slug AS category_slug,
                    u.name AS unit_name, u.symbol AS unit,
                    COALESCE(pa.availability_status, \'available\') AS availability_status,
                    pa.restock_date
               FROM products p
               JOIN product_categories c ON c.id = p.category_id AND c.is_active = 1
               JOIN units_of_measurement u ON u.id = p.unit_id AND u.is_active = 1
               LEFT JOIN product_availability pa ON pa.product_id = p.id
              WHERE p.slug = :slug AND p.is_active = 1
              LIMIT 1',
            [':slug' => $slug]
        );
    }

    public static function images(int $productId): array
    {
        return Database::all(
            'SELECT image_url, alt_text, is_primary
               FROM product_images
              WHERE product_id = :product_id
              ORDER BY is_primary DESC, sort_order, id',
            [':product_id' => $productId]
        );
    }

    public static function suggestions(int $productId, int $categoryId): array
    {
        $curated = Database::all(
            'SELECT p.id, p.name, p.slug, p.short_description, p.current_price_subunit,
                    u.symbol AS unit,
                    COALESCE(pa.availability_status, \'available\') AS availability_status,
                    pa.restock_date,
                    (SELECT pi.image_url FROM product_images pi
                      WHERE pi.product_id = p.id
                      ORDER BY pi.is_primary DESC, pi.sort_order, pi.id LIMIT 1) AS image
               FROM product_pairings pp
               JOIN products p ON p.id = pp.paired_product_id AND p.is_active = 1
               JOIN units_of_measurement u ON u.id = p.unit_id AND u.is_active = 1
               LEFT JOIN product_availability pa ON pa.product_id = p.id
              WHERE pp.product_id = :product_id
              ORDER BY pp.sort_order, pp.id
              LIMIT ' . self::SUGGESTION_LIMIT,
            [':product_id' => $productId]
        );

        if (count($curated) >= self::SUGGESTION_LIMIT) {
            return array_slice($curated, 0, self::SUGGESTION_LIMIT);
        }

        $fallback = Database::all(
            'SELECT p.id, p.name, p.slug, p.short_description, p.current_price_subunit,
                    u.symbol AS unit,
                    COALESCE(pa.availability_status, \'available\') AS availability_status,
                    pa.restock_date,
                    (SELECT pi.image_url FROM product_images pi
                      WHERE pi.product_id = p.id
                      ORDER BY pi.is_primary DESC, pi.sort_order, pi.id LIMIT 1) AS image
               FROM products p
               JOIN units_of_measurement u ON u.id = p.unit_id AND u.is_active = 1
               LEFT JOIN product_availability pa ON pa.product_id = p.id
              WHERE p.category_id = :category_id AND p.id <> :product_id AND p.is_active = 1
              ORDER BY p.is_featured DESC, p.name
              LIMIT ' . self::SUGGESTION_LIMIT,
            [':category_id' => $categoryId, ':product_id' => $productId]
        );

        $seen = [];
        $result = [];
        foreach (array_merge($curated, $fallback) as $row) {
            $id = (int) $row['id'];
            if (!isset($seen[$id])) {
                $seen[$id] = true;
                $result[] = $row;
            }
            if (count($result) === self::SUGGESTION_LIMIT) {
                break;
            }
        }
        return $result;
    }

    // --- Combos (read only, for the storefront) ------------------------------

    /**
     * Combos on the shop today. Active plus inside the availability window,
     * ordered featured first then by name. Component availability is not
     * checked (M3 decision Q2): a combo with an out-of-stock component is
     * still shown, and the packing team handles a substitution or a Make It
     * Right if it comes up.
     *
     * The row also carries `component_total_subunit` (the sum of every
     * component's price * quantity, rounded per line to match
     * Combos::sumComponents) and `fallback_image` (the primary photo of the
     * first component in combo_package_items id order, so "first" is the row
     * the Manager added first in the builder). This turns what used to be one
     * extra query per card in the grid into one round-trip for the whole
     * page, matching the N+1 pattern the M2 audit called out for products.
     */
    public static function combos(): array
    {
        $today = date('Y-m-d');
        return Database::all(
            'SELECT c.id, c.name, c.slug, c.sku, c.description, c.price_subunit,
                    c.image_url, c.is_featured,
                    c.available_from, c.available_until,
                    (SELECT COUNT(*) FROM combo_package_items ci WHERE ci.combo_package_id = c.id) AS component_count,
                    (SELECT COALESCE(SUM(ROUND(ci2.quantity * p2.current_price_subunit)), 0)
                       FROM combo_package_items ci2
                       JOIN products p2 ON p2.id = ci2.product_id
                      WHERE ci2.combo_package_id = c.id
                        AND ci2.quantity > 0
                        AND p2.current_price_subunit > 0) AS component_total_subunit,
                    (SELECT pi.image_url
                       FROM combo_package_items ci3
                       JOIN product_images pi ON pi.product_id = ci3.product_id
                      WHERE ci3.combo_package_id = c.id
                      ORDER BY ci3.id ASC, pi.is_primary DESC, pi.sort_order ASC, pi.id ASC
                      LIMIT 1) AS fallback_image
               FROM combo_packages c
              WHERE c.is_active = 1
                AND (c.available_from IS NULL OR c.available_from <= :today_from)
                AND (c.available_until IS NULL OR c.available_until >= :today_until)
              ORDER BY c.is_featured DESC, c.name',
            [':today_from' => $today, ':today_until' => $today]
        );
    }

    /**
     * Featured combos, for the home page. A small list, buyable now, featured
     * first, ordered by name after that. Falls back to unfeatured combos when
     * there are not enough featured ones to fill $limit.
     */
    public static function featuredCombos(int $limit = 3): array
    {
        $limit = max(1, min(24, $limit));
        return array_slice(self::combos(), 0, $limit);
    }

    /**
     * One combo by slug, along with the count of its components. Returns null
     * when the slug is not a live combo, so the caller can render a 404 rather
     * than a page with no name.
     */
    public static function comboBySlug(string $slug): ?array
    {
        $slug = self::cleanSlug($slug);
        if ($slug === '') {
            return null;
        }
        $today = date('Y-m-d');
        return Database::one(
            'SELECT c.id, c.name, c.slug, c.sku, c.description, c.price_subunit,
                    c.image_url, c.is_featured, c.is_active,
                    c.available_from, c.available_until,
                    (SELECT COUNT(*) FROM combo_package_items ci WHERE ci.combo_package_id = c.id) AS component_count,
                    (SELECT COALESCE(SUM(ROUND(ci2.quantity * p2.current_price_subunit)), 0)
                       FROM combo_package_items ci2
                       JOIN products p2 ON p2.id = ci2.product_id
                      WHERE ci2.combo_package_id = c.id
                        AND ci2.quantity > 0
                        AND p2.current_price_subunit > 0) AS component_total_subunit,
                    (SELECT pi.image_url
                       FROM combo_package_items ci3
                       JOIN product_images pi ON pi.product_id = ci3.product_id
                      WHERE ci3.combo_package_id = c.id
                      ORDER BY ci3.id ASC, pi.is_primary DESC, pi.sort_order ASC, pi.id ASC
                      LIMIT 1) AS fallback_image
               FROM combo_packages c
              WHERE c.slug = :slug
                AND c.is_active = 1
                AND (c.available_from IS NULL OR c.available_from <= :today_from)
                AND (c.available_until IS NULL OR c.available_until >= :today_until)
              LIMIT 1',
            [':slug' => $slug, ':today_from' => $today, ':today_until' => $today]
        );
    }

    /**
     * The components inside one combo, with the product name, slug and unit
     * ready for the detail page's contents list. Read only; the admin builder
     * uses Combos::components() for the same shape plus the current price.
     */
    public static function comboComponents(int $comboId): array
    {
        return Database::all(
            'SELECT ci.id, ci.quantity,
                    p.id AS product_id, p.name AS product_name, p.slug AS product_slug,
                    p.short_description, p.current_price_subunit,
                    u.symbol AS unit, u.name AS unit_name,
                    (SELECT pi.image_url FROM product_images pi
                      WHERE pi.product_id = p.id
                      ORDER BY pi.is_primary DESC, pi.sort_order, pi.id LIMIT 1) AS image
               FROM combo_package_items ci
               JOIN products p ON p.id = ci.product_id
               JOIN units_of_measurement u ON u.id = ci.unit_id
              WHERE ci.combo_package_id = :combo_id
              ORDER BY ci.id',
            [':combo_id' => $comboId]
        );
    }
}

<?php
/**
 * Read-only catalogue queries shared by storefront pages and the public API.
 */
final class Catalogue
{
    public const SUGGESTION_LIMIT = 4;

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

    public static function products(string $search = '', string $category = ''): array
    {
        $search = self::cleanSearch($search);
        $category = self::cleanCategory($category);
        $like = '%' . self::escapeLike($search) . '%';

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
              WHERE p.is_active = 1
                AND (:category_empty = \'\' OR c.slug = :category_slug)
                AND (:search_empty = \'\' OR p.name LIKE :search_name OR p.short_description LIKE :search_short OR p.description LIKE :search_description)
              ORDER BY p.is_featured DESC, c.sort_order, p.name',
            [
                ':category_empty' => $category,
                ':category_slug' => $category,
                ':search_empty' => $search,
                ':search_name' => $like,
                ':search_short' => $like,
                ':search_description' => $like,
            ]
        );
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
}

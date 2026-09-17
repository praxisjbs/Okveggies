<?php
/** Public sitemap composition. Returns neutral data or escaped XML. */
final class Sitemap
{
    private const STATIC_PATHS = ['/', '/shop.php', '/combos.php', '/kitchen-runs.php', '/contact.php'];

    /** Load the complete public set. Any database failure must reach the route. */
    public static function entries(): array
    {
        return self::compose(
            ContentPages::publishedForSitemap(),
            Catalogue::productsForSitemap(),
            Catalogue::combosForSitemap()
        );
    }

    /** Pure composition kept separate so ordering and escaping are unit-testable. */
    public static function compose(array $contentPages, array $products, array $combos): array
    {
        $entries = [];
        foreach (self::STATIC_PATHS as $path) {
            $entries[] = ['path' => $path, 'lastmod' => null];
        }
        foreach ($contentPages as $page) {
            $path = (string) ($page['path'] ?? '');
            if ($path !== '' && $path !== '/' && str_starts_with($path, '/')) {
                $entries[] = ['path' => $path, 'lastmod' => self::dateOnly($page['lastmod'] ?? null)];
            }
        }
        foreach ($products as $product) {
            $slug = Catalogue::cleanSlug((string) ($product['slug'] ?? ''));
            if ($slug !== '') {
                $entries[] = [
                    'path' => '/product.php?slug=' . rawurlencode($slug),
                    'lastmod' => self::dateOnly($product['updated_at'] ?? null),
                ];
            }
        }
        foreach ($combos as $combo) {
            $slug = Catalogue::cleanSlug((string) ($combo['slug'] ?? ''));
            if ($slug !== '') {
                $entries[] = [
                    'path' => '/combo.php?slug=' . rawurlencode($slug),
                    'lastmod' => self::dateOnly($combo['updated_at'] ?? null),
                ];
            }
        }

        $unique = [];
        foreach ($entries as $entry) {
            $unique[$entry['path']] = $entry;
        }
        return array_values($unique);
    }

    public static function xml(array $entries, string $appUrl): string
    {
        $base = rtrim($appUrl, '/');
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($entries as $entry) {
            $location = self::xmlEscape($base . (string) $entry['path']);
            $xml .= "  <url><loc>{$location}</loc>";
            if (!empty($entry['lastmod'])) {
                $xml .= '<lastmod>' . self::xmlEscape((string) $entry['lastmod']) . '</lastmod>';
            }
            $xml .= "</url>\n";
        }
        return $xml . "</urlset>\n";
    }

    private static function dateOnly(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $value) ? substr($value, 0, 10) : null;
    }

    private static function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}

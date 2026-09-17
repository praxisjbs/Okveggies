<?php
/** M12 sitemap and discovery behavior against a migrated scratch database. */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$tests = 0; $passed = 0;
function smh_ok($condition, string $label): void { global $tests, $passed; $tests++; if ($condition) { $passed++; } else { fwrite(STDERR, "  FAIL: $label\n"); } }
function smh_eq($expected, $actual, string $label): void { smh_ok($expected === $actual, $label . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')')); }
function smh_get(string $path): array
{
    $base = rtrim(getenv('OKV_TEST_BASE') ?: 'http://127.0.0.1:8123', '/');
    $headers = [];
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['X-Forwarded-Proto: https'],
        CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$headers) { $headers[] = trim($line); return strlen($line); },
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, (string) $body, $headers];
}

$pages = Database::all(
    'SELECT id, slug, is_published, published_at, updated_at FROM content_pages WHERE slug IN (:published_slug, :hidden_slug)',
    [':published_slug' => 'about', ':hidden_slug' => 'terms']
);
$pageBefore = [];
foreach ($pages as $page) { $pageBefore[(string) $page['slug']] = $page; }
$product = Database::one(
    'SELECT p.id, p.slug, p.is_active, p.updated_at FROM products p '
    . 'JOIN product_categories c ON c.id = p.category_id AND c.is_active = :category_active '
    . 'JOIN units_of_measurement u ON u.id = p.unit_id AND u.is_active = :unit_active '
    . 'ORDER BY p.id LIMIT 1',
    [':category_active' => 1, ':unit_active' => 1]
);
$combo = Database::one('SELECT id, slug, is_active, available_from, available_until, updated_at FROM combo_packages ORDER BY id LIMIT 1');

try {
    Database::run(
        'UPDATE content_pages SET is_published = :published, published_at = :published_at WHERE slug = :slug',
        [':published' => 1, ':published_at' => '2026-09-16 12:00:00', ':slug' => 'about']
    );
    Database::run(
        'UPDATE content_pages SET is_published = :published WHERE slug = :slug',
        [':published' => 0, ':slug' => 'terms']
    );
    if ($product !== null) {
        Database::run('UPDATE products SET is_active = :active WHERE id = :id', [':active' => 1, ':id' => $product['id']]);
    }
    if ($combo !== null) {
        Database::run(
            'UPDATE combo_packages SET is_active = :active, available_from = :available_from, available_until = :available_until WHERE id = :id',
            [':active' => 1, ':available_from' => null, ':available_until' => null, ':id' => $combo['id']]
        );
    }

    [$status, $xml, $headers] = smh_get('/sitemap.xml');
    smh_eq(200, $status, 'the canonical sitemap route returns 200');
    smh_ok((bool) array_filter($headers, static fn(string $line): bool => str_starts_with(strtolower($line), 'content-type: application/xml')), 'sitemap sends an XML content type');
    foreach (['/', '/shop.php', '/combos.php', '/kitchen-runs.php', '/contact.php', '/our-story'] as $path) {
        smh_ok(str_contains($xml, $path . '</loc>'), "sitemap contains $path");
    }
    smh_ok(!str_contains($xml, '/terms</loc>'), 'an unpublished content page is absent from the sitemap');
    smh_ok(!str_contains($xml, '/page.php?slug=') && !str_contains($xml, '/admin/content-preview.php'), 'legacy and preview URLs are absent from the sitemap');
    smh_ok(!str_contains($xml, '/shop.php?category='), 'category filter variants are absent from the sitemap');
    smh_ok(str_contains($xml, '<lastmod>2026-09-16</lastmod>'), 'published content uses its reliable publication date');
    if ($product !== null) {
        smh_ok(str_contains($xml, '/product.php?slug=' . rawurlencode((string) $product['slug']) . '</loc>'), 'an active product canonical URL is in the sitemap');
    }
    if ($combo !== null) {
        smh_ok(str_contains($xml, '/combo.php?slug=' . rawurlencode((string) $combo['slug']) . '</loc>'), 'a currently buyable combo canonical URL is in the sitemap');
    }

    if ($product !== null) {
        Database::run('UPDATE products SET is_active = :active WHERE id = :id', [':active' => 0, ':id' => $product['id']]);
    }
    if ($combo !== null) {
        Database::run(
            'UPDATE combo_packages SET available_until = :available_until WHERE id = :id',
            [':available_until' => '2000-01-01', ':id' => $combo['id']]
        );
    }
    [, $withdrawnXml] = smh_get('/sitemap.xml');
    if ($product !== null) {
        smh_ok(!str_contains($withdrawnXml, '/product.php?slug=' . rawurlencode((string) $product['slug']) . '</loc>'), 'an inactive product is removed from the sitemap');
    }
    if ($combo !== null) {
        smh_ok(!str_contains($withdrawnXml, '/combo.php?slug=' . rawurlencode((string) $combo['slug']) . '</loc>'), 'an expired combo is removed from the sitemap');
    }

    [$status, $robots] = smh_get('/robots.txt');
    smh_eq(200, $status, 'robots.txt is publicly readable');
    smh_ok(str_contains($robots, 'Sitemap: https://okveggies.com.ng/sitemap.xml'), 'robots.txt advertises the canonical sitemap');
} finally {
    foreach ($pageBefore as $row) {
        Database::run(
            'UPDATE content_pages SET is_published = :published, published_at = :published_at, updated_at = :updated_at WHERE id = :id',
            [':published' => $row['is_published'], ':published_at' => $row['published_at'], ':updated_at' => $row['updated_at'], ':id' => $row['id']]
        );
    }
    if ($product !== null) {
        Database::run(
            'UPDATE products SET is_active = :active, updated_at = :updated_at WHERE id = :id',
            [':active' => $product['is_active'], ':updated_at' => $product['updated_at'], ':id' => $product['id']]
        );
    }
    if ($combo !== null) {
        Database::run(
            'UPDATE combo_packages SET is_active = :active, available_from = :available_from, available_until = :available_until, updated_at = :updated_at WHERE id = :id',
            [':active' => $combo['is_active'], ':available_from' => $combo['available_from'], ':available_until' => $combo['available_until'],
             ':updated_at' => $combo['updated_at'], ':id' => $combo['id']]
        );
    }
}

fwrite(STDOUT, "\n$passed / $tests sitemap HTTP assertions passed.\n");
exit($passed === $tests ? 0 : 1);

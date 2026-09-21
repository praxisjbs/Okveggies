<?php
/** Pure and static M12 routing and sitemap checks. */

$root = dirname(__DIR__, 2);
$route = (string) file_get_contents($root . '/sitemap.php');
$apache = (string) file_get_contents($root . '/.htaccess');
$robots = (string) file_get_contents($root . '/robots.txt');
$footer = (string) file_get_contents($root . '/includes/components/shop/footer.php');
$nav = (string) file_get_contents($root . '/includes/config/nav.php');

$entries = Sitemap::compose(
    [
        ['path' => '/our-story', 'lastmod' => '2026-09-14 18:00:00'],
        ['path' => '/privacy', 'lastmod' => null],
        ['path' => 'https://attacker.example/page', 'lastmod' => null],
    ],
    [
        ['slug' => 'tomato', 'updated_at' => '2026-09-15 12:10:00'],
        ['slug' => 'bad/slug', 'updated_at' => '2026-09-15'],
    ],
    [
        ['slug' => 'stew-combo', 'updated_at' => '2026-09-16 08:30:00'],
        ['slug' => 'bad?combo', 'updated_at' => '2026-09-16'],
    ]
);
$paths = array_column($entries, 'path');
foreach (['/', '/shop.php', '/combos.php', '/kitchen-runs.php', '/contact.php', '/our-story', '/privacy', '/product.php?slug=tomato', '/combo.php?slug=stew-combo'] as $path) {
    okv_test_ok(in_array($path, $paths, true), "sitemap composition includes $path");
}
foreach (['https://attacker.example/page', '/product.php?slug=bad%2Fslug', '/combo.php?slug=bad%3Fcombo'] as $path) {
    okv_test_ok(!in_array($path, $paths, true), "sitemap composition rejects $path");
}

$xml = Sitemap::xml($entries, 'https://okveggies.com.ng');
okv_test_ok(str_starts_with($xml, '<?xml version="1.0" encoding="UTF-8"?>'), 'sitemap emits an XML declaration');
okv_test_ok(str_contains($xml, '<loc>https://okveggies.com.ng/product.php?slug=tomato</loc>'), 'sitemap emits the existing canonical product URL');
okv_test_ok(str_contains($xml, '<lastmod>2026-09-15</lastmod>'), 'sitemap emits only the reliable date portion');
okv_test_ok(!str_contains($xml, 'page.php?slug=') && !str_contains($xml, 'admin/content-preview'), 'sitemap never emits legacy or preview URLs');

okv_test_ok(str_contains($route, 'Sitemap::entries()') && str_contains($route, 'Sitemap::xml('), 'sitemap endpoint delegates loading and XML composition');
okv_test_ok(str_contains($route, "http_response_code(503)") && str_contains($route, "header('Retry-After: 300')"), 'sitemap database failure is a retryable 503');
okv_test_ok(str_contains($route, "header('X-Robots-Tag: noindex, nofollow')"), 'sitemap failure is not indexed');
okv_test_ok(str_contains($apache, 'RewriteRule ^sitemap\\.xml$ sitemap.php [END]'), 'Apache maps the public XML path to its endpoint');
okv_test_ok(str_contains($robots, 'Sitemap: https://okveggies.com.ng/sitemap.xml'), 'robots.txt advertises the canonical sitemap URL');
okv_test_ok(str_contains($footer, 'ContentPages::publishedNavigation') && str_contains($footer, "['path']"), 'footer resolves published paths from ContentPages');
okv_test_ok(!str_contains($nav, "'href' => '/our-story'"), 'navigation config carries no duplicate managed-page path');

// The auth, transactional and backend surfaces stay out of the index: robots.txt
// disallows the token and utility endpoints, and the pages a crawler must still
// fetch (sign in, register, password reset, basket, checkout) carry noindex.
foreach (['/public/auth/', '/public/documents/', '/public/payment/', '/public/order.php', '/public/issue_photo.php', '/public/kitchen_run_attachment.php', '/public/cron.php', '/public/healthcheck.php', '/public/migrate.php', '/public/setup.php'] as $backend) {
    okv_test_ok(str_contains($robots, 'Disallow: ' . $backend), "robots.txt keeps crawlers out of $backend");
}
foreach (['account.php', 'cart.php', 'checkout.php'] as $noindexed) {
    $source = (string) file_get_contents($root . '/' . $noindexed);
    okv_test_ok(str_contains($source, '<meta name="robots" content="noindex">'), "$noindexed carries noindex so the auth and transactional pages stay out of the index");
}

<?php
/**
 * scripts/tests/BrandAssetsTest.php
 * The brand foundation must ship whole: the logo and favicon set on disk, the
 * head partial emitting them, the fonts self-hosted in the built stylesheet,
 * and no em dash in the copy this partial or the chrome carries. If any of this
 * regresses the site goes back to looking unbranded, so it is a test, not a hope.
 */

$root = dirname(__DIR__, 2);

// 1. Every derived brand asset exists on disk.
$assets = [
    'favicon.ico',
    'site.webmanifest',
    'assets/img/brand/monogram.svg',
    'assets/img/brand/monogram-white.svg',
    'assets/img/brand/lockup.svg',
    'assets/img/brand/lockup-white.svg',
    'assets/img/brand/lockup-mono-green.svg',
    'assets/img/brand/monogram-mono-green.svg',
    'assets/img/brand/lockup-white-720.png',
    'assets/img/brand/wordmark.svg',
    'assets/img/brand/seal-640.png',
    'assets/img/brand/seal-320.png',
    'assets/img/brand/og-image.png',
    'assets/img/brand/icons/favicon.svg',
    'assets/img/brand/icons/favicon-16.png',
    'assets/img/brand/icons/favicon-32.png',
    'assets/img/brand/icons/apple-touch-icon.png',
    'assets/img/brand/icons/icon-192.png',
    'assets/img/brand/icons/icon-512.png',
    'assets/img/brand/icons/icon-maskable-512.png',
    'assets/fonts/hanken-grotesk-latin.woff2',
    'assets/fonts/hanken-grotesk-latin-ext.woff2',
    'assets/fonts/dm-serif-display-latin.woff2',
    'assets/fonts/dm-serif-display-italic-latin.woff2',
    'assets/fonts/jetbrains-mono-latin.woff2',
];
foreach ($assets as $rel) {
    okv_test_ok(is_file("$root/$rel") && filesize("$root/$rel") > 0, "brand asset present and non-empty: $rel");
}

// 2. The web manifest is valid JSON and on the forest theme colour.
$manifest = json_decode((string) file_get_contents("$root/site.webmanifest"), true);
okv_test_ok(is_array($manifest), 'site.webmanifest is valid JSON');
okv_test_eq('#0F5132', $manifest['theme_color'] ?? null, 'manifest theme colour is Forest Green');
okv_test_ok(!empty($manifest['icons']) && is_array($manifest['icons']), 'manifest declares icons');

// 3. okv_head_meta() emits the brand chrome.
if (!defined('APP_URL')) {
    define('APP_URL', 'https://okveggies.test');
}
require_once $root . '/includes/functions/assets.php';
require_once $root . '/includes/components/head_meta.php';
okv_test_ok(function_exists('okv_head_meta'), 'okv_head_meta() is defined');

ob_start();
okv_head_meta();
$head = (string) ob_get_clean();
okv_test_ok(str_contains($head, 'theme-color') && str_contains($head, '#0F5132'), 'head emits the forest theme colour');
okv_test_ok(str_contains($head, '/favicon.ico'), 'head links the favicon');
okv_test_ok(str_contains($head, 'apple-touch-icon'), 'head links the apple touch icon');
okv_test_ok(str_contains($head, 'site.webmanifest'), 'head links the web manifest');
okv_test_ok(str_contains($head, 'og:image') && str_contains($head, 'og-image.png'), 'head sets a default social image');
okv_test_ok(str_contains($head, 'preload') && str_contains($head, 'hanken-grotesk-latin.woff2'), 'head preloads the brand body font');

// A page can override the social image; the partial must respect it.
ob_start();
okv_head_meta(['og_image' => 'https://okveggies.test/assets/img/products/tomato.jpg']);
$custom = (string) ob_get_clean();
okv_test_ok(str_contains($custom, 'products/tomato.jpg'), 'head uses a page-supplied og:image');

// 4. The built stylesheet self-hosts the three brand faces and dropped the placeholder.
$css = (string) file_get_contents("$root/assets/css/tailwind.css");
okv_test_ok(str_contains($css, 'hanken-grotesk-latin.woff2'), 'tailwind.css self-hosts Hanken Grotesk');
okv_test_ok(str_contains($css, 'DM Serif Display'), 'tailwind.css carries DM Serif Display');
okv_test_ok(str_contains($css, 'jetbrains-mono-latin.woff2'), 'tailwind.css self-hosts JetBrains Mono');
okv_test_ok(str_contains($css, 'font-editorial'), 'tailwind.css exposes the .font-editorial utility');
okv_test_ok(!str_contains($css, 'Plus Jakarta'), 'tailwind.css dropped the Plus Jakarta placeholder');

// 5. The head partial and the storefront chrome carry no em dash.
$emDash = "\xe2\x80\x94";
foreach ([
    'includes/components/head_meta.php',
    'includes/components/shop/header.php',
    'includes/components/shop/footer.php',
    'includes/components/admin/sidebar.php',
] as $rel) {
    okv_test_ok(!str_contains((string) file_get_contents("$root/$rel"), $emDash), "no em dash in $rel");
}

// 6. The single-ink set (bible 3.7a) really is single ink.
// One colour, line and fill only, no gradients and no photography: it has to
// survive a mono laser printer and a fax-quality scan of an invoice.
foreach (['assets/img/brand/lockup-mono-green.svg', 'assets/img/brand/monogram-mono-green.svg'] as $rel) {
    $mark = (string) file_get_contents("$root/$rel");
    okv_test_ok(str_contains($mark, '#0F5132'), "$rel is drawn in Forest Green");
    okv_test_ok(!str_contains($mark, '#C9922B'), "$rel carries no gold, so it is one ink");
    okv_test_ok(!str_contains($mark, '#C8321E'), "$rel carries no tomato, so it is one ink");
    okv_test_ok(!str_contains($mark, '#3E8B4A'), "$rel carries no foliage, so it is one ink");
    okv_test_ok(!str_contains($mark, 'data:image'), "$rel embeds no photography, per bible 3.8");
    okv_test_ok(!str_contains($mark, 'Gradient'), "$rel has no gradient");
}

// 7. Brand::* and tailwind.config.js are the same values.
// The tokens reach an email as inline styles, which no stylesheet can supply.
// If these two ever drift, an email quietly stops being on brand, so the check
// is here rather than in a reviewer's memory.
require_once $root . '/includes/classes/Brand.php';
$config = (string) file_get_contents("$root/tailwind.config.js");
$tokens = [
    'FOREST'       => Brand::FOREST,
    'FOREST_HOVER' => Brand::FOREST_HOVER,
    'FOREST_TINT'  => Brand::FOREST_TINT,
    'GOLD'         => Brand::GOLD,
    'GOLD_TINT'    => Brand::GOLD_TINT,
    'GOLD_INK'     => Brand::GOLD_INK,
    'TOMATO'       => Brand::TOMATO,
    'TOMATO_TINT'  => Brand::TOMATO_TINT,
    'FOLIAGE'      => Brand::FOLIAGE,
    'FOLIAGE_TINT' => Brand::FOLIAGE_TINT,
    'INK'          => Brand::INK,
    'MIST'         => Brand::MIST,
];
foreach ($tokens as $name => $hex) {
    okv_test_ok(str_contains($config, $hex), "Brand::$name ($hex) is a token in tailwind.config.js");
}
okv_test_ok(str_contains($config, 'rgba(3,16,10,0.62)'), 'ink at 62% is still the token Brand::INK_MUTED was flattened from');
okv_test_eq(Brand::INK_MUTED, Brand::flatten(3, 16, 10, 0.62), 'Brand::INK_MUTED is the computed flatten of ink 62% over white, not a colour someone picked');
okv_test_eq('#FFFFFF', Brand::flatten(255, 255, 255, 1.0), 'flatten returns the colour itself at full opacity');
okv_test_eq('#FFFFFF', Brand::flatten(3, 16, 10, 0.0), 'flatten returns the ground at zero opacity');
okv_test_eq('#0F5132', Brand::flatten(15, 81, 50, 1.0, '#000000'), 'flatten works over any ground');

// 8. The back office chrome carries no em dash either.
foreach ([
    'includes/components/admin/header.php',
    'includes/components/admin/placeholder.php',
    'includes/components/pro/header.php',
    'includes/components/pro/nav.php',
    'includes/components/pro/placeholder.php',
    'includes/components/documents/document.php',
    'includes/classes/Mail.php',
    'includes/classes/Brand.php',
] as $rel) {
    okv_test_ok(!str_contains((string) file_get_contents("$root/$rel"), $emDash), "no em dash in $rel");
}

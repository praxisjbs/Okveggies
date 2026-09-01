<?php
/**
 * scripts/tests/StorefrontBrandTest.php
 * The storefront brand application (PR2). Three things are worth pinning:
 * the sourcing trust line, which is one sentence repeated on four surfaces and
 * must never be worded two ways; the seal component, which has a hard 120px
 * floor and a decorative mode; and the storefront markup itself, which carries
 * the fixes this PR made and would be easy to undo by accident.
 */

$root = dirname(__DIR__, 2);
if (!defined('APP_URL')) {
    define('APP_URL', 'https://okveggies.test');
}
require_once $root . '/includes/functions/assets.php';
require_once $root . '/includes/functions/helpers.php';
require_once $root . '/includes/components/shop/brand.php';

// ---------------------------------------------------------------------------
// 1. The sourcing line (bible 6.3).
// ---------------------------------------------------------------------------
okv_test_eq(
    'Sourced Tuesday from Ogun State, Jos',
    okv_sourced_line('Ogun State, Jos', 'Tuesday'),
    'sourcing line names the day and the regions'
);
okv_test_eq(
    'Sourced this week from Ogun State, Jos',
    okv_sourced_line('Ogun State, Jos'),
    'a site with no source_day set still reads as a whole sentence'
);
okv_test_eq(
    'Sourced this week from Ogun State, Jos',
    okv_sourced_line('Ogun State, Jos', '   '),
    'a whitespace-only day is treated as no day, not as a gap in the sentence'
);
okv_test_eq(
    'Sourced Tuesday from Ogun State',
    okv_sourced_line('  Ogun State  ', '  tuesday  '),
    'both halves are trimmed and the day is capitalised'
);
okv_test_eq(
    'Sourced Tuesday and Friday from Ogun State',
    okv_sourced_line('Ogun State', 'Tuesday and Friday'),
    'a two-day sourcing week is passed through as the admin wrote it'
);
okv_test_eq('', okv_sourced_line('', 'Tuesday'), 'no regions means no line, never a farm we cannot name');
okv_test_eq('', okv_sourced_line('   ', 'Tuesday'), 'whitespace-only regions means no line');

// The rendered note carries the sentence, and nothing at all without regions.
ob_start();
okv_sourced_note('Ogun State, Jos', 'Tuesday');
$note = (string) ob_get_clean();
okv_test_ok(str_contains($note, 'Sourced Tuesday from Ogun State, Jos'), 'the note renders the sourcing sentence');
okv_test_ok(str_contains($note, 'okv-trust-line'), 'the note uses the shared trust-line component class');
okv_test_ok(str_contains($note, 'aria-hidden="true"'), 'the leaf marker is decorative, so the words carry the meaning');

ob_start();
okv_sourced_note('Ogun State, Jos', 'Tuesday', 'mt-4 text-white/75');
$toned = (string) ob_get_clean();
okv_test_ok(str_contains($toned, 'mt-4 text-white/75'), 'the note takes a caller class, so it works on a forest ground');

ob_start();
okv_sourced_note('', 'Tuesday');
okv_test_eq('', trim((string) ob_get_clean()), 'the note renders nothing when the regions setting is blank');

// An apostrophe or a quote in a setting must not break out of the markup.
ob_start();
okv_sourced_note('O"Brien\'s Farm, Ogun', 'Tuesday');
$escaped = (string) ob_get_clean();
okv_test_ok(!str_contains($escaped, 'O"Brien'), 'the regions setting is escaped on the way into the page');
okv_test_ok(str_contains($escaped, '&quot;') || str_contains($escaped, '&#039;'), 'the escaped sourcing line keeps the punctuation');

// ---------------------------------------------------------------------------
// 2. The seal component. CLAUDE.md reserves the photographic seal for 120px
//    and up; below that the lockup is the mark, so a smaller ask is lifted.
// ---------------------------------------------------------------------------
ob_start();
okv_seal(120, 'mx-auto', 'The OK Veggies seal');
$seal = (string) ob_get_clean();
okv_test_ok(str_contains($seal, 'seal-320.png'), 'a 120px seal is served from the 320px source, so it stays crisp at 2x');
okv_test_ok(str_contains($seal, 'width="120" height="120"'), 'the seal renders at the size asked for');
okv_test_ok(str_contains($seal, 'alt="The OK Veggies seal"'), 'the seal carries its alt text');
okv_test_ok(!str_contains($seal, 'aria-hidden'), 'a seal with alt text is not hidden from a screen reader');

ob_start();
okv_seal(40, '', 'Seal');
$tiny = (string) ob_get_clean();
okv_test_ok(str_contains($tiny, 'width="120" height="120"'), 'a request under 120px is lifted to the brand minimum');

ob_start();
okv_seal(240, '', '');
$big = (string) ob_get_clean();
okv_test_ok(str_contains($big, 'seal-640.png'), 'a large seal is served from the 640px source');
okv_test_ok(str_contains($big, 'alt=""') && str_contains($big, 'aria-hidden="true"'), 'an empty alt marks the seal decorative and hides it from assistive technology');

// ---------------------------------------------------------------------------
// 3. The storefront markup this PR fixed and applied.
// ---------------------------------------------------------------------------
$read = static fn (string $rel): string => (string) file_get_contents($root . '/' . $rel);

$index = $read('index.php');
okv_test_ok(str_contains($index, 'okv-btn-outline-invert'), 'the home hero uses the on-forest outline button');
okv_test_ok(
    !preg_match('/okv-btn-outline(?!-invert)[-\w]*[^"]*text-white/', $index),
    'the home hero no longer paints white text on the outline button\'s white fill'
);
okv_test_ok(str_contains($index, 'okv_seal(120'), 'the home hero carries the seal at the brand minimum size');

$footer = $read('includes/components/shop/footer.php');
okv_test_ok(str_contains($footer, 'lockup-white.svg'), 'the footer carries the white lockup, not a text wordmark');
okv_test_ok(str_contains($footer, 'bg-forest'), 'the footer sits on the forest ground');
okv_test_ok(str_contains($footer, 'okv_seal('), 'the footer closes on the seal');
okv_test_ok(!str_contains($footer, 'hover:text-gold'), 'a footer link never turns gold: gold fails contrast on forest at body size');

$comboCard = $read('includes/components/shop/combo_card.php');
$comboSpread = $read('includes/components/shop/combo_spread.php');
okv_test_ok(str_contains($comboCard, 'font-editorial'), 'a combo name on a card is set in DM Serif Display');
okv_test_ok(str_contains($comboSpread, 'font-editorial'), 'a combo name on a spread is set in DM Serif Display');
okv_test_ok(str_contains($comboSpread, 'data-add-form'), 'the spread carries the one-tap add, not just a link');

$productCard = $read('includes/components/shop/product_card.php');
okv_test_ok(str_contains($productCard, 'okv_sourced_note'), 'the product card carries the sourcing line');
okv_test_ok(str_contains($productCard, 'sourced from'), 'the product card alt text follows the [product], [unit], sourced from [state] pattern');

$catalogueJs = $read('assets/js/catalogue.js');
okv_test_ok(str_contains($catalogueJs, 'skeletonGrid'), 'the live search shows a skeleton while results are on the way');
okv_test_ok(str_contains($catalogueJs, 'okv-skeleton'), 'the skeleton is built from the shared skeleton class');
okv_test_ok(substr_count($catalogueJs, 'animate-okv-pop') >= 2, 'Market Bounce fires on the add button and the basket counter');

// The gold-on-white wordmark line failed contrast at 2.75:1. It is gone from
// every storefront screen this PR covers.
foreach ([
    'account.php',
    'public/auth/activate.php',
    'public/auth/password_reset.php',
] as $rel) {
    okv_test_ok(!preg_match('/\btext-gold\b/', $read($rel)), "no bare gold text on white in $rel");
    okv_test_ok(str_contains($read($rel), 'okv_seal(') || str_contains($read($rel), 'lockup'), "$rel carries a real brand mark");
}

// ---------------------------------------------------------------------------
// 4. The stylesheet and the migration that back all of the above.
// ---------------------------------------------------------------------------
$css = $read('assets/css/tailwind.css');
okv_test_ok(str_contains($css, 'okv-btn-outline-invert'), 'the built stylesheet carries the on-forest outline button');
okv_test_ok(str_contains($css, 'okv-trust-line'), 'the built stylesheet carries the trust-line component');
okv_test_ok(str_contains($css, 'okv-eyebrow'), 'the built stylesheet carries the eyebrow component');
okv_test_ok(str_contains($css, '3.8125rem'), 'the built stylesheet carries the bible type scale (61px h2 step)');

$migration = $read('migrations/009_source_day_setting.sql');
okv_test_ok(str_contains($migration, "'source_day'"), 'the migration seeds the source_day setting');
okv_test_ok(str_contains($migration, 'ON DUPLICATE KEY UPDATE'), 'the migration is idempotent');
okv_test_ok(
    !str_contains($migration, 'ON DUPLICATE KEY UPDATE setting_value'),
    'a re-run never overwrites a sourcing day the admin has since changed'
);
okv_test_ok(str_contains($migration, 'START TRANSACTION') && str_contains($migration, 'COMMIT'), 'the migration runs in a transaction');

// ---------------------------------------------------------------------------
// 5. No em dash in anything this PR touched.
// ---------------------------------------------------------------------------
$emDash = "\xe2\x80\x94";
foreach ([
    'index.php',
    'shop.php',
    'product.php',
    'combos.php',
    'combo.php',
    'account.php',
    'public/auth/activate.php',
    'public/auth/password_reset.php',
    'includes/components/shop/brand.php',
    'includes/components/shop/footer.php',
    'includes/components/shop/combo_card.php',
    'includes/components/shop/combo_spread.php',
    'includes/components/shop/product_card.php',
    'includes/components/shop/shop_results.php',
    'migrations/009_source_day_setting.sql',
    'assets/js/catalogue.js',
] as $rel) {
    okv_test_ok(!str_contains($read($rel), $emDash), "no em dash in $rel");
}

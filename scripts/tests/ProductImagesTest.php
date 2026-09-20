<?php
/** Catalogue photographs: JPEG in the database, WebP siblings on disk. */

$root = dirname(__DIR__, 2);
$source = (string) file_get_contents($root . '/includes/classes/ProductImages.php');
$picture = (string) file_get_contents($root . '/includes/components/shop/picture.php');
$script = (string) file_get_contents($root . '/scripts/brand/optimise_catalogue_images.sh');

okv_test_ok(str_contains($source, 'public const WIDTHS = [400, 800, 1200]'), 'catalogue WebP widths are 400, 800 and 1200');
okv_test_ok(str_contains($script, '400 800 1200') && str_contains($script, '.webp'), 'the optimiser writes the three WebP widths');
okv_test_ok(str_contains($picture, 'fetchpriority="high"') && str_contains($picture, 'loading="lazy"'), 'picture marks a hero high-priority and lazy-loads the rest');
okv_test_ok(str_contains($picture, 'decoding="async"'), 'every catalogue photograph decodes asynchronously');

$tomato = ProductImages::presentation('/assets/img/product_images/Fresh Tomatoes.jpeg');
okv_test_eq('/assets/img/product_images/Fresh Tomatoes.jpeg', $tomato['src'], 'presentation keeps the JPEG path the database stores');
okv_test_ok($tomato['width'] >= 1 && $tomato['height'] >= 1, 'presentation reports explicit width and height');
if (is_file($root . '/assets/img/product_images/Fresh Tomatoes-400.webp')) {
    okv_test_eq('image/webp', $tomato['type'], 'WebP siblings are advertised when they exist on disk');
    okv_test_ok(str_contains($tomato['srcset'], '400w') && str_contains($tomato['srcset'], '800w') && str_contains($tomato['srcset'], '1200w'),
        'srcset lists the 400, 800 and 1200 candidates');
} else {
    okv_test_eq('', $tomato['srcset'], 'a catalogue JPEG without siblings falls back to the JPEG alone');
}

$missing = ProductImages::presentation('/assets/img/product_images/Does-Not-Exist.jpeg');
okv_test_eq(800, $missing['width'], 'a missing file still reserves an 800px box');
okv_test_eq(800, $missing['height'], 'the missing-file fallback is square, so the layout does not jump');
okv_test_eq('', $missing['srcset'], 'a missing catalogue file does not invent WebP candidates');

$upload = ProductImages::presentation('/uploads/combos/example.jpg');
okv_test_eq('', $upload['srcset'], 'combo uploads are never treated as the launch catalogue');
okv_test_eq('', $upload['type'], 'combo uploads do not claim a WebP type');

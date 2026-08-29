<?php
/** Catalogue input, presentation and reference-seed checks. */

okv_test_eq('fresh red pepper', Catalogue::cleanSearch("  fresh   red\npepper  "), 'search collapses whitespace');
okv_test_eq(80, mb_strlen(Catalogue::cleanSearch(str_repeat('a', 100))), 'search is limited to 80 characters');
okv_test_eq('herbs-spices', Catalogue::cleanCategory(' Herbs-Spices '), 'category slug is normalised');
okv_test_eq('', Catalogue::cleanCategory('../admin'), 'unsafe category slug is rejected');

okv_test_eq('0.5', okv_quantity('0.500'), 'decimal quantity drops trailing zeroes');
okv_test_eq('1', okv_quantity('1.000'), 'whole quantity drops decimal point');
okv_test_eq(
    ['key' => 'available', 'label' => 'Available', 'short_label' => 'Available', 'note' => '', 'can_add' => true],
    okv_availability('available'),
    'available product can be added'
);
okv_test_eq(
    ['key' => 'out', 'label' => 'Out of stock', 'short_label' => 'Out of stock', 'note' => '', 'can_add' => false],
    okv_availability('out_of_stock'),
    'out of stock product cannot be added'
);
okv_test_eq(
    [
        'key' => 'restocking',
        'label' => 'Restocking, back on Monday 31st August',
        'short_label' => 'Restocking',
        'note' => 'Back on Monday 31st August',
        'can_add' => false,
    ],
    okv_availability('restocking', '2026-08-31'),
    'restock date is shown plainly'
);
// The card badge stays short. A long badge used to push the two-up mobile grid
// past the viewport, so the date lives in the note instead.
okv_test_eq('Restocking', okv_availability('restocking', '2026-08-31')['short_label'], 'card badge stays short whatever the restock date');

// A search term is data, not a LIKE pattern. Before this, "%" matched the whole
// catalogue and "t%o" matched most of it.
okv_test_eq('\\%', Catalogue::escapeLike('%'), 'a per-cent sign is escaped, not a wildcard');
okv_test_eq('\\_', Catalogue::escapeLike('_'), 'an underscore is escaped, not a wildcard');
okv_test_eq('\\\\', Catalogue::escapeLike('\\'), 'a backslash is escaped first');
okv_test_eq('t\\%o', Catalogue::escapeLike('t%o'), 'a wildcard inside a word is escaped');
okv_test_eq('tomato', Catalogue::escapeLike('tomato'), 'an ordinary term is left alone');

okv_test_eq('garlic', Catalogue::cleanSlug(' Garlic '), 'a product slug is normalised');
okv_test_eq('', Catalogue::cleanSlug('../../etc/passwd'), 'a traversal attempt is not a slug');

// A redirect target that came from the request may only ever be a path on this
// site. A browser folds a backslash into a forward slash, so "/\evil.example"
// resolves to another host and has to be refused with "//evil.example".
okv_test_eq('/shop.php', okv_safe_path('/shop.php', '/shop.php'), 'a same-site path is kept');
okv_test_eq('/shop.php?search=fresh%20thyme', okv_safe_path('/shop.php?search=fresh%20thyme', '/'), 'an encoded space in a search term is kept');
okv_test_eq('/shop.php', okv_safe_path('//evil.example', '/shop.php'), 'a protocol-relative target is refused');
okv_test_eq('/shop.php', okv_safe_path('/\\evil.example', '/shop.php'), 'a backslash host is refused');
okv_test_eq('/shop.php', okv_safe_path('/\\/evil.example', '/shop.php'), 'a backslash-slash host is refused');
okv_test_eq('/shop.php', okv_safe_path('https://evil.example', '/shop.php'), 'an absolute URL is refused');
okv_test_eq('/shop.php', okv_safe_path('/%09/evil.example', '/shop.php'), 'an encoded control character is refused');
okv_test_eq('/shop.php', okv_safe_path('/%5Cevil.example', '/shop.php'), 'an encoded backslash is refused');
okv_test_eq('/shop.php', okv_safe_path("/shop.php\nSet-Cookie: x=1", '/shop.php'), 'a header injection attempt is refused');
okv_test_eq('/shop.php', okv_safe_path('shop.php', '/shop.php'), 'a relative target is refused');
okv_test_eq('/shop.php', okv_safe_path('', '/shop.php'), 'an empty target falls back');

$referenceSeed = file_get_contents($appRoot . '/migrations/003_reference_seed.sql');
$productSeed = file_get_contents($appRoot . '/migrations/004_product_seed.sql');
okv_test_ok($referenceSeed !== false, 'reference seed can be read');
okv_test_ok($productSeed !== false, 'product seed can be read');

preg_match('/INSERT INTO product_categories.*?VALUES(.*?)ON DUPLICATE KEY UPDATE/s', (string) $referenceSeed, $categoryBlock);
preg_match_all('/\(\d+,\s*\'[^\']+\',\s*\'[^\']+\'/', $categoryBlock[1] ?? '', $categoryRows);
okv_test_eq(5, count($categoryRows[0] ?? []), '5 product categories are seeded');

preg_match('/INSERT INTO units_of_measurement.*?VALUES(.*?)ON DUPLICATE KEY UPDATE/s', (string) $referenceSeed, $unitBlock);
preg_match_all('/\(\d+,\s*\'[^\']+\',\s*\'[^\']+\'/', $unitBlock[1] ?? '', $unitRows);
okv_test_eq(4, count($unitRows[0] ?? []), '4 units are seeded');

preg_match('/INSERT INTO products.*?VALUES(.*?)ON DUPLICATE KEY UPDATE/s', (string) $productSeed, $productBlock);
preg_match_all('/^\s*\(\d+,\s*\d+,\s*\d+,\s*\'/m', $productBlock[1] ?? '', $productRows);
okv_test_eq(24, count($productRows[0] ?? []), '24 products are seeded');
okv_test_ok((bool) preg_match('/\(5,\s*3,\s*1,\s*\'Garlic\'/', $productBlock[1] ?? ''), 'Garlic uses unit 1, kg');

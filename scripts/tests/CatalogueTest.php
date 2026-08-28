<?php
/** Catalogue input, presentation and reference-seed checks. */

okv_test_eq('fresh red pepper', Catalogue::cleanSearch("  fresh   red\npepper  "), 'search collapses whitespace');
okv_test_eq(80, mb_strlen(Catalogue::cleanSearch(str_repeat('a', 100))), 'search is limited to 80 characters');
okv_test_eq('herbs-spices', Catalogue::cleanCategory(' Herbs-Spices '), 'category slug is normalised');
okv_test_eq('', Catalogue::cleanCategory('../admin'), 'unsafe category slug is rejected');

okv_test_eq('0.5', okv_quantity('0.500'), 'decimal quantity drops trailing zeroes');
okv_test_eq('1', okv_quantity('1.000'), 'whole quantity drops decimal point');
okv_test_eq(['key' => 'available', 'label' => 'Available', 'can_add' => true], okv_availability('available'), 'available product can be added');
okv_test_eq(['key' => 'out', 'label' => 'Out of stock', 'can_add' => false], okv_availability('out_of_stock'), 'out of stock product cannot be added');
okv_test_eq(['key' => 'restocking', 'label' => 'Restocking for Mon 31 Aug', 'can_add' => false], okv_availability('restocking', '2026-08-31'), 'restock date is shown plainly');

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

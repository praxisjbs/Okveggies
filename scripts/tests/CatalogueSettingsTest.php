<?php
/**
 * scripts/tests/CatalogueSettingsTest.php
 *
 * Unit tests for CatalogueSettings domain methods: validation of category and
 * unit input, safety constraints, and component contract.
 */

okv_test_eq(120, CatalogueSettings::CATEGORY_NAME_MAX, 'category name max length is 120');
okv_test_eq(2000, CatalogueSettings::CATEGORY_DESC_MAX, 'category description max length is 2000');
okv_test_eq(80, CatalogueSettings::UNIT_NAME_MAX, 'unit name max length is 80');
okv_test_eq(20, CatalogueSettings::UNIT_SYMBOL_MAX, 'unit symbol max length is 20');

// Category validation: empty name
[$clean, $errors] = CatalogueSettings::validateCategory(['name' => '   ', 'description' => 'Test']);
okv_test_ok(isset($errors['name']), 'empty category name is rejected');

// Category validation: long name
[$clean, $errors] = CatalogueSettings::validateCategory(['name' => str_repeat('a', 121)]);
okv_test_ok(isset($errors['name']), 'category name over 120 characters is rejected');

// Category validation: valid creation generates slug
[$clean, $errors] = CatalogueSettings::validateCategory([
    'name'        => 'Fresh Greens & Salads',
    'description' => 'Crisp leafy greens.',
    'is_active'   => '1',
]);
okv_test_eq([], $errors, 'valid category input has no validation errors');
okv_test_eq('Fresh Greens & Salads', $clean['name'], 'category name is trimmed');
okv_test_eq('fresh-greens-salads', $clean['slug'], 'category slug is generated from name');
okv_test_eq('Crisp leafy greens.', $clean['description'], 'category description is saved');
okv_test_eq(1, $clean['is_active'], 'category active flag is parsed as integer 1');

// Category validation: inactive conversion
[$clean, $errors] = CatalogueSettings::validateCategory([
    'name'      => 'Seasonal Berries',
    'is_active' => '0',
]);
okv_test_eq(0, $clean['is_active'], 'is_active 0 is parsed as 0');

// Unit validation: empty name
[$clean, $errors] = CatalogueSettings::validateUnit(['name' => '', 'symbol' => 'kg']);
okv_test_ok(isset($errors['name']), 'empty unit name is rejected');

// Unit validation: empty symbol
[$clean, $errors] = CatalogueSettings::validateUnit(['name' => 'Crate', 'symbol' => '']);
okv_test_ok(isset($errors['symbol']), 'empty unit symbol is rejected');

// Unit validation: long name and symbol
[$clean, $errors] = CatalogueSettings::validateUnit([
    'name'   => str_repeat('b', 85),
    'symbol' => str_repeat('c', 25),
]);
okv_test_ok(isset($errors['name']), 'unit name over 80 characters is rejected');
okv_test_ok(isset($errors['symbol']), 'unit symbol over 20 characters is rejected');

// Unit validation: valid unit creation
[$clean, $errors] = CatalogueSettings::validateUnit([
    'name'           => 'Crate',
    'symbol'         => 'crate',
    'allows_decimal' => '0',
    'is_active'      => '1',
]);
okv_test_eq([], $errors, 'valid unit input has no validation errors');
okv_test_eq('Crate', $clean['name'], 'unit name is recorded');
okv_test_eq('crate', $clean['symbol'], 'unit symbol is recorded');
okv_test_eq(0, $clean['allows_decimal'], 'allows_decimal is converted to 0');
okv_test_eq(1, $clean['is_active'], 'is_active is converted to 1');

// Unit validation: decimal allowed parsed
[$clean, $errors] = CatalogueSettings::validateUnit([
    'name'           => 'Litre',
    'symbol'         => 'L',
    'allows_decimal' => '1',
]);
okv_test_eq(1, $clean['allows_decimal'], 'allows_decimal is converted to 1');

// Contract verification on Products page
$productsPage = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/products.php');
okv_test_ok(str_contains($productsPage, 'data-catalogue-settings-open'), 'admin/products.php includes settings button with data-catalogue-settings-open');
okv_test_ok(str_contains($productsPage, 'Settings</span>'), 'admin/products.php settings button displays Settings label');
okv_test_ok(str_contains($productsPage, 'catalogue_settings_modal.php'), 'admin/products.php loads catalogue_settings_modal.php');

// Contract verification on Modal component
$modalComponent = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/components/admin/catalogue_settings_modal.php');
okv_test_ok(str_contains($modalComponent, 'data-catalogue-settings-modal'), 'modal has data-catalogue-settings-modal attribute');
okv_test_ok(str_contains($modalComponent, 'role="dialog"'), 'modal has role="dialog"');
okv_test_ok(str_contains($modalComponent, 'aria-modal="true"'), 'modal has aria-modal="true"');
okv_test_ok(str_contains($modalComponent, 'data-tab-btn="categories"'), 'modal has categories tab button');
okv_test_ok(str_contains($modalComponent, 'data-tab-btn="units"'), 'modal has units tab button');
okv_test_ok(str_contains($modalComponent, 'data-category-add-form'), 'modal has category add form');
okv_test_ok(str_contains($modalComponent, 'data-unit-add-form'), 'modal has unit add form');

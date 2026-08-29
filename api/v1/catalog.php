<?php
/** Public catalogue reads for progressive storefront enhancement. */
require_once __DIR__ . '/../../includes/bootstrap.php';

$action = okv_action();

switch ($action) {
    case 'categories':
        okv_json(['status' => 'ok', 'categories' => Catalogue::categories()]);

    case 'products':
        $products = Catalogue::products((string) okv_input('search', ''), (string) okv_input('category', ''));
        okv_json(['status' => 'ok', 'products' => $products, 'count' => count($products)]);

    case 'product':
        $product = Catalogue::productBySlug((string) okv_input('slug', ''));
        if (!$product) {
            okv_error('That product was not found.', 404, 'not_found');
        }
        $product['images'] = Catalogue::images((int) $product['id']);
        $product['goes_well_with'] = Catalogue::suggestions((int) $product['id'], (int) $product['category_id']);
        okv_json(['status' => 'ok', 'product' => $product]);

    default:
        okv_error('This action is not available.', 400, 'unknown_action');
}

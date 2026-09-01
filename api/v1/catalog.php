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

    case 'browse': {
        // One page of the shop grid, server-rendered. The live search on
        // shop.php swaps the results column with this markup, so what the
        // customer sees as they type is byte-for-byte what a fresh load of
        // the same URL would render.
        $search = Catalogue::cleanSearch((string) okv_input('search', ''));
        $category = Catalogue::cleanCategory((string) okv_input('category', ''));

        $perPage = Catalogue::PER_PAGE;
        $total = Catalogue::countProducts($search, $category);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, (int) okv_input('page', 1)), $pages);
        $products = Catalogue::products($search, $category, $page, $perPage);

        require_once __DIR__ . '/../../includes/components/pagination.php';
        require_once __DIR__ . '/../../includes/components/shop/product_card.php';
        require_once __DIR__ . '/../../includes/components/shop/shop_results.php';

        ob_start();
        try {
            okv_shop_results(
                $products,
                Catalogue::categories(),
                Settings::str('source_regions', 'Ogun State, Jos'),
                $search,
                $category,
                $page,
                $total,
                $perPage
            );
            $html = (string) ob_get_clean();
        } catch (Throwable $e) {
            ob_end_clean();
            error_log('catalog.browse failed: ' . $e->getMessage());
            okv_error('Something went wrong at our end. Please try again.', 500, 'failed');
        }

        okv_json([
            'status' => 'ok',
            'search' => $search,
            'category' => $category,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
            'total' => $total,
            'summary' => okv_page_summary($page, $total, $perPage, 'item'),
            'html' => $html,
        ]);
    }

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

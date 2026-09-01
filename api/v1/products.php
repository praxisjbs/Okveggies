<?php
/**
 * api/v1/products.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The admin catalogue. This is where the Manager adds a product,
 * edits it, sets what is available this week, manages its photos and takes one
 * off the shop. Built in milestone M2. See docs/PRD.md Section 5.
 *
 * Every action is gated on the server with the products.* permissions. Reads are
 * GET; every change is a POST with a valid CSRF token. Prepared statements only.
 * No exception ever reaches the client.
 *
 * A price is deliberately not editable here in isolation: it moves through
 * Pricing::change() so the history can never be bypassed. See api/v1/pricing.php.
 *
 * Actions:
 *   list              (GET,  products.view)                 the catalogue, filterable
 *   browse            (GET,  products.view)                 one page of the catalogue, server-rendered for the live filter
 *   get               (GET,  products.view)                 one product, its photos and price history
 *   create            (POST, products.create)               add a product
 *   update            (POST, products.edit)                 edit a product
 *   set_active        (POST, products.edit)                 put it on or take it off the shop
 *   set_availability  (POST, products.availability.update)  available, out of stock, restocking
 *   delete            (POST, products.delete)               only when nothing refers to it
 *   add_image         (POST, products.edit)                 upload a photo
 *   set_primary_image (POST, products.edit)                 choose the photo the shop leads with
 *   reorder_images    (POST, products.edit)                 set the gallery order
 *   delete_image      (POST, products.edit)                 remove a photo
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../../includes/bootstrap.php';

$action = okv_action();

/** Guard a write: POST, a valid CSRF token, and the permission. */
function products_guard_write(string $permission): void
{
    if (!okv_is_post()) {
        okv_error('Use POST for this action.', 405, 'method_not_allowed');
    }
    Rbac::requirePermission($permission);
    if (!Csrf::validate()) {
        okv_error('Your session expired. Reload the page and try again.', 419, 'csrf_expired');
    }
}

/** The product id this request names, or stop with a plain answer. */
function products_required_id(): int
{
    $id = (int) okv_input('product_id', 0);
    if ($id < 1) {
        okv_error('Choose a product first.', 422, 'missing_product');
    }
    return $id;
}

/** Turn a failure into a JSON answer without ever leaking the exception. */
function products_fail(Throwable $e, string $context): void
{
    if ($e instanceof DomainException) {
        $known = [
            'not_found'  => ['We could not find that product.', 404],
            'in_use'     => ['That product is in use, so it cannot be removed.', 409],
            'bad_status' => ['That is not an availability we recognise.', 422],
        ];
        [$message, $code] = $known[$e->getMessage()] ?? ['We could not do that.', 400];
        okv_error($message, $code, $e->getMessage());
    }
    error_log('products.' . $context . ' failed: ' . $e->getMessage());
    okv_error('Something went wrong at our end. Please try again.', 500, 'failed');
}

switch ($action) {

    case 'list': {
        Rbac::requirePermission('products.view');
        $rows = Products::all(
            (string) okv_input('search', ''),
            (string) okv_input('category', ''),
            (string) okv_input('status', '')
        );
        okv_json(['status' => 'ok', 'products' => $rows, 'count' => count($rows)]);
    }

    case 'browse': {
        // One page of the catalogue, server-rendered. The live filter on
        // admin/products.php swaps the list with this markup as the Manager
        // types, so typing and a plain reload of the same URL agree exactly.
        Rbac::requirePermission('products.view');

        $search   = (string) okv_input('search', '');
        $category = (string) okv_input('category', '');
        $status   = (string) okv_input('status', '');

        $perPage = Products::PER_PAGE;
        $total   = Products::count($search, $category, $status);
        $pages   = max(1, (int) ceil($total / $perPage));
        $page    = min(max(1, (int) okv_input('page', 1)), $pages);
        $rows    = Products::all($search, $category, $status, $page, $perPage);

        $categories = Database::all('SELECT id, name, slug FROM product_categories WHERE is_active = 1 ORDER BY sort_order, name');
        $units      = Database::all('SELECT id, name, symbol, allows_decimal FROM units_of_measurement WHERE is_active = 1 ORDER BY id');

        require_once __DIR__ . '/../../includes/components/pagination.php';
        require_once __DIR__ . '/../../includes/components/admin/product_list.php';

        ob_start();
        try {
            okv_admin_product_cards(
                $rows,
                $categories,
                $units,
                0,
                Rbac::can('products.edit'),
                Rbac::can('products.delete'),
                Rbac::can('products.availability.update'),
                $search !== '' || $category !== '' || $status !== ''
            );
            okv_pagination($page, $pages, static fn (int $n): string => okv_admin_products_url($search, $category, $status, $n), 'Product pages');
            $html = (string) ob_get_clean();
        } catch (Throwable $e) {
            ob_end_clean();
            products_fail($e, 'browse');
        }

        okv_json([
            'status'  => 'ok',
            'search'  => $search,
            'category' => $category,
            'status_filter' => $status,
            'page'    => $page,
            'pages'   => $pages,
            'per_page' => $perPage,
            'total'   => $total,
            'summary' => okv_page_summary($page, $total, $perPage, 'product'),
            'html'    => $html,
        ]);
    }

    case 'get': {
        Rbac::requirePermission('products.view');
        $id = products_required_id();
        $product = Products::find($id);
        if (!$product) {
            okv_error('We could not find that product.', 404, 'not_found');
        }
        okv_json([
            'status'     => 'ok',
            'product'    => $product,
            'images'     => Products::images($id),
            'history'    => Pricing::history($id, 20),
            'references' => Products::referenceCount($id),
        ]);
    }

    case 'create': {
        products_guard_write('products.create');
        [$clean, $errors] = Products::validate($_POST);
        if ($errors) {
            okv_json(['status' => 'error', 'code' => 'invalid', 'message' => 'Please check the fields marked below.', 'errors' => $errors], 422);
        }
        try {
            $id = Products::create($clean, Rbac::userId());
        } catch (Throwable $e) {
            products_fail($e, 'create');
        }
        okv_json(['status' => 'ok', 'message' => 'Product added.', 'product_id' => $id]);
    }

    case 'update': {
        products_guard_write('products.edit');
        $id = products_required_id();
        [$clean, $errors] = Products::validate($_POST, $id);
        if ($errors) {
            okv_json(['status' => 'error', 'code' => 'invalid', 'message' => 'Please check the fields marked below.', 'errors' => $errors], 422);
        }
        try {
            Products::update($id, $clean, Rbac::userId());
        } catch (Throwable $e) {
            products_fail($e, 'update');
        }
        okv_json(['status' => 'ok', 'message' => 'Product saved.']);
    }

    case 'set_active': {
        products_guard_write('products.edit');
        $id = products_required_id();
        $active = (string) okv_input('is_active', '0') === '1';
        try {
            Products::setActive($id, $active);
        } catch (Throwable $e) {
            products_fail($e, 'set_active');
        }
        okv_json([
            'status'  => 'ok',
            'message' => $active ? 'That product is back on the shop.' : 'That product is off the shop.',
            'is_active' => $active,
        ]);
    }

    case 'set_availability': {
        products_guard_write('products.availability.update');
        $id = products_required_id();
        $status = (string) okv_input('availability_status', '');
        $restock = (string) okv_input('restock_date', '');
        try {
            Products::setAvailability($id, $status, $restock === '' ? null : $restock, Rbac::userId());
        } catch (Throwable $e) {
            products_fail($e, 'set_availability');
        }
        $shown = okv_availability($status, $restock === '' ? null : $restock);
        okv_json(['status' => 'ok', 'message' => 'Availability updated.', 'label' => $shown['label']]);
    }

    case 'delete': {
        products_guard_write('products.delete');
        $id = products_required_id();
        $refs = Products::referenceCount($id);
        if ($refs['total'] > 0) {
            okv_json([
                'status'  => 'error',
                'code'    => 'in_use',
                'message' => Products::inUseMessage($refs),
                'references' => $refs,
            ], 409);
        }
        try {
            Products::delete($id);
        } catch (Throwable $e) {
            products_fail($e, 'delete');
        }
        okv_json(['status' => 'ok', 'message' => 'Product removed.']);
    }

    case 'add_image': {
        products_guard_write('products.edit');
        $id = products_required_id();
        if (!Products::find($id)) {
            okv_error('We could not find that product.', 404, 'not_found');
        }
        if (empty($_FILES['image'])) {
            okv_error('Choose a photo to upload.', 422, 'missing_file');
        }
        try {
            $path = Uploads::saveUploadedFile($_FILES['image'], 'products', ['image/jpeg', 'image/png', 'image/webp']);
            $imageId = Products::addImage($id, $path);
        } catch (RuntimeException $e) {
            // Uploads throws a message written for the person, not a stack trace.
            okv_error($e->getMessage(), 422, 'upload_refused');
        } catch (Throwable $e) {
            products_fail($e, 'add_image');
        }
        okv_json(['status' => 'ok', 'message' => 'Photo added.', 'image_id' => $imageId, 'images' => Products::images($id)]);
    }

    case 'set_primary_image': {
        products_guard_write('products.edit');
        $id = products_required_id();
        try {
            Products::setPrimaryImage($id, (int) okv_input('image_id', 0));
        } catch (Throwable $e) {
            products_fail($e, 'set_primary_image');
        }
        okv_json(['status' => 'ok', 'message' => 'That is now the main photo.', 'images' => Products::images($id)]);
    }

    case 'reorder_images': {
        products_guard_write('products.edit');
        $id = products_required_id();
        $order = okv_input('order', '');
        $ids = is_string($order) ? array_filter(array_map('intval', explode(',', $order))) : [];
        if (!$ids) {
            okv_error('Send the photo order.', 422, 'missing_order');
        }
        try {
            Products::reorderImages($id, $ids);
        } catch (Throwable $e) {
            products_fail($e, 'reorder_images');
        }
        okv_json(['status' => 'ok', 'message' => 'Photo order saved.', 'images' => Products::images($id)]);
    }

    case 'delete_image': {
        products_guard_write('products.edit');
        $id = products_required_id();
        try {
            Products::deleteImage($id, (int) okv_input('image_id', 0));
        } catch (Throwable $e) {
            products_fail($e, 'delete_image');
        }
        okv_json(['status' => 'ok', 'message' => 'Photo removed.', 'images' => Products::images($id)]);
    }

    default:
        okv_error('This action is not available.', 400, 'unknown_action');
}

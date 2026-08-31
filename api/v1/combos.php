<?php
/** OK Veggies. Admin combo builder controller. */
require_once __DIR__ . '/../../includes/bootstrap.php';

$action = okv_action();

function combos_guard_write(string $permission): void
{
    if (!okv_is_post()) {
        okv_error('Use POST for this action.', 405, 'method_not_allowed');
    }
    Rbac::requirePermission($permission);
    if (!Csrf::validate()) {
        okv_error('Your session expired. Reload the page and try again.', 419, 'csrf_expired');
    }
}

function combos_required_id(): int
{
    $id = (int) okv_input('combo_id', 0);
    if ($id < 1) {
        okv_error('Choose a combo first.', 422, 'missing_combo');
    }
    return $id;
}

/** Validate the complete picker without trusting units or prices from the browser. */
function combos_clean_components(array $input): array
{
    $selected = $input['selected_products'] ?? [];
    $quantities = $input['component_quantity'] ?? [];
    if (!is_array($selected) || !is_array($quantities)) {
        return [[], ['components' => 'Choose the products again.']];
    }

    $rows = [];
    $errors = [];
    $seen = [];
    foreach ($selected as $rawId) {
        $productId = (int) $rawId;
        if ($productId < 1 || isset($seen[$productId])) {
            continue;
        }
        $seen[$productId] = true;
        $product = Database::one(
            'SELECT p.id, p.name, p.unit_id, u.allows_decimal
               FROM products p
               JOIN units_of_measurement u ON u.id = p.unit_id
              WHERE p.id = :id',
            [':id' => $productId]
        );
        if (!$product) {
            $errors['components'] = 'One selected product could not be found.';
            continue;
        }
        $quantity = Combos::cleanComponentQuantity($quantities[$productId] ?? '', $product);
        if ($quantity < Combos::MIN_COMPONENT_QUANTITY) {
            $errors['component_quantity_' . $productId] = 'Enter a quantity for ' . $product['name'] . '.';
            continue;
        }
        $rows[] = [
            'product_id' => $productId,
            'unit_id' => (int) $product['unit_id'],
            'quantity' => $quantity,
        ];
    }
    return [$rows, $errors];
}

function combos_fail(Throwable $e, string $context): void
{
    if ($e instanceof DomainException) {
        $known = [
            'not_found' => ['We could not find that combo.', 404],
            'no_components' => ['Choose at least 1 product before publishing.', 422],
            'no_price' => ['Set a sell price before publishing.', 422],
            'component_without_price' => ['Every selected product needs a current price before this combo can be published.', 422],
            'bad_quantity' => ['Check the component quantities.', 422],
            'bad_product' => ['One selected product could not be found.', 422],
            'bad_unit' => ['One selected product has the wrong unit.', 422],
            'already_in_combo' => ['A product was selected more than once.', 422],
            'invalid_price' => ['Enter a valid sell price.', 422],
        ];
        [$message, $status] = $known[$e->getMessage()] ?? ['We could not do that.', 400];
        okv_error($message, $status, $e->getMessage());
    }
    error_log('combos.' . $context . ' failed: ' . $e->getMessage());
    okv_error('Something went wrong at our end. Please try again.', 500, 'failed');
}

switch ($action) {
    case 'list':
        Rbac::requirePermission('combos.view');
        $rows = Combos::all((string) okv_input('search', ''), (string) okv_input('status', ''));
        okv_json(['status' => 'ok', 'combos' => $rows, 'count' => count($rows)]);

    case 'get':
        Rbac::requirePermission('combos.view');
        $id = combos_required_id();
        $combo = Combos::find($id);
        if (!$combo) {
            okv_error('We could not find that combo.', 404, 'not_found');
        }
        $total = Combos::componentTotalDetailed($id);
        okv_json([
            'status' => 'ok',
            'combo' => $combo,
            'components' => $total['components'],
            'component_total_subunit' => $total['total_subunit'],
            'history' => Combos::history($id, 20),
            'references' => Combos::referenceCount($id),
        ]);

    case 'save':
        $id = (int) okv_input('combo_id', 0);
        combos_guard_write($id > 0 ? 'combos.edit' : 'combos.create');
        [$clean, $errors] = Combos::validate($_POST, $id > 0 ? $id : null);
        [$components, $componentErrors] = combos_clean_components($_POST);
        $errors = array_merge($errors, $componentErrors);
        if ($errors) {
            okv_json(['status' => 'error', 'code' => 'invalid', 'message' => 'Please check the fields marked below.', 'errors' => $errors], 422);
        }

        $publish = (int) $clean['is_active'] === 1;
        if ($publish) {
            Rbac::requirePermission('combos.publish');
        }
        $clean['is_active'] = 0;
        $pdo = Database::getInstance()->getConnection();
        try {
            $pdo->beginTransaction();
            if ($id > 0) {
                Combos::update($id, $clean, Rbac::userId());
            } else {
                $id = Combos::create($clean, Rbac::userId());
            }
            Combos::replaceComponents($id, $components);
            if ($publish) {
                Combos::publish($id);
            } else {
                Combos::unpublish($id);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            combos_fail($e, 'save');
        }
        okv_json(['status' => 'ok', 'message' => $publish ? 'Combo saved and put on the shop.' : 'Combo saved as a draft.', 'combo_id' => $id]);

    case 'set_active':
        combos_guard_write('combos.publish');
        $id = combos_required_id();
        $active = (string) okv_input('is_active', '0') === '1';
        try {
            $active ? Combos::publish($id) : Combos::unpublish($id);
        } catch (Throwable $e) {
            combos_fail($e, 'set_active');
        }
        okv_json(['status' => 'ok', 'message' => $active ? 'Combo put on the shop.' : 'Combo taken off the shop.', 'is_active' => $active]);

    case 'upload_photo':
        combos_guard_write('combos.edit');
        $id = combos_required_id();
        $combo = Combos::find($id);
        if (!$combo) {
            okv_error('We could not find that combo.', 404, 'not_found');
        }
        if (empty($_FILES['image'])) {
            okv_error('Choose a photo to upload.', 422, 'missing_file');
        }
        try {
            $path = Uploads::saveUploadedFile($_FILES['image'], 'combos', ['image/jpeg', 'image/png', 'image/webp']);
            [$clean, $errors] = Combos::validate([
                'name' => $combo['name'],
                'sku' => $combo['sku'],
                'description' => $combo['description'],
                'price' => Money::toNaira((int) $combo['price_subunit']),
                'image_url' => $path,
                'is_featured' => $combo['is_featured'],
                'is_active' => $combo['is_active'],
                'available_from' => $combo['available_from'],
                'available_until' => $combo['available_until'],
            ], $id);
            if ($errors) {
                throw new DomainException('invalid_photo_update');
            }
            Combos::update($id, $clean, Rbac::userId());
        } catch (RuntimeException $e) {
            okv_error($e->getMessage(), 422, 'upload_refused');
        } catch (Throwable $e) {
            combos_fail($e, 'upload_photo');
        }
        okv_json(['status' => 'ok', 'message' => 'Combo photo updated.', 'image_url' => $path]);

    case 'delete':
        combos_guard_write('combos.delete');
        $id = combos_required_id();
        $refs = Combos::referenceCount($id);
        if ($refs['total'] > 0) {
            try {
                Combos::unpublish($id);
            } catch (Throwable $e) {
                combos_fail($e, 'delete_unpublish');
            }
            okv_json([
                'status' => 'ok',
                'code' => 'unpublished',
                'message' => Combos::inUseMessage($refs),
                'references' => $refs,
            ]);
        }
        try {
            Combos::delete($id);
        } catch (Throwable $e) {
            combos_fail($e, 'delete');
        }
        okv_json(['status' => 'ok', 'message' => 'Combo removed.']);

    default:
        okv_error('This action is not available.', 400, 'unknown_action');
}

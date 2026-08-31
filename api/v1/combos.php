<?php
/**
 * api/v1/combos.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The admin combo builder. This is where the Manager creates a
 * combo, edits it, adds and removes components, sets its sell price, uploads a
 * photo, publishes it and takes it off the shop. Built in milestone M3 PR2.
 * See docs/PRD.md Section 7.
 *
 * Every action is gated on the server with the combos.* permissions. Reads are
 * GET; every change is a POST with a valid CSRF token. Prepared statements only.
 * No exception ever reaches the client.
 *
 * The sell price moves through Combos::changePrice(), which writes the history
 * in the same transaction, so no path here can change a combo price without
 * recording it. The publish gate lives in Combos::publish() and refuses when a
 * combo has no components or no sell price.
 *
 * Actions:
 *   list                     (GET,  combos.view)      the combo list, filterable
 *   get                      (GET,  combos.view)      one combo, its components, history, totals, references
 *   create                   (POST, combos.create)    a new combo with its first component
 *   update                   (POST, combos.edit)      details (name, sku, description, is_featured, image_url, window)
 *   set_price                (POST, combos.edit)      change the sell price, writing history
 *   add_component            (POST, combos.edit)      add one product to a combo
 *   update_component         (POST, combos.edit)      change one component's quantity
 *   remove_component         (POST, combos.edit)      remove a component; auto-unpublishes when it was the last one
 *   component_total          (POST, combos.edit)      recompute the live component total
 *   set_availability_window  (POST, combos.edit)      set available_from and available_until
 *   publish                  (POST, combos.publish)   put the combo on the shop (gated on components + price)
 *   unpublish                (POST, combos.publish)   take the combo off the shop
 *   upload_image             (POST, combos.edit)      upload a photo, store the path on the combo
 *   remove_image             (POST, combos.edit)      clear the combo's photo
 *   delete                   (POST, combos.delete)    only when nothing refers to it
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../../includes/bootstrap.php';

$action = okv_action();

/** Guard a write: POST, a valid CSRF token, and the permission. */
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

/** The combo id this request names, or stop with a plain answer. */
function combos_required_id(string $field = 'combo_id'): int
{
    $id = (int) okv_input($field, 0);
    if ($id < 1) {
        okv_error('Choose a combo first.', 422, 'missing_combo');
    }
    return $id;
}

/** Turn a failure into a JSON answer without ever leaking the exception. */
function combos_fail(Throwable $e, string $context): void
{
    if ($e instanceof DomainException) {
        $known = [
            'not_found'         => ['We could not find that combo.', 404],
            'in_use'            => ['That combo is in use, so it cannot be removed.', 409],
            'invalid_price'     => ['That price is outside the range we allow.', 422],
            'no_price'          => ['Set a sell price of at least ₦1 before publishing this combo.', 409],
            'no_components'     => ['Add at least one component before publishing this combo.', 409],
            'bad_quantity'      => ['Enter a quantity above zero.', 422],
            'bad_product'       => ['That product does not exist.', 422],
            'bad_unit'          => ['That unit does not exist.', 422],
            'already_in_combo'  => ['That product and unit are already in this combo. Edit its quantity instead.', 409],
        ];
        [$message, $code] = $known[$e->getMessage()] ?? ['We could not do that.', 400];
        okv_error($message, $code, $e->getMessage());
    }
    error_log('combos.' . $context . ' failed: ' . $e->getMessage());
    okv_error('Something went wrong at our end. Please try again.', 500, 'failed');
}

/**
 * The unit row for a component action. Only an active unit is accepted.
 * Returns null when the id is not a real unit; the caller then answers plainly.
 */
function combos_unit(int $unitId): ?array
{
    if ($unitId < 1) { return null; }
    return Database::one(
        'SELECT id, name, symbol, allows_decimal FROM units_of_measurement WHERE id = :id AND is_active = 1',
        [':id' => $unitId]
    );
}

/**
 * Merge the request's window fields onto a combo's existing details and return a
 * clean array for Combos::update. Used by set_availability_window, upload_image
 * and remove_image so the details a page has to send stay narrow.
 */
function combos_merge_update(array $existing, array $overrides): array
{
    return [
        'name'            => (string) $existing['name'],
        'sku'             => (string) $existing['sku'],
        'description'     => (string) ($existing['description'] ?? ''),
        'price_subunit'   => 0, // Combos::update ignores a zero price, so history is untouched.
        'image_url'       => array_key_exists('image_url', $overrides)
                                ? (string) $overrides['image_url']
                                : (string) ($existing['image_url'] ?? ''),
        'is_featured'     => (int) $existing['is_featured'],
        'is_active'       => (int) $existing['is_active'],
        'available_from'  => array_key_exists('available_from', $overrides)
                                ? $overrides['available_from']
                                : $existing['available_from'],
        'available_until' => array_key_exists('available_until', $overrides)
                                ? $overrides['available_until']
                                : $existing['available_until'],
    ];
}

/** The payload we return after any change so the panel can refresh without a page reload. */
function combos_payload(int $comboId): array
{
    $combo = Combos::find($comboId);
    if (!$combo) {
        return ['combo' => null];
    }
    $detail = Combos::componentTotalDetailed($comboId);
    $sell   = (int) $combo['price_subunit'];
    $total  = (int) $detail['total_subunit'];
    return [
        'combo'           => $combo,
        'components'      => $detail['components'],
        'component_total' => $total,
        'component_total_formatted' => Money::format($total),
        'sell_price_formatted'      => $sell > 0 ? Money::format($sell) : '',
        'loss_making'     => Combos::isLossMaking($sell, $total),
        'customer_saving' => Combos::customerSaving($sell, $total),
        'customer_saving_formatted' => Combos::customerSaving($sell, $total) > 0
            ? Money::format(Combos::customerSaving($sell, $total))
            : '',
        'references'      => Combos::referenceCount($comboId),
    ];
}

switch ($action) {

    case 'list': {
        Rbac::requirePermission('combos.view');
        $rows = Combos::all(
            (string) okv_input('search', ''),
            (string) okv_input('status', '')
        );
        okv_json(['status' => 'ok', 'combos' => $rows, 'count' => count($rows)]);
    }

    case 'get': {
        Rbac::requirePermission('combos.view');
        $id = combos_required_id();
        $combo = Combos::find($id);
        if (!$combo) {
            okv_error('We could not find that combo.', 404, 'not_found');
        }
        $payload = combos_payload($id);
        okv_json([
            'status'  => 'ok',
            'combo'   => $payload['combo'],
            'components' => $payload['components'],
            'component_total' => $payload['component_total'],
            'component_total_formatted' => $payload['component_total_formatted'],
            'sell_price_formatted'      => $payload['sell_price_formatted'],
            'loss_making'     => $payload['loss_making'],
            'customer_saving' => $payload['customer_saving'],
            'customer_saving_formatted' => $payload['customer_saving_formatted'],
            'history' => Combos::history($id, 50),
            'references' => $payload['references'],
        ]);
    }

    case 'create': {
        combos_guard_write('combos.create');
        [$clean, $errors] = Combos::validate($_POST);

        // The add-a-combo form ships with a first component (product + quantity
        // + unit), so a combo is never born empty. Validate all three before
        // touching the database.
        $productId = (int) okv_input('first_product_id', 0);
        $unitId    = (int) okv_input('first_unit_id', 0);
        $rawQty    = okv_input('first_quantity', '');
        $unit      = combos_unit($unitId);
        $product   = $productId > 0
            ? Database::one('SELECT id FROM products WHERE id = :id AND is_active = 1', [':id' => $productId])
            : null;
        $quantity  = Combos::cleanComponentQuantity($rawQty, $unit);

        if (!$product) {
            $errors['first_product_id'] = 'Pick an active product for the first component.';
        }
        if (!$unit) {
            $errors['first_unit_id'] = 'Pick a unit for that component.';
        }
        if ($quantity < Combos::MIN_COMPONENT_QUANTITY) {
            $errors['first_quantity'] = 'Enter a quantity above zero.';
        }

        if ($errors) {
            okv_json([
                'status'  => 'error',
                'code'    => 'invalid',
                'message' => 'Please check the fields marked below.',
                'errors'  => $errors,
            ], 422);
        }

        // Create as a draft first (is_active = 0) so the publish gate runs
        // through Combos::publish rather than being bypassed by the checkbox.
        $wantsPublish = !empty($clean['is_active']);
        $clean['is_active'] = 0;

        // The standalone `publish` action is gated on combos.publish. The
        // create form's "put it on the shop" checkbox must not be a way past
        // that: a role with only combos.create should never publish a combo.
        // Refuse the request rather than silently downgrading to a draft, so
        // a caller who thought they were publishing knows they were not.
        if ($wantsPublish && !Rbac::can('combos.publish')) {
            okv_error('You do not have permission to publish a combo.', 403, 'permission_denied');
        }

        // One outer transaction wraps the combo insert, its first component,
        // and the publish gate, so a failure at any step rolls everything back
        // rather than leaving a priced combo with no components in the table.
        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        $id = null;
        $publishSkippedReason = null;
        try {
            $id = Combos::create($clean, Rbac::userId(), false);
            Combos::addComponent($id, $productId, $quantity, $unitId);

            if ($wantsPublish) {
                try {
                    Combos::publish($id);
                } catch (DomainException $publishError) {
                    // A no_price on the way in from the form means the combo
                    // is fine and just wants a price. Commit the draft and
                    // report the skipped publish, don't roll the combo back.
                    $publishSkippedReason = $publishError->getMessage();
                    $wantsPublish = false;
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            combos_fail($e, 'create');
        }

        if ($publishSkippedReason !== null) {
            okv_json([
                'status'    => 'ok',
                'message'   => $publishSkippedReason === 'no_price'
                    ? 'Combo saved as a draft. Set a sell price and publish it when you are ready.'
                    : 'Combo saved as a draft.',
                'combo_id'  => $id,
                'published' => false,
                'reason'    => $publishSkippedReason,
            ]);
        }

        okv_json([
            'status'    => 'ok',
            'message'   => $wantsPublish ? 'Combo added and on the shop.' : 'Combo saved as a draft.',
            'combo_id'  => $id,
            'published' => $wantsPublish,
        ]);
    }

    case 'update': {
        combos_guard_write('combos.edit');
        $id = combos_required_id();
        $existing = Combos::find($id);
        if (!$existing) {
            okv_error('We could not find that combo.', 404, 'not_found');
        }
        // Merge the details the form sends onto the fields it does not, so a
        // Manager saving the name never wipes the photo or the availability
        // window. Photo has upload_image / remove_image; window has
        // set_availability_window; publishing has publish / unpublish.
        $merged = $_POST + [
            'image_url'       => (string) ($existing['image_url'] ?? ''),
            'available_from'  => $existing['available_from'],
            'available_until' => $existing['available_until'],
        ];
        [$clean, $errors] = Combos::validate($merged, $id);
        if ($errors) {
            okv_json(['status' => 'error', 'code' => 'invalid', 'message' => 'Please check the fields marked below.', 'errors' => $errors], 422);
        }

        // Details form never toggles is_active: Publish and Unpublish own that
        // so the gate cannot be bypassed. And the price never moves here: it
        // has its own set_price action that writes the history.
        $clean['is_active']     = (int) $existing['is_active'];
        $clean['price_subunit'] = 0;

        try {
            Combos::update($id, $clean, Rbac::userId());
        } catch (Throwable $e) {
            combos_fail($e, 'update');
        }
        okv_json([
            'status'  => 'ok',
            'message' => 'Combo saved.',
        ] + combos_payload($id));
    }

    case 'set_price': {
        combos_guard_write('combos.edit');
        $id = combos_required_id();
        $raw = trim((string) okv_input('price', ''));
        if ($raw === '') {
            okv_error('Enter a price.', 422, 'missing_price');
        }
        $subunit = Money::toSubunit($raw);
        $reason  = trim((string) okv_input('reason', ''));

        try {
            $result = Combos::changePrice($id, $subunit, $reason === '' ? null : $reason, Rbac::userId());
        } catch (Throwable $e) {
            combos_fail($e, 'set_price');
        }

        okv_json([
            'status'  => 'ok',
            'message' => $result['changed']
                ? 'Sell price updated to ' . Money::format($result['new']) . '.'
                : 'That is already the sell price.',
            'changed'   => $result['changed'],
            'old'       => $result['old'],
            'new'       => $result['new'],
            'formatted' => Money::format($result['new']),
        ] + combos_payload($id));
    }

    case 'add_component': {
        combos_guard_write('combos.edit');
        $comboId   = combos_required_id();
        $productId = (int) okv_input('product_id', 0);
        $unitId    = (int) okv_input('unit_id', 0);
        $unit      = combos_unit($unitId);
        $quantity  = Combos::cleanComponentQuantity(okv_input('quantity', ''), $unit);

        try {
            Combos::addComponent($comboId, $productId, $quantity, $unitId);
        } catch (Throwable $e) {
            combos_fail($e, 'add_component');
        }

        okv_json([
            'status'  => 'ok',
            'message' => 'Component added.',
        ] + combos_payload($comboId));
    }

    case 'update_component': {
        combos_guard_write('combos.edit');
        $comboId     = combos_required_id();
        $componentId = (int) okv_input('component_id', 0);
        if ($componentId < 1) {
            okv_error('Choose a component first.', 422, 'missing_component');
        }
        // Look up the unit off the component so cleanComponentQuantity respects
        // the rule for the unit that is already on the row.
        $row = Database::one(
            'SELECT ci.id, u.allows_decimal
               FROM combo_package_items ci
               JOIN units_of_measurement u ON u.id = ci.unit_id
              WHERE ci.id = :id AND ci.combo_package_id = :combo_id',
            [':id' => $componentId, ':combo_id' => $comboId]
        );
        if (!$row) {
            okv_error('We could not find that component.', 404, 'not_found');
        }
        $quantity = Combos::cleanComponentQuantity(
            okv_input('quantity', ''),
            ['allows_decimal' => $row['allows_decimal']]
        );

        try {
            Combos::updateComponent($componentId, $quantity);
        } catch (Throwable $e) {
            combos_fail($e, 'update_component');
        }

        okv_json([
            'status'  => 'ok',
            'message' => 'Quantity updated.',
        ] + combos_payload($comboId));
    }

    case 'remove_component': {
        combos_guard_write('combos.edit');
        $comboId     = combos_required_id();
        $componentId = (int) okv_input('component_id', 0);
        if ($componentId < 1) {
            okv_error('Choose a component first.', 422, 'missing_component');
        }

        // The M3 answer to "what happens when the last component goes": auto
        // unpublish so the shop never carries a broken combo. Count first, then
        // remove, then unpublish inside a small transaction so a failure does
        // not leave the panel disagreeing with the database.
        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            $existing = Database::one(
                'SELECT id, is_active FROM combo_packages WHERE id = :id FOR UPDATE',
                [':id' => $comboId]
            );
            if (!$existing) {
                throw new DomainException('not_found');
            }
            $before = Combos::componentCount($comboId);
            Combos::removeComponent($componentId);
            $autoUnpublished = false;
            if ($before <= 1 && (int) $existing['is_active'] === 1) {
                Combos::unpublish($comboId);
                $autoUnpublished = true;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            combos_fail($e, 'remove_component');
        }

        okv_json([
            'status'  => 'ok',
            'message' => $autoUnpublished
                ? 'That was the last component, so the combo is off the shop until you add one and publish it again.'
                : 'Component removed.',
            'auto_unpublished' => $autoUnpublished,
        ] + combos_payload($comboId));
    }

    case 'component_total': {
        combos_guard_write('combos.edit');
        $comboId = combos_required_id();
        if (!Combos::find($comboId)) {
            okv_error('We could not find that combo.', 404, 'not_found');
        }
        okv_json([
            'status'  => 'ok',
        ] + combos_payload($comboId));
    }

    case 'set_availability_window': {
        combos_guard_write('combos.edit');
        $id = combos_required_id();
        $existing = Combos::find($id);
        if (!$existing) {
            okv_error('We could not find that combo.', 404, 'not_found');
        }
        // Validate the two dates through the same rule Combos::validate uses.
        [$clean, $errors] = Combos::validate([
            'name'            => (string) $existing['name'],
            'sku'             => (string) $existing['sku'],
            'description'     => (string) ($existing['description'] ?? ''),
            'price'           => '',
            'image_url'       => (string) ($existing['image_url'] ?? ''),
            'is_featured'     => (int) $existing['is_featured'],
            'is_active'       => (int) $existing['is_active'],
            'available_from'  => okv_input('available_from', ''),
            'available_until' => okv_input('available_until', ''),
        ], $id);
        if (isset($errors['available_until'])) {
            okv_json(['status' => 'error', 'code' => 'invalid', 'message' => $errors['available_until'], 'errors' => ['available_until' => $errors['available_until']]], 422);
        }

        try {
            Combos::update($id, $clean, Rbac::userId());
        } catch (Throwable $e) {
            combos_fail($e, 'set_availability_window');
        }

        okv_json([
            'status'  => 'ok',
            'message' => 'Availability window updated.',
        ] + combos_payload($id));
    }

    case 'publish': {
        combos_guard_write('combos.publish');
        $id = combos_required_id();
        try {
            Combos::publish($id);
        } catch (Throwable $e) {
            combos_fail($e, 'publish');
        }
        okv_json([
            'status'  => 'ok',
            'message' => 'That combo is on the shop.',
            'is_active' => 1,
        ] + combos_payload($id));
    }

    case 'unpublish': {
        combos_guard_write('combos.publish');
        $id = combos_required_id();
        try {
            Combos::unpublish($id);
        } catch (Throwable $e) {
            combos_fail($e, 'unpublish');
        }
        okv_json([
            'status'  => 'ok',
            'message' => 'That combo is off the shop.',
            'is_active' => 0,
        ] + combos_payload($id));
    }

    case 'upload_image': {
        combos_guard_write('combos.edit');
        $id = combos_required_id();
        $existing = Combos::find($id);
        if (!$existing) {
            okv_error('We could not find that combo.', 404, 'not_found');
        }
        if (empty($_FILES['image'])) {
            okv_error('Choose a photo to upload.', 422, 'missing_file');
        }

        try {
            $path = Uploads::saveUploadedFile($_FILES['image'], 'combos', ['image/jpeg', 'image/png', 'image/webp']);
            Combos::update($id, combos_merge_update($existing, ['image_url' => $path]), Rbac::userId());
        } catch (RuntimeException $e) {
            okv_error($e->getMessage(), 422, 'upload_refused');
        } catch (Throwable $e) {
            combos_fail($e, 'upload_image');
        }

        okv_json([
            'status'    => 'ok',
            'message'   => 'Photo saved.',
            'image_url' => $path,
        ] + combos_payload($id));
    }

    case 'remove_image': {
        combos_guard_write('combos.edit');
        $id = combos_required_id();
        $existing = Combos::find($id);
        if (!$existing) {
            okv_error('We could not find that combo.', 404, 'not_found');
        }
        try {
            Combos::update($id, combos_merge_update($existing, ['image_url' => '']), Rbac::userId());
        } catch (Throwable $e) {
            combos_fail($e, 'remove_image');
        }
        okv_json([
            'status'  => 'ok',
            'message' => 'Photo removed.',
        ] + combos_payload($id));
    }

    case 'delete': {
        combos_guard_write('combos.delete');
        $id = combos_required_id();
        $refs = Combos::referenceCount($id);
        if ($refs['total'] > 0) {
            okv_json([
                'status'     => 'error',
                'code'       => 'in_use',
                'message'    => Combos::inUseMessage($refs),
                'references' => $refs,
            ], 409);
        }
        try {
            Combos::delete($id);
        } catch (Throwable $e) {
            combos_fail($e, 'delete');
        }
        okv_json(['status' => 'ok', 'message' => 'Combo removed.']);
    }

    default:
        okv_error('This action is not available.', 400, 'unknown_action');
}

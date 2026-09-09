<?php
/**
 * api/v1/kitchen_runs.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Submit, quote, approve, decline, cancel and convert Kitchen Runs
 * (PRD Section 8).
 *
 * Every action is gated twice: the method and the CSRF token first, then either
 * a customer session or an RBAC permission. The frontend gate is UX only and
 * this file assumes nothing from it.
 *
 * Two callers, one controller. The storefront and the admin screens post their
 * forms natively so the flow works with JavaScript switched off, and their JS
 * posts the same forms with fetch. A fetch caller gets JSON; a plain form post
 * gets a 303 back to the screen it came from, carrying either a success flag or
 * an error code the screen turns into the same sentence through
 * KitchenRuns::message(). Neither caller is ever shown a raw JSON body it did
 * not ask for, and neither is ever shown an exception.
 *
 * Notifications are sent after the transaction has committed, never inside it,
 * so a slow SMTP round trip can never hold a database lock and a refused email
 * can never roll back a request.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../../includes/bootstrap.php';

/** True when the caller asked for JSON rather than a page. */
function kr_wants_json(): bool
{
    return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch'
        || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
}

/** Answer the caller in the shape it asked for, then stop. */
function kr_ok(array $payload, string $redirectTo): void
{
    if (kr_wants_json()) {
        okv_json(['status' => 'ok'] + $payload);
    }
    okv_redirect($redirectTo, 303);
}

/** Refuse the caller in the shape it asked for, then stop. */
function kr_fail(string $code, string $redirectTo): void
{
    if (kr_wants_json()) {
        okv_error(KitchenRuns::message($code), KitchenRuns::statusCode($code), $code);
    }
    okv_redirect($redirectTo . (str_contains($redirectTo, '?') ? '&' : '?') . 'error=' . rawurlencode($code), 303);
}

/**
 * The posted lines, with naira turned into kobo once, here, so nothing further
 * in has to think about two units of money.
 *
 * A price that is not plainly a price is refused rather than coerced. Every
 * figure on a Kitchen Run is typed by hand, so "1e3" quietly becoming 13 naira
 * would be a real mispricing on a real order; KitchenRuns::nairaToKobo() says
 * false to anything it does not recognise and the line is refused.
 *
 * @throws DomainException when a price field holds something that is not money.
 */
function kr_posted_items(): array
{
    $items = $_POST['items'] ?? [];
    if (!is_array($items)) {
        return [];
    }

    $clean = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        // A row the person left completely blank is not an error, it is an
        // unused row on a form that offers several. Drop it quietly.
        if (kr_row_is_blank($item)) {
            continue;
        }
        foreach (['unit_price' => 'unit_price_subunit', 'target_price' => 'target_price_subunit'] as $typed => $kobo) {
            if (!isset($item[$typed]) || trim((string) $item[$typed]) === '') {
                continue;
            }
            $parsed = KitchenRuns::nairaToKobo($item[$typed]);
            if ($parsed === false) {
                throw new DomainException('invalid_line');
            }
            $item[$kobo] = $parsed;
        }
        $clean[] = $item;
    }
    return $clean;
}

function kr_row_is_blank(array $item): bool
{
    foreach (['product_id', 'item_name', 'quantity', 'unit_price', 'unit_price_subunit', 'target_price', 'target_price_subunit', 'note'] as $key) {
        if (trim((string) ($item[$key] ?? '')) !== '') {
            return false;
        }
    }
    return true;
}

/**
 * A naira amount from a form, in kobo, or null when the field was left empty.
 * Refuses anything that is not money rather than reading it as zero.
 *
 * @throws DomainException when the field holds something that is not money.
 */
function kr_money_input(string $key, string $code): ?int
{
    $parsed = KitchenRuns::nairaToKobo(okv_input($key, ''));
    if ($parsed === false) {
        throw new DomainException($code);
    }
    return $parsed;
}

$action     = okv_action();
$requestId  = (int) okv_input('request_id', 0);
$customerUrl = '/kitchen-runs.php' . ($requestId > 0 ? '?request=' . $requestId : '');
$adminUrl    = '/admin/kitchen_runs.php' . ($requestId > 0 ? '?request=' . $requestId : '');
$backTo      = in_array($action, ['quote', 'decline', 'convert', 'staff_approve', 'save_note'], true) ? $adminUrl : $customerUrl;

if (!okv_is_post()) {
    okv_error('Use POST for this action.', 405, 'method_not_allowed');
}
if (!Csrf::validate()) {
    okv_error('Your session expired. Reload the page and try again.', 419, 'csrf_expired');
}

try {
    switch ($action) {

        // --- The customer sends a list ---------------------------------------
        case 'submit':
            Customer::requireLoginApi();

            $mode       = (string) okv_input('input_mode', '');
            $attachment = null;
            if ($mode === 'upload') {
                $file = $_FILES['attachment'] ?? [];
                if (!KitchenRuns::allowedUpload(
                    (string) ($file['name'] ?? ''),
                    (string) ($file['type'] ?? ''),
                    (int) ($file['size'] ?? 0)
                )) {
                    // Checked before the file is saved, so a rejection is a plain
                    // sentence rather than an exception. Uploads::saveUploadedFile
                    // then sniffs the real type, because a browser-supplied one is
                    // only a claim.
                    kr_fail('attachment_rejected', $customerUrl);
                }
                try {
                    $attachment = Uploads::saveUploadedFile($file, 'kitchen_runs', KitchenRuns::UPLOAD_MIME);
                } catch (RuntimeException $e) {
                    // Uploads refuses with one of these. The customer hears a
                    // sentence about their file, never the exception text.
                    error_log('kitchen_runs upload refused: ' . $e->getMessage());
                    kr_fail('attachment_rejected', $customerUrl);
                }
            }

            $result = KitchenRunWorkflow::submit(
                (int) Customer::id(),
                (string) Customer::type(),
                [
                    'input_mode'            => $mode,
                    'pricing_mode'          => (string) okv_input('pricing_mode', 'by_us'),
                    'is_open_budget'        => okv_input('is_open_budget', '') !== '',
                    'spend_cap_subunit'     => kr_money_input('spend_cap', 'budget_not_a_number'),
                    'budget_ceiling_subunit' => kr_money_input('budget_ceiling', 'budget_not_a_number'),
                    'customer_note'         => okv_input('customer_note', ''),
                    'preferred_delivery_date' => okv_input('preferred_delivery_date', ''),
                    'delivery_zone_id'      => okv_input('delivery_zone_id', 0),
                    'recipient_name'        => okv_input('recipient_name', ''),
                    'recipient_phone'       => okv_input('recipient_phone', ''),
                    'address_line_1'        => okv_input('address_line_1', ''),
                    'address_line_2'        => okv_input('address_line_2', ''),
                    'city'                  => okv_input('city', ''),
                    'state'                 => okv_input('state', ''),
                    'landmark'              => okv_input('landmark', ''),
                    'items'                 => kr_posted_items(),
                ],
                $attachment
            );

            Audit::record('kitchen_run.submit', 'kitchen_run', (int) $result['id'], null, ['request_number' => $result['request_number']], (int) Customer::id());
            Notifications::announceKitchenRunSubmitted((int) $result['id']);
            Notifications::announceKitchenRunReceived((int) $result['id']);
            kr_ok($result, '/kitchen-runs.php?request=' . $result['id'] . '&submitted=1');
            break;

        // --- Staff price it ---------------------------------------------------
        case 'quote':
            Rbac::requirePermission('kitchen_runs.quote');
            $staffId = (int) Rbac::userId();

            $result = KitchenRunWorkflow::quote($requestId, $staffId, (int) okv_input('state_version', 0), [
                'items'                   => kr_posted_items(),
                'deposit_subunit'         => kr_money_input('deposit', 'deposit_not_a_number'),
                'preferred_delivery_date' => okv_input('preferred_delivery_date', ''),
                'delivery_zone_id'        => okv_input('delivery_zone_id', 0),
                'admin_note'              => okv_input('admin_note', ''),
            ]);

            Audit::record('kitchen_run.quote', 'kitchen_run', $requestId, null, ['total_subunit' => $result['total_subunit']], $staffId);
            Notifications::announceKitchenRunQuoted($requestId, $staffId);
            kr_ok($result, '/admin/kitchen_runs.php?request=' . $requestId . '&quoted=1');
            break;

        // --- The customer says yes -------------------------------------------
        case 'approve':
            Customer::requireLoginApi();
            $userId = (int) Customer::id();

            $result = KitchenRunWorkflow::approve($requestId, $userId, (int) okv_input('state_version', 0));

            Audit::record('kitchen_run.approve', 'kitchen_run', $requestId, null, null, $userId);
            Notifications::announceKitchenRunApproved($requestId, $userId);
            kr_ok($result, '/kitchen-runs.php?request=' . $requestId . '&approved=1');
            break;

        // --- The customer withdraws it ---------------------------------------
        case 'cancel':
            Customer::requireLoginApi();
            $userId = (int) Customer::id();

            $result = KitchenRunWorkflow::cancel($requestId, $userId, (int) okv_input('state_version', 0));

            Audit::record('kitchen_run.cancel', 'kitchen_run', $requestId, null, ['from' => $result['from']], $userId);
            if (($result['from'] ?? '') === 'approved') {
                // Nothing to refund, but our own cash may already be at the
                // market against this list. The team hears about that one.
                Notifications::announceKitchenRunCancelled($requestId, $userId);
            }
            kr_ok($result, '/kitchen-runs.php?request=' . $requestId . '&cancelled=1');
            break;

        // --- Staff approve for a customer who told us on the phone -----------
        case 'staff_approve':
            Rbac::requirePermission('kitchen_runs.approve');
            $staffId = (int) Rbac::userId();

            $result = KitchenRunWorkflow::approveForCustomer(
                $requestId,
                $staffId,
                (int) okv_input('state_version', 0),
                (string) okv_input('authorisation', '')
            );

            Audit::record('kitchen_run.approve', 'kitchen_run', $requestId, null, ['on_behalf_of_customer' => true], $staffId);
            Notifications::announceKitchenRunApproved($requestId, $staffId);
            kr_ok($result, '/admin/kitchen_runs.php?request=' . $requestId . '&approved=1');
            break;

        // --- The team's own note, which no customer ever reads ---------------
        case 'save_note':
            Rbac::requirePermission('kitchen_runs.quote');
            $staffId = (int) Rbac::userId();

            $result = KitchenRunWorkflow::saveStaffNote($requestId, $staffId, (string) okv_input('staff_note', ''));

            Audit::record('kitchen_run.note.update', 'kitchen_run', $requestId, null, null, $staffId);
            kr_ok($result, '/admin/kitchen_runs.php?request=' . $requestId . '&note_saved=1');
            break;

        // --- Staff turn it down ----------------------------------------------
        case 'decline':
            Rbac::requirePermission('kitchen_runs.decline');
            $staffId = (int) Rbac::userId();

            $result = KitchenRunWorkflow::decline($requestId, $staffId, (int) okv_input('state_version', 0), (string) okv_input('admin_note', ''));

            Audit::record('kitchen_run.decline', 'kitchen_run', $requestId, null, null, $staffId);
            Notifications::announceKitchenRunDeclined($requestId, $staffId);
            kr_ok($result, '/admin/kitchen_runs.php?request=' . $requestId . '&declined=1');
            break;

        // --- Staff make it an order ------------------------------------------
        case 'convert':
            Rbac::requirePermission('kitchen_runs.convert');
            $staffId = (int) Rbac::userId();

            $date   = trim((string) okv_input('preferred_delivery_date', ''));
            $result = KitchenRunWorkflow::convert(
                $requestId,
                $staffId,
                (int) okv_input('state_version', 0),
                (string) okv_input('payment_option', ''),
                $date === '' ? null : $date
            );

            if (!$result['already_converted']) {
                Audit::record('kitchen_run.convert', 'order', (int) $result['id'], null, ['order_number' => $result['order_number'], 'request_id' => $requestId], $staffId);
                try {
                    Notifications::announceOrderPlaced((int) $result['id'], (string) $result['trail_token']);
                } catch (Throwable $e) {
                    error_log('kitchen_runs announce order placed failed: ' . $e->getMessage());
                }
                if ((string) okv_input('payment_option', '') === 'on_account') {
                    try {
                        Notifications::announceCreditChargePosted((int) $result['id']);
                    } catch (Throwable $e) {
                        error_log('kitchen_runs announce credit charge failed: ' . $e->getMessage());
                    }
                }
            }
            // The token is the customer's private key to their order trail and
            // is never handed back over the API.
            unset($result['trail_token']);
            kr_ok($result, '/admin/orders.php?order=' . $result['id']);
            break;

        default:
            okv_error('That action is not available.', 400, 'unknown_action');
    }
} catch (DomainException $e) {
    kr_fail($e->getMessage(), $backTo);
} catch (Throwable $e) {
    // Everything else is ours, and it is reported as ours. This block used to
    // catch RuntimeException first and call it a rejected attachment, which is
    // true of the upload helper and of nothing else: PDOException extends
    // RuntimeException, so a database fault while pricing a list told the
    // colleague their file was not a JPEG. A refusal that names the wrong
    // cause is worse than no refusal, because it sends somebody looking in the
    // wrong place. The upload now catches its own exception, beside the upload.
    error_log('kitchen_runs ' . $action . ' failed: ' . $e->getMessage());
    if (kr_wants_json()) {
        okv_error('We could not save that Kitchen Run. Please try again.', 500, 'failed');
    }
    okv_redirect($backTo . (str_contains($backTo, '?') ? '&' : '?') . 'error=failed', 303);
}

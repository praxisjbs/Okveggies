<?php
/**
 * api/v1/pricing.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Weekly price changes and the spreadsheet round trip. This is the
 * business's core recurring task, so it is the screen that has to be quickest
 * and hardest to get wrong. Built in milestone M2. See docs/PRD.md Section 6.
 *
 * Every action is gated on the server with the pricing.* permissions. Reads are
 * GET; every change is a POST with a valid CSRF token. Every price movement goes
 * through Pricing::change(), which writes the history in the same transaction,
 * so no path here can change a price without recording it.
 *
 * The import is deliberately two steps. preview_import reads the file and says
 * exactly what it would do, writing nothing. apply_import re-checks that against
 * the database as it stands right now and then applies it in one transaction, so
 * a sheet previewed ten minutes ago cannot quietly overwrite a change made since.
 *
 * Actions:
 *   list           (GET,  pricing.view)    the pricing table
 *   history        (GET,  pricing.view)    one product's price history
 *   set_price      (POST, pricing.update)  change one price
 *   preview_bulk   (POST, pricing.update)  what a category move would do
 *   apply_bulk     (POST, pricing.update)  do it, all or nothing
 *   export         (GET,  pricing.export)  download the price list as .xlsx
 *   preview_import (POST, pricing.import)  read a sheet and report, writing nothing
 *   apply_import   (POST, pricing.import)  apply the sheet just previewed
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../../includes/bootstrap.php';

$action = okv_action();

/** Where a previewed import waits between the two steps. */
const PRICING_IMPORT_SESSION_KEY = 'okv_price_import';

/** The largest spreadsheet we will read, 4MB. A price list is a few kilobytes. */
const PRICING_MAX_UPLOAD_BYTES = 4194304;

function pricing_guard_write(string $permission): void
{
    if (!okv_is_post()) {
        okv_error('Use POST for this action.', 405, 'method_not_allowed');
    }
    Rbac::requirePermission($permission);
    if (!Csrf::validate()) {
        okv_error('Your session expired. Reload the page and try again.', 419, 'csrf_expired');
    }
}

function pricing_fail(Throwable $e, string $context): void
{
    if ($e instanceof DomainException) {
        $known = [
            'not_found'       => ['We could not find that product.', 404],
            'invalid_price'   => ['That price is outside the range we allow.', 422],
            'blocked'         => ['That move would take a price out of range, so nothing was changed.', 409],
            'reason_required' => ['Say why prices are moving. It goes into the history.', 422],
            'has_problems'    => ['That sheet still has problems, so nothing was changed.', 409],
        ];
        [$message, $code] = $known[$e->getMessage()] ?? ['We could not do that.', 400];
        okv_error($message, $code, $e->getMessage());
    }
    error_log('pricing.' . $context . ' failed: ' . $e->getMessage());
    okv_error('Something went wrong at our end. Please try again.', 500, 'failed');
}

/** percent or flat, and nothing else. */
function pricing_mode(): string
{
    $mode = (string) okv_input('mode', Pricing::MODE_PERCENT);
    if (!in_array($mode, [Pricing::MODE_PERCENT, Pricing::MODE_FLAT], true)) {
        okv_error('Choose a percentage or a flat amount.', 422, 'bad_mode');
    }
    return $mode;
}

/**
 * The size of the move, in the units its mode expects: a percentage for percent,
 * subunits for flat. A move of nothing is refused, because it is always a slip.
 */
function pricing_amount(string $mode): float
{
    $raw = trim((string) okv_input('amount', ''));
    if ($raw === '' || !preg_match('/^-?[0-9]+(\.[0-9]+)?$/', str_replace([',', ' ', '₦'], '', $raw))) {
        okv_error('Enter how much prices should move by.', 422, 'bad_amount');
    }
    $clean = (float) str_replace([',', ' ', '₦'], '', $raw);
    if ($clean === 0.0) {
        okv_error('That move is nothing. Enter an amount.', 422, 'bad_amount');
    }
    if ($mode === Pricing::MODE_PERCENT) {
        if ($clean <= -100 || $clean > 1000) {
            okv_error('Keep a percentage move between -100 and 1000.', 422, 'bad_amount');
        }
        return $clean;
    }
    // Flat moves arrive in naira and are held in subunits from here on.
    return (float) Money::toSubunit($clean);
}

/** The category this request names, checked against the table. */
function pricing_category_id(): int
{
    $id = (int) okv_input('category_id', 0);
    if (!Database::one('SELECT id FROM product_categories WHERE id = :id', [':id' => $id])) {
        okv_error('Pick a category.', 422, 'bad_category');
    }
    return $id;
}

switch ($action) {

    case 'list': {
        Rbac::requirePermission('pricing.view');
        $rows = Database::all(
            'SELECT p.id, p.name, p.sku, p.current_price_subunit, p.is_active,
                    c.id AS category_id, c.name AS category_name,
                    u.symbol AS unit,
                    (SELECT h.effective_from FROM product_price_history h
                      WHERE h.product_id = p.id ORDER BY h.effective_from DESC, h.id DESC LIMIT 1) AS last_changed_at
               FROM products p
               JOIN product_categories c ON c.id = p.category_id
               JOIN units_of_measurement u ON u.id = p.unit_id
              WHERE p.is_active = 1
              ORDER BY c.sort_order, p.name'
        );
        okv_json(['status' => 'ok', 'products' => $rows, 'count' => count($rows)]);
    }

    case 'history': {
        Rbac::requirePermission('pricing.view');
        $id = (int) okv_input('product_id', 0);
        if ($id < 1) {
            okv_error('Choose a product first.', 422, 'missing_product');
        }
        okv_json(['status' => 'ok', 'history' => Pricing::history($id, 50)]);
    }

    case 'set_price': {
        pricing_guard_write('pricing.update');
        $id = (int) okv_input('product_id', 0);
        if ($id < 1) {
            okv_error('Choose a product first.', 422, 'missing_product');
        }
        $raw = trim((string) okv_input('price', ''));
        if ($raw === '') {
            okv_error('Enter a price.', 422, 'missing_price');
        }
        $subunit = Money::toSubunit($raw);
        $reason = trim((string) okv_input('reason', ''));

        try {
            $result = Pricing::change($id, $subunit, $reason === '' ? null : $reason, Rbac::userId());
        } catch (Throwable $e) {
            pricing_fail($e, 'set_price');
        }

        okv_json([
            'status'  => 'ok',
            'message' => $result['changed']
                ? 'Price updated to ' . Money::format($result['new']) . '.'
                : 'That is already the price.',
            'changed'   => $result['changed'],
            'old'       => $result['old'],
            'new'       => $result['new'],
            'formatted' => Money::format($result['new']),
        ]);
    }

    case 'preview_bulk': {
        pricing_guard_write('pricing.update');
        $categoryId = pricing_category_id();
        $mode = pricing_mode();
        $amount = pricing_amount($mode);

        try {
            $preview = Pricing::previewBulk($categoryId, $mode, $amount);
        } catch (Throwable $e) {
            pricing_fail($e, 'preview_bulk');
        }

        okv_json([
            'status'   => 'ok',
            'rows'     => $preview['rows'],
            'skipped'  => $preview['skipped'],
            'blocked'  => $preview['blocked'],
            'ok'       => $preview['blocked'] === [],
            'moving'   => count(array_filter($preview['rows'], static fn($r) => $r['changed'])),
        ]);
    }

    case 'apply_bulk': {
        pricing_guard_write('pricing.update');
        $categoryId = pricing_category_id();
        $mode = pricing_mode();
        $amount = pricing_amount($mode);
        $reason = trim((string) okv_input('reason', ''));

        try {
            $result = Pricing::applyBulk($categoryId, $mode, $amount, $reason, Rbac::userId());
        } catch (Throwable $e) {
            pricing_fail($e, 'apply_bulk');
        }

        $changed = (int) $result['changed'];
        okv_json([
            'status'  => 'ok',
            'message' => $changed === 1 ? '1 price updated.' : $changed . ' prices updated.',
            'changed' => $changed,
            'skipped' => $result['skipped'],
        ]);
    }

    case 'export': {
        Rbac::requirePermission('pricing.export');
        $path = tempnam(sys_get_temp_dir(), 'okvprices');
        if ($path === false) {
            okv_error('We could not prepare the file. Please try again.', 500, 'failed');
        }
        try {
            PriceSheet::export($path);
            $name = PriceSheet::exportFilename();
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . (string) filesize($path));
            header('Cache-Control: no-store');
            readfile($path);
        } catch (Throwable $e) {
            error_log('pricing.export failed: ' . $e->getMessage());
            okv_error('We could not build the price list. Please try again.', 500, 'failed');
        } finally {
            @unlink($path);
        }
        exit;
    }

    case 'preview_import': {
        pricing_guard_write('pricing.import');

        $file = $_FILES['sheet'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            okv_error('Choose a spreadsheet to upload.', 422, 'missing_file');
        }
        if (($file['size'] ?? 0) > PRICING_MAX_UPLOAD_BYTES) {
            okv_error('That file is too large. A price list should be well under 4MB.', 422, 'too_large');
        }
        if (!is_uploaded_file($file['tmp_name'] ?? '')) {
            okv_error('That upload did not arrive properly. Please try again.', 422, 'bad_upload');
        }

        // Sniff the real type rather than trusting the name. The file is read
        // from the temp path and never stored under the web root.
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($file['tmp_name']);
        $allowed = [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel',
            'application/zip',
            'text/csv',
            'text/plain',
        ];
        if (!in_array($mime, $allowed, true)) {
            okv_error('That is not a spreadsheet we can read. Send an .xlsx or a .csv.', 422, 'bad_type');
        }

        $name = (string) ($file['name'] ?? 'price sheet');
        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
            okv_error('Send an .xlsx or a .csv file.', 422, 'bad_extension');
        }

        // PhpSpreadsheet picks its reader from the extension, so give the temp
        // file one it recognises.
        $readable = $file['tmp_name'] . '.' . $extension;
        if (!copy($file['tmp_name'], $readable)) {
            okv_error('We could not read that file. Please try again.', 500, 'failed');
        }

        try {
            $rows = PriceSheet::read($readable);
            $preview = PriceSheet::preview($rows);
        } catch (Throwable $e) {
            error_log('pricing.preview_import failed: ' . $e->getMessage());
            okv_error('We could not read that spreadsheet. Check it opens in Excel and try again.', 422, 'unreadable');
        } finally {
            @unlink($readable);
        }

        if (!$rows) {
            okv_error('That sheet has no rows we recognise. It needs a SKU column and a price column.', 422, 'empty_sheet');
        }

        // Hold the parsed rows, not the file, for the confirm step.
        $token = bin2hex(random_bytes(16));
        $_SESSION[PRICING_IMPORT_SESSION_KEY] = [
            'token'  => $token,
            'name'   => mb_substr($name, 0, 180),
            'rows'   => $rows,
            'at'     => time(),
        ];

        okv_json([
            'status'   => 'ok',
            'token'    => $token,
            'file'     => mb_substr($name, 0, 180),
            'changes'  => $preview['changes'],
            'same'     => count($preview['same']),
            'skipped'  => count($preview['skipped']),
            'problems' => $preview['problems'],
            'ok'       => $preview['ok'],
        ]);
    }

    case 'apply_import': {
        pricing_guard_write('pricing.import');

        $held = $_SESSION[PRICING_IMPORT_SESSION_KEY] ?? null;
        $token = (string) okv_input('token', '');
        if (!is_array($held) || $token === '' || !hash_equals((string) $held['token'], $token)) {
            okv_error('That import has expired. Upload the sheet again.', 409, 'expired_import');
        }
        // Half an hour is plenty to look at a preview and decide.
        if (time() - (int) $held['at'] > 1800) {
            unset($_SESSION[PRICING_IMPORT_SESSION_KEY]);
            okv_error('That import has expired. Upload the sheet again.', 409, 'expired_import');
        }

        try {
            // Check again against the database as it is now, not as it was when
            // the preview was taken. A price may have moved in between.
            $preview = PriceSheet::preview($held['rows']);
            if (!$preview['ok']) {
                okv_json([
                    'status'   => 'error',
                    'code'     => 'has_problems',
                    'message'  => 'That sheet has problems, so nothing was changed.',
                    'problems' => $preview['problems'],
                ], 409);
            }
            $applied = PriceSheet::apply($preview, (string) $held['name'], Rbac::userId());
        } catch (Throwable $e) {
            pricing_fail($e, 'apply_import');
        }

        unset($_SESSION[PRICING_IMPORT_SESSION_KEY]);
        okv_json([
            'status'  => 'ok',
            'message' => $applied === 1 ? '1 price updated from the sheet.' : $applied . ' prices updated from the sheet.',
            'applied' => $applied,
        ]);
    }

    default:
        okv_error('This action is not available.', 400, 'unknown_action');
}

<?php
/**
 * api/v1/expenses.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The one controller for the Expense module.
 *
 *   Writes (POST, CSRF, expenses.manage):
 *     create  record one expense
 *     void    reverse an expense (never a delete)
 *   Reads (expenses.view):
 *     list             recent expenses, with optional month/category/supplier filters
 *     categories       the enforced category list, for the entry sheet
 *     supplier_suggest typeahead over suppliers already used
 *
 * Every action re-checks its permission on the server. Money out is sensitive,
 * so a read needs expenses.view and a write needs expenses.manage. Exception
 * text is logged, never returned to the client.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../../includes/bootstrap.php';

$action = okv_action();

$writeActions = ['create', 'void'];
$readActions  = ['list', 'categories', 'supplier_suggest'];

if (in_array($action, $writeActions, true)) {
    if (!okv_is_post()) {
        okv_error('Use POST for this action.', 405, 'method_not_allowed');
    }
    if (!Csrf::validate()) {
        okv_error('Your session expired. Reload the page and try again.', 419, 'csrf_expired');
    }
    Rbac::requirePermission('expenses.manage');
} elseif (in_array($action, $readActions, true)) {
    Rbac::requirePermission('expenses.view');
} else {
    okv_error('That action is not available.', 400, 'unknown_action');
}

try {
    if ($action === 'create') {
        $result = Expenses::create([
            'spent_on'      => okv_input('spent_on', ''),
            'category_slug' => okv_input('category_slug', ''),
            'supplier_name' => okv_input('supplier_name', ''),
            'description'   => okv_input('description', ''),
            'quantity'      => okv_input('quantity', ''),
            'unit_cost'     => okv_input('unit_cost', ''),
            'amount'        => okv_input('amount', ''),
        ], (int) Rbac::userId());

        Audit::record(
            'expense.create',
            'expense',
            (int) $result['id'],
            null,
            ['amount_subunit' => $result['amount_subunit'], 'category_slug' => $result['category_slug']],
            (int) Rbac::userId()
        );

        okv_json([
            'status'         => 'ok',
            'id'             => $result['id'],
            'amount_subunit' => $result['amount_subunit'],
            'amount_display' => Money::format((int) $result['amount_subunit']),
        ], 201);
    }

    if ($action === 'void') {
        $id = (int) okv_input('id', 0);
        Expenses::void($id, (int) Rbac::userId(), (string) okv_input('reason', ''));

        Audit::record('expense.void', 'expense', $id, null, ['reason' => (string) okv_input('reason', '')], (int) Rbac::userId());

        okv_json(['status' => 'ok', 'id' => $id]);
    }

    if ($action === 'list') {
        $expenses = Expenses::listRecent([
            'month'         => okv_input('month', ''),
            'category_slug' => okv_input('category_slug', ''),
            'supplier_key'  => okv_input('supplier', ''),
            'limit'         => (int) okv_input('limit', 100),
        ]);
        // Carry a formatted amount so the client never hand-formats naira.
        foreach ($expenses as &$row) {
            $row['amount_display'] = Money::format((int) $row['amount_subunit']);
        }
        unset($row);

        okv_json(['status' => 'ok', 'expenses' => $expenses]);
    }

    if ($action === 'categories') {
        okv_json(['status' => 'ok', 'categories' => Expenses::categoriesFromDb()]);
    }

    if ($action === 'supplier_suggest') {
        okv_json(['status' => 'ok', 'suppliers' => Expenses::supplierSuggestions((string) okv_input('q', ''))]);
    }

    // Every mapped action returns above; reaching here is a programming error.
    okv_error('That action is not available.', 400, 'unknown_action');

} catch (DomainException $e) {
    // A code the person can correct: a missing category, a zero amount, a bad
    // date, or a row that is gone or already void.
    $code    = $e->getMessage();
    $conflict = ['already_void'];
    $status  = in_array($code, $conflict, true) ? 409 : ($code === 'not_found' ? 404 : 422);
    okv_error(Expenses::message($code), $status, $code);
} catch (Throwable $e) {
    error_log('expenses ' . $action . ' failed: ' . $e->getMessage());
    okv_error('We could not complete that action. Please try again.', 500, 'failed');
}

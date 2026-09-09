<?php
/** Business-customer CRUD for reusable Kitchen Lists. */
require_once __DIR__ . '/../../includes/bootstrap.php';

function kl_wants_json(): bool
{
    return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch'
        || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
}

function kl_ok(array $payload, string $redirect): void
{
    if (kl_wants_json()) {
        okv_json(['status' => 'ok', 'redirect' => $redirect] + $payload);
    }
    okv_redirect($redirect, 303);
}

function kl_fail(string $code, string $redirect): void
{
    if (kl_wants_json()) {
        okv_error(KitchenLists::message($code), KitchenLists::statusCode($code), $code);
    }
    okv_redirect($redirect . (str_contains($redirect, '?') ? '&' : '?') . 'error=' . rawurlencode($code), 303);
}

function kl_items(): array
{
    $items = $_POST['items'] ?? [];
    if (!is_array($items)) {
        return [];
    }
    return array_values(array_filter($items, static function ($item): bool {
        if (!is_array($item)) {
            return false;
        }
        foreach (['product_id', 'item_name', 'quantity', 'unit_id', 'unit_label', 'note'] as $key) {
            if (trim((string) ($item[$key] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }));
}

if (!okv_is_post()) {
    okv_error('Use POST for this action.', 405, 'method_not_allowed');
}
if (!Csrf::validate()) {
    okv_error('Your session expired. Reload the page and try again.', 419, 'csrf_expired');
}
Customer::requireLoginApi();
if (!Customer::isBusiness()) {
    okv_error('This action is for business accounts.', 403, 'forbidden');
}

$action = okv_action();
$userId = (int) Customer::id();
$listId = (int) okv_input('list_id', 0);
$back = '/pro/kitchen_lists.php' . ($listId > 0 ? '?list=' . $listId : '');

try {
    switch ($action) {
        case 'create':
            $result = KitchenLists::create(
                $userId,
                (string) okv_input('name', ''),
                (string) okv_input('note', ''),
                kl_items()
            );
            Audit::record('kitchen_list.create', 'kitchen_run_template', (int) $result['id'], null, ['name' => $result['name']], $userId);
            kl_ok($result, '/pro/kitchen_lists.php?list=' . $result['id'] . '&saved=1');
            break;

        case 'update':
            $result = KitchenLists::update(
                $listId,
                $userId,
                (string) okv_input('name', ''),
                (string) okv_input('note', ''),
                kl_items()
            );
            Audit::record('kitchen_list.update', 'kitchen_run_template', $listId, null, ['name' => $result['name']], $userId);
            kl_ok($result, '/pro/kitchen_lists.php?list=' . $listId . '&saved=1');
            break;

        case 'delete':
            if ((string) okv_input('confirm_delete', '') !== '1') {
                throw new DomainException('confirm_delete');
            }
            KitchenLists::delete($listId, $userId);
            Audit::record('kitchen_list.delete', 'kitchen_run_template', $listId, null, null, $userId);
            kl_ok(['id' => $listId], '/pro/kitchen_lists.php?deleted=1');
            break;

        case 'save_from_run':
            $runId = (int) okv_input('run_id', 0);
            $result = KitchenLists::saveFromRun(
                $runId,
                $userId,
                (string) okv_input('name', ''),
                (string) okv_input('note', '')
            );
            Audit::record('kitchen_list.create_from_run', 'kitchen_run_template', (int) $result['id'], null, ['run_id' => $runId], $userId);
            kl_ok($result, '/pro/kitchen_lists.php?list=' . $result['id'] . '&saved=1');
            break;

        case 'start_run':
            if (KitchenLists::forRun($listId, $userId) === null) {
                throw new DomainException('not_found');
            }
            kl_ok(['id' => $listId], '/kitchen-runs.php?start=catalogue&saved_list=' . $listId);
            break;

        default:
            okv_error('That action is not available.', 400, 'unknown_action');
    }
} catch (DomainException $e) {
    kl_fail($e->getMessage(), $back);
} catch (Throwable $e) {
    error_log('kitchen_lists ' . $action . ' failed: ' . $e->getMessage());
    if (kl_wants_json()) {
        okv_error('We could not save that list. Please try again.', 500, 'failed');
    }
    okv_redirect($back . (str_contains($back, '?') ? '&' : '?') . 'error=failed', 303);
}

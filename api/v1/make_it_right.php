<?php
/** Customer and staff Make It Right actions. */
require_once __DIR__ . '/../../includes/bootstrap.php';

function issue_wants_json(): bool
{
    return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch'
        || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
}

function issue_remember_form(int $orderId, array $result): void
{
    if ($orderId < 1) {
        return;
    }
    $_SESSION['issue_form'][$orderId] = [
        'code' => (string) ($result['code'] ?? 'failed'),
        'message' => (string) ($result['message'] ?? 'We could not save that report. Please try again.'),
        'field' => (string) ($result['field'] ?? ''),
        'category' => trim((string) okv_input('category', '')),
        'description' => mb_substr(trim((string) okv_input('description', '')), 0, IssueReports::DESCRIPTION_MAX),
    ];
}

function issue_fail(array $result, int $status, int $orderId): void
{
    if (($result['code'] ?? '') === 'rate_limited') {
        header('Retry-After: ' . IssueReports::RATE_WINDOW);
    }
    if (issue_wants_json()) {
        okv_json(['status' => 'error'] + $result, $status);
    }
    issue_remember_form($orderId, $result);
    okv_redirect('/public/order.php?order=' . $orderId . '&issue_error=1#make-it-right', 303);
}

function issue_admin_finish(array $result, string $returnTo): void
{
    if (issue_wants_json()) {
        okv_json(['status' => 'ok'] + $result);
    }
    $separator = str_contains($returnTo, '?') ? '&' : '?';
    okv_redirect($returnTo . $separator . 'notice=' . rawurlencode((string) ($result['code'] ?? 'saved')), 303);
}

function issue_admin_fail(array $result, int $status, string $returnTo): void
{
    if (issue_wants_json()) {
        okv_json(['status' => 'error'] + $result, $status);
    }
    $separator = str_contains($returnTo, '?') ? '&' : '?';
    okv_redirect($returnTo . $separator . 'error=' . rawurlencode((string) ($result['code'] ?? 'failed')), 303);
}

if (!okv_is_post()) {
    header('Allow: POST');
    okv_json([
        'status' => 'error',
        'code' => 'method_not_allowed',
        'message' => 'Use the form on your order to send a report.',
    ], 405);
}
$action = okv_action();
$staffActions = ['take', 'decline', 'resolve', 'reassign'];
if (in_array($action, $staffActions, true)) {
    Rbac::requirePermission('issues.view');
    Rbac::requirePermission('issues.resolve');
    $issueId = (int) okv_input('issue_id', 0);
    $returnTo = okv_safe_path(
        (string) okv_input('return_to', ''),
        '/admin/make_it_right.php?report=' . $issueId
    );
    if (!Csrf::validate()) {
        issue_admin_fail(['code' => 'csrf_expired', 'message' => 'Your session expired. Reload the page and try again.'], 419, $returnTo);
    }
    $resolutionType = trim((string) okv_input('resolution_type', ''));
    if ($action === 'resolve' && $resolutionType === 'refund') {
        Rbac::requirePermission('payments.refund');
    }
    if ($action === 'resolve' && $resolutionType === 'credit') {
        Rbac::requirePermission('credit.grant');
    }
    if ($action === 'reassign' && !in_array('owner', Rbac::roles(), true)) {
        okv_error('Only the Owner can take over another colleague\'s report.', 403, 'forbidden');
    }
    if ($action === 'resolve' && !okv_input('confirmed', '')) {
        issue_admin_fail(['code' => 'not_confirmed', 'message' => 'Confirm the final outcome before saving it.'], 422, $returnTo);
    }
    try {
        $result = match ($action) {
            'take' => IssueReports::take(
                $issueId,
                (string) okv_input('expected_status', ''),
                (int) Rbac::userId()
            ),
            'decline' => IssueReports::decline(
                $issueId,
                (string) okv_input('expected_status', ''),
                (int) Rbac::userId(),
                (string) okv_input('resolution_note', '')
            ),
            'reassign' => IssueResolutions::reassignToOwner(
                $issueId,
                (string) okv_input('expected_status', ''),
                (int) Rbac::userId()
            ),
            'resolve' => IssueResolutions::resolve(
                $issueId,
                (string) okv_input('expected_status', ''),
                (int) Rbac::userId(),
                $resolutionType,
                (string) okv_input('resolution_note', ''),
                is_array($_POST['item_ids'] ?? null) ? $_POST['item_ids'] : [],
                Money::toSubunit((string) okv_input('amount', '')),
                (int) okv_input('transaction_id', 0),
                (string) okv_input('replacement_order_number', '')
            ),
        };
    } catch (Throwable $e) {
        error_log('make_it_right.' . $action . ' failed: ' . $e->getMessage());
        issue_admin_fail(['code' => 'failed', 'message' => 'We could not update that report. Reload it and try again.'], 500, $returnTo);
    }
    if (empty($result['ok'])) {
        $status = match ((string) ($result['code'] ?? '')) {
            'not_found' => 404,
            'stale', 'terminal', 'not_handler', 'resolution_in_progress', 'replacement_used' => 409,
            'credit_not_available' => 409,
            default => 422,
        };
        issue_admin_fail($result, $status, $returnTo);
    }
    if (($result['code'] ?? '') === 'declined' || ($result['code'] ?? '') === 'resolved') {
        Notifications::announceIssueReportResolved($issueId, (int) Rbac::userId());
    }
    if (!empty($result['refund_result']) && is_array($result['refund_result'])) {
        Notifications::announceRefund($result['refund_result']);
    }
    issue_admin_finish($result, $returnTo);
}

if ($action !== 'report') {
    issue_fail(['code' => 'unknown_action', 'message' => 'That report action is not available.'], 400, 0);
}
Customer::requireLoginApi();
$orderId = (int) okv_input('order_id', 0);
if (!Csrf::validate()) {
    issue_fail(['code' => 'csrf_expired', 'message' => 'Your session expired. Reload the page and try again.'], 419, $orderId);
}

try {
    $result = IssueReports::submit(
        $orderId,
        (int) Customer::id(),
        (string) okv_input('category', ''),
        (string) okv_input('description', ''),
        is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : null,
        IssueReports::normalisePhotoUpload(is_array($_FILES['photos'] ?? null) ? $_FILES['photos'] : [])
    );
} catch (Throwable $e) {
    error_log('make_it_right.report failed: ' . $e->getMessage());
    issue_fail(['code' => 'failed', 'message' => 'We could not save your report. Please try again.'], 500, $orderId);
}

if (empty($result['ok'])) {
    $status = match ((string) ($result['code'] ?? '')) {
        'not_found' => 404,
        'rate_limited' => 429,
        'already_open' => 409,
        default => 422,
    };
    issue_fail($result, $status, $orderId);
}

unset($_SESSION['issue_form'][$orderId]);
if (($result['code'] ?? '') === 'reported') {
    // The service has committed both the report and its audit row. Notification
    // failure is recorded by the dispatcher and can never remove the report.
    Notifications::announceIssueReportReceived((int) $result['issue_id']);
}

if (issue_wants_json()) {
    okv_json([
        'status' => 'ok',
        'code' => (string) $result['code'],
        'message' => ($result['code'] ?? '') === 'already_open'
            ? 'We already have an open report for order ' . ($result['order_number'] ?? '') . '.'
            : 'We received your report for order ' . ($result['order_number'] ?? '') . '.',
        'order_number' => (string) ($result['order_number'] ?? ''),
        'photo_count' => (int) ($result['photo_count'] ?? 0),
    ], ($result['code'] ?? '') === 'reported' ? 201 : 200);
}

$notice = ($result['code'] ?? '') === 'already_open' ? 'already_open' : 'reported';
okv_redirect('/public/order.php?order=' . $orderId . '&issue=' . $notice . '#make-it-right', 303);

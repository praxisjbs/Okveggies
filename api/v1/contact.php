<?php
/** Public contact-form submission controller. */
require_once __DIR__ . '/../../includes/bootstrap.php';

function contact_wants_json(): bool
{
    return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch'
        || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
}

function contact_fail(array $result, int $status): void
{
    if (($result['code'] ?? '') === 'rate_limited') {
        header('Retry-After: 900');
    }
    if (contact_wants_json()) {
        okv_json(['status' => 'error'] + $result, $status);
    }
    okv_redirect('/contact.php?error=' . rawurlencode((string) ($result['code'] ?? 'failed')), 303);
}

function contact_admin_finish(array $result, string $returnTo): void
{
    if (contact_wants_json()) {
        okv_json(['status' => 'ok'] + $result);
    }
    $separator = str_contains($returnTo, '?') ? '&' : '?';
    okv_redirect($returnTo . $separator . 'notice=' . rawurlencode((string) ($result['code'] ?? 'saved')), 303);
}

function contact_admin_fail(array $result, int $status, string $returnTo): void
{
    if (contact_wants_json()) {
        okv_json(['status' => 'error'] + $result, $status);
    }
    $separator = str_contains($returnTo, '?') ? '&' : '?';
    okv_redirect($returnTo . $separator . 'error=' . rawurlencode((string) ($result['code'] ?? 'failed')), 303);
}

$action = okv_action();
$adminActions = ['save_note', 'handle', 'reopen'];
if (in_array($action, $adminActions, true)) {
    if (!okv_is_post()) {
        okv_error('Use POST for this action.', 405, 'method_not_allowed');
    }
    Rbac::requirePermission('messages.handle');
    if (!Csrf::validate()) {
        okv_error('Your session expired. Reload the page and try again.', 419, 'csrf_expired');
    }
    $messageId = (int) okv_input('message_id', 0);
    $returnTo = okv_safe_path((string) okv_input('return_to', ''), '/admin/content.php?message=' . $messageId);
    try {
        $result = match ($action) {
            'save_note' => ContactMessages::saveNote(
                $messageId,
                (string) okv_input('admin_note', ''),
                (string) okv_input('expected_note', ''),
                (int) Rbac::userId()
            ),
            'handle' => ContactMessages::handle(
                $messageId,
                (string) okv_input('expected_status', ''),
                (int) Rbac::userId()
            ),
            'reopen' => ContactMessages::reopen(
                $messageId,
                (string) okv_input('expected_status', ''),
                (int) Rbac::userId()
            ),
        };
    } catch (Throwable $e) {
        error_log('contact admin ' . $action . ' failed: ' . $e->getMessage());
        contact_admin_fail(['code' => 'failed', 'message' => 'We could not update that message. Please try again.'], 500, $returnTo);
    }
    if (empty($result['ok'])) {
        $status = ($result['code'] ?? '') === 'not_found' ? 404
            : (($result['code'] ?? '') === 'stale' ? 409 : 422);
        contact_admin_fail($result, $status, $returnTo);
    }
    contact_admin_finish($result, $returnTo);
}

if (!okv_is_post()) {
    contact_fail(['code' => 'method_not_allowed', 'message' => 'Use the contact form to send a message.'], 405);
}
if ($action !== 'submit') {
    contact_fail(['code' => 'unknown_action', 'message' => 'That contact action is not available.'], 400);
}
if (!Csrf::validate()) {
    contact_fail(['code' => 'csrf_expired', 'message' => 'Your session expired. Reload the page and try again.'], 419);
}

try {
    $result = ContactMessages::submit($_POST, Customer::id());
} catch (Throwable $e) {
    error_log('contact.submit failed: ' . $e->getMessage());
    contact_fail(['code' => 'failed', 'message' => 'We could not save your message. Please try again.'], 500);
}

if (empty($result['ok'])) {
    $status = ($result['code'] ?? '') === 'rate_limited' ? 429
        : (($result['code'] ?? '') === 'duplicate' ? 409 : 422);
    contact_fail($result, $status);
}

// The row and its audit record are committed. A mail failure is recorded by
// Notifications and cannot remove the message the team needs to answer.
Notifications::announceContactMessage((int) $result['message_id']);

if (contact_wants_json()) {
    okv_json([
        'status' => 'ok',
        'code' => 'submitted',
        'message' => 'We have your message. A member of our team will reply using the details you provided.',
        'message_id' => (int) $result['message_id'],
    ], 201);
}
okv_redirect('/contact.php?sent=1', 303);

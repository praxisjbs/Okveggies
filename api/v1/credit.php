<?php
/**
 * api/v1/credit.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The one controller for credit: a business applying for it, and
 * the separately permissioned staff actions on it.
 *
 * Every action is POST with a CSRF token. The customer action asks for a
 * business account; each staff action asks for its own seeded permission,
 * because approving an application, opening a facility and changing a limit are
 * three different levels of trust:
 *
 *   credit.apply.review   approve, decline
 *   credit.grant          grant
 *   credit.limit.set      change_terms, suspend, withdraw, reinstate,
 *                         record_repayment
 *
 * Emails go out after the domain transaction has committed, so a mail failure
 * is logged rather than rolling back a decision that has already been made.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../../includes/bootstrap.php';

if (!okv_is_post()) {
    okv_error('Use POST for this action.', 405, 'method_not_allowed');
}
if (!Csrf::validate()) {
    okv_error('Your session expired. Reload the page and try again.', 419, 'csrf_expired');
}

$action = okv_action();

try {
    // ---- The customer action ------------------------------------------------
    if ($action === 'apply') {
        Customer::requireLoginApi();
        if (!Customer::isBusiness()) {
            okv_error('This action is for business accounts.', 403, 'forbidden');
        }

        $result = Credit::apply((int) Customer::id(), [
            'requested_days'  => okv_input('requested_days', ''),
            'requested_limit' => okv_input('requested_limit', ''),
            'reason'          => okv_input('reason', ''),
        ]);
        Audit::record(
            'credit.application.create',
            'credit_application',
            (int) $result['id'],
            null,
            ['requested_days' => $result['requested_days'], 'requested_limit_subunit' => $result['requested_limit_subunit']],
            (int) Customer::id()
        );

        try {
            Notifications::announceCreditApplicationSubmitted((int) $result['id'], (int) Customer::id());
        } catch (Throwable $e) {
            error_log('credit announce application submitted failed: ' . $e->getMessage());
        }

        credit_response(['application_status' => $result['status'], 'id' => $result['id']], '/pro/credit.php?applied=1', 201);
    }

    // ---- The staff actions --------------------------------------------------
    $permissions = [
        'approve'          => 'credit.apply.review',
        'decline'          => 'credit.apply.review',
        'grant'            => 'credit.grant',
        'change_terms'     => 'credit.limit.set',
        'suspend'          => 'credit.limit.set',
        'withdraw'         => 'credit.limit.set',
        'reinstate'        => 'credit.limit.set',
        'record_repayment' => 'credit.limit.set',
    ];
    if (!isset($permissions[$action])) {
        okv_error('That action is not available.', 400, 'unknown_action');
    }
    Rbac::requirePermission($permissions[$action]);

    $staffId  = (int) Rbac::userId();
    $entityId = 0;
    $result   = [];

    if ($action === 'approve') {
        $entityId = (int) okv_input('application_id', 0);
        $result   = Credit::approveApplication($entityId, $staffId, okv_input('credit_days', ''), okv_input('credit_limit', ''));
    } elseif ($action === 'decline') {
        $entityId = (int) okv_input('application_id', 0);
        $result   = Credit::declineApplication($entityId, $staffId, (string) okv_input('decision_reason', ''));
    } elseif ($action === 'grant') {
        $entityId = (int) okv_input('business_id', 0);
        $result   = Credit::grant($entityId, $staffId, okv_input('credit_days', ''), okv_input('credit_limit', ''));
    } elseif ($action === 'change_terms') {
        $entityId = (int) okv_input('business_id', 0);
        Credit::changeTerms($entityId, okv_input('credit_days', ''), okv_input('credit_limit', ''));
    } elseif (in_array($action, ['suspend', 'withdraw', 'reinstate'], true)) {
        $entityId = (int) okv_input('business_id', 0);
        Credit::transitionFacility($entityId, ['suspend' => 'suspended', 'withdraw' => 'withdrawn', 'reinstate' => 'approved'][$action]);
    } else {
        $entityId = (int) okv_input('business_id', 0);
        $result   = Credit::recordRepayment($entityId, (int) okv_input('payment_id', 0));
    }

    $isApplicationAction = $action === 'approve' || $action === 'decline';
    Audit::record(
        'credit.' . $action,
        $isApplicationAction ? 'credit_application' : 'business_customer',
        $entityId,
        null,
        $result ?: null,
        $staffId
    );

    // Credit emails go out only after the transaction has committed, so a mail
    // failure never rolls back an approval, a decline or a grant.
    try {
        if ($action === 'approve' && empty($result['already'])) {
            Notifications::announceCreditApproved($entityId, $staffId);
        } elseif ($action === 'decline' && empty($result['already'])) {
            Notifications::announceCreditDeclined($entityId, $staffId);
        } elseif ($action === 'grant' && empty($result['already'])) {
            Notifications::announceCreditGranted($entityId, $staffId);
        }
    } catch (Throwable $e) {
        error_log('credit announce ' . $action . ' failed: ' . $e->getMessage());
    }

    credit_response(
        $result,
        '/admin/credit.php?' . ($isApplicationAction ? 'application=' : 'business=') . $entityId . '&saved=1'
    );

} catch (DomainException $e) {
    // A conflict is a state we refuse to overwrite; anything else the customer
    // or staff member typed is a 422 they can correct.
    $code = $e->getMessage();
    $conflicts = ['conflicting_review', 'closed_application', 'repayment_recorded', 'active_application', 'active_credit'];
    $status = in_array($code, $conflicts, true) ? 409 : ($code === 'not_found' ? 404 : 422);
    credit_failure($code, $status, $action);
} catch (Throwable $e) {
    // The message never reaches the client. It goes to the log, and the caller
    // gets a plain sentence.
    error_log('credit ' . $action . ' failed: ' . $e->getMessage());
    credit_failure('failed', 500, $action);
}

/** Whether the caller wants JSON back rather than a redirect. */
function credit_is_json(): bool
{
    return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch'
        || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
}

/** Answer a fetch caller in JSON, and a plain form post with a redirect. */
function credit_response(array $data, string $redirect, int $status = 200): void
{
    if (credit_is_json()) {
        okv_json(['status' => 'ok', 'redirect' => $redirect] + $data, $status);
    }
    okv_redirect($redirect, 303);
}

/** The same refusal, in whichever form the caller asked for. */
function credit_failure(string $code, int $status, string $action): void
{
    $message = $code === 'failed'
        ? 'We could not save that credit change. Please try again.'
        : Credit::message($code);

    if (credit_is_json()) {
        okv_error($message, $status, $code);
    }
    $base = $action === 'apply' ? '/pro/credit.php' : '/admin/credit.php';
    okv_redirect($base . '?error=' . rawurlencode($code), 303);
}

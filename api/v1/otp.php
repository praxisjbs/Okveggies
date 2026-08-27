<?php
/**
 * api/v1/otp.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Account activation codes for the signed-in customer: ask for a
 * code (or a fresh one), and verify it. Built in milestone M1 Part 2. See
 * docs/PRD.md Section 10.
 *
 * The code is issued and emailed for the customer in the current session, so the
 * identifier comes from the session, never from the request. That means one
 * person can only ever activate their own account. Requests are rate limited by
 * identifier with a short resend cooldown. Verifying a code marks the account
 * activated (users.email_verified_at), which is what a pay-on-delivery order
 * checks for later.
 *
 * Activation is not required to sign in or to shop, so this never blocks the
 * rest of the account area. A fetch gets JSON; a plain form post gets a real
 * redirect. If the email cannot be sent we say so plainly, never a silent
 * success.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../../includes/bootstrap.php';

if (!function_exists('otp_wants_json')) {
    function otp_wants_json(): bool
    {
        $xrw    = trim($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
        $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
        return $xrw !== '' || strpos($accept, 'application/json') !== false;
    }
}

if (!function_exists('otp_respond')) {
    /** JSON for a fetch, a real redirect for a plain form post. */
    function otp_respond(bool $ok, string $message, int $code, string $errorCode, string $redirect, array $extra = []): void
    {
        if (otp_wants_json()) {
            $payload = $ok
                ? (['status' => 'ok', 'message' => $message] + $extra)
                : (['status' => 'error', 'code' => $errorCode, 'message' => $message] + $extra);
            okv_json($payload, $code);
        }
        $to = $ok ? $redirect : '/public/auth/activate.php?error=' . rawurlencode($errorCode);
        okv_redirect($to);
    }
}

$action = okv_action();

if (!in_array($action, ['request', 'verify'], true)) {
    otp_respond(false, 'This action is not available.', 400, 'unknown_action', '/public/auth/activate.php');
}
if (!okv_is_post()) {
    otp_respond(false, 'Use POST for this action.', 405, 'method_not_allowed', '/public/auth/activate.php');
}
if (!Csrf::validate()) {
    otp_respond(false, 'Your session expired. Reload the page and try again.', 419, 'csrf_expired', '/public/auth/activate.php');
}
if (!Customer::isLoggedIn()) {
    otp_respond(false, 'Please sign in first.', 401, 'unauthenticated', '/account.php?mode=signin');
}

$user = Customer::current();
if (!$user) {
    otp_respond(false, 'Please sign in first.', 401, 'unauthenticated', '/account.php?mode=signin');
}
$email = (string) $user['email'];
$name  = (string) ($user['first_name'] ?? '');

// Already active? Nothing to do, and say so calmly.
if (!empty($user['email_verified_at'])) {
    Customer::markActivated();
    otp_respond(true, 'Your account is already active.', 200, '', '/account.php', ['activated' => true]);
}

switch ($action) {

    case 'request': {
        $cool   = 'otp:activate:cool:' . sha1($email);
        $window = 'otp:activate:win:'  . sha1($email);

        if (RateLimiter::isLocked($cool, 1)) {
            $wait = RateLimiter::retryAfter($cool);
            if ($wait > 0 && otp_wants_json()) {
                header('Retry-After: ' . $wait);
            }
            otp_respond(false, 'Please wait a moment before asking for another code.', 429, 'cooldown', '/public/auth/activate.php', ['retry_after' => $wait]);
        }
        if (!RateLimiter::hit($window, (int) env('OTP_MAX_PER_IDENTIFIER', 5), (int) env('OTP_WINDOW_SECONDS', 900))) {
            otp_respond(false, 'Too many code requests. Wait a few minutes and try again.', 429, 'rate_limited', '/public/auth/activate.php');
        }
        RateLimiter::hit($cool, 1, (int) env('OTP_RESEND_COOLDOWN_SECONDS', 60));

        $sent = okv_send_account_code($email, $name, 'account_activation', 'account_activation', 'activate_url', '/public/auth/activate.php', (int) $user['id']);
        if (!$sent) {
            otp_respond(false, 'We could not send the code right now. Please try again in a moment.', 502, 'mail_failed', '/public/auth/activate.php');
        }
        otp_respond(true, 'We have sent a new code to your email.', 200, '', '/public/auth/activate.php?sent=1', ['sent' => true, 'cooldown' => (int) env('OTP_RESEND_COOLDOWN_SECONDS', 60)]);
        break;
    }

    case 'verify': {
        $code = trim((string) okv_input('code', ''));
        if ($code === '') {
            otp_respond(false, 'Enter the 6 digit code we sent you.', 422, 'missing_code', '/public/auth/activate.php');
        }
        if (!RateLimiter::hit('otp:activate:verify:' . sha1($email), 10, (int) env('OTP_WINDOW_SECONDS', 900))) {
            otp_respond(false, 'Too many tries. Wait a few minutes and try again.', 429, 'rate_limited', '/public/auth/activate.php');
        }
        if (!Otp::verify($email, 'account_activation', $code)) {
            otp_respond(false, 'That code is not right or has expired. Ask for a new one.', 422, 'bad_code', '/public/auth/activate.php');
        }

        Database::run('UPDATE users SET email_verified_at = NOW() WHERE id = :id AND email_verified_at IS NULL', [':id' => (int) $user['id']]);
        Customer::markActivated();

        otp_respond(true, 'Your account is active. Thank you.', 200, '', '/account.php?activated=1', ['activated' => true, 'redirect' => '/account.php?activated=1']);
        break;
    }
}

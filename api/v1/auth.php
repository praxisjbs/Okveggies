<?php
/**
 * api/v1/auth.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Sign in and out, customer registration, the signed-in password
 * change, and password reset by email code. Built in milestone M1 (staff in
 * Part 1, customers in Part 2). See docs/PRD.md Section 10 and CLAUDE.md.
 *
 * Actions (all POST, all CSRF checked):
 *   login            phone or email plus password. Rate limited by IP and by
 *                    identifier. Regenerates the session, then sends staff to
 *                    /admin, business customers to /pro, households to /.
 *   logout           destroys the session.
 *   register         creates a household or business customer, signs them in,
 *                    and sends the activation code.
 *   change_password  a signed-in staff member sets a new password for themselves.
 *   forgot_password  emails a reset code (says nothing about whether the email
 *                    is registered).
 *   reset_password   verifies the reset code and sets a new password.
 *
 * A form works with or without JavaScript. A fetch request (the normal path)
 * gets JSON with a redirect field. A plain form post gets a real 302. We never
 * put an exception message in front of the person; the log carries the detail.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../../includes/bootstrap.php';

/** A constant hash so an unknown identifier costs the same time as a real one. */
const AUTH_DUMMY_HASH = '$2y$12$t/krnpkJ.HCVfo4qoNCEB.EDDOIhAn8YSk2kUHTHM2yb8w517ZEcS';

if (!function_exists('auth_wants_json')) {
    /** True when the caller is a fetch or an API client, not a plain form post. */
    function auth_wants_json(): bool
    {
        // Any XHR marker (our OKV.fetch sends "fetch", others send
        // "xmlhttprequest"), or an explicit JSON Accept, means a script client.
        $xrw    = trim($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
        $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
        return $xrw !== '' || strpos($accept, 'application/json') !== false;
    }
}

if (!function_exists('auth_client_ip')) {
    /** The connecting address. REMOTE_ADDR only, because it cannot be spoofed. */
    function auth_client_ip(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return is_string($ip) && $ip !== '' ? $ip : '0.0.0.0';
    }
}

if (!function_exists('auth_respond')) {
    /**
     * Answer the caller. JSON for a fetch, a redirect for a plain form post.
     * $extra can carry a JSON payload (for example the login redirect target).
     */
    function auth_respond(bool $ok, string $message, int $code, string $errorCode, string $redirect, array $extra = []): void
    {
        if (auth_wants_json()) {
            $payload = $ok
                ? (['status' => 'ok', 'message' => $message] + $extra)
                : (['status' => 'error', 'code' => $errorCode, 'message' => $message] + $extra);
            okv_json($payload, $code);
        }
        // No-JS fallback: a real redirect. On success go to the target; on
        // failure go back to the form with a short, non-revealing marker.
        $to = $ok ? $redirect : $redirect . (strpos($redirect, '?') === false ? '?' : '&') . 'error=' . rawurlencode($errorCode);
        okv_redirect($to);
    }
}

$action     = okv_action();
$context    = (string) okv_input('context', '');
$storefront = ($context === 'storefront');

// Only these actions exist here, and every one is a state change.
$allowed = ['login', 'logout', 'register', 'change_password', 'forgot_password', 'reset_password'];
if (!in_array($action, $allowed, true)) {
    okv_error('This action is not available.', 400, 'unknown_action');
}
if (!okv_is_post()) {
    okv_error('Use POST for this action.', 405, 'method_not_allowed');
}
if (!Csrf::validate()) {
    switch ($action) {
        case 'change_password':                 $back = '/admin/account.php'; break;
        case 'register':                        $back = '/account.php?mode=register'; break;
        case 'forgot_password':
        case 'reset_password':                  $back = '/public/auth/password_reset.php'; break;
        case 'login':                           $back = $storefront ? '/account.php?mode=signin' : '/admin/login.php'; break;
        default:                                $back = $storefront ? '/account.php' : '/admin/login.php';
    }
    auth_respond(false, 'Your session expired. Reload the page and try again.', 419, 'csrf_expired', $back);
}

switch ($action) {

    case 'login': {
        $identifier = trim((string) okv_input('identifier', ''));
        $password   = (string) okv_input('password', '');
        $loginBack  = $storefront ? '/account.php?mode=signin' : '/admin/login.php';

        if ($identifier === '' || $password === '') {
            auth_respond(false, 'Enter your phone or email and your password.', 422, 'missing_fields', $loginBack);
        }

        // Throttle by IP and by identifier. Read the window and limits from .env.
        $ip       = auth_client_ip();
        $window   = (int) env('LOGIN_WINDOW_SECONDS', 900);
        $maxId    = (int) env('LOGIN_MAX_PER_IDENTIFIER', 5);
        $maxIp    = (int) env('LOGIN_MAX_PER_IP', 20);
        $ipBucket = 'login:ip:' . $ip;
        $idBucket = 'login:id:' . Auth::rateBucket($identifier); // normalised, hashed

        if (RateLimiter::isLocked($idBucket, $maxId) || RateLimiter::isLocked($ipBucket, $maxIp)) {
            $wait = max(RateLimiter::retryAfter($idBucket), RateLimiter::retryAfter($ipBucket));
            if ($wait > 0 && auth_wants_json()) {
                header('Retry-After: ' . $wait);
            }
            auth_respond(false, 'Too many tries. Wait a few minutes and try again.', 429, 'rate_limited', $loginBack);
        }

        // One lookup for phone or email, normalised the same way registration stored it.
        $user  = Auth::findByIdentifier($identifier);
        $hash  = $user['password_hash'] ?? AUTH_DUMMY_HASH;
        $valid = Password::verify($password, $hash); // runs even with no user, for constant time

        if (!$user || !$valid) {
            RateLimiter::hit($idBucket, $maxId, $window);
            RateLimiter::hit($ipBucket, $maxIp, $window);
            auth_respond(false, 'We could not sign you in. Check your details and try again.', 401, 'invalid_credentials', $loginBack);
        }

        if (($user['status'] ?? '') !== 'active') {
            $msg = $storefront
                ? 'This account is not active. Please contact us so we can help.'
                : 'This account is not active. Ask the owner to switch it back on.';
            auth_respond(false, $msg, 403, 'inactive', $loginBack);
        }

        // Success. Clear the identifier counter, keep the IP counter as a signal.
        RateLimiter::reset($idBucket);
        Auth::startSession($user);

        // Whatever they put in the basket as a guest comes with them. Lines the
        // account already holds at the same price add up; a line at a different
        // price keeps its own row, so a price they were given is never lost.
        // A merge that fails must never block a sign-in, so it is logged and
        // the customer carries on.
        try {
            Basket::mergeGuestCart((int) $user['id']);
        } catch (Throwable $e) {
            error_log('login: basket merge failed: ' . $e->getMessage());
        }

        try {
            Database::run('UPDATE users SET last_login_at = NOW() WHERE id = :id', [':id' => (int) $user['id']]);
            // Transparently upgrade an older hash to the current cost.
            if (Password::needsRehash((string) $user['password_hash'])) {
                Database::run('UPDATE users SET password_hash = :h WHERE id = :id', [':h' => Password::hash($password), ':id' => (int) $user['id']]);
            }
        } catch (Throwable $e) {
            error_log('login: post-login update failed: ' . $e->getMessage());
        }

        $dest = Auth::landingPath($user);
        auth_respond(true, 'Signed in.', 200, '', $dest, ['redirect' => $dest]);
        break;
    }

    case 'register': {
        $back    = '/account.php?mode=register';
        $first   = trim((string) okv_input('first_name', ''));
        $last    = trim((string) okv_input('last_name', ''));
        $email   = strtolower(trim((string) okv_input('email', '')));
        $phoneIn = trim((string) okv_input('phone', ''));
        $pass    = (string) okv_input('password', '');
        $type    = (string) okv_input('account_type', 'household');
        if (!in_array($type, ['household', 'business'], true)) {
            $type = 'household';
        }
        $businessName = trim((string) okv_input('business_name', ''));
        $businessType = trim((string) okv_input('business_type', ''));
        $wantsCredit  = in_array(strtolower((string) okv_input('request_credit', '')), ['1', 'true', 'on', 'yes'], true);

        if ($first === '' || $last === '') {
            auth_respond(false, 'Enter your first and last name.', 422, 'missing_name', $back);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            auth_respond(false, 'Enter a valid email address.', 422, 'bad_email', $back);
        }
        $phone = Phone::normalize($phoneIn);
        if ($phone === null) {
            auth_respond(false, 'Enter a valid phone number, for example 0803 000 0000.', 422, 'bad_phone', $back);
        }
        $policy = Password::policyError($pass, $email, $phone);
        if ($policy !== null) {
            auth_respond(false, $policy, 422, 'weak_password', $back);
        }
        if ($type === 'business' && $businessName === '') {
            auth_respond(false, 'Enter your business name.', 422, 'missing_business', $back);
        }

        // Already registered? Tell them plainly and offer sign in, but reveal
        // nothing beyond the email they just typed (which they already know).
        if (Database::one('SELECT id FROM users WHERE email = :e OR phone = :p LIMIT 1', [':e' => $email, ':p' => $phone])) {
            auth_respond(false, 'You already have an account with these details. Please sign in.', 409, 'account_exists', $back, ['prefill' => $email]);
        }

        $pdo = Database::getInstance()->getConnection();
        try {
            $pdo->beginTransaction();
            $pdo->prepare(
                "INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at)
                 VALUES (:fn, :ln, :em, :ph, :pw, :ut, 'active', NULL)"
            )->execute([
                ':fn' => $first, ':ln' => $last, ':em' => $email, ':ph' => $phone,
                ':pw' => Password::hash($pass), ':ut' => $type,
            ]);
            $newId = (int) $pdo->lastInsertId();

            if ($type === 'business') {
                $pdo->prepare(
                    "INSERT INTO business_customers (user_id, business_name, business_type, contact_person, credit_requested, credit_status)
                     VALUES (:u, :bn, :bt, :cp, :cr, :cs)"
                )->execute([
                    ':u'  => $newId,
                    ':bn' => $businessName,
                    ':bt' => ($businessType !== '' ? $businessType : null),
                    ':cp' => $first . ' ' . $last,
                    ':cr' => $wantsCredit ? 1 : 0,
                    ':cs' => $wantsCredit ? 'requested' : 'not_requested',
                ]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('register failed: ' . $e->getMessage());
            auth_respond(false, 'We could not create your account. Please try again.', 500, 'register_failed', $back);
        }

        // Sign them in now. Activation happens next and only gates a
        // pay-on-delivery order later, never sign in or browsing.
        $userRow = Database::one('SELECT id, user_type, email_verified_at, first_name FROM users WHERE id = :id', [':id' => $newId]);
        Auth::startSession($userRow);

        // A guest who filled a basket and then registered keeps that basket.
        try {
            Basket::mergeGuestCart($newId);
        } catch (Throwable $e) {
            error_log('register: basket merge failed: ' . $e->getMessage());
        }

        // Issue and send the activation code. If it cannot be sent we say so
        // plainly rather than pretend it went out.
        $sent = okv_send_account_code($email, $first, 'account_activation', 'account_activation', 'activate_url', '/public/auth/activate.php', $newId);

        auth_respond(
            true,
            'Your account is ready.',
            201,
            '',
            '/account.php',
            ['redirect' => '/account.php', 'activation_email_sent' => $sent]
        );
        break;
    }

    case 'logout': {
        Auth::logout();
        $dest = $storefront ? '/' : '/admin/login.php';
        auth_respond(true, 'Signed out.', 200, '', $dest, ['redirect' => $dest]);
        break;
    }

    case 'change_password': {
        // Must be a signed-in staff member changing their own password.
        if (!Rbac::isLoggedIn() || !Rbac::isStaff()) {
            auth_respond(false, 'Please sign in first.', 401, 'unauthenticated', '/admin/login.php');
        }

        $current = (string) okv_input('current_password', '');
        $new     = (string) okv_input('new_password', '');
        $confirm = (string) okv_input('confirm_password', '');
        $userId  = (int) Rbac::userId();

        $row = Database::one('SELECT email, phone, password_hash FROM users WHERE id = :id', [':id' => $userId]);
        if (!$row) {
            auth_respond(false, 'Please sign in first.', 401, 'unauthenticated', '/admin/login.php');
        }
        if (!Password::verify($current, $row['password_hash'] ?? '')) {
            auth_respond(false, 'Your current password is not right.', 403, 'wrong_current', '/admin/account.php');
        }
        if ($new !== $confirm) {
            auth_respond(false, 'The two new passwords do not match.', 422, 'mismatch', '/admin/account.php');
        }
        $policy = Password::policyError($new, (string) $row['email'], (string) $row['phone']);
        if ($policy !== null) {
            auth_respond(false, $policy, 422, 'weak_password', '/admin/account.php');
        }

        Database::run('UPDATE users SET password_hash = :h WHERE id = :id', [':h' => Password::hash($new), ':id' => $userId]);
        session_regenerate_id(true); // fresh id after a credential change

        auth_respond(true, 'Your password is changed.', 200, '', '/admin/account.php?changed=1', ['redirect' => '/admin/account.php?changed=1']);
        break;
    }

    case 'forgot_password': {
        $back  = '/public/auth/password_reset.php';
        $email = strtolower(trim((string) okv_input('email', '')));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            auth_respond(false, 'Enter a valid email address.', 422, 'bad_email', $back);
        }

        // The same friendly answer whether or not the email is registered, so
        // this page never confirms who has an account.
        $done = function () use ($back) {
            auth_respond(
                true,
                'If that email is registered, we have sent a reset code. Check your inbox.',
                200,
                '',
                $back . '?step=code&sent=1',
                ['step' => 'code']
            );
        };

        $cool   = 'pwreset:cool:' . sha1($email);
        $window = 'pwreset:win:' . sha1($email);
        if (RateLimiter::isLocked($cool, 1)) {
            $done();
        }
        if (!RateLimiter::hit($window, (int) env('OTP_MAX_PER_IDENTIFIER', 5), (int) env('OTP_WINDOW_SECONDS', 900))) {
            $done();
        }
        RateLimiter::hit($cool, 1, (int) env('OTP_RESEND_COOLDOWN_SECONDS', 60));

        $user = Database::one(
            "SELECT id, first_name FROM users WHERE email = :e AND user_type IN ('household', 'business') LIMIT 1",
            [':e' => $email]
        );
        if ($user) {
            okv_send_account_code($email, (string) $user['first_name'], 'password_reset', 'password_reset', 'reset_url', '/public/auth/password_reset.php', (int) $user['id']);
        }
        $done();
        break;
    }

    case 'reset_password': {
        $back    = '/public/auth/password_reset.php?step=code';
        $email   = strtolower(trim((string) okv_input('email', '')));
        $code    = trim((string) okv_input('code', ''));
        $new     = (string) okv_input('new_password', '');
        $confirm = (string) okv_input('confirm_password', '');

        if ($email === '' || $code === '') {
            auth_respond(false, 'Enter the code we sent and your new password.', 422, 'missing_fields', $back);
        }
        // Limit verify attempts by email. The per-code attempt cap applies too.
        if (!RateLimiter::hit('pwreset:verify:' . sha1($email), 10, (int) env('OTP_WINDOW_SECONDS', 900))) {
            auth_respond(false, 'Too many tries. Wait a few minutes and try again.', 429, 'rate_limited', $back);
        }
        if ($new !== $confirm) {
            auth_respond(false, 'The two new passwords do not match.', 422, 'mismatch', $back);
        }

        $user   = Database::one("SELECT id, email, phone FROM users WHERE email = :e AND user_type IN ('household', 'business') LIMIT 1", [':e' => $email]);
        $policy = Password::policyError($new, $email, (string) ($user['phone'] ?? ''));
        if ($policy !== null) {
            auth_respond(false, $policy, 422, 'weak_password', $back);
        }
        // Verify (and consume) the code last, so a weak password never burns it.
        if (!$user || !Otp::verify($email, 'password_reset', $code)) {
            auth_respond(false, 'That code is not right or has expired. Ask for a new one.', 422, 'bad_code', $back);
        }

        Database::run('UPDATE users SET password_hash = :h WHERE id = :id', [':h' => Password::hash($new), ':id' => (int) $user['id']]);
        auth_respond(true, 'Your password is changed. Please sign in.', 200, '', '/account.php?mode=signin&reset=1', ['redirect' => '/account.php?mode=signin&reset=1']);
        break;
    }
}

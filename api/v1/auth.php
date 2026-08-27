<?php
/**
 * api/v1/auth.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Staff sign in and out, and the signed-in password change.
 * Built in milestone M1. See docs/PRD.md Section 10 and CLAUDE.md.
 *
 * Actions (all POST, all CSRF checked):
 *   login            phone or email plus password. Rate limited by IP and by
 *                    identifier. On success it regenerates the session id, loads
 *                    the RBAC set, and returns where to go next.
 *   logout           destroys the session.
 *   change_password  a signed-in staff member sets a new password for themselves.
 *
 * The login form works with or without JavaScript. A fetch request (the normal
 * path) gets JSON with a redirect field. A plain form post gets a real 302, so
 * the page still works if the script does not load. We never put an exception
 * message in front of the person; the log carries the detail.
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
                : ['status' => 'error', 'code' => $errorCode, 'message' => $message];
            okv_json($payload, $code);
        }
        // No-JS fallback: a real redirect. On success go to the target; on
        // failure go back to the form with a short, non-revealing marker.
        $to = $ok ? $redirect : $redirect . (strpos($redirect, '?') === false ? '?' : '&') . 'error=' . rawurlencode($errorCode);
        okv_redirect($to);
    }
}

$action = okv_action();

// Only these actions exist here, and every one is a state change.
if (!in_array($action, ['login', 'logout', 'change_password'], true)) {
    okv_error('This action is not available.', 400, 'unknown_action');
}
if (!okv_is_post()) {
    okv_error('Use POST for this action.', 405, 'method_not_allowed');
}
if (!Csrf::validate()) {
    $back = $action === 'change_password' ? '/admin/account.php' : '/admin/login.php';
    auth_respond(false, 'Your session expired. Reload the page and try again.', 419, 'csrf_expired', $back);
}

switch ($action) {

    case 'login': {
        $identifier = trim((string) okv_input('identifier', ''));
        $password   = (string) okv_input('password', '');

        if ($identifier === '' || $password === '') {
            auth_respond(false, 'Enter your phone or email and your password.', 422, 'missing_fields', '/admin/login.php');
        }

        // Throttle by IP and by identifier. Read the window and limits from .env.
        $ip        = auth_client_ip();
        $window    = (int) env('LOGIN_WINDOW_SECONDS', 900);
        $maxId     = (int) env('LOGIN_MAX_PER_IDENTIFIER', 5);
        $maxIp     = (int) env('LOGIN_MAX_PER_IP', 20);
        $ipBucket  = 'login:ip:' . $ip;
        $idBucket  = 'login:id:' . sha1(strtolower($identifier)); // hashed: bounded and not the raw email

        if (RateLimiter::isLocked($idBucket, $maxId) || RateLimiter::isLocked($ipBucket, $maxIp)) {
            $wait = max(RateLimiter::retryAfter($idBucket), RateLimiter::retryAfter($ipBucket));
            if ($wait > 0 && auth_wants_json()) {
                header('Retry-After: ' . $wait);
            }
            auth_respond(false, 'Too many tries. Wait a few minutes and try again.', 429, 'rate_limited', '/admin/login.php');
        }

        // Look the person up by email when the identifier has an "@", else phone.
        $byEmail = strpos($identifier, '@') !== false;
        $user = Database::one(
            'SELECT id, password_hash, status FROM users WHERE ' . ($byEmail ? 'email' : 'phone') . ' = :v LIMIT 1',
            [':v' => $identifier]
        );

        $hash  = $user['password_hash'] ?? AUTH_DUMMY_HASH;
        $valid = Password::verify($password, $hash); // runs even with no user, for constant time

        if (!$user || !$valid) {
            // Count this failed attempt against both buckets.
            RateLimiter::hit($idBucket, $maxId, $window);
            RateLimiter::hit($ipBucket, $maxIp, $window);
            auth_respond(false, 'We could not sign you in. Check your details and try again.', 401, 'invalid_credentials', '/admin/login.php');
        }

        if (($user['status'] ?? '') !== 'active') {
            auth_respond(false, 'This account is not active. Ask the owner to switch it back on.', 403, 'inactive', '/admin/login.php');
        }

        // Success. Clear the identifier counter, keep the IP counter as a signal.
        RateLimiter::reset($idBucket);

        // Harden the session: a brand new id, old one deleted.
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        Rbac::loadFromDb((int) $user['id']);

        try {
            Database::run('UPDATE users SET last_login_at = NOW() WHERE id = :id', [':id' => (int) $user['id']]);
        } catch (Throwable $e) {
            error_log('login: last_login_at update failed: ' . $e->getMessage());
        }

        $dest = Rbac::isStaff() ? '/admin' : '/';
        auth_respond(true, 'Signed in.', 200, '', $dest, ['redirect' => $dest]);
        break;
    }

    case 'logout': {
        // Validate CSRF (done above), then tear the session down completely.
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
        auth_respond(true, 'Signed out.', 200, '', '/admin/login.php', ['redirect' => '/admin/login.php']);
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
}

<?php
/**
 * includes/config/db.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Database and application constants (env-driven).
 *
 * All secrets and environment-varying values live in the .env file at the
 * application root. This file defines the constants the rest of the app expects
 * (DB_HOST / DB_PORT / DB_NAME / DB_USER / DB_PASS / APP_URL, and friends),
 * sourced from env(). It also applies runtime hardening:
 *   - error_reporting / display_errors from APP_DEBUG
 *   - error_log destination outside public_html (if configured)
 *   - session cookie params (HttpOnly, Secure, SameSite) before session_start()
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

if (defined('OKV_DB_CONFIG_LOADED')) {
    return;
}

require_once __DIR__ . '/env.php';

// --- Error handling ---------------------------------------------------------
$__app_debug   = env('APP_DEBUG', false);
$__log_errors  = env('LOG_ERRORS', true);
$__display_err = env('DISPLAY_ERRORS', false);
$__error_log   = env('ERROR_LOG_PATH', null);

error_reporting(E_ALL);
ini_set('display_errors',         ($__app_debug || $__display_err) ? '1' : '0');
ini_set('display_startup_errors', ($__app_debug || $__display_err) ? '1' : '0');
ini_set('log_errors',             $__log_errors ? '1' : '0');

if (!empty($__error_log)) {
    $__error_log_dir = dirname($__error_log);
    if (is_dir($__error_log_dir) && is_writable($__error_log_dir)) {
        ini_set('error_log', $__error_log);
    }
    unset($__error_log_dir);
}

// --- Session cookie hardening (before any session_start) ---------------------
if (session_status() === PHP_SESSION_NONE) {
    $__cookie_name = env('SESSION_COOKIE_NAME', 'okv_session');
    $__lifetime    = (int) env('SESSION_LIFETIME_MINUTES', 480) * 60;

    session_name($__cookie_name);
    session_set_cookie_params([
        'lifetime' => $__lifetime,
        'path'     => '/',
        'domain'   => '',
        'secure'   => (bool) env('SESSION_COOKIE_SECURE', true),
        'httponly' => (bool) env('SESSION_COOKIE_HTTPONLY', true),
        'samesite' => (string) env('SESSION_COOKIE_SAMESITE', 'Lax'),
    ]);
    ini_set('session.gc_maxlifetime', (string) $__lifetime);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    unset($__cookie_name, $__lifetime);
}

unset($__app_debug, $__log_errors, $__display_err, $__error_log);

// --- Database constants ------------------------------------------------------
define('DB_HOST',    env('DB_HOST',    'localhost'));
define('DB_PORT',    (int) env('DB_PORT', 3306));
define('DB_NAME',    env('DB_NAME',    ''));
define('DB_USER',    env('DB_USER',    ''));
define('DB_PASS',    env('DB_PASS',    ''));
define('DB_CHARSET', env('DB_CHARSET', 'utf8mb4'));

// --- Application constants ---------------------------------------------------
define('APP_NAME',  env('APP_NAME',  'OK Veggies'));
define('APP_URL',   env('APP_URL',   'https://okveggies.com.ng'));
define('APP_ENV',   env('APP_ENV',   'production'));
define('APP_DEBUG', (bool) env('APP_DEBUG', false));
define('APP_TIMEZONE', env('APP_TIMEZONE', 'Africa/Lagos'));
define('CURRENCY',  env('CURRENCY',  'NGN'));

// --- Safety net: refuse to run without DB credentials ------------------------
if (DB_NAME === '' || DB_USER === '') {
    error_log('OKV bootstrap: DB_NAME or DB_USER is empty. Check .env at the application root.');
    http_response_code(500);
    die('Configuration error. Please contact the administrator.');
}

define('OKV_DB_CONFIG_LOADED', true);

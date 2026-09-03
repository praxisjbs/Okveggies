<?php
/**
 * includes/bootstrap.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Single include every entry point requires first.
 *
 *   require_once __DIR__ . '/includes/bootstrap.php';        // web root
 *   require_once __DIR__ . '/../../includes/bootstrap.php';  // api/v1 or a module
 *
 * It:
 *   1. Sends security response headers.
 *   2. Loads .env, DB and app constants, and hardened session cookie params.
 *   3. Starts the session.
 *   4. Loads CSRF and the core classes.
 *   5. Loads Composer's autoloader if vendor/ is populated.
 *   6. Warms the RBAC cache from the session.
 *   7. Applies the timezone and loads the shared helper functions.
 *
 * Idempotent. Safe to include more than once. Does not gate anything; each page
 * decides its own permission requirement.
 * -----------------------------------------------------------------------------
 */

if (defined('OKV_BOOTSTRAPPED')) {
    return;
}

// 0. Security response headers, sent from PHP so they do not depend on the host
//    having mod_headers loaded.
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
$__okv_https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
if ($__okv_https) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
unset($__okv_https);

// 1. Env + DB constants + error handling + session cookie params.
require_once __DIR__ . '/config/db.php';

// 2. Database singleton.
require_once __DIR__ . '/classes/Database.php';

// 3. Start the session (cookie params were set in db.php before this call).
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 4. Security primitives.
require_once __DIR__ . '/classes/Csrf.php';
require_once __DIR__ . '/classes/RateLimiter.php';
Csrf::init();

// 5. Composer autoload, if vendor/ has been populated (dompdf, PhpSpreadsheet,
//    PHPMailer). Degrades quietly during a partial deploy.
$__okv_autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_readable($__okv_autoload)) {
    require_once $__okv_autoload;
}
unset($__okv_autoload);

// 6. Core classes.
require_once __DIR__ . '/classes/Brand.php';
require_once __DIR__ . '/classes/Money.php';
require_once __DIR__ . '/classes/OrderNumber.php';
require_once __DIR__ . '/classes/Settings.php';
require_once __DIR__ . '/classes/SettingsEditor.php';
require_once __DIR__ . '/classes/Password.php';
require_once __DIR__ . '/classes/Rbac.php';
require_once __DIR__ . '/classes/Audit.php';
require_once __DIR__ . '/classes/Phone.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Customer.php';
require_once __DIR__ . '/classes/Catalogue.php';
require_once __DIR__ . '/classes/Basket.php';
require_once __DIR__ . '/classes/Pricing.php';
require_once __DIR__ . '/classes/Products.php';
require_once __DIR__ . '/classes/Combos.php';
require_once __DIR__ . '/classes/Delivery.php';
require_once __DIR__ . '/classes/OrderTrail.php';
require_once __DIR__ . '/classes/Checkout.php';
require_once __DIR__ . '/classes/PriceSheet.php';
require_once __DIR__ . '/classes/Otp.php';
require_once __DIR__ . '/classes/Mail.php';
require_once __DIR__ . '/classes/Paystack.php';
require_once __DIR__ . '/classes/Uploads.php';
// M5 payments. Every one of these is reachable from a page or an endpoint, so
// every one has to be loaded here. There is no autoloader for app classes: a
// class file that is not on this list does not exist at runtime, however green
// the unit tests are, because the test runner has its own require list.
// scripts/tests/BootstrapTest.php guards that from happening again.
require_once __DIR__ . '/classes/Payments.php';
require_once __DIR__ . '/classes/ManualPayments.php';
require_once __DIR__ . '/classes/Refunds.php';
require_once __DIR__ . '/classes/Cancellation.php';
require_once __DIR__ . '/classes/OrderDocument.php';
require_once __DIR__ . '/classes/PaymentHealth.php';

// 7. Warm the RBAC cache from the session (no DB hit unless a user is loaded).
Rbac::init();

// 8. Timezone. Africa/Lagos by default.
$__okv_tz = APP_TIMEZONE;
if ($__okv_tz && in_array($__okv_tz, timezone_identifiers_list(), true)) {
    date_default_timezone_set($__okv_tz);
}
unset($__okv_tz);

// 9. Shared helper functions and the brand head block.
require_once __DIR__ . '/functions/helpers.php';
require_once __DIR__ . '/functions/assets.php';
require_once __DIR__ . '/components/head_meta.php';

// 10. Tag the DB session with the current user id so audit columns can record
//     who made a change. Degrades quietly if the DB is not reachable.
if (Rbac::isLoggedIn()) {
    try {
        Database::getInstance()->getConnection()->exec('SET @okv_current_user_id = ' . (int) Rbac::userId());
    } catch (Throwable $e) {
        error_log('audit user tag: ' . $e->getMessage());
    }
}

define('OKV_BOOTSTRAPPED', true);

<?php
/** Delivery configuration validation, method, CSRF and RBAC over local HTTP. */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
$base = rtrim(getenv('OKV_TEST_BASE') ?: 'http://127.0.0.1:8123', '/'); $tests = 0; $passed = 0;
function dh_ok($condition, string $label): void { global $tests, $passed; $tests++; if ($condition) { $passed++; } else { fwrite(STDERR, "  FAIL: $label\n"); } }
function dh_eq($expected, $actual, string $label): void { dh_ok($expected === $actual, $label . ($expected === $actual ? '' : " (expected $expected, got $actual)")); }
function dh_req(string $jar, string $url, ?array $post = null): array { $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => ['X-Requested-With: fetch', 'Accept: application/json']]); if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); } $body = (string) curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); return [$code, $body]; }
function dh_csrf(string $jar, string $url): string { [, $body] = dh_req($jar, $url); return preg_match('/name="okv_csrf" value="([^"]+)"/', $body, $m) ? $m[1] : ''; }

$suffix = substr(bin2hex(random_bytes(5)), 0, 10); $password = 'delivery-http-777'; $userId = 0; $jar = tempnam(sys_get_temp_dir(), 'okv-dh-'); $date = date('Y-m-d', strtotime('+40 days'));
try {
    Database::run('INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at) VALUES (\'Delivery\', \'Manager\', :email, :phone, :hash, \'staff\', \'active\', NOW())', [':email' => "delivery-http-$suffix@example.test", ':phone' => '+23477' . random_int(10000000, 99999999), ':hash' => password_hash($password, PASSWORD_BCRYPT)]);
    $userId = (int) Database::getInstance()->getConnection()->lastInsertId(); Database::run('INSERT INTO user_roles (user_id, role_id) SELECT :user, id FROM roles WHERE name = \'manager\'', [':user' => $userId]); Database::run('DELETE FROM rate_limits');
    $csrf = dh_csrf($jar, $base . '/admin/login.php'); [$code] = dh_req($jar, $base . '/api/v1/auth.php', ['action' => 'login', 'identifier' => "delivery-http-$suffix@example.test", 'password' => $password, 'okv_csrf' => $csrf]); dh_eq(200, $code, 'delivery manager signs in');
    $csrf = dh_csrf($jar, $base . '/admin/delivery.php'); dh_ok($csrf !== '', 'delivery screen carries a CSRF token');
    [$code] = dh_req($jar, $base . '/api/v1/delivery.php?action=set_day'); dh_eq(405, $code, 'delivery writes reject GET');
    [$code] = dh_req($jar, $base . '/api/v1/delivery.php', ['action' => 'set_day', 'customer_type' => 'household', 'day_of_week' => 1, 'cutoff_time' => '18:00', 'minimum_lead_days' => 1]); dh_eq(419, $code, 'delivery writes reject missing CSRF');
    [$code] = dh_req($jar, $base . '/api/v1/delivery.php', ['action' => 'set_day', 'customer_type' => 'household', 'day_of_week' => 1, 'cutoff_time' => '24:00', 'minimum_lead_days' => 1, 'okv_csrf' => $csrf]); dh_eq(422, $code, 'invalid cutoff is refused');
    [$code] = dh_req($jar, $base . '/api/v1/delivery.php', ['action' => 'set_zone_active', 'zone_id' => 999999999, 'okv_csrf' => $csrf]); dh_eq(422, $code, 'unknown zone is refused');
    [$code] = dh_req($jar, $base . '/api/v1/delivery.php', ['action' => 'save_exception', 'exception_date' => '2027-02-29', 'okv_csrf' => $csrf]); dh_eq(422, $code, 'impossible exception date is refused');
    [$code] = dh_req($jar, $base . '/api/v1/delivery.php', ['action' => 'save_exception', 'exception_date' => $date, 'reason' => 'Test closure', 'okv_csrf' => $csrf]); dh_eq(200, $code, 'a valid dated exception is saved');
    [$code] = dh_req($jar, $base . '/api/v1/delivery.php', ['action' => 'delete_exception', 'exception_date' => $date, 'okv_csrf' => $csrf]); dh_eq(200, $code, 'a dated exception can be removed safely');
    dh_ok(Database::one('SELECT id FROM delivery_date_exceptions WHERE exception_date = :date', [':date' => $date]) === null, 'removed exception is no longer applied');
} finally {
    Database::run('DELETE FROM delivery_date_exceptions WHERE exception_date = :date', [':date' => $date]);
    if ($userId) { Database::run('DELETE FROM user_roles WHERE user_id = :id', [':id' => $userId]); Database::run('DELETE FROM users WHERE id = :id', [':id' => $userId]); }
    if (is_string($jar) && is_file($jar)) { unlink($jar); }
}
fwrite(STDOUT, "\n$passed / $tests delivery HTTP assertions passed.\n"); exit($passed === $tests ? 0 : 1);

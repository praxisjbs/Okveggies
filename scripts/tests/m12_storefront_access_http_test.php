<?php
/** Guest, household and business access to public M12 storefront routes. */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_once __DIR__ . '/lib/scratch_guard.php';

$base = rtrim(getenv('OKV_TEST_BASE') ?: 'http://127.0.0.1:8123', '/');
$tests = 0; $passed = 0;
function msa_ok($condition, string $label): void { global $tests, $passed; $tests++; if ($condition) { $passed++; } else { fwrite(STDERR, "  FAIL: $label\n"); } }
function msa_eq($expected, $actual, string $label): void { msa_ok($expected === $actual, $label . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')')); }
function msa_request(string $base, string $jar, string $method, string $path, ?array $fields = null): array
{
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields ?? []));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Requested-With: fetch', 'Accept: application/json']);
    }
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, $body];
}
function msa_login(string $base, string $jar, string $email, string $password): void
{
    [, $account] = msa_request($base, $jar, 'GET', '/account.php');
    preg_match('/name="okv_csrf" value="([^"]+)"/', $account, $match);
    [$status] = msa_request($base, $jar, 'POST', '/api/v1/auth.php', [
        'action' => 'login', 'context' => 'storefront', 'identifier' => $email,
        'password' => $password, 'okv_csrf' => (string) ($match[1] ?? ''),
    ]);
    msa_eq(200, $status, $email . ' signs in to the storefront');
}

$suffix = bin2hex(random_bytes(5));
$password = 'm12-storefront-88';
$users = [];
$jars = ['guest' => tempnam(sys_get_temp_dir(), 'okv-m12-guest-')];

try {
    foreach (['household', 'business'] as $type) {
        $email = "m12-$type-$suffix@example.test";
        Database::run(
            'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at) VALUES (:first, :last, :email, :phone, :hash, :type, :status, NOW())',
            [':first' => ucfirst($type), ':last' => 'M12', ':email' => $email, ':phone' => '+23476' . random_int(10000000, 99999999),
             ':hash' => password_hash($password, PASSWORD_BCRYPT), ':type' => $type, ':status' => 'active']
        );
        $users[$type] = ['id' => (int) Database::getInstance()->getConnection()->lastInsertId(), 'email' => $email];
        if ($type === 'business') {
            Database::run(
                'INSERT INTO business_customers (user_id, business_name, contact_person) VALUES (:user, :name, :contact)',
                [':user' => $users[$type]['id'], ':name' => 'M12 Test Kitchen', ':contact' => 'Business M12']
            );
        }
        $jars[$type] = tempnam(sys_get_temp_dir(), 'okv-m12-' . $type . '-');
    }

    [$status, $guest] = msa_request($base, $jars['guest'], 'GET', '/our-story');
    msa_eq(200, $status, 'a guest opens a published M12 page');
    msa_ok(str_contains($guest, '<main>') && str_contains($guest, 'Browse the shop'), 'the guest receives the public content shell and route onward');

    foreach (['household', 'business'] as $type) {
        msa_login($base, $jars[$type], $users[$type]['email'], $password);
        [$status, $page] = msa_request($base, $jars[$type], 'GET', '/how-it-works');
        msa_eq(200, $status, "a signed-in $type customer opens a published M12 page");
        msa_ok(str_contains($page, '<main>') && str_contains($page, 'Start shopping'), "the signed-in $type customer receives the public content shell and route onward");
    }
} finally {
    if (isset($users['business'])) { Database::run('DELETE FROM business_customers WHERE user_id = :id', [':id' => $users['business']['id']]); }
    foreach ($users as $user) { Database::run('DELETE FROM users WHERE id = :id', [':id' => $user['id']]); }
    foreach ($jars as $jar) { if (is_file($jar)) { unlink($jar); } }
}

fwrite(STDOUT, "\n$passed / $tests M12 storefront-access HTTP assertions passed.\n");
exit($passed === $tests ? 0 : 1);

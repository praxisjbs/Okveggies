<?php
/**
 * scripts/payment_healthcheck.php
 * -----------------------------------------------------------------------------
 * OK Veggies. One command that answers "can this server actually take a
 * payment right now", so nobody has to find out by clicking.
 *
 *   php scripts/payment_healthcheck.php
 *
 * Checks the classes load, the migrations that payments depend on have actually
 * run, the Paystack keys are set and which mode they are, that Paystack answers,
 * and that the settings are seeded. Exits 0 when everything passes and 1 when
 * anything fails, so it can gate a deploy.
 *
 * It takes no payment and changes nothing.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$failures = 0;
$warnings = 0;

function okv_check(string $label, bool $passed, string $detail = '', bool $warnOnly = false): void
{
    global $failures, $warnings;
    if ($passed) {
        echo "  ok    " . $label . ($detail !== '' ? '  (' . $detail . ')' : '') . PHP_EOL;
        return;
    }
    if ($warnOnly) {
        $warnings++;
        echo "  WARN  " . $label . ($detail !== '' ? '  (' . $detail . ')' : '') . PHP_EOL;
        return;
    }
    $failures++;
    echo "  FAIL  " . $label . ($detail !== '' ? '  (' . $detail . ')' : '') . PHP_EOL;
}

echo PHP_EOL, "OK Veggies payment health check", PHP_EOL, PHP_EOL;

// 1. Classes -----------------------------------------------------------------
echo "1. Application classes", PHP_EOL;
foreach (['Paystack', 'Payments', 'ManualPayments', 'Refunds', 'Cancellation', 'OrderDocument'] as $class) {
    okv_check($class . ' is loaded', class_exists($class));
}

// 2. Schema ------------------------------------------------------------------
echo PHP_EOL, "2. Database schema", PHP_EOL;
try {
    $pdo = Database::getInstance()->getConnection();

    $applied = [];
    foreach ($pdo->query('SELECT version FROM schema_migrations') as $row) {
        $applied[(string) $row['version']] = true;
    }
    foreach (['012', '013', '014', '015', '016', '017'] as $version) {
        $found = false;
        foreach (array_keys($applied) as $key) {
            if (str_starts_with($key, $version)) { $found = true; break; }
        }
        okv_check('migration ' . $version . ' applied', $found, $found ? '' : 'run scripts/migrate.php');
    }

    $columnExists = static function (string $table, string $column) use ($pdo): bool {
        $s = $pdo->prepare('SELECT COUNT(*) c FROM information_schema.COLUMNS
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $s->execute([$table, $column]);
        return (int) $s->fetch()['c'] > 0;
    };
    $tableExists = static function (string $table) use ($pdo): bool {
        $s = $pdo->prepare('SELECT COUNT(*) c FROM information_schema.TABLES
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $s->execute([$table]);
        return (int) $s->fetch()['c'] > 0;
    };

    okv_check('payment_transactions.attempt_number exists', $columnExists('payment_transactions', 'attempt_number'), 'from migration 014');
    okv_check('manual_payment_proofs.method exists', $columnExists('manual_payment_proofs', 'method'), 'from migration 016');
    okv_check('manual_payment_proofs.recorded_by exists', $columnExists('manual_payment_proofs', 'recorded_by'), 'from migration 016');
    okv_check('payment_reversals table exists', $tableExists('payment_reversals'), 'from migration 016');
} catch (Throwable $e) {
    okv_check('database reachable', false, $e->getMessage());
}

// 3. Settings ----------------------------------------------------------------
echo PHP_EOL, "3. Payment settings", PHP_EOL;
try {
    okv_check('payment_verify_sweep_minutes seeded', Settings::int('payment_verify_sweep_minutes', 0) > 0, 'from migration 014');
    okv_check('deposit percentage set', Settings::depositPercentage() > 0, Settings::depositPercentage() . '%');
    $channels = Paystack::channels();
    okv_check('channels', true, $channels ? implode(', ', $channels) : 'dashboard decides, which is the recommended default');
} catch (Throwable $e) {
    okv_check('settings readable', false, $e->getMessage());
}

// 4. Paystack credentials ----------------------------------------------------
echo PHP_EOL, "4. Paystack", PHP_EOL;
$secret = (string) env('PAYSTACK_SECRET_KEY', '');
okv_check('PAYSTACK_SECRET_KEY is set', $secret !== '', $secret === '' ? 'add it to .env' : '');
if ($secret !== '') {
    $looksRight = str_starts_with($secret, 'sk_test_') || str_starts_with($secret, 'sk_live_');
    okv_check('secret key looks like a Paystack key', $looksRight, $looksRight ? Paystack::domain() . ' mode' : 'expected sk_test_ or sk_live_');
}
okv_check('APP_URL is set', (string) APP_URL !== '', (string) APP_URL);
echo "  note  webhook URL to paste into Paystack: " . rtrim((string) APP_URL, '/') . "/api/v1/paystack_webhook.php" . PHP_EOL;

// 5. Can we actually reach Paystack? -----------------------------------------
echo PHP_EOL, "5. Reaching Paystack", PHP_EOL;
if ($secret === '') {
    okv_check('Paystack reachable', false, 'no secret key to try with');
} else {
    // A verify on a reference that cannot exist. A 404 from Paystack is a
    // success here: it means the key authenticated and the API answered.
    $probe = Paystack::verifyTransaction('okvhealthcheck0000');
    $reachable = $probe['ok'] || ($probe['reason'] ?? '') === 'api';
    okv_check('Paystack answers our key', $reachable, $reachable
        ? 'authenticated'
        : 'reason: ' . (string) ($probe['reason'] ?? 'unknown') . ', ' . (string) ($probe['message'] ?? ''));
}

// 6. Orders waiting to be paid -----------------------------------------------
echo PHP_EOL, "6. Orders", PHP_EOL;
try {
    $waiting = Database::all(
        'SELECT o.order_number, p.id AS payment_id, p.expected_amount_subunit
           FROM payments p JOIN orders o ON o.id = p.order_id
          WHERE p.provider = \'paystack\' AND p.status <> \'paid\'
            AND p.expected_amount_subunit > p.paid_amount_subunit
          ORDER BY p.id DESC LIMIT 5'
    );
    okv_check('orders with money owed online', true, count($waiting) . ' found');
    foreach ($waiting as $row) {
        echo "        " . $row['order_number'] . '  ' . Money::format((int) $row['expected_amount_subunit'])
           . '  (payment id ' . (int) $row['payment_id'] . ')' . PHP_EOL;
    }
} catch (Throwable $e) {
    okv_check('order lookup', false, $e->getMessage());
}

echo PHP_EOL;
if ($failures === 0) {
    echo "All clear. This server can take a payment.", PHP_EOL, PHP_EOL;
    exit(0);
}
echo $failures . " check(s) failed. Fix these before taking a payment.", PHP_EOL, PHP_EOL;
exit(1);

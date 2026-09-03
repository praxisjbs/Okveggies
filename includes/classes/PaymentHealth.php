<?php
/**
 * includes/classes/PaymentHealth.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Can this server actually take a payment right now.
 *
 * One set of checks, two front doors: scripts/payment_healthcheck.php for a
 * shell, and public/healthcheck.php for shared hosting where there is no shell.
 * They share this class so the two answers can never disagree.
 *
 * Every check is a read. Nothing here takes a payment, writes a row or changes
 * a setting.
 * -----------------------------------------------------------------------------
 */

final class PaymentHealth
{
    /**
     * Run every check. Returns sections of
     * ['label' => string, 'state' => 'ok'|'fail'|'warn'|'note', 'detail' => string].
     */
    public static function run(): array
    {
        return [
            'Application classes' => self::classes(),
            'Database schema'     => self::schema(),
            'Payment settings'    => self::settings(),
            'Paystack'            => self::paystack(),
            'Reaching Paystack'   => self::reachability(),
            'Orders'              => self::orders(),
        ];
    }

    /** How many checks failed across every section. */
    public static function failureCount(array $sections): int
    {
        $failures = 0;
        foreach ($sections as $checks) {
            foreach ($checks as $check) {
                if ($check['state'] === 'fail') {
                    $failures++;
                }
            }
        }
        return $failures;
    }

    private static function check(string $label, bool $passed, string $detail = '', bool $warnOnly = false): array
    {
        return [
            'label'  => $label,
            'state'  => $passed ? 'ok' : ($warnOnly ? 'warn' : 'fail'),
            'detail' => $detail,
        ];
    }

    private static function classes(): array
    {
        $out = [];
        foreach (['Paystack', 'Payments', 'ManualPayments', 'Refunds', 'Cancellation', 'OrderDocument'] as $class) {
            $out[] = self::check(
                $class . ' is loaded',
                class_exists($class),
                class_exists($class) ? '' : 'not required by includes/bootstrap.php'
            );
        }
        return $out;
    }

    private static function schema(): array
    {
        $out = [];
        try {
            $pdo = Database::getInstance()->getConnection();

            $applied = [];
            foreach ($pdo->query('SELECT version FROM schema_migrations') as $row) {
                $applied[] = (string) $row['version'];
            }
            foreach (['012', '013', '014', '015', '016', '017'] as $version) {
                $found = false;
                foreach ($applied as $key) {
                    if (str_starts_with($key, $version)) {
                        $found = true;
                        break;
                    }
                }
                $out[] = self::check('migration ' . $version . ' applied', $found, $found ? '' : 'run the migration runner');
            }

            $column = static function (string $table, string $col) use ($pdo): bool {
                $s = $pdo->prepare('SELECT COUNT(*) c FROM information_schema.COLUMNS
                                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
                $s->execute([$table, $col]);
                return (int) $s->fetch()['c'] > 0;
            };
            $table = static function (string $t) use ($pdo): bool {
                $s = $pdo->prepare('SELECT COUNT(*) c FROM information_schema.TABLES
                                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
                $s->execute([$t]);
                return (int) $s->fetch()['c'] > 0;
            };

            $out[] = self::check('payment_transactions.attempt_number', $column('payment_transactions', 'attempt_number'), 'migration 014');
            $out[] = self::check('manual_payment_proofs.method', $column('manual_payment_proofs', 'method'), 'migration 016');
            $out[] = self::check('manual_payment_proofs.recorded_by', $column('manual_payment_proofs', 'recorded_by'), 'migration 016');
            $out[] = self::check('payment_reversals table', $table('payment_reversals'), 'migration 016');
        } catch (Throwable $e) {
            $out[] = self::check('database reachable', false, $e->getMessage());
        }
        return $out;
    }

    private static function settings(): array
    {
        $out = [];
        try {
            $out[] = self::check('sweep window seeded', Settings::int('payment_verify_sweep_minutes', 0) > 0, 'migration 014');
            $out[] = self::check('deposit percentage set', Settings::depositPercentage() > 0, Settings::depositPercentage() . '%');
            $channels = Paystack::channels();
            $out[] = self::check('channels', true, $channels
                ? implode(', ', $channels)
                : 'none set, so the Paystack dashboard decides. That is the recommended default.');
        } catch (Throwable $e) {
            $out[] = self::check('settings readable', false, $e->getMessage());
        }
        return $out;
    }

    private static function paystack(): array
    {
        $secret = (string) env('PAYSTACK_SECRET_KEY', '');
        $out = [];
        $out[] = self::check('PAYSTACK_SECRET_KEY is set', $secret !== '', $secret === '' ? 'add it to .env' : '');
        if ($secret !== '') {
            $shape = str_starts_with($secret, 'sk_test_') || str_starts_with($secret, 'sk_live_');
            $out[] = self::check('secret key shape', $shape, $shape ? Paystack::domain() . ' mode' : 'expected sk_test_ or sk_live_');
        }
        $out[] = self::check('APP_URL is set', (string) APP_URL !== '', (string) APP_URL);
        $out[] = [
            'label'  => 'webhook URL to paste into Paystack',
            'state'  => 'note',
            'detail' => rtrim((string) APP_URL, '/') . '/api/v1/paystack_webhook.php',
        ];
        return $out;
    }

    private static function reachability(): array
    {
        if ((string) env('PAYSTACK_SECRET_KEY', '') === '') {
            return [self::check('Paystack answers our key', false, 'no secret key to try with')];
        }
        // Verify a reference that cannot exist. Paystack answering at all, even
        // to say it does not know it, proves the key authenticated.
        $probe = Paystack::verifyTransaction('okvhealthcheck0000');
        $reachable = $probe['ok'] || ($probe['reason'] ?? '') === 'api';
        return [self::check(
            'Paystack answers our key',
            $reachable,
            $reachable ? 'authenticated' : (string) ($probe['reason'] ?? 'unknown') . ': ' . (string) ($probe['message'] ?? '')
        )];
    }

    private static function orders(): array
    {
        try {
            $waiting = Database::all(
                'SELECT o.id, o.order_number, p.id AS payment_id, p.expected_amount_subunit, p.paid_amount_subunit
                   FROM payments p JOIN orders o ON o.id = p.order_id
                  WHERE p.provider = \'paystack\' AND p.status <> \'paid\'
                    AND p.expected_amount_subunit > p.paid_amount_subunit
                  ORDER BY p.id DESC LIMIT 5'
            );
            $out = [self::check('orders with money owed online', true, count($waiting) . ' found')];
            foreach ($waiting as $row) {
                $due = Money::balance((int) $row['expected_amount_subunit'], (int) $row['paid_amount_subunit']);
                $out[] = [
                    'label'  => '  ' . (string) $row['order_number'] . '  ' . Money::format($due),
                    'state'  => 'note',
                    'detail' => rtrim((string) APP_URL, '/') . '/public/order.php?order=' . (int) $row['id'],
                ];
            }
            return $out;
        } catch (Throwable $e) {
            return [self::check('order lookup', false, $e->getMessage())];
        }
    }
}

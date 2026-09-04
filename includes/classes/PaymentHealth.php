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
            'Email and notices'   => self::notifications(),
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

    /**
     * Can this server actually tell a customer anything. Every check is a read:
     * nothing here sends a message. To prove a real send, use "Send one to me"
     * on the Notifications tab of the settings screen, which posts through the
     * real mail server to the signed-in person's own address.
     */
    private static function notifications(): array
    {
        $out = [];
        $out[] = self::check('Notifications is loaded', class_exists('Notifications'), class_exists('Notifications') ? '' : 'not required by includes/bootstrap.php');
        $out[] = self::check(
            'PHPMailer is installed',
            class_exists(\PHPMailer\PHPMailer\PHPMailer::class),
            class_exists(\PHPMailer\PHPMailer\PHPMailer::class) ? '' : 'composer install has not run, so every email is only written to the error log'
        );

        $host = trim((string) env('SMTP_HOST', ''));
        $user = trim((string) env('SMTP_USER', ''));
        $pass = (string) env('SMTP_PASS', '');
        $out[] = self::check('SMTP host is set', $host !== '', $host !== '' ? $host . ':' . (int) env('SMTP_PORT', 465) : 'SMTP_HOST is empty in .env');
        $out[] = self::check('SMTP user is set', $user !== '', $user !== '' ? $user : 'SMTP_USER is empty in .env');
        $out[] = self::check('SMTP password is set', $pass !== '', $pass !== '' ? 'set' : 'SMTP_PASS is empty in .env');
        $from = trim((string) env('SMTP_FROM_EMAIL', $user));
        $out[] = self::check('Emails have a from address', $from !== '', $from !== '' ? $from : 'neither SMTP_FROM_EMAIL nor SMTP_USER is set');

        try {
            $templates = Database::all('SELECT template_key, is_active FROM notification_templates');
            $active = [];
            foreach ($templates as $row) {
                if ((int) $row['is_active'] === 1) {
                    $active[] = (string) $row['template_key'];
                }
            }
            $missing = [];
            foreach (Notifications::EVENTS as $definition) {
                if (!in_array($definition['template'], $active, true)) {
                    $missing[] = $definition['template'];
                }
            }
            $out[] = self::check(
                'Every event has words to send',
                $missing === [],
                $missing === [] ? count($active) . ' active templates' : 'missing or switched off: ' . implode(', ', $missing)
            );

            $recent = Database::one(
                'SELECT COUNT(*) AS n FROM notification_deliveries
                  WHERE channel = :channel AND status = :status AND queued_at > (NOW() - INTERVAL 7 DAY)',
                [':channel' => Notifications::CHANNEL_EMAIL, ':status' => Notifications::STATUS_FAILED]
            );
            $failed = (int) ($recent['n'] ?? 0);
            $out[] = self::check(
                'No email has failed this week',
                $failed === 0,
                $failed === 0 ? '' : $failed . ' failed in the last 7 days. Open the order and read the error beside the message.',
                true
            );

            $sent = Database::one(
                'SELECT COUNT(*) AS n FROM notification_deliveries
                  WHERE channel = :channel AND status = :status AND queued_at > (NOW() - INTERVAL 7 DAY)',
                [':channel' => Notifications::CHANNEL_EMAIL, ':status' => Notifications::STATUS_SENT]
            );
            $out[] = self::check(
                'Email has gone out this week',
                (int) ($sent['n'] ?? 0) > 0,
                (int) ($sent['n'] ?? 0) > 0 ? (int) $sent['n'] . ' sent in the last 7 days' : 'nothing sent yet. Use "Send one to me" on the Notifications settings tab.',
                true
            );
        } catch (Throwable $e) {
            $out[] = self::check('Notification tables are readable', false, 'the notifications tables could not be read');
        }
        return $out;
    }

    private static function classes(): array
    {
        $out = [];
        foreach (['Paystack', 'Payments', 'ManualPayments', 'Refunds', 'Cancellation', 'OrderDocument', 'Notifications'] as $class) {
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

            // A placeholder is "set" and has the right prefix, so it passes
            // every other check here. It has to be called out by name.
            $placeholder = self::looksLikePlaceholder($secret);
            $out[] = self::check(
                'secret key is a real key, not the template placeholder',
                !$placeholder,
                $placeholder ? 'this is still the sk_..._xxxxx placeholder from .env.example' : ''
            );
        }
        $out[] = self::check('APP_URL is set', (string) APP_URL !== '', (string) APP_URL);
        $out[] = [
            'label'  => 'webhook URL to paste into Paystack',
            'state'  => 'note',
            'detail' => rtrim((string) APP_URL, '/') . '/api/v1/paystack_webhook.php',
        ];
        return $out;
    }

    /**
     * Is a key obviously still the template placeholder rather than a real one.
     * .env.example ships sk_live_xxxxxxxxxxxxxxxxxxxxx, and a placeholder is
     * "set" and has the right prefix, so nothing else here would notice it.
     */
    public static function looksLikePlaceholder(string $secret): bool
    {
        return $secret !== '' && stripos($secret, 'xxxx') !== false;
    }

    /**
     * Decide what a probe of Paystack actually proved.
     *
     * This used to treat any answer from Paystack as proof the key worked,
     * which is wrong and reported a rejected key as authenticated. Paystack
     * answers 401 to a bad key just as readily as 404 to an unknown reference,
     * and only the second means our credentials are good. Pure so it can be
     * tested without reaching Paystack at all.
     *
     * @return array{ok: bool, detail: string}
     */
    public static function classifyProbe(array $probe): array
    {
        if (!empty($probe['ok'])) {
            return ['ok' => true, 'detail' => 'authenticated'];
        }

        $reason = (string) ($probe['reason'] ?? 'unknown');
        $code   = (int) ($probe['http_code'] ?? 0);

        if ($reason === 'network' || $reason === 'http') {
            return ['ok' => false, 'detail' => 'could not reach Paystack at all. Check outbound HTTPS from this server.'];
        }

        if ($reason === 'api') {
            if ($code === 401 || $code === 403) {
                return ['ok' => false, 'detail' => 'Paystack REJECTED this key (HTTP ' . $code . '). The key in .env is wrong, a placeholder, or from the other mode.'];
            }
            if ($code === 404) {
                // The key authenticated; Paystack simply has no such reference,
                // which is exactly what we asked it about.
                return ['ok' => true, 'detail' => 'authenticated (HTTP 404 on a deliberately unknown reference, which is the expected answer)'];
            }
            return ['ok' => false, 'detail' => 'Paystack answered HTTP ' . $code . ': ' . (string) ($probe['message'] ?? '')];
        }

        return ['ok' => false, 'detail' => $reason . ': ' . (string) ($probe['message'] ?? '')];
    }

    private static function reachability(): array
    {
        $secret = (string) env('PAYSTACK_SECRET_KEY', '');
        if ($secret === '') {
            return [self::check('Paystack accepts our key', false, 'no secret key to try with')];
        }
        // A reference that cannot exist. A good key earns 404; a bad one earns
        // 401, and telling those two apart is the whole point of this check.
        $verdict = self::classifyProbe(Paystack::verifyTransaction('okvhealthcheck0000'));
        return [self::check('Paystack accepts our key', $verdict['ok'], $verdict['detail'])];
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

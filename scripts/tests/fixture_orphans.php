<?php
/**
 * scripts/tests/fixture_orphans.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Proves the suites cleaned up after themselves.
 *
 * Requirement 20 of the release gate is cleanup of disposable fixtures, and
 * "each suite cleans up in a finally block" is a claim, not evidence. This reads
 * the disposable database after the suites have run and counts anything left
 * behind that carries a fixture identity.
 *
 * It runs twice in the gate: once after the database and HTTP suites, and once
 * after the browser pass has been torn down. Both runs must be clean.
 *
 *   php scripts/tests/fixture_orphans.php
 *
 * Exit codes: 0 clean, 1 orphans found, 2 refused or misconfigured.
 * -----------------------------------------------------------------------------
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This runs from the CLI only.\n");
    exit(2);
}

$root = dirname(__DIR__, 2);
require_once $root . '/includes/bootstrap.php';
require_once __DIR__ . '/lib/scratch_guard.php';

$env = strtolower(trim((string) env('APP_ENV', 'production')));
if ($env === 'production') {
    fwrite(STDERR, "REFUSING TO RUN against production.\n");
    exit(2);
}

/** Fixture identities, by the two domains every suite uses. */
$fixtureEmail = "(email LIKE '%@example.test' OR email LIKE '%@example.com')";
$fixtureEmailU = "(u.email LIKE '%@example.test' OR u.email LIKE '%@example.com')";
$fixtureRole = "(LOWER(COALESCE(description,'')) LIKE '%test%' OR LOWER(COALESCE(description,'')) LIKE '%fixture%' OR LOWER(COALESCE(description,'')) LIKE '%probe%')";

/**
 * The second identity, and the reason these are LEFT joins.
 *
 * `orders`, `payments` and `kitchen_run_requests` all carry
 * `ON DELETE SET NULL` on `user_id`. When a suite deletes its fixture user in a
 * finally block, its order is not deleted with it: the row survives with
 * `user_id` NULL. An inner join to `users` then matches nothing, so the check
 * reported "no orphans" in exactly the case it exists to catch, a customer row
 * cleaned up and its money rows left behind.
 *
 * A left join plus the number prefix closes that. Real numbers come from the
 * `OrderNumber` helper and start `OKV` or `KR`; every suite in this folder
 * numbers its fixtures `ZZ...`, so a `ZZ` row in a scratch database is debris
 * whether or not its customer still exists. `issue_reports` has no number of
 * its own, so it is reached through the order it hangs off.
 */
$fixtureNumber = static fn(string $column): string => "$column LIKE 'ZZ%'";

$checks = [
    'users'                 => "SELECT COUNT(*) AS n FROM users WHERE $fixtureEmail",
    'orders'                => "SELECT COUNT(*) AS n FROM orders o LEFT JOIN users u ON u.id = o.user_id "
                             . "WHERE $fixtureEmailU OR " . $fixtureNumber('o.order_number'),
    'payments'              => "SELECT COUNT(*) AS n FROM payments p LEFT JOIN users u ON u.id = p.user_id "
                             . "WHERE $fixtureEmailU OR " . $fixtureNumber('p.payment_number'),
    'payment_transactions'  => "SELECT COUNT(*) AS n FROM payment_transactions WHERE " . $fixtureNumber('reference'),
    'kitchen_run_requests'  => "SELECT COUNT(*) AS n FROM kitchen_run_requests k LEFT JOIN users u ON u.id = k.user_id "
                             . "WHERE $fixtureEmailU OR " . $fixtureNumber('k.request_number'),
    'issue_reports'         => "SELECT COUNT(*) AS n FROM issue_reports i LEFT JOIN users u ON u.id = i.user_id "
                             . "LEFT JOIN orders o ON o.id = i.order_id "
                             . "WHERE $fixtureEmailU OR " . $fixtureNumber('o.order_number'),
    'contact_messages'      => "SELECT COUNT(*) AS n FROM contact_messages WHERE $fixtureEmail",
    'roles'                 => "SELECT COUNT(*) AS n FROM roles WHERE $fixtureRole",
    'rate_limits'           => "SELECT COUNT(*) AS n FROM rate_limits WHERE bucket LIKE 'login:%'",
];

$offenders = [];
$total = 0;

foreach ($checks as $label => $sql) {
    try {
        $row = Database::one($sql);
        $count = $row === null ? 0 : (int) $row['n'];
    } catch (Throwable $e) {
        fwrite(STDERR, "FAIL: could not count $label: " . $e->getMessage() . "\n");
        exit(1);
    }
    $total += $count;
    printf("  %-22s %d\n", $label, $count);
    if ($count > 0) {
        $offenders[$label] = $count;
    }
}

if ($total === 0) {
    echo "ORPHANS OK: no fixture rows remain in any of the " . count($checks) . " checked tables.\n";
    exit(0);
}

fwrite(STDERR, "\nFAIL: " . count($offenders) . " table(s) still hold fixture rows.\n");
foreach (array_keys($offenders) as $label) {
    if ($label === 'users') {
        $samples = Database::all("SELECT email FROM users WHERE $fixtureEmail LIMIT 5") ?: [];
        foreach ($samples as $row) {
            fwrite(STDERR, '       sample user: ' . $row['email'] . "\n");
        }
    }
    if ($label === 'orders') {
        $samples = Database::all(
            "SELECT o.order_number FROM orders o LEFT JOIN users u ON u.id = o.user_id "
            . "WHERE $fixtureEmailU OR o.order_number LIKE 'ZZ%' LIMIT 5"
        ) ?: [];
        foreach ($samples as $row) {
            fwrite(STDERR, '       sample order: ' . $row['order_number'] . "\n");
        }
    }
    if ($label === 'roles') {
        $samples = Database::all("SELECT name, description FROM roles WHERE $fixtureRole LIMIT 5") ?: [];
        foreach ($samples as $row) {
            fwrite(STDERR, '       sample role: ' . $row['name'] . ' (' . $row['description'] . ")\n");
        }
    }
}
fwrite(STDERR, "A suite is leaving rows behind. Fix the suite's finally block rather than the check.\n");
exit(1);

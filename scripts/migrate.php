#!/usr/bin/env php
<?php
/**
 * scripts/migrate.php
 * -----------------------------------------------------------------------------
 * OK Veggies. SQL migration runner.
 *
 * Reads every *.sql file in ../migrations/ (natural filename order), skips ones
 * already recorded in schema_migrations, and applies the rest inside a
 * transaction. Records success with runtime + SHA-256 checksum. Refuses to skip
 * a migration whose checksum changed (unless --force).
 *
 * Usage (from the app root or scripts/):
 *   php scripts/migrate.php          apply pending
 *   php scripts/migrate.php --status show applied vs pending
 *   php scripts/migrate.php --force  re-apply even if checksum changed
 *   php scripts/migrate.php --dry    show what would run, do not execute
 *
 * Exit codes: 0 success, 1 a migration failed, 2 config / usage error.
 * -----------------------------------------------------------------------------
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the CLI.\n"); exit(2);
}

$root = dirname(__DIR__);
require_once $root . '/includes/config/env.php';

$args       = array_slice($argv, 1);
$flagForce  = in_array('--force',  $args, true);
$flagDry    = in_array('--dry',    $args, true);
$flagStatus = in_array('--status', $args, true);

$MIG_DIR = $root . '/migrations';
if (!is_dir($MIG_DIR)) { fwrite(STDERR, "No migrations/ directory.\n"); exit(2); }

$port = (int) env('DB_PORT', 3306);
$dsn  = 'mysql:host=' . env('DB_HOST', 'localhost')
      . ($port ? ';port=' . $port : '')
      . ';dbname='  . env('DB_NAME', '')
      . ';charset=' . env('DB_CHARSET', 'utf8mb4');
try {
    $pdo = new PDO($dsn, env('DB_USER'), env('DB_PASS'), [
        PDO::ATTR_ERRMODE                => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE     => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES       => false,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . "\n"); exit(2);
}

$files = glob($MIG_DIR . '/*.sql') ?: [];
sort($files, SORT_NATURAL);
if (!$files) { info("No migrations found in $MIG_DIR"); exit(0); }

if (!schema_migrations_table_exists($pdo)) {
    info("schema_migrations table missing, running 000_init first.");
    $initFile = $MIG_DIR . '/000_init_schema_migrations.sql';
    if (!is_file($initFile)) {
        fwrite(STDERR, "FATAL: schema_migrations table missing AND 000_init_schema_migrations.sql not present.\n");
        exit(2);
    }
    if (!$flagDry) execute_sql_file($pdo, $initFile);
}

$applied = [];
if (schema_migrations_table_exists($pdo)) {
    foreach ($pdo->query("SELECT version, checksum FROM schema_migrations") as $r) {
        $applied[$r['version']] = $r['checksum'];
    }
}

if ($flagStatus) {
    printf("%-6s  %-42s  %s\n", 'STATE', 'VERSION', 'FILE');
    foreach ($files as $f) {
        $ver = basename($f, '.sql');
        $sum = sha256_file($f);
        if (!isset($applied[$ver]))       $state = 'PEND';
        elseif ($applied[$ver] === null)  $state = 'OK';
        elseif ($applied[$ver] !== $sum)  $state = 'DRIFT';
        else                              $state = 'OK';
        printf("%-6s  %-42s  %s\n", $state, $ver, basename($f));
    }
    exit(0);
}

$pending = [];
foreach ($files as $f) {
    $ver = basename($f, '.sql');
    $sum = sha256_file($f);
    if (!isset($applied[$ver])) { $pending[] = [$f, $ver, $sum, 'new']; continue; }
    if ($applied[$ver] !== null && $applied[$ver] !== $sum) {
        if ($flagForce) { $pending[] = [$f, $ver, $sum, 'checksum-drift, --force']; continue; }
        fwrite(STDERR, "REFUSING to skip $ver: file checksum changed. Create a new migration or pass --force.\n");
        exit(1);
    }
}
if (!$pending) { info("Nothing to apply. Everything up to date."); exit(0); }

info("Pending migrations: " . count($pending));
foreach ($pending as [$f, $ver, $sum, $why]) {
    info(sprintf("  . %s (%s)", $ver, $why));
}
if ($flagDry) { info("--dry passed. No changes made."); exit(0); }

$whoami = trim(gethostname() ?: 'unknown') . '/' . get_current_user();

foreach ($pending as [$f, $ver, $sum, $why]) {
    printf("  -> applying %s ... ", $ver);
    $t0 = microtime(true);
    try {
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        $pdo->beginTransaction();
        execute_sql_file($pdo, $f);
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $up = $pdo->prepare("REPLACE INTO schema_migrations (version, runtime_ms, checksum, applied_by) VALUES (?, ?, ?, ?)");
        $up->execute([$ver, $ms, $sum, $whoami]);
        if ($pdo->inTransaction()) $pdo->commit();
        printf("OK (%d ms)\n", $ms);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        printf("FAIL\n");
        fwrite(STDERR, "Migration $ver failed:\n" . $e->getMessage() . "\n");
        exit(1);
    }
}

info("All pending migrations applied.");
exit(0);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function schema_migrations_table_exists(PDO $pdo): bool {
    try {
        $pdo->query("SELECT 1 FROM schema_migrations LIMIT 1");
        return true;
    } catch (Throwable $e) { return false; }
}

/**
 * Execute a .sql file statement by statement. String and comment aware so a
 * semicolon inside a quoted value is not treated as a terminator, and DELIMITER
 * directives (for triggers/procedures) are honoured.
 */
function execute_sql_file(PDO $pdo, string $path): void {
    $sql = file_get_contents($path);
    if ($sql === false) throw new RuntimeException("Cannot read $path");

    $delimiter = ';';
    $buffer    = '';
    $inString  = null;   // "'", '"', '`', or null
    $inComment = false;  // inside a block comment

    foreach (preg_split('/\r\n|\n|\r/', $sql) as $line) {
        if ($inString === null && !$inComment
            && preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $m)) {
            run_sql_statement($pdo, $buffer);
            $delimiter = $m[1];
            $buffer    = '';
            continue;
        }
        $buffer .= $line . "\n";

        $dlen = strlen($delimiter);
        $len  = strlen($line);
        $cut  = null;
        for ($i = 0; $i < $len; $i++) {
            $ch = $line[$i];
            if ($inComment) {
                if ($ch === '*' && ($i + 1) < $len && $line[$i + 1] === '/') { $inComment = false; $i++; }
                continue;
            }
            if ($inString !== null) {
                if ($ch === '\\') { $i++; continue; }
                if ($ch === $inString) {
                    if (($i + 1) < $len && $line[$i + 1] === $inString) { $i++; continue; }
                    $inString = null;
                }
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') { $inString = $ch; continue; }
            if ($ch === '#') break;
            if ($ch === '-' && ($i + 1) < $len && $line[$i + 1] === '-') {
                if (($i + 2) >= $len || $line[$i + 2] === ' ' || $line[$i + 2] === "\t") break;
            }
            if ($ch === '/' && ($i + 1) < $len && $line[$i + 1] === '*') { $inComment = true; $i++; continue; }
            if ($dlen > 0 && substr($line, $i, $dlen) === $delimiter) {
                $cut = $i + $dlen;
                $i  += $dlen - 1;
            }
        }
        if ($cut !== null) {
            $tailLen = $len - $cut;
            $stmtLen = strlen($buffer) - 1 - $tailLen - $dlen;
            $statement = substr($buffer, 0, $stmtLen);
            $remainder = substr($line, $cut);
            run_sql_statement($pdo, $statement);
            $buffer = ($remainder === '' ? '' : $remainder . "\n");
        }
    }
    if ($inString !== null) {
        throw new RuntimeException("Unterminated $inString-quoted string in " . basename($path));
    }
    run_sql_statement($pdo, $buffer);
}

function run_sql_statement(PDO $pdo, string $stmt): void {
    $stripped = trim(preg_replace('/^\s*--.*$/m', '', $stmt));
    if ($stripped === '') return;
    $pdo->exec($stmt);
}

function sha256_file(string $path): string { return hash_file('sha256', $path) ?: ''; }
function info(string $msg): void { fwrite(STDOUT, "[migrate] $msg\n"); }

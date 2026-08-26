<?php
/**
 * includes/classes/Migrator.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The migration engine, shared by the CLI runner
 * (scripts/migrate.php) and the web runner (public/migrate.php) so both apply
 * migrations with identical, tested logic. On a shared host with no shell, the
 * web runner is how migrations get applied on deploy.
 *
 * Reads every *.sql in migrations/, skips ones already recorded in
 * schema_migrations, and applies the rest in a transaction, recording a
 * SHA-256 checksum. Refuses to skip a migration whose checksum changed unless
 * forced. The statement splitter is string and comment aware and honours
 * DELIMITER directives.
 * -----------------------------------------------------------------------------
 */

final class Migrator
{
    /** Build a PDO from the DB_* env values (multi-statements on for DDL files). */
    public static function connectFromEnv(): PDO
    {
        $port = (int) env('DB_PORT', 3306);
        $dsn  = 'mysql:host=' . env('DB_HOST', 'localhost')
              . ($port ? ';port=' . $port : '')
              . ';dbname='  . env('DB_NAME', '')
              . ';charset=' . env('DB_CHARSET', 'utf8mb4');
        return new PDO($dsn, env('DB_USER'), env('DB_PASS'), [
            PDO::ATTR_ERRMODE                => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE     => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES       => false,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
        ]);
    }

    public static function migrationsDir(): string
    {
        return dirname(__DIR__, 2) . '/migrations';
    }

    public static function files(string $dir): array
    {
        $files = glob($dir . '/*.sql') ?: [];
        sort($files, SORT_NATURAL);
        return $files;
    }

    public static function sha256(string $path): string
    {
        return hash_file('sha256', $path) ?: '';
    }

    public static function trackingTableExists(PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT 1 FROM schema_migrations LIMIT 1');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Run 000_init first if the tracking table is missing. */
    public static function ensureTracking(PDO $pdo, string $dir): void
    {
        if (self::trackingTableExists($pdo)) {
            return;
        }
        $init = $dir . '/000_init_schema_migrations.sql';
        if (!is_file($init)) {
            throw new RuntimeException('schema_migrations missing and 000_init not present.');
        }
        self::executeSqlFile($pdo, $init);
    }

    /** version => checksum for everything already applied. */
    public static function appliedVersions(PDO $pdo): array
    {
        $out = [];
        if (self::trackingTableExists($pdo)) {
            foreach ($pdo->query('SELECT version, checksum FROM schema_migrations') as $r) {
                $out[$r['version']] = $r['checksum'];
            }
        }
        return $out;
    }

    /** [ [state, version, file], ... ] for a status view. */
    public static function status(PDO $pdo, string $dir): array
    {
        $applied = self::appliedVersions($pdo);
        $rows = [];
        foreach (self::files($dir) as $f) {
            $ver = basename($f, '.sql');
            $sum = self::sha256($f);
            if (!isset($applied[$ver]))          $state = 'PENDING';
            elseif ($applied[$ver] === null)     $state = 'OK';
            elseif ($applied[$ver] !== $sum)     $state = 'DRIFT';
            else                                 $state = 'OK';
            $rows[] = [$state, $ver, basename($f)];
        }
        return $rows;
    }

    /**
     * Apply all pending migrations. Returns a list of log lines. Calls $log for
     * each line too, if given (so a CLI or web caller can stream progress).
     * Throws on the first failure (after rolling back that migration).
     */
    public static function apply(PDO $pdo, string $dir, bool $force = false, ?callable $log = null): array
    {
        $out = [];
        $say = function (string $m) use (&$out, $log) { $out[] = $m; if ($log) $log($m); };

        self::ensureTracking($pdo, $dir);
        $applied = self::appliedVersions($pdo);

        $pending = [];
        foreach (self::files($dir) as $f) {
            $ver = basename($f, '.sql');
            $sum = self::sha256($f);
            if (!isset($applied[$ver])) { $pending[] = [$f, $ver, $sum, 'new']; continue; }
            if ($applied[$ver] !== null && $applied[$ver] !== $sum) {
                if ($force) { $pending[] = [$f, $ver, $sum, 'checksum-drift, forced']; continue; }
                throw new RuntimeException("Refusing to skip $ver: checksum changed. Create a new migration or force.");
            }
        }

        if (!$pending) { $say('Nothing to apply. Everything up to date.'); return $out; }

        $who = (PHP_SAPI === 'cli' ? (trim(gethostname() ?: 'cli')) : 'web') . '/' . (get_current_user() ?: 'okv');
        foreach ($pending as [$f, $ver, $sum, $why]) {
            $t0 = microtime(true);
            try {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
                $pdo->beginTransaction();
                self::executeSqlFile($pdo, $f);
                $ms = (int) round((microtime(true) - $t0) * 1000);
                $up = $pdo->prepare('REPLACE INTO schema_migrations (version, runtime_ms, checksum, applied_by) VALUES (?, ?, ?, ?)');
                $up->execute([$ver, $ms, $sum, $who]);
                if ($pdo->inTransaction()) $pdo->commit();
                $say(sprintf('applied %s (%s) in %d ms', $ver, $why, $ms));
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw new RuntimeException("Migration $ver failed: " . $e->getMessage(), 0, $e);
            }
        }
        $say('All pending migrations applied.');
        return $out;
    }

    /**
     * Execute a .sql file statement by statement. String and comment aware, so a
     * semicolon inside a quoted value is not a terminator, and DELIMITER
     * directives (triggers, procedures) are honoured.
     */
    public static function executeSqlFile(PDO $pdo, string $path): void
    {
        $sql = file_get_contents($path);
        if ($sql === false) throw new RuntimeException("Cannot read $path");

        $delimiter = ';';
        $buffer    = '';
        $inString  = null;
        $inComment = false;

        foreach (preg_split('/\r\n|\n|\r/', $sql) as $line) {
            if ($inString === null && !$inComment
                && preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $m)) {
                self::runStatement($pdo, $buffer);
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
                self::runStatement($pdo, $statement);
                $buffer = ($remainder === '' ? '' : $remainder . "\n");
            }
        }
        if ($inString !== null) {
            throw new RuntimeException('Unterminated ' . $inString . '-quoted string in ' . basename($path));
        }
        self::runStatement($pdo, $buffer);
    }

    private static function runStatement(PDO $pdo, string $stmt): void
    {
        $stripped = trim(preg_replace('/^\s*--.*$/m', '', $stmt));
        if ($stripped === '') return;
        $pdo->exec($stmt);
    }
}

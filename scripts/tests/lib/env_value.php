<?php
/**
 * scripts/tests/lib/env_value.php
 * -----------------------------------------------------------------------------
 * OK Veggies. One way to read a value out of .env, for the test guards.
 *
 * The application's loader (`includes/config/env.php`) keeps everything after
 * the first "=", so `APP_ENV=production # live` reaches the application as the
 * whole string, comment included. Any guard that compares that to 'production'
 * matches nothing and waves the run through. Both guards need the same answer
 * to the same question, so they ask it here rather than each carrying its own
 * parser:
 *
 *   scripts/tests/lib/scratch_guard.php   requires this file
 *   scripts/tests/release_gate.sh         runs it: php .../env_value.php APP_ENV
 *
 * The rules: lines starting with "#" are comments, the key is what sits before
 * the first "=", a quoted value ends at its closing quote, an unquoted value
 * ends at whitespace followed by "#", and the last assignment of a key wins.
 * The process environment is consulted first, because that is what the
 * application itself sees first.
 *
 * Returns null when the key is set nowhere. A caller deciding whether something
 * is safe treats null as unsafe; this file does not make that decision.
 * -----------------------------------------------------------------------------
 */

if (!function_exists('okv_test_env_clean')) {
    /** Strip surrounding quotes and any trailing comment from one raw value. */
    function okv_test_env_clean(string $raw): string
    {
        $value = trim($raw);
        if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
            $quote = $value[0];
            $end = strpos($value, $quote, 1);
            $value = $end === false ? substr($value, 1) : substr($value, 1, $end - 1);
        } elseif (preg_match('/^(.*?)\s+#/', $value, $match) === 1) {
            $value = $match[1];
        }
        return trim($value);
    }
}

if (!function_exists('okv_test_env_path')) {
    /** The .env the application would load, honouring OKV_ENV_PATH. */
    function okv_test_env_path(): string
    {
        $fromEnv = getenv('OKV_ENV_PATH');
        if (is_string($fromEnv) && $fromEnv !== '') {
            return $fromEnv;
        }
        return dirname(__DIR__, 3) . '/.env';
    }
}

if (!function_exists('okv_test_env_value')) {
    /**
     * One value, from the process environment first and then the .env file.
     * Returns null when neither sets it.
     */
    function okv_test_env_value(string $key, ?string $path = null): ?string
    {
        $fromProcess = getenv($key);
        if ($fromProcess !== false && trim((string) $fromProcess) !== '') {
            return okv_test_env_clean((string) $fromProcess);
        }

        $path ??= okv_test_env_path();
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return null;
        }

        $found = null;
        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '' || $trimmed[0] === '#') {
                continue;
            }
            $eq = strpos($line, '=');
            if ($eq === false || trim(substr($line, 0, $eq)) !== $key) {
                continue;
            }
            // The last assignment wins, the way a reader going down the file
            // would take it.
            $found = okv_test_env_clean(substr($line, $eq + 1));
        }
        return $found;
    }
}

// Run directly, it answers one key for a shell caller: prints the value and
// exits 0, or prints nothing and exits 1 when the key is set nowhere.
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    if (!isset($argv[1]) || $argv[1] === '') {
        fwrite(STDERR, "usage: php scripts/tests/lib/env_value.php KEY\n");
        exit(2);
    }
    $okvValue = okv_test_env_value($argv[1], $argv[2] ?? null);
    if ($okvValue === null) {
        exit(1);
    }
    fwrite(STDOUT, $okvValue);
    exit(0);
}

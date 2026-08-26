<?php
/**
 * includes/config/env.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Minimal .env loader (no Composer dependency).
 *
 * Loads the .env file at the application root exactly once per request,
 * populates $_ENV / $_SERVER / getenv(), and exposes an env() helper.
 *
 * Search order for the .env file:
 *   1. Path in the OKV_ENV_PATH environment variable (set via cPanel or SetEnv).
 *   2. <app_root>/.env  (default; app root = 2 levels above this file).
 *
 * Placing .env OUTSIDE public_html is stronger. To do so, move the file and set
 * OKV_ENV_PATH via cPanel MultiPHP INI Editor or a SetEnv line in .htaccess.
 * -----------------------------------------------------------------------------
 */

// Prevent direct HTTP access.
if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

if (defined('OKV_ENV_LOADED')) {
    return;
}

/**
 * Parse a .env file into an associative array.
 * Supports comments, blank lines, KEY=value, and single or double quoted values.
 * Everything after the first "=" is the value, so values may contain "=".
 */
function okv_parse_env_file(string $path): array
{
    $out = [];
    if (!is_file($path) || !is_readable($path)) {
        return $out;
    }
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return $out;
    }
    foreach ($lines as $line) {
        $trim = ltrim($line);
        if ($trim === '' || $trim[0] === '#') {
            continue;
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        $key = trim(substr($line, 0, $eq));
        $val = trim(substr($line, $eq + 1));
        if (strlen($val) >= 2) {
            $first = $val[0];
            $last  = $val[strlen($val) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $val = substr($val, 1, -1);
                if ($first === '"') {
                    $val = str_replace(['\n', '\r', '\t', '\\"', '\\\\'], ["\n", "\r", "\t", '"', '\\'], $val);
                }
            }
        }
        if ($key !== '') {
            $out[$key] = $val;
        }
    }
    return $out;
}

$__okv_env_path = getenv('OKV_ENV_PATH') ?: null;
if (!$__okv_env_path) {
    $__okv_env_path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';
}
$__okv_env_vars = okv_parse_env_file($__okv_env_path);

foreach ($__okv_env_vars as $__k => $__v) {
    if (getenv($__k) === false) {
        putenv("$__k=$__v");
    }
    if (!isset($_ENV[$__k])) {
        $_ENV[$__k] = $__v;
    }
    if (!isset($_SERVER[$__k])) {
        $_SERVER[$__k] = $__v;
    }
}
unset($__k, $__v, $__okv_env_path, $__okv_env_vars);

/**
 * Read an environment value with sensible type coercion.
 *   env('APP_DEBUG', false)              -> bool
 *   env('SESSION_LIFETIME_MINUTES', 30)  -> int
 *   env('DB_PASS')                       -> string
 */
if (!function_exists('env')) {
    function env(string $key, $default = null)
    {
        $val = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($val === false || $val === null || $val === '') {
            return $default;
        }
        $lower = strtolower(trim((string) $val));
        if (is_bool($default) || in_array($lower, ['true', 'false', 'yes', 'no', 'on', 'off'], true)) {
            if (in_array($lower, ['true', 'yes', 'on', '1'], true))  return true;
            if (in_array($lower, ['false', 'no', 'off', '0'], true)) return false;
        }
        if ($lower === 'null') {
            return null;
        }
        if (is_int($default) && is_numeric($val)) {
            return (int) $val;
        }
        if (is_float($default) && is_numeric($val)) {
            return (float) $val;
        }
        return $val;
    }
}

define('OKV_ENV_LOADED', true);

<?php
/**
 * includes/functions/assets.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Cache-busted asset URLs. okv_asset('/assets/css/tailwind.css')
 * appends the file's modification time so a new deploy is never served from a
 * stale browser cache. Falls back to the plain path if the file is missing.
 * -----------------------------------------------------------------------------
 */

if (!function_exists('okv_asset')) {
    function okv_asset(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $file = dirname(__DIR__, 2) . $path;
        if (is_file($file)) {
            return $path . '?v=' . filemtime($file);
        }
        return $path;
    }
}

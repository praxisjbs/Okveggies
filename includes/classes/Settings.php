<?php
/**
 * includes/classes/Settings.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Reads and writes site_settings. Everything configurable in the
 * admin Settings screen goes through here: the deposit percentage, the delivery
 * cutoff time, the support WhatsApp number, and so on. Nothing is hardcoded.
 * Values are cached per request.
 * -----------------------------------------------------------------------------
 */

final class Settings
{
    private static ?array $cache = null;

    private static function load(): void
    {
        if (self::$cache !== null) {
            return;
        }
        self::$cache = [];
        try {
            foreach (Database::all('SELECT setting_key, setting_value, value_type FROM site_settings') as $r) {
                self::$cache[$r['setting_key']] = self::coerce($r['setting_value'], $r['value_type']);
            }
        } catch (Throwable $e) {
            // Degrade to defaults if the table is not there yet (partial deploy).
            error_log('Settings load: ' . $e->getMessage());
        }
    }

    private static function coerce(string $value, string $type)
    {
        switch ($type) {
            case 'int':   return (int) $value;
            case 'float': return (float) $value;
            case 'bool':  return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
            case 'json':  return json_decode($value, true);
            default:      return $value;
        }
    }

    /**
     * Drop the per-request cache. The only caller is a settings write that was
     * rolled back: Settings::set updates the cache as it writes, so after a
     * rollback the cache holds a value the database never took.
     */
    public static function flushCache(): void
    {
        self::$cache = null;
    }

    public static function get(string $key, $default = null)
    {
        self::load();
        return array_key_exists($key, self::$cache) ? self::$cache[$key] : $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::get($key, $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key, $default);
        return is_bool($v) ? $v : in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    public static function str(string $key, string $default = ''): string
    {
        $v = self::get($key, $default);
        return is_scalar($v) ? (string) $v : $default;
    }

    /** Deposit percentage the storefront and checkout use. Configurable. */
    public static function depositPercentage(): float
    {
        return (float) self::get('deposit_percentage_default', 30);
    }

    public static function set(string $key, $value, string $type = 'string', ?int $userId = null): void
    {
        $store = is_bool($value) ? ($value ? 'true' : 'false')
               : (is_array($value) ? json_encode($value) : (string) $value);
        Database::run(
            'INSERT INTO site_settings (setting_key, setting_value, value_type, updated_by)
             VALUES (:k, :v, :t, :u)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), value_type = VALUES(value_type), updated_by = VALUES(updated_by)',
            [':k' => $key, ':v' => $store, ':t' => $type, ':u' => $userId]
        );
        if (self::$cache !== null) {
            self::$cache[$key] = self::coerce($store, $type);
        }
    }
}

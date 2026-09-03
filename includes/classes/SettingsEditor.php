<?php
/**
 * includes/classes/SettingsEditor.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The write half of site settings: the registry, the validation and
 * the transactional save behind the admin Settings screen (PRD Section 17.2).
 *
 * Settings.php stays the read path the whole app already uses. This class is the
 * only thing that writes a setting from a request, and it does three jobs:
 *
 *   1. Reads the field registry, so only a registered key can ever be written.
 *   2. Validates a whole tab at once, returning every problem rather than the
 *      first, so a person fixes one screenful instead of one field at a time.
 *   3. Saves a tab in one transaction, writing an audit_logs row for every value
 *      that actually moved. A tab never lands half applied, and a change is
 *      never made without a record of who made it.
 *
 * validate() touches no database, so the rules are unit tested without one.
 * -----------------------------------------------------------------------------
 */

final class SettingsEditor
{
    /** entity_type used on every audit row this class writes. */
    public const AUDIT_ENTITY = 'site_settings';

    /** action used on every audit row this class writes. */
    public const AUDIT_ACTION = 'settings.update';

    private static ?array $groups = null;
    private static ?array $fixed  = null;

    private static function loadRegistry(): void
    {
        if (self::$groups !== null) {
            return;
        }
        $OKV_SETTINGS_GROUPS = [];
        $OKV_SETTINGS_FIXED  = [];
        require __DIR__ . '/../config/settings_fields.php';
        self::$groups = $OKV_SETTINGS_GROUPS;
        self::$fixed  = $OKV_SETTINGS_FIXED;
    }

    /** Every tab, in the order the screen shows them. */
    public static function groups(): array
    {
        self::loadRegistry();
        return self::$groups;
    }

    /** One tab, or null when the request named a tab that does not exist. */
    public static function group(string $key): ?array
    {
        self::loadRegistry();
        return self::$groups[$key] ?? null;
    }

    /** The keys that exist but are not editable, with the reason. */
    public static function fixed(): array
    {
        self::loadRegistry();
        return self::$fixed;
    }

    /** True when this key is registered in this tab. Nothing else is writable. */
    public static function isEditable(string $groupKey, string $fieldKey): bool
    {
        $group = self::group($groupKey);
        return $group !== null && isset($group['fields'][$fieldKey]);
    }

    /**
     * Current values for every key in a tab, read through Settings so the screen
     * and the storefront can never disagree about what is stored.
     */
    public static function values(string $groupKey): array
    {
        $group = self::group($groupKey);
        if ($group === null) {
            return [];
        }
        $out = [];
        foreach ($group['fields'] as $key => $field) {
            $out[$key] = Settings::get($key, self::emptyFor($field));
        }
        return $out;
    }

    /** What an unset key of this type reads as, so a template never sees null. */
    private static function emptyFor(array $field)
    {
        switch ($field['type']) {
            case 'bool':                      return false;
            case 'percent': case 'days': case 'minutes': case 'money': return 0;
            default:                          return '';
        }
    }

    // --- Validation ----------------------------------------------------------

    /**
     * Check one tab's worth of submitted values. Pure: no database, no session.
     *
     * A key the caller did not submit is left alone rather than blanked, so a
     * partial post can never wipe a value the person never saw.
     *
     * @param  string $groupKey  Tab name, 'order' or 'site'.
     * @param  array  $input     Raw request values, keyed by setting key.
     * @return array{clean: array, errors: array<string,string>}
     */
    public static function validate(string $groupKey, array $input): array
    {
        $group = self::group($groupKey);
        if ($group === null) {
            return ['clean' => [], 'errors' => ['_group' => 'That is not a settings tab.']];
        }

        $clean  = [];
        $errors = [];

        foreach ($group['fields'] as $key => $field) {
            // A checkbox that is off sends nothing, so a bool is always present.
            if (!array_key_exists($key, $input) && $field['type'] !== 'bool') {
                continue;
            }
            $raw = $input[$key] ?? '';
            if (!is_scalar($raw) && $raw !== null) {
                $errors[$key] = 'That value is not something we can read.';
                continue;
            }
            $result = self::validateField($field, (string) ($raw ?? ''));
            if ($result['error'] !== null) {
                $errors[$key] = $result['error'];
            } else {
                $clean[$key] = $result['value'];
            }
        }

        return ['clean' => $clean, 'errors' => $errors];
    }

    /**
     * Check one value against its field rule.
     *
     * @return array{value: mixed, error: string|null}
     */
    public static function validateField(array $field, string $raw): array
    {
        $value = trim($raw);
        $label = $field['label'] ?? 'This value';

        switch ($field['type']) {

            case 'percent':
            case 'days':
            case 'minutes':
                if ($value === '' || !preg_match('/^-?\d+$/', $value)) {
                    return self::bad($label . ' has to be a whole number.');
                }
                $n   = (int) $value;
                $min = (int) ($field['min'] ?? 0);
                $cap = (int) ($field['cap'] ?? 100);
                if ($n < $min || $n > $cap) {
                    return self::bad($label . ' has to be between ' . $min . ' and ' . $cap . '.');
                }
                return self::good($n);

            case 'money':
                // Typed in naira, stored in kobo. One helper does the maths.
                $stripped = str_replace([',', ' ', "\u{20A6}"], '', $value);
                if ($stripped === '') {
                    $stripped = '0';
                }
                if (!preg_match('/^\d+(\.\d{1,2})?$/', $stripped)) {
                    return self::bad($label . ' has to be an amount in naira, for example 5,000.');
                }
                $subunit = Money::toSubunit($stripped);
                $min     = (int) ($field['min'] ?? 0);
                $cap     = (int) ($field['cap'] ?? OKV_SETTINGS_MAX_MIN_ORDER_SUBUNIT);
                if ($subunit < $min || $subunit > $cap) {
                    return self::bad($label . ' has to be between ' . Money::format($min) . ' and ' . Money::format($cap) . '.');
                }
                return self::good($subunit);

            case 'time':
                if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value)) {
                    return self::bad($label . ' has to be a time on the 24-hour clock, for example 16:00.');
                }
                return self::good($value);

            case 'bool':
                return self::good(in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true));

            case 'email':
                if ($value === '') {
                    return empty($field['required'])
                        ? self::good('')
                        : self::bad($label . ' cannot be empty.');
                }
                if (mb_strlen($value) > (int) ($field['max'] ?? 190) || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return self::bad($label . ' has to be a working email address.');
                }
                return self::good($value);

            case 'phone':
                $digits = preg_replace('/\D+/', '', $value);
                if ($digits === '') {
                    return empty($field['required'])
                        ? self::good('')
                        : self::bad($label . ' cannot be empty.');
                }
                if (strlen($digits) < 10 || strlen($digits) > 15) {
                    return self::bad($label . ' has to be 10 to 15 digits in international form, for example 2348000000000.');
                }
                return self::good($digits);

            case 'text':
            default:
                $max = (int) ($field['max'] ?? 255);
                if ($value === '' && !empty($field['required'])) {
                    return self::bad($label . ' cannot be empty.');
                }
                if (mb_strlen($value) > $max) {
                    return self::bad($label . ' has to be ' . $max . ' characters or fewer.');
                }
                // A control character in a value the storefront prints is never
                // wanted, and a newline in a one-line field is a paste accident.
                return self::good(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $value));
        }
    }

    private static function good($value): array
    {
        return ['value' => $value, 'error' => null];
    }

    private static function bad(string $message): array
    {
        return ['value' => null, 'error' => $message];
    }

    // --- Saving --------------------------------------------------------------

    /**
     * What a validated set of values would change, without writing anything.
     * The screen uses it to spell out a deposit change in words before it saves,
     * and save() uses it so an unchanged field never writes an audit row.
     *
     * @return array<string, array{label: string, from: mixed, to: mixed, confirm: bool}>
     */
    public static function diff(string $groupKey, array $clean): array
    {
        $group = self::group($groupKey);
        if ($group === null) {
            return [];
        }
        $changes = [];
        foreach ($clean as $key => $new) {
            $field = $group['fields'][$key] ?? null;
            if ($field === null) {
                continue;
            }
            $old = Settings::get($key, self::emptyFor($field));
            if (self::sameValue($old, $new, $field)) {
                continue;
            }
            $changes[$key] = [
                'label'   => $field['label'],
                'from'    => self::display($field, $old),
                'to'      => self::display($field, $new),
                'confirm' => !empty($field['confirm']),
            ];
        }
        return $changes;
    }

    /** Compare in the field's own terms, so "30" and 30 are not a change. */
    private static function sameValue($old, $new, array $field): bool
    {
        if ($field['type'] === 'bool') {
            return (bool) $old === (bool) $new;
        }
        if (in_array($field['type'], ['percent', 'days', 'money'], true)) {
            return (int) $old === (int) $new;
        }
        return (string) $old === (string) $new;
    }

    /** One value written the way a person reads it, for the confirm step and the log. */
    public static function display(array $field, $value): string
    {
        switch ($field['type']) {
            case 'percent': return ((int) $value) . '%';
            case 'days':    return ((int) $value) === 1 ? '1 day' : ((int) $value) . ' days';
            case 'money':   return Money::format((int) $value);
            case 'bool':    return $value ? 'On' : 'Off';
            default:        return ((string) $value) === '' ? 'Not set' : (string) $value;
        }
    }

    /**
     * Save one tab. All of it or none of it.
     *
     * Every value that actually moved is written through Settings::set and gets
     * its own audit_logs row inside the same transaction, so the record and the
     * change cannot come apart. A value that did not move writes nothing.
     *
     * @param  string   $groupKey Tab name.
     * @param  array    $clean    Values that already came back clean from validate().
     * @param  int|null $userId   Who is saving.
     * @return array<string, array> The changes that were applied, keyed by setting key.
     * @throws DomainException 'unknown_group'
     */
    public static function save(string $groupKey, array $clean, ?int $userId): array
    {
        $group = self::group($groupKey);
        if ($group === null) {
            throw new DomainException('unknown_group');
        }

        $changes = self::diff($groupKey, $clean);
        if (!$changes) {
            return [];
        }

        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            foreach (array_keys($changes) as $key) {
                $field = $group['fields'][$key];
                $before = Database::one(
                    'SELECT id, setting_value FROM site_settings WHERE setting_key = :k',
                    [':k' => $key]
                );

                Settings::set($key, $clean[$key], $field['value_type'], $userId);

                $row = Database::one('SELECT id FROM site_settings WHERE setting_key = :k', [':k' => $key]);

                Audit::record(
                    self::AUDIT_ACTION,
                    self::AUDIT_ENTITY,
                    $row ? (int) $row['id'] : null,
                    [$key => $before['setting_value'] ?? null],
                    [$key => self::store($clean[$key])],
                    $userId
                );
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            // Settings::set updated its per-request cache before the rollback,
            // so drop it rather than serve a value the database does not hold.
            Settings::flushCache();
            throw $e;
        }

        return $changes;
    }

    /**
     * Fill {{tokens}} the way Mail does, so a preview on the settings screen is
     * the same rendering the customer will receive rather than a lookalike.
     */
    public static function fillTemplate(string $template, array $vars): string
    {
        return trim((string) preg_replace_callback(
            '/\{\{\s*([a-z0-9_]+)\s*\}\}/i',
            static fn(array $m): string => isset($vars[$m[1]]) ? (string) $vars[$m[1]] : '',
            $template
        ));
    }

    /**
     * Believable stand-in values for one template's tokens, so a preview reads
     * like a real email instead of a row of empty gaps. Sample only: nothing
     * here is ever sent.
     */
    public static function sampleTokens(string $templateKey): array
    {
        $base = rtrim((string) (defined('APP_URL') ? APP_URL : ''), '/');
        $all = [
            'customer_name'  => 'Ada',
            'order_number'   => 'OKV26014',
            'delivery_day'   => 'Thursday 24th September',
            'order_total'    => Money::format(1250000),
            'amount'         => Money::format(500000),
            'zone_name'      => 'Ikeja',
            'payment_choice' => 'Deposit online, balance on delivery',
            'source_line'    => okv_sourced_line(Settings::str('source_regions', 'Ogun State'), Settings::str('source_day', 'Tuesday')),
            'balance_line'   => 'There is still ' . Money::format(750000) . ' to settle on this order.',
            'money_line'     => 'We are sending ' . Money::format(500000) . ' back to you.',
            'reason'         => 'The customer bank account could not be reached.',
            'order_trail_url' => $base . '/public/order.php?token=sample',
            'admin_url'      => $base . '/admin/orders.php?order=1',
        ];
        $tokens = Notifications::TOKENS[$templateKey] ?? [];
        $sample = [];
        foreach ($tokens as $token) {
            $sample[$token] = $all[$token] ?? '';
        }
        return $sample;
    }

    /** Every notification template with the words currently stored for it. */
    public static function templates(): array
    {
        $stored = [];
        foreach (Database::all('SELECT template_key, subject_template, body_template, is_active, updated_at FROM notification_templates') as $row) {
            $stored[(string) $row['template_key']] = $row;
        }
        $out = [];
        foreach (Notifications::EVENTS as $event => $definition) {
            $key = $definition['template'];
            $row = $stored[$key] ?? null;
            $out[$key] = [
                'key'      => $key,
                'label'    => $definition['label'],
                'audience' => $definition['audience'],
                'subject'  => (string) ($row['subject_template'] ?? ''),
                'body'     => (string) ($row['body_template'] ?? ''),
                'missing'  => $row === null,
                'tokens'   => Notifications::TOKENS[$key] ?? [],
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ];
        }
        return $out;
    }

    /** The string form Settings::set will store, so the audit row matches the column. */
    private static function store($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return is_array($value) ? (string) json_encode($value) : (string) $value;
    }
}

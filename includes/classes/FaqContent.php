<?php
/** Safe FAQ parsing, operational token resolution and presentation data. */
final class FaqContent
{
    private const DAY_NAMES = [
        1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday',
        5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday',
    ];

    /** Labels and examples shown beside the FAQ editor. */
    public static function tokenDefinitions(): array
    {
        return [
            'deposit_percentage' => ['Deposit percentage', 'Current checkout deposit, for example 30%.'],
            'household_delivery_schedule' => ['Household delivery schedule', 'Active household days, cutoffs and lead times.'],
            'business_delivery_schedule' => ['Business delivery schedule', 'Active business days, cutoffs and lead times.'],
            'cancellation_policy' => ['Cancellation policy', 'The current checkout cancellation wording.'],
            'make_it_right_window' => ['Make It Right window', 'Current reporting window, for example 7 days.'],
            'kitchen_run_quote_window' => ['Kitchen Run quote window', 'Current quote life, for example 7 days.'],
            'support_whatsapp_number' => ['WhatsApp number', 'The current support WhatsApp number.'],
            'support_email' => ['Support email', 'The current support email address.'],
        ];
    }

    /** Complete, unique entries in stored order, ready for a safe template. */
    public static function present(string $body, ?array $fixedValues = null): array
    {
        $parsed = ContentPages::validateFaq($body);
        $items = [];
        $anchors = [];
        $seen = [];
        foreach ($parsed['items'] as $item) {
            $question = trim((string) ($item['question'] ?? ''));
            $answer = trim((string) ($item['answer'] ?? ''));
            if ($question === '' || $answer === '') {
                continue;
            }
            $duplicateKey = self::questionKey($question);
            if (isset($seen[$duplicateKey])) {
                continue;
            }
            $seen[$duplicateKey] = true;
            $resolved = self::resolveTokens($answer, $fixedValues);
            $rendered = ContentRenderer::render($resolved);
            $items[] = [
                'question' => $question,
                'answer' => $resolved,
                'answer_html' => $rendered['html'],
                'anchor' => self::uniqueAnchor($question, $anchors),
            ];
        }
        return $items;
    }

    /** Resolve only documented tokens. Unknown text remains harmless text. */
    public static function resolveTokens(string $text, ?array $fixedValues = null): string
    {
        return (string) preg_replace_callback(
            '/\{\{([a-z0-9_]+)\}\}/u',
            static function (array $match) use ($fixedValues): string {
                $token = (string) $match[1];
                if (!in_array($token, ContentPages::FAQ_TOKENS, true)) {
                    return (string) $match[0];
                }
                if ($fixedValues !== null && array_key_exists($token, $fixedValues)) {
                    return (string) $fixedValues[$token];
                }
                return self::liveValue($token);
            },
            $text
        );
    }

    private static function liveValue(string $token): string
    {
        return match ($token) {
            'deposit_percentage' => self::percentage(Settings::depositPercentage()),
            'household_delivery_schedule' => self::deliverySchedule('household'),
            'business_delivery_schedule' => self::deliverySchedule('business'),
            'cancellation_policy' => Cancellation::policyLine(
                Settings::str('cancellation_cutoff_time', '18:00'),
                Settings::bool('cancellation_deposit_forfeit_after_cutoff', true),
                Settings::bool('cancellation_after_dispatch_allowed', true),
                Settings::bool('cancellation_dispatched_forfeit_deposit', true)
            ),
            'make_it_right_window' => self::days(IssueReports::reportingWindowDays()),
            'kitchen_run_quote_window' => self::days(KitchenRuns::quoteDays()),
            'support_whatsapp_number' => Phone::display(Settings::str('support_whatsapp_number', '2348000000000')),
            'support_email' => Settings::str('support_email', 'hello@okveggies.com.ng'),
            default => '',
        };
    }

    private static function deliverySchedule(string $customerType): string
    {
        $parts = [];
        foreach (Delivery::rulesByDay($customerType) as $day => $rule) {
            if (empty($rule['is_active'])) {
                continue;
            }
            $lead = max(0, (int) ($rule['minimum_lead_days'] ?? 0));
            $cutoff = substr((string) ($rule['cutoff_time'] ?? '16:00'), 0, 5);
            $parts[] = (self::DAY_NAMES[(int) $day] ?? 'Delivery day') . ' (' . $cutoff
                . ' cutoff on the previous day, ' . $lead . ' ' . ($lead === 1 ? 'day' : 'days') . ' lead time)';
        }
        if (!$parts) {
            return 'No regular delivery days are currently open. Check the delivery-date picker or contact us.';
        }
        return self::sentenceList($parts) . '. Dated closures and extra delivery days are reflected in the checkout picker.';
    }

    private static function sentenceList(array $parts): string
    {
        if (count($parts) === 1) {
            return $parts[0];
        }
        $last = array_pop($parts);
        return implode(', ', $parts) . ' and ' . $last;
    }

    private static function percentage(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . '%';
    }

    private static function days(int $value): string
    {
        $value = max(0, $value);
        return $value . ' ' . ($value === 1 ? 'day' : 'days');
    }

    private static function questionKey(string $question): string
    {
        $question = mb_strtolower(trim($question));
        $question = (string) preg_replace('/\s+/u', ' ', $question);
        return trim($question, " \t\n\r\0\x0B?.!");
    }

    private static function uniqueAnchor(string $question, array &$used): string
    {
        $base = mb_strtolower($question);
        $base = trim((string) preg_replace('/[^\pL\pN]+/u', '-', $base), '-');
        $base = $base !== '' ? $base : 'question';
        $used[$base] = ($used[$base] ?? 0) + 1;
        return $used[$base] === 1 ? $base : $base . '-' . $used[$base];
    }
}

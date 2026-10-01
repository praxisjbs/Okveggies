<?php
/**
 * includes/classes/ContentSlots.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Every word on a public page, editable from the dashboard.
 *
 * The client's rule is that nothing on a page the public reads is hardcoded:
 * "the copy that must stand is what the user edits in the Content and Messages
 * module". A page has a title and a body, which the Content module already
 * edits. Everything else a page says (the eyebrow over its heading, a lead line,
 * the three steps, a button's label and where it goes, the founder's photograph
 * and its caption, the table of delivery days) is a SLOT.
 *
 * A slot is declared here once: its key, what the editor calls it, what kind of
 * value it holds and how long it may be, and the standard wording the site shows
 * until somebody changes it. The values live in the same draft and published JSON
 * the Content module already stores (`draft_content_data` and `content_data`), so
 * a slot is saved as a draft, previewed, published, audited and protected by the
 * same optimistic lock as the page body. There is no second store, nothing to
 * seed, and the dashboard is the source of truth: the page shows the published
 * value, and only when a slot has never been filled in does it show the standard
 * wording, so a page can never render empty.
 *
 * Kinds
 *   line   one line of plain text.
 *   text   a few lines of plain text.
 *   long   restricted Markdown, the same dialect as the page body.
 *   path   where a button goes: a site path, a #section on the page, an https
 *          address, or the word "whatsapp" for the live support chat link.
 *   rows   one "left | right" pair per line, for a small table.
 *   image  a photograph from the protected upload workflow. It has no text box;
 *          it is uploaded on its own form, and its description is the slot named
 *          by `alt`.
 *
 * Every kind except path and image may carry the operational tokens the FAQ
 * already supports, such as {{make_it_right_window}}, so copy that quotes a
 * policy figure follows the setting instead of going stale.
 * -----------------------------------------------------------------------------
 */
final class ContentSlots
{
    public const LINE_MAX = 160;
    public const TEXT_MAX = 600;
    public const LONG_MAX = 10000;
    public const PATH_MAX = 255;
    public const ROWS_MAX_LINES = 14;

    /** The FAQ's operational tokens, and one more for the report photo limit. */
    public const TOKENS = [
        'deposit_percentage',
        'household_delivery_schedule',
        'business_delivery_schedule',
        'cancellation_policy',
        'make_it_right_window',
        'make_it_right_photos',
        'kitchen_run_quote_window',
        'support_whatsapp_number',
        'support_email',
    ];

    /** Well known destinations offered as suggestions beside every path slot. */
    public const DESTINATIONS = [
        '/' => 'Home',
        '/shop.php' => 'Shop produce',
        '/combos.php' => 'Combos',
        '/kitchen-runs.php' => 'Send a Kitchen Run',
        '/contact.php' => 'Contact us',
        '/account.php' => 'Your account and orders',
        '/account.php?mode=register' => 'Create an account',
        '/account.php?mode=signin' => 'Sign in',
        '/our-story' => 'Our Story',
        '/how-it-works' => 'How It Works',
        '/faq' => 'FAQ',
        '/delivery-policy' => 'Delivery Policy',
        'whatsapp' => 'WhatsApp chat with us',
    ];

    /** @var array<string, list<array{group:string, note:string, slots:array<string, array<string,mixed>>}>>|null */
    private static ?array $registry = null;

    // -------------------------------------------------------------------------
    // The registry
    // -------------------------------------------------------------------------

    /** The pages that have slots, in the order the Content module lists them. */
    public static function pages(): array
    {
        return array_keys(self::registry());
    }

    public static function has(string $slug): bool
    {
        return isset(self::registry()[$slug]);
    }

    /**
     * The groups of slots a page has, for the editor.
     *
     * @return list<array{group:string, note:string, slots:array<string, array<string,mixed>>}>
     */
    public static function groups(string $slug): array
    {
        return self::registry()[$slug] ?? [];
    }

    /** Every slot of a page, flat, keyed by slot key. */
    public static function slots(string $slug): array
    {
        $flat = [];
        foreach (self::groups($slug) as $group) {
            foreach ($group['slots'] as $key => $slot) {
                $flat[$key] = $slot;
            }
        }
        return $flat;
    }

    public static function keys(string $slug): array
    {
        return array_keys(self::slots($slug));
    }

    /** The keys that are photographs. They are not part of the text form. */
    public static function imageKeys(string $slug): array
    {
        return array_keys(array_filter(self::slots($slug), static fn(array $slot): bool => $slot['kind'] === 'image'));
    }

    /** The standard wording of every slot of a page, as written in the registry. */
    public static function defaults(string $slug): array
    {
        $defaults = [];
        foreach (self::slots($slug) as $key => $slot) {
            $defaults[$key] = (string) $slot['default'];
        }
        return $defaults;
    }

    /** Slot keys and their maximum lengths for the text slots, the shape the old homeFields() had. */
    public static function textLimits(string $slug): array
    {
        $limits = [];
        foreach (self::slots($slug) as $key => $slot) {
            if ($slot['kind'] !== 'image') {
                $limits[$key] = (int) $slot['max'];
            }
        }
        return $limits;
    }

    // -------------------------------------------------------------------------
    // Reading a page's copy
    // -------------------------------------------------------------------------

    /**
     * What a page shows: the published value of each slot, or its standard wording
     * when it has never been filled in. Text, line and rows slots come back with
     * their tokens resolved to today's figures. Paths come back ready to put in an
     * href. A long slot comes back as the Markdown it was written in, because the
     * template renders it with html(). An image slot comes back as a path.
     *
     * @param array<string,mixed> $stored the page's published content_data
     * @return array<string,string>
     */
    public static function copy(string $slug, array $stored): array
    {
        $copy = [];
        foreach (self::slots($slug) as $key => $slot) {
            $value = trim((string) ($stored[$key] ?? ''));
            if ($value === '') {
                $value = (string) $slot['default'];
            }
            $copy[$key] = match ($slot['kind']) {
                'line', 'text', 'rows' => self::resolve($value),
                'path'                 => self::href($value),
                default                => $value,
            };
        }
        return $copy;
    }

    /**
     * The values the editor opens with: what is stored, or the standard wording,
     * unresolved, so the box holds exactly what the public sees and what a token
     * looks like when it is there.
     *
     * @param array<string,mixed> $draft the page's draft content_data
     * @return array<string,string>
     */
    public static function formValues(string $slug, array $draft): array
    {
        $values = [];
        foreach (self::slots($slug) as $key => $slot) {
            $value = trim((string) ($draft[$key] ?? ''));
            $values[$key] = $value !== '' ? $value : (string) $slot['default'];
        }
        return $values;
    }

    /** Replace the documented tokens with today's figures. Anything else stays harmless text. */
    public static function resolve(string $text): string
    {
        if (!str_contains($text, '{{')) {
            return $text;
        }
        $text = str_replace('{{make_it_right_photos}}', (string) IssueReports::MAX_PHOTOS, $text);
        return FaqContent::resolveTokens($text);
    }

    /** A long slot as safe HTML, headings included. Tokens are resolved first. */
    public static function html(string $markdown): string
    {
        return ContentRenderer::render(self::resolve($markdown))['html'];
    }

    /**
     * A long slot for a help sheet. Sheets are tighter than a page body: the first
     * paragraph sits flush under the heading and the rest are one step apart, which
     * is how the sheets were set before their words became editable. Same safe
     * Markdown, same tokens, only the spacing classes differ.
     */
    public static function sheetHtml(string $markdown): string
    {
        $html = self::html($markdown);
        $first = true;
        $html = (string) preg_replace_callback(
            '/<p class="mt-5 leading-7 text-ink-60">/',
            static function () use (&$first): string {
                if ($first) {
                    $first = false;
                    return '<p>';
                }
                return '<p class="mt-3">';
            },
            $html
        );
        return str_replace(['<ul class="mt-5 ', '<ol class="mt-5 '], ['<ul class="mt-3 ', '<ol class="mt-3 '], $html);
    }

    /** Where a path slot points. "whatsapp" is the live support chat link. */
    public static function href(string $value): string
    {
        $value = trim($value);
        if ($value === 'whatsapp') {
            return okv_support_whatsapp_url();
        }
        return self::pathError($value) === '' ? $value : '/';
    }

    /**
     * A rows slot as pairs. Lines without a pipe or with an empty side are
     * dropped, so a half typed row can never break the table.
     *
     * @return list<array{0:string,1:string}>
     */
    public static function rows(string $value): array
    {
        $rows = [];
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $value)) as $line) {
            $parts = explode('|', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $left = trim($parts[0]);
            $right = trim($parts[1]);
            if ($left !== '' && $right !== '') {
                $rows[] = [$left, $right];
            }
        }
        return $rows;
    }

    // -------------------------------------------------------------------------
    // Validating what the editor sends
    // -------------------------------------------------------------------------

    /**
     * Clean and check the text slots of a page. Blank is allowed and means "use the
     * standard wording", so nothing is ever required to publish. Photographs are
     * not part of the text form and are ignored here.
     *
     * @param array<string,mixed> $raw what the form posted
     * @return array{clean:array<string,string>, errors:array<string,string>}
     */
    public static function clean(string $slug, array $raw): array
    {
        $clean = [];
        $errors = [];
        foreach (self::slots($slug) as $key => $slot) {
            if ($slot['kind'] === 'image') {
                continue;
            }
            $value = $slot['kind'] === 'line' || $slot['kind'] === 'path'
                ? self::line($raw[$key] ?? '')
                : self::block($raw[$key] ?? '');
            $clean[$key] = $value;
            $message = self::valueError($slot, $value);
            if ($message !== '') {
                $errors['content_data.' . $key] = $message;
            }
        }
        return ['clean' => $clean, 'errors' => $errors];
    }

    /** The reason a value cannot be saved in this slot, or an empty string when it can. */
    public static function valueError(array $slot, string $value): string
    {
        if ($value === '') {
            return '';
        }
        $kind = (string) $slot['kind'];
        $max = (int) $slot['max'];
        $message = ContentPages::slotTextError($value, $max, $kind === 'long');
        if ($message !== '') {
            return $message;
        }
        if ($kind === 'path') {
            return self::pathError($value);
        }
        if ($kind === 'rows') {
            return self::rowsError($value);
        }
        if ($kind !== 'image') {
            return self::tokenError($value);
        }
        return '';
    }

    /** Whether a destination is one a button may point to. Empty string means it is. */
    public static function pathError(string $value): string
    {
        if ($value === '' || $value === 'whatsapp') {
            return '';
        }
        if (mb_strlen($value) > self::PATH_MAX) {
            return 'This destination must be ' . self::PATH_MAX . ' characters or fewer.';
        }
        if (preg_match('/[\x00-\x20\x7f]/', $value)) {
            return 'A destination cannot contain spaces.';
        }
        if (preg_match('/^#[A-Za-z0-9_-]+$/', $value)) {
            return '';
        }
        if (str_starts_with($value, '/') && !str_starts_with($value, '//') && !str_contains($value, '..')
            && preg_match('#^/[A-Za-z0-9._~\-/?=&%\#:@+]*$#', $value)) {
            return '';
        }
        if (str_starts_with($value, 'https://') && filter_var($value, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['https'], true)) {
            return '';
        }
        return 'Use a page on this site such as /shop.php, a #section, an https address, or the word whatsapp.';
    }

    /** Each line of a rows slot is "left | right", with both sides filled in. */
    public static function rowsError(string $value): string
    {
        $lines = array_values(array_filter(
            explode("\n", str_replace(["\r\n", "\r"], "\n", $value)),
            static fn(string $line): bool => trim($line) !== ''
        ));
        if (count($lines) > self::ROWS_MAX_LINES) {
            return 'Use no more than ' . self::ROWS_MAX_LINES . ' rows.';
        }
        foreach ($lines as $index => $line) {
            $parts = explode('|', $line, 2);
            if (count($parts) !== 2 || trim($parts[0]) === '' || trim($parts[1]) === '') {
                return 'Row ' . ($index + 1) . ' needs two parts separated by a | sign, for example: Mon | Household and business.';
            }
            if (mb_strlen(trim($parts[0])) > 60 || mb_strlen(trim($parts[1])) > 160) {
                return 'Row ' . ($index + 1) . ' is too long.';
            }
        }
        return '';
    }

    /** Only documented tokens, each complete. */
    public static function tokenError(string $value): string
    {
        if (preg_match_all('/\{\{([a-z0-9_]+)\}\}/u', $value, $matches)) {
            foreach (array_unique($matches[1]) as $token) {
                if (!in_array($token, self::TOKENS, true)) {
                    return 'This copy uses an unsupported token: {{' . $token . '}}.';
                }
            }
        }
        $without = preg_replace('/\{\{[a-z0-9_]+\}\}/u', '', $value) ?? '';
        if (str_contains($without, '{{') || str_contains($without, '}}')) {
            return 'This copy contains an incomplete token. A token looks like {{support_email}}.';
        }
        return '';
    }

    /**
     * Problems with the photographs a page's draft holds, for publishing. A
     * photograph that is set must have a description, and must be one that came
     * through the protected upload workflow.
     *
     * @param array<string,mixed> $data the draft content_data
     * @return array<string,string>
     */
    public static function imageErrors(string $slug, array $data): array
    {
        $errors = [];
        foreach (self::slots($slug) as $key => $slot) {
            if ($slot['kind'] !== 'image') {
                continue;
            }
            $path = trim((string) ($data[$key] ?? ''));
            if ($path === '') {
                continue;
            }
            if (!self::isUploadedPath($path)) {
                $errors['content_data.' . $key] = 'Choose a verified content image from the protected upload workflow.';
                continue;
            }
            $altKey = (string) ($slot['alt'] ?? '');
            if ($altKey !== '' && trim((string) ($data[$altKey] ?? '')) === '') {
                $errors['content_data.' . $altKey] = 'Describe the photograph before publishing it.';
            }
        }
        return $errors;
    }

    /** A path the upload workflow produced. */
    public static function isUploadedPath(string $path): bool
    {
        return mb_strlen($path) <= 500
            && preg_match('#^/uploads/content/[A-Za-z0-9][A-Za-z0-9._-]*\.(?:jpe?g|png|webp)$#i', $path) === 1;
    }

    /**
     * Keep the photographs a draft already holds when the text form is saved,
     * because the form has no box for them and must never wipe them.
     *
     * @param array<string,mixed> $existing the draft's current content_data
     * @param array<string,string> $cleanText the freshly cleaned text slots
     * @return array<string,string>
     */
    public static function withImages(string $slug, array $existing, array $cleanText): array
    {
        foreach (self::imageKeys($slug) as $key) {
            $value = trim((string) ($existing[$key] ?? ''));
            if ($value !== '') {
                $cleanText[$key] = $value;
            }
        }
        return $cleanText;
    }

    /**
     * The path of the photograph a page should show, and its description: the
     * uploaded one when there is one, the standard picture otherwise.
     *
     * @param array<string,string> $copy the result of copy()
     * @return array{path:string, alt:string, custom:bool}
     */
    public static function image(string $slug, string $key, array $copy): array
    {
        $slot = self::slots($slug)[$key] ?? null;
        $path = (string) ($copy[$key] ?? '');
        $altKey = (string) ($slot['alt'] ?? '');
        return [
            'path' => $path,
            'alt' => $altKey !== '' ? (string) ($copy[$altKey] ?? '') : '',
            'custom' => self::isUploadedPath($path),
        ];
    }

    // -------------------------------------------------------------------------
    // Normalising
    // -------------------------------------------------------------------------

    private static function line($value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', self::block($value)));
    }

    private static function block($value): string
    {
        if (!is_scalar($value) && $value !== null) {
            return '';
        }
        return trim(str_replace(["\r\n", "\r"], "\n", (string) ($value ?? '')));
    }

    // -------------------------------------------------------------------------
    // The registry itself
    // -------------------------------------------------------------------------

    private static function registry(): array
    {
        if (self::$registry !== null) {
            return self::$registry;
        }

        $line  = static fn(string $label, string $default, int $max = 120, string $help = ''): array => ['label' => $label, 'kind' => 'line', 'max' => $max, 'default' => $default, 'help' => $help];
        $text  = static fn(string $label, string $default, int $max = self::TEXT_MAX, string $help = ''): array => ['label' => $label, 'kind' => 'text', 'max' => $max, 'default' => $default, 'help' => $help];
        $long  = static fn(string $label, string $default, int $max = self::LONG_MAX, string $help = ''): array => ['label' => $label, 'kind' => 'long', 'max' => $max, 'default' => $default, 'help' => $help];
        $path  = static fn(string $label, string $default, string $help = ''): array => ['label' => $label, 'kind' => 'path', 'max' => self::PATH_MAX, 'default' => $default, 'help' => $help];
        $rows  = static fn(string $label, string $default, string $help = ''): array => ['label' => $label, 'kind' => 'rows', 'max' => 2000, 'default' => $default, 'help' => $help];
        $image = static fn(string $label, string $default, string $alt, string $help = ''): array => ['label' => $label, 'kind' => 'image', 'max' => 500, 'default' => $default, 'alt' => $alt, 'help' => $help];

        /** A button: its words and where it goes, as two slots with predictable names. */
        $button = static function (string $prefix, string $label, string $words, string $to) use ($line, $path): array {
            return [
                $prefix . '_label' => $line($label . ': words', $words, 60),
                $prefix . '_path'  => $path($label . ': where it goes', $to),
            ];
        };

        /** The closing "Keep going" panel every information page ends with. */
        $closing = static function (array $buttons) use ($line, $button): array {
            $slots = [
                'closing_eyebrow' => $line('Small heading', 'Keep going', 60),
                'closing_heading' => $line('Heading', 'What would you like to do next?', 120),
            ];
            foreach ($buttons as $index => [$words, $to]) {
                $slots += $button('action_' . ($index + 1), 'Button ' . ($index + 1), $words, $to);
            }
            return ['group' => 'Closing buttons', 'note' => 'The panel at the foot of the page.', 'slots' => $slots];
        };

        /** The Make It Right panel How It Works and Delivery Policy both carry. */
        $makeItRight = static function () use ($line, $long): array {
            return [
                'group' => 'Make It Right panel',
                'note' => 'The "if something is not right" panel. {{make_it_right_window}} and {{make_it_right_photos}} follow the settings.',
                'slots' => [
                    'mir_eyebrow'     => $line('Small heading', 'Make It Right', 60),
                    'mir_heading'     => $line('Heading', 'If something is not right', 120),
                    'mir_line'        => $line('One line', 'Report it within {{make_it_right_window}} from your order.', 200),
                    'mir_learn_label' => $line('Learn button', 'Learn', 40),
                    'mir_orders_label' => $line('Button for a signed in customer', 'Open your orders', 60),
                    'mir_signin_label' => $line('Button for a visitor', 'Sign in to report', 60),
                    'mir_sheet_body'  => $long('Learn sheet', "After dispatch or delivery, report a wrong, missing, damaged or late item from that order.\n\nSend it within {{make_it_right_window}}. Add a description and up to {{make_it_right_photos}} photos.\n\nWe record a refund, credit, replacement, or a reason if we cannot approve it.\n\nThe report stays on your signed-in order. It is never on the shareable trail.", 4000),
                ],
            ];
        };

        $registry = [];

        // ---- Homepage ------------------------------------------------------------------------------------------------
        $registry['home'] = [
            ['group' => 'Hero', 'note' => 'The first thing a visitor reads. The photograph is managed under Documentary photograph.', 'slots' => [
                'hero_eyebrow' => $line('Small heading beside the seal', 'Est. 2026. Lagos', 60),
                'hero_heading' => $line('Heading', 'Bringing the Best of the Farm Straight to Your Kitchen.', 160),
                'hero_intro'   => $text('Introduction', 'Freshness You Can Trust. Sourced daily from local farms, carefully selected, and delivered perfectly to you.', 500),
            ] + $button('primary_cta', 'Main button', 'Start shopping', '/shop.php')
              + $button('secondary_cta', 'Second button', 'See the combos', '/combos.php')],
            ['group' => 'The promise', 'note' => 'The three cards under the hero and the sheet behind Learn.', 'slots' => [
                'promise_eyebrow'  => $line('Small heading', 'The OK Veggies promise', 60),
                'promise_heading'  => $line('Heading', 'Sourced right. Priced right. Delivered right.', 160),
                'promise_1_title'  => $line('Card 1: title', 'Sourced right', 60),
                'promise_1_line'   => $line('Card 1: one line', 'Farms we have visited.', 120),
                'promise_2_title'  => $line('Card 2: title', 'Priced right', 60),
                'promise_2_line'   => $line('Card 2: one line', 'Prices we can explain.', 120),
                'promise_3_title'  => $line('Card 3: title', 'Delivered right', 60),
                'promise_3_line'   => $line('Card 3: one line', 'A delivery day you picked.', 120),
                'promise_learn_label' => $line('Learn button', 'Learn', 40),
                'promise_body'     => $long('Learn sheet', 'Farms we have visited. Prices we can explain. A delivery day you picked.'),
            ]],
            ['group' => 'Ways to start an order', 'note' => 'The three buttons under the promise.', 'slots' =>
                  $button('start_shop', 'Shop', 'Shop produce', '/shop.php') + ['start_shop_line' => $line('Shop: one line', "Pick this week's items.", 80)]
                + $button('start_combos', 'Combos', 'Choose a Combo', '/combos.php') + ['start_combos_line' => $line('Combos: one line', 'A ready basket for the pot.', 80)]
                + $button('start_kitchen', 'Kitchen Run', 'Send a Kitchen Run', '/kitchen-runs.php') + ['start_kitchen_line' => $line('Kitchen Run: one line', 'Send your list. We source it.', 80)]],
            ['group' => 'Combos', 'note' => 'The strip of featured combos.', 'slots' => [
                'combos_eyebrow'       => $line('Small heading', 'Cooked together, priced together', 60),
                'combos_heading'       => $line('Heading', "This week's combos", 160),
                'combos_link_label'    => $line('Link to every combo', 'See all combos', 60),
                'combos_empty_heading' => $line('When none is featured: heading', 'No featured combo is on the stall today', 120),
                'combos_empty_body'    => $text('When none is featured: words', 'Browse every available combo, or send the list your kitchen needs.', 240),
                'combos_empty_button'  => $line('When none is featured: button', 'Browse combos', 60),
            ]],
            ['group' => 'Categories', 'note' => 'The aisles.', 'slots' => [
                'categories_eyebrow'       => $line('Small heading', 'Five aisles, one stall', 60),
                'categories_heading'       => $line('Heading', 'Shop by category', 160),
                'categories_empty_heading' => $line('When there are none: heading', 'The category list is being prepared', 120),
                'categories_empty_body'    => $text('When there are none: words', 'Browse the shop or send a Kitchen Run while the aisles are organised.', 240),
                'categories_empty_button'  => $line('When there are none: button', 'Browse the shop', 60),
            ]],
            ['group' => 'This week\'s picks', 'note' => 'The weekly selection of produce.', 'slots' => [
                'products_eyebrow'       => $line('Small heading', 'Picked this week', 60),
                'products_heading'       => $line('Heading', "This week's picks", 160),
                'products_link_label'    => $line('Link to all produce', 'See all produce', 60),
                'products_empty_heading' => $line('When none is picked: heading', 'No produce has been marked as a weekly pick', 120),
                'products_empty_body'    => $text('When none is picked: words', 'Browse the whole shop or send the list your kitchen needs.', 240),
                'products_empty_button'  => $line('When none is picked: button', 'Browse all produce', 60),
            ]],
        ];

        // ---- Our Story -------------------------------------------------------------------------------------------------
        $registry['about'] = [
            ['group' => 'Top of the page', 'note' => 'Above and beside the title. The page photograph is managed under Documentary photograph.', 'slots' => [
                'eyebrow' => $line('Small heading', 'Our story', 60),
                'lead'    => $text('Lead line under the title', 'Fresh produce begins with people, places and work we can stand behind.', 300),
            ]],
            ['group' => 'The founder', 'note' => 'The portrait sits under the heading "The person behind it", or at the end of the story when there is no such heading.', 'slots' => [
                'founder_portrait'     => $image('Portrait', '/assets/img/story/founder-kumbish-emmanuel-putleh.jpg', 'founder_portrait_alt', 'JPEG, PNG or WebP. It is shown at up to 28rem wide.'),
                'founder_portrait_alt' => $line('Portrait description for people who cannot see it', 'Kumbish Emmanuel Putleh, founder of OK Veggies', 255),
                'founder_caption'      => $line('Caption under the portrait', 'Kumbish Emmanuel Putleh, Founder', 160),
            ]],
            $closing([['Browse the shop', '/shop.php'], ['See the combos', '/combos.php'], ['Contact us', '/contact.php']]),
        ];

        // ---- How It Works -----------------------------------------------------------------------------------------------
        $steps = [
            1 => ['Pick what you need', 'Shop produce, a combo, or send a list.'],
            2 => ['Choose a delivery day', 'We source that morning at the market.'],
            3 => ['We bring it over', 'Weighed, packed, at your door.'],
        ];
        $stepSlots = ['eyebrow' => $line('Small heading', 'From list to doorstep', 60), 'tap_label' => $line('Words on each card', 'Tap for details', 40), 'learn_label' => $line('Learn button', 'Learn', 40), 'body_sheet_title' => $line('Learn sheet: title', 'How a delivery works', 120)];
        foreach ($steps as $n => [$title, $oneLine]) {
            $stepSlots["step_{$n}_title"] = $line("Step $n: title", $title, 60);
            $stepSlots["step_{$n}_line"]  = $line("Step $n: one line", $oneLine, 120);
        }
        $sheetDefaults = [
            1 => "Three ways in, all the same basket. Shop the aisles and add what you see. Take a combo, a ready basket priced below buying it piece by piece. Or send a Kitchen Run list and let us source it for you.\n\nNew to OK Veggies? Create an account first: your name, your phone number and your email. We send a code to the phone and the account is active when you enter it. Once you are signed in the basket remembers you, so you can start it on the phone and finish it at the desk.",
            2 => "Every household order picks a delivery day from the days we run. We source that morning at the market, so what you ordered is what is actually on the stall that week.\n\nAt checkout you confirm the basket and choose how to pay: card, bank transfer, USSD on a feature phone, or business credit if your Pro account has an approved facility. The balance, the day and the address all sit on the order before you pay, so there is nothing to remember after.\n\nYou can cancel before sourcing begins. After that, the cancellation cost for your order applies, and it is always shown to you before you pay, never after.",
            3 => "On your day we weigh everything at the source, pack it, and bring it to your door. Your order trail carries a photograph of the weighed produce, so what the van brings is what the scale showed.\n\nWeighed light, damaged, missing or late? Report it within {{make_it_right_window}} from your order with a description and up to {{make_it_right_photos}} photos. We record a refund, a credit, a replacement, or a reason if we cannot approve it. The report stays on your signed-in order, never on the shareable trail.",
        ];
        $sheetButtons = [
            1 => [['Create an account', '/account.php?mode=register'], ['Start shopping', '/shop.php']],
            2 => [['Start shopping', '/shop.php'], ['See the combos', '/combos.php']],
            3 => [['Read Make It Right', '#make-it-right'], ['Open your orders', '/account.php']],
        ];
        $sheetGroups = [];
        foreach ($steps as $n => [$title]) {
            $slots = ["sheet_{$n}_body" => $long("Step $n sheet: words", $sheetDefaults[$n], 4000)];
            foreach ($sheetButtons[$n] as $index => [$words, $to]) {
                $slots += $button("sheet_{$n}_button_" . ($index + 1), 'Button ' . ($index + 1), $words, $to);
            }
            $sheetGroups[] = ['group' => "Step $n sheet", 'note' => 'What opens when a customer taps the card.', 'slots' => $slots];
        }
        $registry['how-it-works'] = array_merge(
            [['group' => 'The three steps', 'note' => 'The cards under the title. The page body above is shown behind the Learn button.', 'slots' => $stepSlots]],
            $sheetGroups,
            [$makeItRight()],
            [$closing([['Start shopping', '/shop.php'], ['Send a Kitchen Run', '/kitchen-runs.php']])]
        );

        // ---- FAQ ---------------------------------------------------------------------------------------------------------
        $registry['faq'] = [
            ['group' => 'Top of the page', 'note' => 'The questions and answers themselves are the page body.', 'slots' => [
                'eyebrow' => $line('Small heading', 'Questions and answers', 60),
                'lead'    => $text('Line under the title', 'Open a question. Chat if yours is not here.', 240),
            ]],
            ['group' => 'Still need a hand?', 'note' => 'The panel under the questions.', 'slots' => [
                'help_heading' => $line('Heading', 'Still need a hand?', 120),
                'help_line'    => $line('One line', 'Send a form or start a chat.', 160),
            ] + $button('help_contact', 'First button', 'Contact us', '/contact.php')
              + $button('help_chat', 'Second button', 'Chat on WhatsApp', 'whatsapp')],
            ['group' => 'When there are no questions yet', 'note' => 'Shown only while no question is published.', 'slots' => [
                'empty_heading' => $line('Heading', 'We are preparing these answers', 120),
                'empty_body'    => $text('Words', 'There are no published questions just now.', 240),
            ]],
            $closing([['Send us a message', '/contact.php'], ['Chat on WhatsApp', 'whatsapp']]),
        ];

        // ---- The legal pages ------------------------------------------------------------------------------------------------
        $registry['terms'] = [
            ['group' => 'Top of the page', 'note' => 'The terms themselves are the page body.', 'slots' => [
                'eyebrow'   => $line('Small heading', 'Legal information', 60),
                'toc_label' => $line('Heading of the contents list', 'On this page', 60),
            ]],
            $closing([['Back to the shop', '/shop.php'], ['Ask us a question', '/contact.php']]),
        ];
        $registry['privacy'] = [
            ['group' => 'Top of the page', 'note' => 'The privacy notice itself is the page body.', 'slots' => [
                'eyebrow'   => $line('Small heading', 'Your information', 60),
                'toc_label' => $line('Heading of the contents list', 'On this page', 60),
            ]],
            $closing([['Back to the shop', '/shop.php'], ['Ask us a question', '/contact.php']]),
        ];
        $registry['delivery-policy'] = [
            ['group' => 'Top of the page', 'note' => 'The full policy is the page body, shown behind the Read button.', 'slots' => [
                'eyebrow'     => $line('Small heading', 'Delivery and customer care', 60),
                'read_label'  => $line('Read button', 'Read', 40),
                'sheet_title' => $line('Read sheet: title', 'Delivery policy', 120),
            ]],
            ['group' => 'Delivery days', 'note' => 'One row per line: the day, a | sign, then who we deliver to.', 'slots' => [
                'table_day_heading' => $line('Column heading: day', 'Day', 40),
                'table_who_heading' => $line('Column heading: who', 'Who we deliver to', 60),
                'delivery_rows'     => $rows('Rows', "Mon | Household and business\nTue | Business kitchens\nWed | Household\nThu | Household\nFri | Business kitchens\nSat | Household"),
                'delivery_note'     => $line('Line under the table', 'Order by 16:00 the evening before. We source that morning.', 200),
            ]],
            $makeItRight(),
            $closing([['Back to the shop', '/shop.php'], ['Ask us a question', '/contact.php']]),
        ];

        return self::$registry = $registry;
    }
}

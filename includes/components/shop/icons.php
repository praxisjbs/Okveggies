<?php
/**
 * includes/components/shop/icons.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The single-stroke line icon set for the shop. One function, one
 * source of truth, so checkout and basket never hand-draw their own.
 *
 * Every icon is drawn on a 24px grid, stroke 1.5, round caps and joins, and
 * inherits its colour from currentColor. They sit beside a word and never
 * replace it, so each carries aria-hidden="true" and the visible label next to
 * it does the talking. Nothing here is a photograph and nothing is fetched:
 * an icon that has to load is an icon that arrives late.
 * -----------------------------------------------------------------------------
 */

if (!function_exists('okv_icon')) {
    /**
     * The drawn shapes, one per name, on the 24px grid.
     *
     * @return array<string, string>
     */
    function okv_icon_paths(): array
    {
        static $paths = null;
        if ($paths !== null) {
            return $paths;
        }
        $paths = [
            // A woven market basket, the shop's own mark for "things you buy".
            'basket'       => '<path d="M4 10h16l-1.5 9a2 2 0 0 1-2 1.7h-9a2 2 0 0 1-2-1.7L4 10Z"/><path d="M8 10 12 3l4 7"/><path d="M9.5 14v3M14.5 14v3"/>',
            // One person.
            'user'         => '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c1.2-3.2 3.8-4.8 7-4.8s5.8 1.6 7 4.8"/>',
            // Calendar with a marked day, for the delivery picker.
            'calendar'     => '<rect x="4" y="5" width="16" height="15" rx="2"/><path d="M8 3v4M16 3v4M4 10h16"/><path d="M9 14.5h2M13 14.5h2"/>',
            // Payment card.
            'card'         => '<rect x="3" y="5.5" width="18" height="13" rx="2"/><path d="M3 10h18"/><path d="M6.5 14.5h4"/>',
            // Bank transfer: a note changing hands across a counter.
            'banknote'     => '<rect x="3" y="6.5" width="18" height="11" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6.5 12h.01M17.5 12h.01"/>',
            // USSD on a feature phone.
            'phone'        => '<rect x="7" y="3" width="10" height="18" rx="2"/><path d="M11 17.5h2"/>',
            // Handing the basket over at the door.
            'handshake'    => '<path d="M3 7h4l3 2.5a2 2 0 0 0 2.6 0L16 7h5"/><path d="m3 7 4.5 10L12 20l4.5-3L21 7"/><path d="m9.5 12.5 2 1.5"/>',
            // A receipt on account, for business credit.
            'receipt'      => '<path d="M6 3h12v18l-2-1.5L14 21l-2-1.5L10 21l-2-1.5L6 21V3Z"/><path d="M9 8h6M9 12h6"/>',
            // Shield with a check, for payment safety.
            'shield'       => '<path d="M12 3 5 5.8v5.4c0 4.4 3 7.6 7 9.3 4-1.7 7-4.9 7-9.3V5.8L12 3Z"/><path d="m9 11.6 2.1 2.1L15.4 9.4"/>',
            // The order trail: a dotted road from farm to door.
            'trail'        => '<circle cx="5" cy="19" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="5" r="1.6"/><path d="M6.3 17.7 10.7 13.3M13.3 10.7 17.7 6.3"/>',
            // One leaf, one stroke pair.
            'leaf'         => '<path d="M5 19C5 10 10 5 19 5c0 9-5 14-14 14Z"/><path d="M5 19c3-6 7-9 11-11"/>',
            // A small sparkle for the sourced-right microcopy.
            'sparkle'      => '<path d="M12 4c.6 3.8 2.2 5.4 6 6-3.8.6-5.4 2.2-6 6-.6-3.8-2.2-5.4-6-6 3.8-.6 5.4-2.2 6-6Z"/>',
            // Forward arrow for primary buttons.
            'arrow-right'  => '<path d="M4 12h15"/><path d="m13 6 6 6-6 6"/>',
            // Back arrow.
            'arrow-left'   => '<path d="M20 12H5"/><path d="m11 6-6 6 6 6"/>',
            // Check, for the finished step of the progress line.
            'check'        => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
            // Round information mark, for the sheets that carry the detail.
            'info'         => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8h.01"/>',
            // Map pin, for the delivery area.
            'map-pin'      => '<path d="M12 21s-6.5-5.4-6.5-10.3a6.5 6.5 0 0 1 13 0C18.5 15.6 12 21 12 21Z"/><circle cx="12" cy="10.5" r="2.3"/>',
            // A plate with produce, for the basket review step.
            'plate'        => '<circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4.5"/><path d="m9.5 12.5 1.8-3 1.6 2 1.6-2.6"/>',
            // The empty basket, drawn to read as a picture at 80px.
            'basket-empty' => '<path d="M4 10h16l-1.5 9a2 2 0 0 1-2 1.7h-9a2 2 0 0 1-2-1.7L4 10Z"/><path d="M8 10 12 3l4 7"/><path d="M9.5 14v3M14.5 14v3"/><path d="M2.5 6.5 5 10M21.5 6.5 19 10"/>',
        ];
        return $paths;
    }

    /**
     * Print one line icon.
     *
     * @param string $name  The icon name, see okv_icon_paths().
     * @param string $class Size and spacing classes, for example "h-5 w-5".
     */
    function okv_icon(string $name, string $class = 'h-5 w-5'): void
    {
        $paths = okv_icon_paths();
        $d = $paths[$name] ?? '';
        if ($d === '') {
            return;
        }
        echo '<svg class="' . okv_e($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
            . ' stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . $d . '</svg>';
    }
}

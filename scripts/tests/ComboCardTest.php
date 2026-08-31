<?php
/**
 * scripts/tests/ComboCardTest.php
 * The pure image-fallback function used by the storefront combo card and the
 * combo detail page. Answer to M3 decision Q6: a combo with no image_url of
 * its own falls back to the primary photo of the first component in
 * Catalogue::comboComponents order (which orders by combo_package_items.id
 * ascending, so "first" is the row the Manager added first in the builder).
 */

require_once dirname(__DIR__, 2) . '/includes/components/shop/combo_card.php';

// The combo's own image wins whenever it is set, even when components carry one.
okv_test_eq(
    'combos/stew.jpg',
    okv_combo_card_image(
        ['image_url' => 'combos/stew.jpg'],
        [['image' => 'products/tomato.jpg']]
    ),
    'the combo\'s own image_url wins over any component photo'
);

// A trimmed-empty own image is treated as no image, so a saved-then-cleared
// image_url still falls back rather than showing a blank photo.
okv_test_eq(
    'products/tomato.jpg',
    okv_combo_card_image(
        ['image_url' => '   '],
        [['image' => 'products/tomato.jpg']]
    ),
    'a whitespace-only image_url is treated as no image and falls back'
);

// The first component with a photo wins. That is the row the Manager added
// first in the builder, because comboComponents orders by ci.id ascending.
okv_test_eq(
    'products/tomato.jpg',
    okv_combo_card_image(
        [],
        [
            ['image' => ''],
            ['image' => 'products/tomato.jpg'],
            ['image' => 'products/onion.jpg'],
        ]
    ),
    'the first component with a photo carries the fallback'
);

// A missing image column on a component is treated as no photo, so a badly
// shaped row does not trip a warning.
okv_test_eq(
    'products/onion.jpg',
    okv_combo_card_image(
        [],
        [
            [],
            ['image' => 'products/onion.jpg'],
        ]
    ),
    'a component with no image column contributes no fallback'
);

// Nothing on the combo and nothing on any component leaves the caller to
// render "Photo coming soon" rather than a broken image element.
okv_test_eq('', okv_combo_card_image([], []), 'no combo image and no components returns empty');
okv_test_eq(
    '',
    okv_combo_card_image(
        ['image_url' => null],
        [['image' => null], ['image' => '']]
    ),
    'nulls and empties across the board return empty'
);

// customerSaving is what the combo card and the detail page read to decide
// whether to draw the strike-through and the "You save" label. It never
// returns a negative number, so the label never accidentally reads
// "You save -₦100".
okv_test_eq(0, Combos::customerSaving(2000000, 1755000), 'a combo priced above its components draws no strike-through');
okv_test_ok(Combos::customerSaving(1690000, 1755000) > 0, 'a combo priced below its components draws the strike-through');

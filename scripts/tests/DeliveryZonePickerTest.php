<?php
/**
 * scripts/tests/DeliveryZonePickerTest.php
 * OK Veggies. The searchable delivery-area picker, without a database.
 *
 *   1. Delivery::preferredZoneId and zoneIdFrom: which zone a picker opens on,
 *      that only an active zone is ever prefilled, and that nothing is picked
 *      by its position in the list.
 *   2. okv_zone_picker(): the no-JavaScript select it renders (canonical ids,
 *      the database's names, notes and order, hostile text escaped), the
 *      combobox shell it hides until the script takes over, and the notice for
 *      a zone switched off since it was chosen.
 *   3. The wiring: every screen where a zone is chosen uses the one component
 *      and loads the one script, and none of them keeps its own select or a
 *      silent first-zone default.
 *
 * The typed search itself lives in assets/js/zone-picker.js and is held to
 * its word by scripts/tests/zone_picker_test.mjs and, in a real browser, by
 * scripts/tests/zone_picker_visual_test.mjs. The database reads and the
 * server's refusal of an inactive zone are in delivery_db_test.php and
 * zone_picker_http_test.php.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/includes/components/shop/delivery_picker.php';

$render = static function (array $zones, array $options = []): string {
    ob_start();
    okv_zone_picker($zones, $options);
    return (string) ob_get_clean();
};

// Zones shaped exactly as Delivery::zonesActive() returns them. The names and
// ids are made up for the test, on purpose: nothing in the component may
// depend on a particular area existing.
$zones = [
    ['id' => 41, 'name' => 'Harbour Side', 'slug' => 'harbour-side', 'area_note' => 'Old Quay, Ferry Road'],
    ['id' => 7,  'name' => 'Hilltop',      'slug' => 'hilltop',      'area_note' => null],
    ['id' => 93, 'name' => 'Riverbend <b>&"East"',  'slug' => 'riverbend', 'area_note' => 'Mill Lane <script>alert(1)</script>'],
];

// --- 1. Which zone the picker opens on ----------------------------------------

okv_test_eq(7, Delivery::preferredZoneId($zones, [7]), 'an active candidate is prefilled');
okv_test_eq(41, Delivery::preferredZoneId($zones, [0, 41, 7]), 'the first active candidate wins, in the order given');
okv_test_eq(7, Delivery::preferredZoneId($zones, [555, 7]), 'a zone that is not active (or not on the list) is skipped for the next candidate');
okv_test_eq(0, Delivery::preferredZoneId($zones, [555]), 'nothing is prefilled when no candidate is active');
okv_test_eq(0, Delivery::preferredZoneId($zones, []), 'no candidates means no prefill, never the first zone on the list');
okv_test_eq(0, Delivery::preferredZoneId([], [7]), 'no active zones means no prefill');
okv_test_eq(93, Delivery::preferredZoneId($zones, ['93']), 'a posted string id is read as the zone');
okv_test_eq(0, Delivery::preferredZoneId($zones, ['7abc']), 'an id with trailing junk is not a zone');
okv_test_eq(0, Delivery::preferredZoneId($zones, ['Hilltop']), 'a zone name is never accepted as an identity');

okv_test_eq(12, Delivery::zoneIdFrom('12'), 'a run of digits is a zone id');
okv_test_eq(12, Delivery::zoneIdFrom(' 12 '), 'surrounding space is trimmed');
okv_test_eq(12, Delivery::zoneIdFrom(12), 'an integer id is kept');
okv_test_eq(0, Delivery::zoneIdFrom('-4'), 'a negative id is refused');
okv_test_eq(0, Delivery::zoneIdFrom(-4), 'a negative integer is refused');
okv_test_eq(0, Delivery::zoneIdFrom('1e3'), 'exponent notation is refused');
okv_test_eq(0, Delivery::zoneIdFrom('12.0'), 'a decimal is refused');
okv_test_eq(0, Delivery::zoneIdFrom(''), 'an empty value is no zone');
okv_test_eq(0, Delivery::zoneIdFrom(['12']), 'an array is no zone');
okv_test_eq(0, Delivery::zoneIdFrom(null), 'null is no zone');
okv_test_eq(0, Delivery::zoneIdFrom(str_repeat('9', 40)), 'an absurdly long number is refused rather than overflowing');

// --- 2. The markup -------------------------------------------------------------

$html = $render($zones, ['selected' => 7]);

okv_test_ok(str_contains($html, 'name="delivery_zone_id"'), 'the select posts delivery_zone_id');
okv_test_eq(1, substr_count($html, 'name="delivery_zone_id"'), 'exactly one field carries the zone, so the typed text is never posted');
okv_test_ok(!preg_match('/<input[^>]*data-zone-input[^>]*\bname=/', $html), 'the search box has no name attribute');
okv_test_ok((bool) preg_match('/<select[^>]*\brequired\b[^>]*data-zone-select/', $html), 'the select is required by default');
okv_test_ok(str_contains($html, '<option value="">Choose your area</option>'), 'an empty first choice, so nothing is chosen by default');

preg_match_all('/<option value="(\d+)"/', $html, $m);
okv_test_eq(['41', '7', '93'], $m[1], 'options carry the canonical ids, in the order the database gave them');
okv_test_ok((bool) preg_match('/<option value="7"[^>]*\sselected>Hilltop<\/option>/', $html), 'the chosen zone is selected in the no-JavaScript select');
okv_test_eq(1, substr_count($html, ' selected>'), 'only one option is selected');
okv_test_ok(str_contains($html, 'data-note="Old Quay, Ferry Road"'), 'the database note rides on the option for the search');
okv_test_ok((bool) preg_match('/<option value="7">|<option value="7" selected>/', $html), 'a zone with no note carries no data-note');

okv_test_ok(!str_contains($html, '<script>alert(1)</script>'), 'a hostile note is escaped');
okv_test_ok(str_contains($html, 'Mill Lane &lt;script&gt;alert(1)&lt;/script&gt;'), 'the hostile note survives as text');
okv_test_ok(str_contains($html, 'Riverbend &lt;b&gt;&amp;&quot;East&quot;'), 'a hostile name is escaped, quotes included');

okv_test_ok((bool) preg_match('/<div data-zone-combo hidden>/', $html), 'the search box is hidden until the script takes over (the no-JavaScript fallback is the select)');
okv_test_ok(str_contains($html, 'role="combobox"'), 'the search box is a combobox');
okv_test_ok(str_contains($html, 'aria-autocomplete="list"'), 'it announces list autocomplete');
okv_test_ok(str_contains($html, 'aria-expanded="false"'), 'it starts collapsed');
okv_test_ok(str_contains($html, 'aria-controls="delivery_zone_id-listbox"') && str_contains($html, 'id="delivery_zone_id-listbox" role="listbox"'), 'aria-controls points at the listbox');
okv_test_ok(str_contains($html, 'aria-labelledby="delivery_zone_id-label"') && str_contains($html, 'id="delivery_zone_id-label"'), 'the listbox is named by the visible label');
okv_test_ok((bool) preg_match('/<label class="okv-label" id="delivery_zone_id-label" for="delivery_zone_id">Delivery area<\/label>/', $html), 'a visible label is tied to the select');
okv_test_ok(str_contains($html, 'role="status"') && str_contains($html, 'aria-live="polite"'), 'a polite live region announces the count and no results');
okv_test_ok(str_contains($html, 'autocomplete="off"'), 'the browser does not offer its own suggestions over ours');
okv_test_ok((bool) preg_match('/data-zone-toggle[^>]*|min-h-\[44px\] min-w-\[44px\][^>]*data-zone-toggle/', $html) && str_contains($html, 'min-h-[44px] min-w-[44px]'), 'the open button is at least 44px square');
okv_test_ok(str_contains($html, 'min-h-[48px]'), 'the field is at least 48px tall');
okv_test_ok(str_contains($html, '<template data-zone-check>'), 'the tick is a template the script clones, not HTML it builds');
okv_test_ok(str_contains($html, 'data-zone-count="3"'), 'the count is whatever the database gave, not a number written down');
okv_test_ok(!str_contains($html, 'data-zone-stale'), 'no stale notice when nothing was switched off');

$custom = $render($zones, [
    'field' => 'zone_for_test', 'id' => 'kr-zone', 'label' => 'Area', 'placeholder' => 'Choose the area',
    'required' => false, 'icon' => 'map-pin', 'large' => true,
]);
okv_test_ok(str_contains($custom, 'name="zone_for_test"') && str_contains($custom, 'id="kr-zone"'), 'the field name and id can be set');
okv_test_ok(str_contains($custom, '>Area</label>') && str_contains($custom, '>Choose the area</option>'), 'the label and the empty choice can be set');
okv_test_ok(!preg_match('/<select[^>]*\brequired\b/', $custom), 'required can be switched off');
okv_test_ok(str_contains($custom, 'min-h-[56px]') && str_contains($custom, 'pl-12'), 'the large size and the icon pad the field');
okv_test_ok(!str_contains($custom, ' selected>'), 'without a selection nothing is selected');

$stale = $render($zones, ['selected' => 0, 'stale_name' => 'Old <Wharf>']);
okv_test_ok(str_contains($stale, 'data-zone-stale'), 'a switched-off zone gets a notice');
okv_test_ok(str_contains($stale, 'Old &lt;Wharf&gt; is no longer on our delivery list. Choose another area.'), 'the notice names the zone, escaped, and says what to do');
okv_test_ok((bool) preg_match('/<select[^>]*aria-describedby="delivery_zone_id-stale"/', $stale), 'the no-JavaScript select is described by the notice');
okv_test_ok(str_contains($stale, 'aria-describedby="delivery_zone_id-hint delivery_zone_id-stale"'), 'the combobox is described by the hint and the notice');
okv_test_ok(!str_contains($stale, 'Old &lt;Wharf&gt;</option>'), 'the switched-off zone is not offered');

$none = $render([]);
okv_test_ok(str_contains($none, 'data-zone-none'), 'no active zone says so');
okv_test_ok(!preg_match('/<option value="\d+"/', $none), 'and offers nothing to pick');
$quiet = $render([], ['empty_text' => '']);
okv_test_ok(!str_contains($quiet, 'data-zone-none'), 'a page that explains the empty state itself can silence ours');

// --- 3. The wiring ---------------------------------------------------------------

$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);

$surfaces = [
    'checkout.php'                                   => '/assets/js/zone-picker.min.js',
    'kitchen-runs.php'                               => '/assets/js/zone-picker.min.js',
    'admin/order_new.php'                            => '/assets/js/zone-picker.min.js',
    'admin/kitchen_run_new.php'                      => '/assets/js/zone-picker.min.js',
    'includes/components/admin/kitchen_run_panel.php' => null,
];
foreach ($surfaces as $path => $script) {
    $source = $read($path);
    okv_test_ok(str_contains($source, 'okv_zone_picker($zones'), "$path uses the shared area picker");
    okv_test_ok(!preg_match('/<select[^>]*name="delivery_zone_id"/', $source), "$path keeps no select of its own for the zone");
    okv_test_ok(str_contains($source, 'Delivery::preferredZoneId('), "$path opens on an active zone only");
    if ($script !== null) {
        okv_test_ok(str_contains($source, $script), "$path loads the zone picker script");
    }
}
okv_test_ok(str_contains($read('admin/kitchen_runs.php'), '/assets/js/zone-picker.min.js'), 'the staff Kitchen Runs screen loads the zone picker script for its panel');

$kr = $read('kitchen-runs.php');
okv_test_ok(!str_contains($kr, "\$zones[0]"), 'Kitchen Runs no longer defaults to the first zone on the list');
okv_test_ok(!str_contains($kr, 'data-kr-zone-btn'), 'Kitchen Runs no longer draws a button per zone');
okv_test_ok(str_contains($kr, "Delivery::lastRunZoneId(\$userId)"), 'Kitchen Runs prefills the last run\'s area, if still active');
okv_test_ok(str_contains($kr, "okv_input('zone', 0)"), 'Kitchen Runs reopens on the area a refused plain send carried back');
okv_test_ok(!str_contains($read('assets/js/kitchen-runs.js'), 'data-kr-zone'), 'the Kitchen Runs script no longer drives its own zone field');

$checkout = $read('checkout.php');
okv_test_ok(str_contains($checkout, "\$savedDelivery['delivery_zone_id']"), 'checkout reopens on the area saved in the session');
okv_test_ok(str_contains($checkout, 'Delivery::lastOrderZoneId('), 'checkout falls back to the area of the last order');
okv_test_ok(str_contains($checkout, "'stale_name' => \$staleZoneName"), 'checkout names a saved area that has been switched off');

$panel = $read('includes/components/admin/kitchen_run_panel.php');
okv_test_ok(str_contains($panel, "'stale_name'  => \$panelZoneGone"), 'the staff panel names a run\'s area that has been switched off');

$orders = $read('api/v1/orders.php');
okv_test_ok(str_contains($orders, "\$back['zone'] = \$backZone") && str_contains($orders, "\$back['user_id'] = \$backUser"), 'a refused phone order returns to the same customer and area');
$runs = $read('api/v1/kitchen_runs.php');
okv_test_ok(str_contains($runs, "Delivery::zoneIdFrom(okv_input('delivery_zone_id', ''))"), 'a refused plain Kitchen Run post carries the area back as an integer only');

$build = $read('scripts/build-js.mjs');
okv_test_ok(str_contains($build, "'assets/js/zone-picker.js'"), 'the zone picker source is in the JS build');
okv_test_ok(is_file($root . '/assets/js/zone-picker.min.js'), 'the built zone picker exists');
okv_test_ok(
    is_file($root . '/assets/js/zone-picker.min.js')
    && str_contains($read('assets/js/zone-picker.min.js'), 'OKVZonePicker'),
    'the built file is the zone picker'
);

$js = $read('assets/js/zone-picker.js');
okv_test_ok(!preg_match('/new RegExp\s*\(/', $js), 'the search never builds a regular expression from what was typed');
okv_test_ok(!str_contains($js, 'innerHTML'), 'the zone picker never writes innerHTML');
okv_test_ok(str_contains($js, 'aria-activedescendant'), 'the highlighted option is announced through aria-activedescendant');

$css = $read('assets/css/src/input.css');
okv_test_ok(str_contains($css, '.okv-zone-option {') && str_contains($css, 'min-h-[48px]'), 'every option is a 48px target');
okv_test_ok(str_contains($css, ".okv-zone-option.is-active") && str_contains($css, "outline: 2.5px solid theme('colors.gold.DEFAULT')"), 'the highlighted option carries the gold focus ring');

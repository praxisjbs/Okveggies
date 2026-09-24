<?php
/**
 * scripts/tests/lib/zone_picker_fixture.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Prints one page holding the real okv_zone_picker(), the real
 * stylesheet and the real built script, for scripts/tests/zone_picker_visual_test.mjs
 * to drive in a browser. It needs no database: the zones come from a JSON file
 * the test writes, shaped exactly as Delivery::zonesActive() returns them, so
 * the browser test can change the zones between runs and prove nothing about
 * the picker depends on a particular list.
 *
 *   php scripts/tests/lib/zone_picker_fixture.php /tmp/zones.json '{"selected":7}'
 *
 * The form submits by GET to /echo, which the test server answers with what
 * was posted, so the test can read back exactly which fields left the page.
 * This is a test fixture and is never served by the site.
 * -----------------------------------------------------------------------------
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 3);
require_once $root . '/includes/functions/helpers.php';
require_once $root . '/includes/functions/assets.php';
require_once $root . '/includes/components/head_meta.php';
require_once $root . '/includes/classes/Delivery.php';
require_once $root . '/includes/components/shop/delivery_picker.php';

$zonesFile = (string) ($argv[1] ?? '');
$zones     = is_file($zonesFile) ? json_decode((string) file_get_contents($zonesFile), true) : [];
$options   = json_decode((string) ($argv[2] ?? '{}'), true);
if (!is_array($zones)) {
    $zones = [];
}
if (!is_array($options)) {
    $options = [];
}
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Zone picker fixture</title>
  <?php okv_head_meta(['og_title' => 'Zone picker fixture']); ?>
  <link rel="stylesheet" href="<?= okv_e(okv_asset('/assets/css/tailwind.css')) ?>">
</head>
<body class="bg-white">
  <main class="mx-auto max-w-xl px-4 py-8">
    <h1 class="font-display text-2xl font-bold text-ink">Delivery</h1>
    <form class="mt-6 space-y-5" method="get" action="/echo" data-fixture-form>
      <div>
        <label class="okv-label" for="before">Before the picker</label>
        <input class="okv-input" id="before" name="before" value="kept">
      </div>
      <div>
        <?php okv_zone_picker($zones, [
            'selected'   => $options['selected'] ?? 0,
            'stale_name' => (string) ($options['stale_name'] ?? ''),
            'icon'       => !empty($options['icon']) ? 'map-pin' : '',
            'large'      => !empty($options['large']),
        ]); ?>
      </div>
      <div>
        <label class="okv-label" for="after">After the picker</label>
        <input class="okv-input" id="after" name="after" value="">
      </div>
      <button class="okv-btn min-h-[44px] px-6" type="submit" data-fixture-submit>Continue</button>
    </form>
  </main>
  <?php if (empty($options['no_script'])): ?>
  <script src="<?= okv_e(okv_asset('/assets/js/zone-picker.min.js')) ?>" defer></script>
  <?php endif; ?>
</body>
</html>

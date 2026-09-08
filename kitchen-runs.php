<?php
/**
 * kitchen-runs.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The customer half of Kitchen Runs (PRD Section 8): send a list,
 * read the quote we send back, approve it or withdraw it.
 *
 * This is the highest-trust thing we ask a customer to do, so the page is built
 * to be trusted. Every action is a plain HTML form posting to the Kitchen Run
 * API, so the whole flow works with JavaScript switched off; assets/js/
 * kitchen-runs.js only adds and removes list rows, shows a running total and
 * hides the fields a chosen mode does not need. Nothing here depends on it.
 *
 * Four ways to start a list (PRD 8.1), presented as four choices rather than
 * one form with every field on it at once:
 *
 *   Pick from the shop      catalogue lines, we price them from the shop price.
 *   Type your own list      free text, and either we price it or you set a target.
 *   Upload a written list   a photo or a PDF, and we transcribe and price it.
 *   Already priced          your list with your prices, and we only confirm it.
 *
 * The delivery address is captured here, with the list, because a Kitchen Run
 * becomes a real order later, and an order with no address cannot be packed or
 * delivered.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/components/shop/activation_banner.php';
require_once __DIR__ . '/includes/components/shop/header.php';
require_once __DIR__ . '/includes/components/shop/footer.php';
require_once __DIR__ . '/includes/components/shop/support_widget.php';

Customer::requireLogin();

$userId       = (int) Customer::id();
$customerType = Customer::type() ?? 'household';
$current      = Customer::current();

$openId  = (int) okv_input('request', 0);
$request = $openId > 0 ? KitchenRuns::findForCustomer($openId, $userId) : null;
$lines   = $request ? KitchenRuns::lines((int) $request['id']) : [];
$history = $request ? KitchenRuns::history((int) $request['id']) : [];
$runs    = KitchenRuns::allForCustomer($userId);

// The shop picker only ever offers what is on the shop with a price on it.
$products = Database::all(
    'SELECT p.id, p.name, p.current_price_subunit, u.name AS unit_name
       FROM products p
       JOIN units_of_measurement u ON u.id = p.unit_id
      WHERE p.is_active = 1 AND p.current_price_subunit IS NOT NULL
      ORDER BY p.name'
);
$units = Database::all('SELECT id, name FROM units_of_measurement ORDER BY id');

// The address we already know about, so nobody types their street twice.
$saved = Database::one(
    'SELECT * FROM customer_addresses WHERE user_id = :user_id ORDER BY is_default DESC, id LIMIT 1',
    [':user_id' => $userId]
);
$prefill = static function (string $key, string $fallback = '') use ($saved, $current): string {
    if ($saved && trim((string) ($saved[$key] ?? '')) !== '') {
        return (string) $saved[$key];
    }
    return $current && isset($current[$key]) ? (string) $current[$key] : $fallback;
};
$defaultName = trim(($current['first_name'] ?? '') . ' ' . ($current['last_name'] ?? ''));

// One notice band. A success flag and a refusal code are both just a sentence.
$errorCode = trim((string) okv_input('error', ''));
$notice    = null;
if ($errorCode !== '') {
    $notice = ['tone' => 'bad', 'text' => KitchenRuns::message($errorCode)];
} elseif (okv_input('submitted', '') !== '') {
    $notice = ['tone' => 'good', 'text' => 'Your list is with the team. We will price it and send the quote here.'];
} elseif (okv_input('approved', '') !== '') {
    $notice = ['tone' => 'good', 'text' => 'Approved. We are turning it into an order and will be in touch about the deposit.'];
} elseif (okv_input('cancelled', '') !== '') {
    $notice = ['tone' => 'good', 'text' => 'That Kitchen Run has been withdrawn. Nothing has been charged.'];
}

/** The four starts, as the customer thinks of them, not as the database does. */
$starts = [
    'catalogue' => [
        'title' => 'Pick from the shop',
        'blurb' => 'Choose items we already stock. We price them at the shop price.',
        'pricing' => 'by_us',
    ],
    'custom' => [
        'title' => 'Type your own list',
        'blurb' => 'Anything at all, including things we do not stock: pomo, meat, oil.',
        'pricing' => 'by_us',
    ],
    'upload' => [
        'title' => 'Upload a written list',
        'blurb' => 'A photo or a PDF of your list. We read it, type it up and price it.',
        'pricing' => 'by_us',
    ],
    'mixed' => [
        'title' => 'Already priced',
        'blurb' => 'Your list with your own prices on it. We only confirm and get moving.',
        'pricing' => 'already_priced',
    ],
];
$chosen = (string) okv_input('start', 'custom');
if (!isset($starts[$chosen])) {
    $chosen = 'custom';
}

$pageTitle = 'Kitchen Runs, send us your list. OK Veggies';
$canonical = rtrim((string) APP_URL, '/') . '/kitchen-runs.php';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= okv_e($pageTitle) ?></title>
  <meta name="description" content="Send OK Veggies your kitchen list, however you have it written. We source it, price it and deliver it once you have approved the quote.">
  <meta name="robots" content="noindex, nofollow">
  <link rel="canonical" href="<?= okv_e($canonical) ?>">
  <?php okv_head_meta(); ?>
  <link rel="stylesheet" href="<?= okv_e(okv_asset('/assets/css/tailwind.css')) ?>">
</head>
<body class="bg-canvas text-ink">
<?php okv_shop_header('kitchen-runs'); ?>
<?php okv_activation_banner(); ?>

<main class="okv-container py-8 md:py-12">

  <nav aria-label="Breadcrumb" class="text-sm text-ink-60">
    <ol class="flex flex-wrap items-center gap-2">
      <li><a class="hover:text-forest underline-offset-2 hover:underline" href="/">Home</a></li>
      <li aria-hidden="true">/</li>
      <li><a class="hover:text-forest underline-offset-2 hover:underline" href="/shop.php">Shop</a></li>
      <li aria-hidden="true">/</li>
      <li aria-current="page" class="text-ink">Kitchen Runs</li>
    </ol>
  </nav>

  <header class="mt-4 max-w-2xl">
    <h1 class="font-editorial text-3xl md:text-4xl text-ink">Send us your list</h1>
    <p class="mt-3 text-ink-60">
      This is how OK Veggies started. Write out what your kitchen needs, however you have it,
      and we buy it at the market and bring it. Plenty of it will not be on our shop:
      pomo, meat, oil, anything. Send it anyway.
    </p>
    <p class="mt-2 text-ink-60">
      We price your list and send it back. Nothing is charged until you have read the prices and approved them.
    </p>
  </header>

  <?php if ($notice): ?>
    <p class="mt-6 rounded-md border px-4 py-3 text-sm <?= $notice['tone'] === 'good' ? 'border-foliage bg-foliage-tint text-forest' : 'border-tomato bg-tomato-tint text-tomato' ?>" role="status">
      <?= okv_e($notice['text']) ?>
    </p>
  <?php endif; ?>

  <?php if ($request): ?>
    <?php require __DIR__ . '/includes/components/shop/kitchen_run_detail.php'; ?>
  <?php endif; ?>

  <!-- ---------------------------------------------------------------------
       Start a run. Four ways in, one form, and the form only shows the fields
       the chosen way actually needs.
       --------------------------------------------------------------------- -->
  <section class="mt-10" aria-labelledby="start-heading">
    <h2 id="start-heading" class="font-editorial text-2xl text-ink">Start a Kitchen Run</h2>

    <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4" role="group" aria-label="How you want to send your list">
      <?php foreach ($starts as $key => $start): ?>
        <a href="?start=<?= okv_e($key) ?>#start-heading"
           class="block rounded-xl border p-4 min-h-[44px] transition <?= $chosen === $key ? 'border-forest bg-foliage-tint' : 'border-mist bg-white hover:border-forest' ?>"
           <?= $chosen === $key ? 'aria-current="true"' : '' ?>>
          <span class="block font-semibold text-ink"><?= okv_e($start['title']) ?></span>
          <span class="mt-1 block text-sm text-ink-60"><?= okv_e($start['blurb']) ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <form action="/api/v1/kitchen_runs.php" method="post" enctype="multipart/form-data"
          class="mt-6 rounded-xl border border-mist bg-white p-5 md:p-6"
          data-kitchen-run-form data-start="<?= okv_e($chosen) ?>">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="submit">
      <input type="hidden" name="input_mode" value="<?= okv_e($chosen) ?>">

      <!-- Who prices it. Only the typed list gets a genuine choice here. -->
      <?php if ($chosen === 'custom'): ?>
        <fieldset>
          <legend class="okv-label">Who puts the prices on it?</legend>
          <div class="grid gap-2 sm:grid-cols-2">
            <label class="flex items-start gap-3 rounded-lg border border-mist p-3 min-h-[44px]">
              <input type="radio" name="pricing_mode" value="by_us" checked class="mt-1" data-pricing>
              <span>
                <span class="block font-medium">You price it</span>
                <span class="block text-sm text-ink-60">Tell us how much of each thing you want, for example 6kg tomatoes. We fill in the prices.</span>
              </span>
            </label>
            <label class="flex items-start gap-3 rounded-lg border border-mist p-3 min-h-[44px]">
              <input type="radio" name="pricing_mode" value="by_customer" class="mt-1" data-pricing>
              <span>
                <span class="block font-medium">I have a budget per item</span>
                <span class="block text-sm text-ink-60">Give us a figure per item, for example tomatoes ₦35,000, and we buy as much as that gets.</span>
              </span>
            </label>
          </div>
        </fieldset>
      <?php else: ?>
        <input type="hidden" name="pricing_mode" value="<?= okv_e($starts[$chosen]['pricing']) ?>">
      <?php endif; ?>

      <!-- The list itself. -->
      <?php if ($chosen === 'upload'): ?>
        <div class="mt-5">
          <label class="okv-label" for="attachment">Your written list</label>
          <input class="okv-input" type="file" id="attachment" name="attachment" required
                 accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf">
          <p class="mt-1.5 text-sm text-ink-60">
            A photo of the paper is fine. JPEG, PNG or PDF, up to 5MB. We type it up and send you every line with a price on it.
          </p>
        </div>
      <?php else: ?>
        <div class="mt-5">
          <h3 class="okv-label">Your list</h3>
          <div data-kr-rows class="space-y-3">
            <?php for ($row = 0; $row < 3; $row++): ?>
              <?php require __DIR__ . '/includes/components/shop/kitchen_run_row.php'; ?>
            <?php endfor; ?>
          </div>
          <div class="mt-3 flex flex-wrap items-center gap-3">
            <button type="button" class="okv-btn-outline-sm" data-kr-add hidden>Add another item</button>
            <p class="text-sm text-ink-60" data-kr-total hidden></p>
          </div>
          <noscript>
            <p class="mt-3 text-sm text-ink-60">
              Fill in as many of the 3 rows as you need. To send a longer list, upload it instead.
            </p>
          </noscript>
        </div>
      <?php endif; ?>

      <!-- Open budget. PRD 8.2: trust, with a cap that protects both sides. -->
      <?php if ($chosen !== 'mixed'): ?>
        <div class="mt-5 rounded-lg border border-mist bg-canvas p-4">
          <label class="flex items-start gap-3 min-h-[44px]">
            <input type="checkbox" name="is_open_budget" value="1" class="mt-1" data-kr-open>
            <span>
              <span class="block font-medium">Open budget. Just source it.</span>
              <span class="block text-sm text-ink-60">
                For the weeks you would rather we bought whatever it costs. We agree a deposit with you,
                and you can set a cap below that we will not go past.
              </span>
            </span>
          </label>
          <div class="mt-3" data-kr-cap hidden>
            <label class="okv-label" for="spend_cap">Spend cap, in naira. Leave it blank for no cap.</label>
            <input class="okv-input" id="spend_cap" name="spend_cap" inputmode="decimal" placeholder="150,000">
          </div>
        </div>
      <?php endif; ?>

      <!-- Where it goes. Captured now, because this becomes a real order. -->
      <fieldset class="mt-6 border-t border-mist pt-5">
        <legend class="font-semibold text-ink">Where should we deliver it?</legend>
        <p class="mt-1 text-sm text-ink-60">
          The delivery fee is not charged here. We confirm your area and settle the fee with you before the van goes out.
        </p>
        <div class="mt-4 grid gap-4 sm:grid-cols-2">
          <div>
            <label class="okv-label" for="recipient_name">Who receives it</label>
            <input class="okv-input" id="recipient_name" name="recipient_name" required maxlength="150"
                   autocomplete="name" value="<?= okv_e($prefill('recipient_name', $defaultName)) ?>">
          </div>
          <div>
            <label class="okv-label" for="recipient_phone">Phone number</label>
            <input class="okv-input" id="recipient_phone" name="recipient_phone" required maxlength="30"
                   inputmode="tel" autocomplete="tel" value="<?= okv_e($prefill('recipient_phone', (string) ($current['phone'] ?? ''))) ?>">
          </div>
          <div class="sm:col-span-2">
            <label class="okv-label" for="address_line_1">Street address</label>
            <input class="okv-input" id="address_line_1" name="address_line_1" required maxlength="255"
                   autocomplete="address-line1" value="<?= okv_e($prefill('address_line_1')) ?>">
          </div>
          <div class="sm:col-span-2">
            <label class="okv-label" for="address_line_2">Flat, floor or estate, if any</label>
            <input class="okv-input" id="address_line_2" name="address_line_2" maxlength="255"
                   autocomplete="address-line2" value="<?= okv_e($prefill('address_line_2')) ?>">
          </div>
          <div>
            <label class="okv-label" for="city">City</label>
            <input class="okv-input" id="city" name="city" required maxlength="100"
                   autocomplete="address-level2" value="<?= okv_e($prefill('city', 'Lagos')) ?>">
          </div>
          <div>
            <label class="okv-label" for="state">State</label>
            <input class="okv-input" id="state" name="state" required maxlength="100"
                   autocomplete="address-level1" value="<?= okv_e($prefill('state', 'Lagos')) ?>">
          </div>
          <div class="sm:col-span-2">
            <label class="okv-label" for="landmark">A landmark that helps the driver find you</label>
            <input class="okv-input" id="landmark" name="landmark" maxlength="255" value="<?= okv_e($prefill('landmark')) ?>">
          </div>
        </div>
      </fieldset>

      <div class="mt-5">
        <label class="okv-label" for="customer_note">Anything we should know</label>
        <textarea class="okv-input" id="customer_note" name="customer_note" rows="3" maxlength="2000"
                  placeholder="Soft pomo please, and the tomatoes should be firm, not the very ripe ones."></textarea>
      </div>

      <div class="mt-6 flex flex-wrap items-center gap-3">
        <button class="okv-btn" type="submit">Send my list</button>
        <p class="text-sm text-ink-60">We reply with a price. Nothing is charged until you approve it.</p>
      </div>
      <p class="mt-3 text-sm text-tomato" data-kr-error role="alert" hidden></p>
    </form>
  </section>

  <!-- --------------------------------------------------------------------- -->
  <section class="mt-12" aria-labelledby="runs-heading">
    <h2 id="runs-heading" class="font-editorial text-2xl text-ink">Your Kitchen Runs</h2>

    <?php if (!$runs): ?>
      <p class="mt-3 rounded-md border border-mist bg-white px-4 py-6 text-center text-ink-60">
        You have not sent a list yet. The form above is the way in.
      </p>
    <?php else: ?>
      <ul class="mt-4 space-y-3">
        <?php foreach ($runs as $run): ?>
          <li class="rounded-xl border border-mist bg-white p-4">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
              <a class="font-mono font-semibold text-forest underline-offset-2 hover:underline"
                 href="/kitchen-runs.php?request=<?= (int) $run['id'] ?>">
                <?= okv_e($run['request_number']) ?>
              </a>
              <span class="text-sm text-ink-60"><?= okv_e($run['status_label']) ?></span>
            </div>
            <p class="mt-1 text-sm text-ink-60">
              <?= okv_e(date('j M Y', strtotime((string) $run['created_at']))) ?>,
              <?= (int) $run['line_count'] ?> <?= (int) $run['line_count'] === 1 ? 'item' : 'items' ?>
              <?php if ($run['quoted_total_subunit'] !== null): ?>
                , quoted <span class="font-mono"><?= okv_e(Money::format((int) $run['quoted_total_subunit'])) ?></span>
              <?php endif; ?>
            </p>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

</main>

<?php okv_support_widget(); ?>
<?php okv_shop_footer(); ?>
<script src="<?= okv_e(okv_asset('/assets/js/kitchen-runs.min.js')) ?>" defer></script>
</body>
</html>

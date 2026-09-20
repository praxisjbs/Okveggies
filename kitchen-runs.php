<?php
/**
 * kitchen-runs.php
 * ---------------------------------------------------------------------------
 * PR2 Fix 28: 3-tap native flow.
 * Step1 mode cards -> sheet for items -> delivery sheet.
 * 390px 1 viewport/step, 44px targets, 80px illustration, backdrop blur.
 * ---------------------------------------------------------------------------
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/components/shop/activation_banner.php';
require_once __DIR__ . '/includes/components/shop/header.php';
require_once __DIR__ . '/includes/components/shop/footer.php';
require_once __DIR__ . '/includes/components/shop/support_widget.php';
require_once __DIR__ . '/includes/components/shop/delivery_picker.php';
require_once __DIR__ . '/includes/components/shop/icons.php';
require_once __DIR__ . '/includes/components/shop/empty_state.php';

if (!Customer::isLoggedIn()) {
    require __DIR__ . '/includes/components/shop/kitchen_run_intro.php';
    return;
}

$userId       = (int) Customer::id();
$customerType = Customer::type() ?? 'household';
$current      = Customer::current();
$savedListId  = (int) okv_input('saved_list', 0);
$savedList    = $savedListId > 0 && Customer::isBusiness() ? KitchenLists::forRun($savedListId, $userId) : null;
$prefillLines = $savedList['items'] ?? [];

$openId  = (int) okv_input('request', 0);
$request = $openId > 0 ? KitchenRuns::findForCustomer($openId, $userId) : null;
$lines   = $request ? KitchenRuns::lines((int) $request['id']) : [];
$history = $request ? KitchenRuns::history((int) $request['id']) : [];
$runs    = KitchenRuns::allForCustomer($userId);

$products = Database::all(
    'SELECT p.id, p.name, p.current_price_subunit, u.name AS unit_name
       FROM products p
       JOIN units_of_measurement u ON u.id = p.unit_id
      WHERE p.is_active = 1 AND p.current_price_subunit IS NOT NULL
      ORDER BY p.name'
);
$units = Database::all('SELECT id, name FROM units_of_measurement ORDER BY id');
$zones = Delivery::zonesActive();

$prefillDate = '';
$lastDay = Database::one(
    'SELECT preferred_delivery_date FROM kitchen_run_requests
      WHERE user_id = :user AND preferred_delivery_date IS NOT NULL
      ORDER BY id DESC LIMIT 1',
    [':user' => $userId]
);
if ($lastDay && !empty(Delivery::isEligible((string) $lastDay['preferred_delivery_date'], $customerType)['eligible'])) {
    $prefillDate = (string) $lastDay['preferred_delivery_date'];
}

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

$errorCode = trim((string) okv_input('error', ''));
$notice    = null;
if ($errorCode !== '') {
    $notice = ['tone' => 'bad', 'text' => KitchenRuns::message($errorCode)];
} elseif (okv_input('notice', '') === 'pro_business') {
    $notice = ['tone' => 'neutral', 'text' => 'Pro is for business. Your runs are here.'];
} elseif ($savedListId > 0 && $savedList === null) {
    $notice = ['tone' => 'bad', 'text' => 'Saved list unavailable. Pick from My Lists.'];
} elseif ($savedList !== null) {
    $notice = ['tone' => 'good', 'text' => 'Saved list loaded. Check then send.'];
} elseif (okv_input('submitted', '') !== '') {
    $notice = ['tone' => 'good', 'text' => 'List received. We will price it.'];
} elseif (okv_input('approved', '') !== '') {
    $notice = ['tone' => 'good', 'text' => 'Approved. We are making your order.'];
} elseif (okv_input('cancelled', '') !== '') {
    $notice = ['tone' => 'good', 'text' => 'Run withdrawn. Nothing charged.'];
}

$starts = [
    'catalogue' => ['title' => 'Pick from shop', 'blurb' => 'Shop items, shop price.', 'icon' => 'shop', 'pricing' => 'by_us'],
    'custom'    => ['title' => 'Type my list', 'blurb' => 'Anything, even off-shop.', 'icon' => 'edit', 'pricing' => 'by_us'],
    'upload'    => ['title' => 'Upload list', 'blurb' => 'Photo or PDF.', 'icon' => 'upload', 'pricing' => 'by_us'],
    'priced'    => ['title' => 'Already priced', 'blurb' => 'Your prices, we confirm.', 'icon' => 'tag', 'pricing' => 'already_priced'],
];
$chosen = (string) okv_input('start', 'custom');
if ($savedList !== null) { $chosen = 'catalogue'; }
if (!isset($starts[$chosen])) { $chosen = 'custom'; }

$pageTitle = 'Kitchen Runs, send your list. OK Veggies';
$canonical = rtrim((string) APP_URL, '/') . '/kitchen-runs.php';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= okv_e($pageTitle) ?></title>
  <meta name="description" content="Send your kitchen list. We source, price, deliver after approval.">
  <meta name="robots" content="noindex, nofollow">
  <link rel="canonical" href="<?= okv_e($canonical) ?>">
  <?php okv_head_meta(); ?>
  <link rel="stylesheet" href="<?= okv_e(okv_asset('/assets/css/tailwind.css')) ?>">
  <style>
    .kr-dot{width:32px;height:32px;border-radius:9999px;display:flex;align-items:center;justify-content:center;border:1.5px solid #EAE8E8;background:#fff;color:#03100A66;font-size:12px}
    .kr-dot.active{background:#0F5132;border-color:#0F5132;color:#fff}
    .kr-dot.done{background:#EBF2EC;border-color:#0F5132;color:#0F5132}
    .kr-sheet{border-radius:24px 24px 0 0;box-shadow:0 -8px 32px rgba(3,16,10,0.12)}
    .kr-backdrop{background:rgba(3,16,10,0.4);backdrop-filter:blur(8px)}
    .kr-sticky-cta{position:sticky;bottom:0;z-index:20;padding:12px 16px calc(12px + env(safe-area-inset-bottom));background:linear-gradient(to top, #fff 80%, rgba(255,255,255,0))}
  </style>
</head>
<body class="bg-canvas text-ink antialiased">
<?php okv_shop_header('kitchen-runs'); ?>
<?php okv_activation_banner(); ?>

<main id="okv-main" class="okv-container pb-24 md:pb-12">
  <nav aria-label="Breadcrumb" class="pt-3 text-xs text-ink-60">
    <ol class="flex items-center gap-2">
      <li><a class="hover:text-forest underline-offset-2 hover:underline" href="/">Home</a></li>
      <li aria-hidden="true">/</li>
      <li aria-current="page" class="text-ink">Kitchen Runs</li>
    </ol>
  </nav>

  <?php if ($notice): ?>
    <?php $cls = $notice['tone']==='good' ? 'border-foliage bg-foliage/10 text-forest' : ($notice['tone']==='bad' ? 'border-tomato/20 bg-tomato/10 text-tomato' : 'border-mist bg-white'); ?>
    <p class="mt-4 rounded-xl border px-4 py-3 text-sm <?= $cls ?>" role="status" aria-live="polite"><?= okv_e($notice['text']) ?></p>
  <?php endif; ?>

  <?php if ($request): ?>
    <div class="mt-4"><?php require __DIR__ . '/includes/components/shop/kitchen_run_detail.php'; ?></div>
  <?php endif; ?>

  <section class="mt-6" aria-labelledby="kr-heading">
    <div class="flex items-start justify-between gap-4">
      <div>
        <h1 id="kr-heading" class="font-display text-[28px] font-semibold leading-[0.95] tracking-tight">Send your list</h1>
        <p class="mt-2 max-w-[28ch] text-[13px] leading-snug text-ink-60">3 taps. We source it. You approve price.</p>
      </div>
      <div class="shrink-0" aria-hidden="true">
        <svg width="80" height="80" viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg">
          <circle cx="40" cy="40" r="32" fill="#EBF2EC"/>
          <path d="M28 38c4-8 14-14 24-10-2 10-10 18-20 20-2-3-4-6-4-10z" stroke="#0F5132" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
          <path d="M32 42c2-4 7-7 12-6" stroke="#3E8B4A" stroke-width="1.2" stroke-linecap="round"/>
          <path d="M52 48c-2 4-6 6-10 6" stroke="#B85C3E" stroke-width="1.2" stroke-linecap="round"/>
        </svg>
      </div>
    </div>

    <div class="mt-5 flex items-center gap-2" role="tablist" aria-label="Progress">
      <button type="button" class="kr-dot active" data-kr-step-dot="1" aria-current="step" aria-label="Step 1 mode"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/></svg></button>
      <div class="h-px w-6 bg-mist"></div>
      <button type="button" class="kr-dot" data-kr-step-dot="2" aria-label="Step 2 items"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 5h10"/><path d="M9 12h10"/><path d="M9 19h10"/><path d="M5 5v0"/><path d="M5 12v0"/><path d="M5 19v0"/></svg></button>
      <div class="h-px w-6 bg-mist"></div>
      <button type="button" class="kr-dot" data-kr-step-dot="3" aria-label="Step 3 delivery"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 8l2-4h12l2 4v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8z"/><path d="M7 12h10"/></svg></button>
    </div>

    <form action="/api/v1/kitchen_runs.php" method="post" enctype="multipart/form-data" class="mt-6" data-kr-form data-start="<?= okv_e($chosen) ?>">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="submit">
      <input type="hidden" name="input_mode" value="<?= okv_e($chosen) ?>" data-kr-input-mode>
      <input type="hidden" name="pricing_mode" value="<?= okv_e($starts[$chosen]['pricing']) ?>" data-kr-pricing-mode>
      <input type="hidden" name="preferred_delivery_date" value="<?= okv_e($prefillDate) ?>" data-kr-date>
      <input type="hidden" name="delivery_zone_id" value="<?= $zones ? (int)$zones[0]['id'] : '' ?>" data-kr-zone>

      <!-- STEP 1 -->
      <div data-kr-step="1" class="min-h-[70dvh] md:min-h-0">
        <h2 class="font-display text-[22px] font-semibold tracking-tight">How is your list?</h2>
        <p class="mt-1 text-[13px] text-ink-60">Pick one. Next is items.</p>

        <div class="mt-4 grid gap-3 sm:grid-cols-2">
          <?php foreach ($starts as $key => $s): $isActive = $chosen===$key; ?>
          <button type="button" data-kr-mode="<?= okv_e($key) ?>" class="group flex min-h-[88px] items-center gap-4 rounded-[16px] border bg-white p-4 text-left transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold <?= $isActive ? 'border-forest bg-forest/5 ring-1 ring-forest' : 'border-ink-10 hover:border-forest/40' ?>" aria-pressed="<?= $isActive ? 'true' : 'false' ?>">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-mist text-forest group-[.border-forest]:bg-forest group-[.border-forest]:text-white">
              <?php if ($s['icon']==='shop'): ?><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M6 7l-2-3h16l-2 3"/><path d="M6 7v10a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V7"/><path d="M9 11h6"/></svg>
              <?php elseif ($s['icon']==='edit'): ?><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.1 2.1 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
              <?php elseif ($s['icon']==='upload'): ?><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 16V3"/><path d="M8 7l4-4 4 4"/><path d="M20 16v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-4"/></svg>
              <?php else: ?><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M20 12V8a2 2 0 0 0-2-2h-4"/><path d="M4 12V8a2 2 0 0 0 2-2h4"/><path d="M12 20a8 8 0 0 0 8-8"/><path d="M12 20a8 8 0 0 1-8-8"/></svg>
              <?php endif; ?>
            </span>
            <span class="min-w-0">
              <span class="block text-[14px] font-medium leading-tight"><?= okv_e($s['title']) ?></span>
              <span class="mt-0.5 block text-[12px] leading-snug text-ink-60"><?= okv_e($s['blurb']) ?></span>
            </span>
          </button>
          <?php endforeach; ?>
        </div>

        <div class="mt-6 flex items-center gap-2">
          <button type="button" class="inline-flex h-7 w-7 items-center justify-center rounded-full border border-ink-10 text-ink-60 hover:bg-mist" data-kr-help="mode" aria-label="Help about modes">?</button>
          <span class="text-xs text-ink-60">Tap ? for help</span>
        </div>

        <div class="kr-sticky-cta md:static md:bg-transparent md:p-0">
          <button type="button" data-kr-next="1" class="inline-flex min-h-[44px] w-full items-center justify-center rounded-xl bg-forest px-6 text-sm font-medium text-white shadow-sm hover:bg-forest/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold active:scale-[0.98] md:w-auto">Continue</button>
        </div>
      </div>

      <!-- STEP 2 sheet -->
      <div data-kr-step="2" hidden class="min-h-[100dvh] md:min-h-0">
        <h2 class="font-display text-[22px] font-semibold tracking-tight">Your items</h2>
        <p class="mt-1 text-[13px] text-ink-60">Add lines. 44px fields.</p>

        <div class="mt-4 space-y-3" data-kr-rows>
          <?php $rowCount = max(1, count($prefillLines)); for ($row=0;$row<$rowCount;$row++): $prefillLine=$prefillLines[$row]??[]; ?>
            <div data-kr-row class="rounded-[14px] border border-ink-10 bg-white p-3">
              <div class="flex items-center justify-between gap-2">
                <label class="text-xs font-medium text-ink-60" for="kr-name-<?= $row ?>">Item <?= $row+1 ?></label>
                <button type="button" data-kr-remove class="inline-flex min-h-[32px] items-center rounded-full px-2 text-xs text-ink-60 hover:bg-mist" aria-label="Remove item">Remove</button>
              </div>
              <input class="okv-input mt-2 min-h-[44px] rounded-xl" id="kr-name-<?= $row ?>" name="items[<?= $row ?>][item_name]" data-kr-name placeholder="Tomatoes" value="<?= okv_e((string)($prefillLine['item_name']??'')) ?>">
              <div class="mt-2 grid grid-cols-3 gap-2">
                <div>
                  <label class="sr-only" for="kr-qty-<?= $row ?>">Quantity</label>
                  <input class="okv-input min-h-[44px] rounded-xl" id="kr-qty-<?= $row ?>" name="items[<?= $row ?>][quantity]" data-kr-qty inputmode="decimal" placeholder="2" value="<?= okv_e((string)($prefillLine['quantity']??'')) ?>">
                </div>
                <div>
                  <label class="sr-only" for="kr-unit-<?= $row ?>">Unit</label>
                  <select class="okv-input min-h-[44px] rounded-xl" id="kr-unit-<?= $row ?>" name="items[<?= $row ?>][unit_id]">
                    <option value="">Unit</option>
                    <?php foreach ($units as $u): ?><option value="<?= (int)$u['id'] ?>"><?= okv_e($u['name']) ?></option><?php endforeach; ?>
                  </select>
                  <input type="hidden" name="items[<?= $row ?>][unit_label]" value="kg" data-kr-unit-label>
                </div>
                <div data-kr-price-field>
                  <label class="sr-only" for="kr-price-<?= $row ?>">Price</label>
                  <input class="okv-input min-h-[44px] rounded-xl" id="kr-price-<?= $row ?>" name="items[<?= $row ?>][price]" data-kr-price inputmode="decimal" placeholder="₦">
                </div>
              </div>
              <input type="hidden" name="items[<?= $row ?>][note]" value="">
            </div>
          <?php endfor; ?>
        </div>

        <button type="button" data-kr-add class="mt-3 flex min-h-[44px] w-full items-center justify-center gap-2 rounded-xl border border-dashed border-ink-20 bg-white text-sm text-ink-60 hover:bg-mist">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 5v14M5 12h14"/></svg> Add item
        </button>

        <div class="mt-4 rounded-xl bg-mist/60 p-3">
          <label class="flex items-start gap-3">
            <input type="checkbox" name="is_open_budget" value="1" class="mt-1 h-5 w-5 rounded border-ink-20" data-kr-open>
            <span class="text-sm"><span class="font-medium">Open budget.</span> <span class="text-ink-60">We buy, you cap.</span></span>
          </label>
          <div class="mt-2" data-kr-cap hidden>
            <label class="sr-only" for="kr-cap">Spend cap</label>
            <input class="okv-input min-h-[44px] rounded-xl" id="kr-cap" name="spend_cap" inputmode="decimal" placeholder="Cap ₦">
          </div>
        </div>

        <div id="kr-upload" class="mt-4" hidden>
          <label class="okv-label text-xs" for="kr-attachment">Upload list</label>
          <input class="okv-input min-h-[44px] rounded-xl" type="file" id="kr-attachment" name="attachment" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf">
          <p class="mt-1 text-xs text-ink-60">JPEG, PNG, PDF up to 5MB.</p>
        </div>

        <div class="mt-4 flex gap-2">
          <button type="button" data-kr-back="1" class="inline-flex min-h-[44px] items-center rounded-xl border border-ink-10 px-4 text-sm">Back</button>
          <button type="button" class="inline-flex h-7 w-7 items-center justify-center rounded-full border border-ink-10 text-ink-60" data-kr-help="items" aria-label="Help about items">?</button>
        </div>

        <div class="kr-sticky-cta">
          <button type="button" data-kr-next="2" class="inline-flex min-h-[44px] w-full items-center justify-center rounded-xl bg-forest px-6 text-sm font-medium text-white shadow-sm hover:bg-forest/90 focus-visible:ring-2 focus-visible:ring-gold active:scale-[0.98]">Continue to delivery</button>
        </div>
      </div>

      <!-- STEP 3 delivery -->
      <div data-kr-step="3" hidden class="min-h-[100dvh] md:min-h-0">
        <h2 class="font-display text-[22px] font-semibold tracking-tight">Delivery day</h2>
        <p class="mt-1 text-[13px] text-ink-60">Choose day and area.</p>

        <div class="mt-4">
          <p class="text-xs font-medium text-ink-60">Day</p>
          <div class="mt-2 flex flex-wrap gap-2" data-kr-day-chips role="group" aria-label="Delivery days">
            <?php
              $eligible = Delivery::nextEligibleDates($customerType, 14);
              foreach (array_slice($eligible,0,7) as $d):
                $dateStr = (string)($d['date'] ?? '');
                $label = date('D j M', strtotime($dateStr));
            ?>
              <button type="button" data-kr-day="<?= okv_e($dateStr) ?>" class="inline-flex min-h-[44px] items-center rounded-full border border-ink-10 bg-white px-4 text-sm hover:border-forest <?= $dateStr===$prefillDate ? 'border-forest bg-forest text-white' : '' ?>"><?= okv_e($label) ?></button>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="mt-5">
          <p class="text-xs font-medium text-ink-60">Area</p>
          <div class="mt-2 flex flex-wrap gap-2" data-kr-zone-chips role="group" aria-label="Delivery zones">
            <?php foreach ($zones as $z): ?>
              <button type="button" data-kr-zone-btn="<?= (int)$z['id'] ?>" class="inline-flex min-h-[44px] items-center rounded-full border border-ink-10 bg-white px-4 text-sm hover:border-forest"><?= okv_e($z['name']) ?></button>
            <?php endforeach; ?>
          </div>
        </div>

        <details class="mt-5 rounded-xl border border-ink-10">
          <summary class="flex min-h-[44px] cursor-pointer list-none items-center justify-between px-4 text-sm font-medium">Address <span class="text-xs text-ink-60">Tap to edit</span></summary>
          <div class="grid gap-3 border-t border-ink-10 p-4">
            <div class="grid gap-3 sm:grid-cols-2">
              <div><label class="okv-label text-xs" for="kr-recipient">Who receives</label><input class="okv-input min-h-[44px] rounded-xl" id="kr-recipient" name="recipient_name" required value="<?= okv_e($prefill('recipient_name', $defaultName)) ?>"></div>
              <div><label class="okv-label text-xs" for="kr-phone">Phone</label><input class="okv-input min-h-[44px] rounded-xl" id="kr-phone" name="recipient_phone" required value="<?= okv_e($prefill('recipient_phone', (string)($current['phone']??''))) ?>"></div>
            </div>
            <div><label class="okv-label text-xs" for="kr-addr1">Street</label><input class="okv-input min-h-[44px] rounded-xl" id="kr-addr1" name="address_line_1" required value="<?= okv_e($prefill('address_line_1')) ?>"></div>
            <div class="grid gap-3 sm:grid-cols-2">
              <div><label class="okv-label text-xs" for="kr-city">City</label><input class="okv-input min-h-[44px] rounded-xl" id="kr-city" name="city" required value="<?= okv_e($prefill('city','Lagos')) ?>"></div>
              <div><label class="okv-label text-xs" for="kr-state">State</label><input class="okv-input min-h-[44px] rounded-xl" id="kr-state" name="state" required value="<?= okv_e($prefill('state','Lagos')) ?>"></div>
            </div>
            <div><label class="okv-label text-xs" for="kr-note">Note for market</label><textarea class="okv-input min-h-[64px] rounded-xl py-3" id="kr-note" name="customer_note" maxlength="2000" placeholder="Firm tomatoes please."><?= okv_e((string)($savedList['note']??'')) ?></textarea></div>
          </div>
        </details>

        <div class="mt-4 flex gap-2">
          <button type="button" data-kr-back="2" class="inline-flex min-h-[44px] items-center rounded-xl border border-ink-10 px-4 text-sm">Back</button>
          <button type="button" class="inline-flex h-7 w-7 items-center justify-center rounded-full border border-ink-10 text-ink-60" data-kr-help="delivery" aria-label="Help about delivery">?</button>
        </div>

        <p class="mt-3 hidden text-sm text-tomato" data-kr-error role="alert" aria-live="polite"></p>

        <div class="kr-sticky-cta">
          <button type="submit" class="inline-flex min-h-[44px] w-full items-center justify-center rounded-xl bg-forest px-6 text-sm font-medium text-white shadow-sm hover:bg-forest/90 focus-visible:ring-2 focus-visible:ring-gold active:scale-[0.98]">Send list</button>
          <p class="mt-2 text-center text-xs text-ink-60">Nothing charged until approval.</p>
        </div>
      </div>
    </form>
  </section>

  <section class="mt-10" aria-labelledby="runs-heading">
    <h2 id="runs-heading" class="font-display text-[18px] font-semibold tracking-tight">Your runs</h2>
    <?php if (!$runs): ?>
      <div class="mt-3">
        <?php okv_empty_state('list', 'No runs yet here', 'Start above. We will price your list.', [
            ['href' => '/shop.php', 'label' => 'Shop produce', 'icon' => 'leaf'],
            ['href' => '/combos.php', 'label' => 'See combos', 'style' => 'outline', 'icon' => 'basket'],
        ], ['class' => 'border border-dashed border-ink-20 shadow-none']); ?>
      </div>
    <?php else: ?>
      <ul class="mt-3 space-y-2">
        <?php foreach ($runs as $run): ?>
          <li class="rounded-xl border border-ink-10 bg-white p-4">
            <div class="flex items-baseline justify-between gap-3">
              <a class="font-mono text-sm font-semibold text-forest hover:underline" href="/kitchen-runs.php?request=<?= (int)$run['id'] ?>"><?= okv_e($run['request_number']) ?></a>
              <span class="rounded-full bg-mist px-2 py-0.5 text-[11px] text-ink-60"><?= okv_e($run['status_label']) ?></span>
            </div>
            <p class="mt-1 text-xs text-ink-60"><?= okv_e(date('j M Y', strtotime((string)$run['created_at']))) ?> • <?= (int)$run['line_count'] ?> items<?php if ($run['quoted_total_subunit']!==null): ?> • <?= okv_e(Money::format((int)$run['quoted_total_subunit'])) ?><?php endif; ?></p>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</main>

<!-- Backdrop + sheets -->
<div id="kr-backdrop" class="kr-backdrop fixed inset-0 z-40 hidden" data-kr-close aria-hidden="true"></div>

<div id="kr-items-sheet" class="kr-sheet fixed inset-x-0 bottom-0 z-50 hidden max-h-[85dvh] overflow-auto bg-white" role="dialog" aria-modal="true" aria-label="Items help">
  <div class="p-5">
    <div class="mx-auto h-1 w-10 rounded-full bg-mist"></div>
    <h3 class="mt-4 font-display text-[18px] font-semibold">Add items fast</h3>
    <p class="mt-1 text-sm text-ink-60">Tap Add item. Quantity + unit.</p>
  </div>
</div>

<div id="kr-delivery-sheet" class="kr-sheet fixed inset-x-0 bottom-0 z-50 hidden max-h-[85dvh] overflow-auto bg-white" role="dialog" aria-modal="true" aria-label="Delivery help"></div>

<div id="kr-help-sheet" class="kr-sheet fixed inset-x-0 bottom-0 z-50 hidden bg-white" role="dialog" aria-modal="true" aria-labelledby="kr-help-title">
  <div class="p-6 text-center">
    <div class="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-forest-tint" aria-hidden="true">
      <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#0F5132" stroke-width="1.5"><circle cx="12" cy="12" r="9"/><path d="M9.5 9a3 3 0 0 1 5 2c0 2-3 2-3 4"/><path d="M12 17v0"/></svg>
    </div>
    <h3 id="kr-help-title" class="mt-4 font-display text-[18px] font-semibold">How it works</h3>
    <p class="mt-2 text-sm text-ink-60" data-kr-help-text>We source market. You approve price.</p>
    <button type="button" class="mt-5 inline-flex min-h-[44px] w-full items-center justify-center rounded-xl bg-forest px-6 text-sm font-medium text-white" data-kr-close>Got it</button>
  </div>
</div>

<?php okv_shop_footer(); ?>
<script src="<?= okv_e(okv_asset('/assets/js/kitchen-runs.js')) ?>" defer></script>
</body>
</html>

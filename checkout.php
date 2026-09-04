<?php
/**
 * checkout.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Checkout in four steps: review the basket, give your details,
 * choose a delivery day and area, then choose how to pay. Every essential
 * action is a plain HTML form posting to the checkout API, so checkout works
 * with JavaScript switched off.
 *
 * Placing an order with a card option hands the customer to Paystack, so the
 * copy on this page has to say so. It used to say no payment is taken here,
 * which was true only while M5 was unbuilt.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/components/shop/activation_banner.php';
require_once __DIR__ . '/includes/components/shop/header.php';
require_once __DIR__ . '/includes/components/shop/footer.php';
require_once __DIR__ . '/includes/components/shop/support_widget.php';
require_once __DIR__ . '/includes/components/shop/delivery_picker.php';

$step   = max(1, min(4, (int) okv_input('step', 1)));
$basket = Basket::state();
$bag    = Checkout::bag();
$savedCustomer = $bag['customer'] ?? [];
$savedDelivery = $bag['delivery'] ?? [];

$current      = Customer::current();
$customerType = Customer::type() ?? 'household';
$address = Customer::isLoggedIn()
    ? Database::one('SELECT * FROM customer_addresses WHERE user_id = :user_id ORDER BY is_default DESC, id LIMIT 1', [':user_id' => Customer::id()])
    : null;

/** Prefill a field from the saved step, then a saved address, then the account. */
$value = static function (string $key, string $fallback = '') use ($savedCustomer, $address, $current): string {
    if (isset($savedCustomer[$key]) && $savedCustomer[$key] !== '') {
        return (string) $savedCustomer[$key];
    }
    if ($address && isset($address[$key]) && $address[$key] !== null) {
        return (string) $address[$key];
    }
    return $current && isset($current[$key]) ? (string) $current[$key] : $fallback;
};

$zones   = Delivery::zonesActive();
$payment = (string) ($bag['payment']['payment_option'] ?? 'pay_in_full');
$deposit = Money::deposit((int) $basket['subtotal_subunit'], Settings::depositPercentage());
$steps   = [1 => 'Basket review', 2 => 'Your details', 3 => 'Delivery', 4 => 'Payment choice'];

$pageTitle = 'Checkout. OK Veggies';
$canonical = rtrim((string) APP_URL, '/') . '/checkout.php';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= okv_e($pageTitle) ?></title>
  <meta name="description" content="Give your delivery details, choose a delivery day and area, then choose how to pay.">
  <meta name="robots" content="noindex">
  <link rel="canonical" href="<?= okv_e($canonical) ?>">
  <meta property="og:url" content="<?= okv_e($canonical) ?>">
  <?php okv_head_meta(['og_title' => 'Checkout', 'og_description' => 'Give your delivery details and choose how to pay.']); ?>
  <link rel="stylesheet" href="<?= okv_e(okv_asset('/assets/css/tailwind.css')) ?>">
</head>
<body class="min-h-screen bg-forest-tint">
<?php okv_activation_banner(); ?>
<?php okv_shop_header('basket'); ?>

<main class="okv-container py-8 md:py-12">
  <p class="text-xs font-semibold uppercase tracking-[0.2em] text-gold-ink">Your order</p>
  <h1 class="mt-2 font-display text-4xl font-extrabold text-ink">Checkout</h1>

  <ol class="mt-6 grid grid-cols-2 gap-2 md:grid-cols-4" aria-label="Checkout progress">
    <?php foreach ($steps as $number => $label): ?>
      <li class="rounded-md border px-3 py-3 text-sm <?= $number === $step ? 'border-forest bg-forest text-white' : 'border-mist bg-white text-ink-60' ?>"<?= $number === $step ? ' aria-current="step"' : '' ?>>
        <span class="font-semibold"><?= (int) $number ?>.</span> <?= okv_e($label) ?>
      </li>
    <?php endforeach; ?>
  </ol>

  <?php if (!$basket['lines']): ?>
    <section class="mt-8 rounded-lg bg-white p-8 text-center shadow-okv-1">
      <h2 class="font-display text-2xl font-bold text-ink">Your basket is empty</h2>
      <p class="mt-3 text-ink-60">Add produce or a ready basket before checkout.</p>
      <a class="okv-btn mt-6 px-4" href="/shop.php">Shop produce</a>
    </section>
  <?php else: ?>
    <div class="mt-8 grid gap-8 lg:grid-cols-12">
      <section class="rounded-lg bg-white p-5 shadow-okv-1 md:p-7 lg:col-span-8">
        <?php if ($step === 1): ?>
          <h2 class="font-display text-2xl font-bold text-ink">Check your basket</h2>
          <ul class="mt-5 divide-y divide-mist">
            <?php foreach ($basket['lines'] as $line): ?>
              <li class="flex justify-between gap-4 py-4">
                <span>
                  <strong class="block text-ink"><?= okv_e($line['name']) ?></strong>
                  <span class="text-sm text-ink-60"><?= okv_e($line['quantity_display']) ?> <?= okv_e($line['unit']) ?> at <?= okv_e($line['unit_price_display']) ?></span>
                </span>
                <span class="font-mono font-semibold text-forest"><?= okv_e($line['line_total_display']) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
          <div class="mt-6 flex flex-wrap justify-between gap-3">
            <a class="okv-btn-outline px-4" href="/cart.php">Edit basket</a>
            <a class="okv-btn px-4" href="/checkout.php?step=2">Continue</a>
          </div>

        <?php elseif ($step === 2): ?>
          <h2 class="font-display text-2xl font-bold text-ink">Your details</h2>
          <p class="mt-2 text-sm text-ink-60">Tell us who should receive this order and where to bring it.</p>
          <form class="mt-6 grid gap-4 sm:grid-cols-2" method="post" action="/api/v1/checkout.php">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save_step">
            <input type="hidden" name="step" value="customer">
            <input type="hidden" name="customer_type" value="<?= okv_e($customerType) ?>">
            <label class="okv-label sm:col-span-2">Recipient name
              <input class="okv-input mt-1" name="recipient_name" value="<?= okv_e(trim($value('recipient_name', trim($value('first_name') . ' ' . $value('last_name'))))) ?>" required>
            </label>
            <label class="okv-label">Phone number
              <input class="okv-input mt-1" name="recipient_phone" value="<?= okv_e($value('recipient_phone', $value('phone'))) ?>" required>
            </label>
            <label class="okv-label">Email address
              <input class="okv-input mt-1" type="email" name="email" value="<?= okv_e($value('email')) ?>" required>
            </label>
            <label class="okv-label sm:col-span-2">Address
              <input class="okv-input mt-1" name="address_line_1" value="<?= okv_e($value('address_line_1')) ?>" required>
            </label>
            <label class="okv-label sm:col-span-2">Address details, optional
              <input class="okv-input mt-1" name="address_line_2" value="<?= okv_e($value('address_line_2')) ?>">
            </label>
            <label class="okv-label">City
              <input class="okv-input mt-1" name="city" value="<?= okv_e($value('city', 'Lagos')) ?>" required>
            </label>
            <label class="okv-label">State
              <input class="okv-input mt-1" name="state" value="<?= okv_e($value('state', 'Lagos')) ?>" required>
            </label>
            <label class="okv-label sm:col-span-2">Landmark, optional
              <input class="okv-input mt-1" name="landmark" value="<?= okv_e($value('landmark')) ?>">
            </label>
            <?php if (!Customer::isLoggedIn()): ?>
              <label class="flex gap-3 rounded-md bg-forest-tint p-4 text-sm text-ink sm:col-span-2">
                <input type="checkbox" name="create_account" value="1" required>
                <span>Create an account for this order. You can set your password and manage the order after checkout.</span>
              </label>
            <?php endif; ?>
            <div class="flex justify-between gap-3 sm:col-span-2">
              <a class="okv-btn-text px-2" href="/checkout.php?step=1">Back</a>
              <button class="okv-btn px-4">Continue</button>
            </div>
          </form>

        <?php elseif ($step === 3): ?>
          <h2 class="font-display text-2xl font-bold text-ink">Choose delivery</h2>
          <form class="mt-6 space-y-5" method="post" action="/api/v1/checkout.php">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save_step">
            <input type="hidden" name="step" value="delivery">
            <div><?php okv_delivery_picker($customerType, 'delivery_date', (string) ($savedDelivery['delivery_date'] ?? '')); ?></div>
            <div>
              <label class="okv-label" for="delivery_zone_id">Delivery area</label>
              <select class="okv-input" id="delivery_zone_id" name="delivery_zone_id" required>
                <option value="">Choose your area</option>
                <?php foreach ($zones as $zone): ?>
                  <option value="<?= (int) $zone['id'] ?>" <?= (int) ($savedDelivery['delivery_zone_id'] ?? 0) === (int) $zone['id'] ? 'selected' : '' ?>><?= okv_e($zone['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <p class="mt-2 text-sm text-ink-60">Delivery is arranged and settled separately after we confirm your area.</p>
            </div>
            <div class="flex justify-between gap-3">
              <a class="okv-btn-text px-2" href="/checkout.php?step=2">Back</a>
              <button class="okv-btn px-4">Continue</button>
            </div>
          </form>

        <?php else: ?>
          <h2 class="font-display text-2xl font-bold text-ink">Choose how to pay</h2>
          <form class="mt-6 space-y-4" method="post" action="/api/v1/checkout.php">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="place_order">
            <label class="block rounded-md border border-mist p-4">
              <input type="radio" name="payment_option" value="pay_in_full" <?= $payment === 'pay_in_full' ? 'checked' : '' ?> required>
              <strong>Pay the full amount</strong>
              <span class="mt-1 block text-sm text-ink-60">Pay <?= okv_e(Money::format((int) $basket['subtotal_subunit'])) ?> now. We take you to Paystack, where you can pay by card, bank transfer or USSD.</span>
            </label>
            <label class="block rounded-md border border-mist p-4">
              <input type="radio" name="payment_option" value="deposit" <?= $payment === 'deposit' ? 'checked' : '' ?>>
              <strong>Pay a <?= okv_e(rtrim(rtrim(number_format(Settings::depositPercentage(), 2), '0'), '.')) ?>% deposit</strong>
              <span class="mt-1 block text-sm text-ink-60">Pay <?= okv_e(Money::format($deposit)) ?> now through Paystack. The rest is settled on delivery.</span>
            </label>
            <label class="block rounded-md border border-mist p-4 <?= Customer::isActivated() ? '' : 'opacity-60' ?>">
              <input type="radio" name="payment_option" value="pay_on_delivery" <?= $payment === 'pay_on_delivery' ? 'checked' : '' ?> <?= Customer::isActivated() ? '' : 'disabled' ?>>
              <strong>Pay on delivery</strong>
              <span class="mt-1 block text-sm text-ink-60"><?= Customer::isActivated() ? 'Nothing is taken now. You pay our team when your order arrives.' : 'Verify your email before choosing pay on delivery.' ?></span>
            </label>
            <?php if (Customer::isBusiness()): ?>
              <label class="block rounded-md border border-mist p-4">
                <input type="radio" name="payment_option" value="on_account" <?= $payment === 'on_account' ? 'checked' : '' ?>>
                <strong>Use approved business credit</strong>
                <span class="mt-1 block text-sm text-ink-60">The server checks your credit approval before placing the order.</span>
              </label>
            <?php endif; ?>

            <!-- The cancellation rule, said before the money moves rather than
                 discovered afterwards. Cancellation::policyLine is the same
                 sentence the order screen and the cancellation email use, so
                 the promise made here is the one that is kept. -->
            <p class="okv-note bg-clay-tint">
              <?= okv_e(Cancellation::policyLine(
                    Settings::str('cancellation_cutoff_time', '18:00'),
                    Settings::bool('cancellation_deposit_forfeit_after_cutoff', true),
                    Settings::bool('cancellation_after_dispatch_allowed', true),
                    Settings::bool('cancellation_dispatched_forfeit_deposit', true)
                  )) ?>
            </p>

            <div class="flex justify-between gap-3 pt-2">
              <a class="okv-btn-text px-2" href="/checkout.php?step=3">Back</a>
              <button class="okv-btn px-4">Place order</button>
            </div>
          </form>
        <?php endif; ?>
      </section>

      <aside class="lg:col-span-4">
        <div class="sticky top-24 rounded-lg bg-white p-5 shadow-okv-2">
          <h2 class="font-display text-xl font-bold text-ink">Order summary</h2>
          <p class="mt-5 flex justify-between font-semibold">
            <span><?= (int) $basket['count'] ?> lines</span>
            <span class="font-mono text-forest"><?= okv_e($basket['subtotal_display']) ?></span>
          </p>
          <p class="mt-3 text-sm text-ink-60">Each item keeps the price shown when it was added.</p>
        </div>
      </aside>
    </div>
  <?php endif; ?>
</main>

<?php okv_shop_footer(); okv_support_widget(); ?>
<script src="<?= okv_e(okv_asset('/assets/js/okv.min.js')) ?>"></script>
</body>
</html>

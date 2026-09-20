<?php
/**
 * checkout.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Checkout in four steps: review the basket, give your details,
 * choose a delivery day and area, then choose how to pay.
 *
 * The four steps are one page each, driven by ?step=. Every essential action is
 * a plain HTML form posting to the checkout API, so checkout works with
 * JavaScript switched off; assets/js/checkout.js is the app layer on top of
 * that same markup (the day picker sheet, the info sheets, the sticky pay bar).
 *
 * Placing an order with a card option hands the customer to Paystack, so the
 * copy on this page says so. The payment choices are large tappable cards, the
 * trust panel sits below the whole radio group so it can never read as a
 * choice itself (M10 review), and the primary button is a full-width pay bar
 * that floats above the mobile tab bar.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/components/shop/activation_banner.php';
require_once __DIR__ . '/includes/components/shop/header.php';
require_once __DIR__ . '/includes/components/shop/footer.php';
require_once __DIR__ . '/includes/components/shop/support_widget.php';
require_once __DIR__ . '/includes/components/shop/delivery_picker.php';
require_once __DIR__ . '/includes/components/shop/icons.php';
require_once __DIR__ . '/includes/components/shop/policy_links.php';

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
$depositPercent = rtrim(rtrim(number_format(Settings::depositPercentage(), 2), '0'), '.');
$sourceRegions  = Settings::str('source_regions', 'Ogun State, Jos');
$steps   = [1 => 'Basket', 2 => 'Details', 3 => 'Delivery', 4 => 'Payment'];
$stepIcons = [1 => 'basket', 2 => 'user', 3 => 'map-pin', 4 => 'card'];

// On account is offered from the real facility, never from the account type
// alone. The same rule refuses the order on the server, so what is shown here
// and what is allowed cannot drift apart.
$creditFacility = Customer::isBusiness() && Customer::id() !== null
    ? Credit::facilityForUser((int) Customer::id())
    : null;
$creditRefusal  = Customer::isBusiness()
    ? Credit::drawRefusal($creditFacility, (int) $basket['subtotal_subunit'])
    : 'credit_not_approved';
$creditDeliveryDate = (string) ($savedDelivery['delivery_date'] ?? '');
$creditDueDate  = $creditRefusal === '' && $creditDeliveryDate !== ''
    ? Credit::dueDateFor($creditDeliveryDate, (int) $creditFacility['days'])
    : '';
$creditNote     = static function (?array $facility, string $refusal, int $total, string $dueDate): string {
    if ($refusal === '') {
        $line = 'Nothing is taken now. ' . Money::format((int) $facility['available_subunit']) . ' is available today.';
        return $dueDate === '' ? $line : $line . ' This order falls due on ' . date('j F Y', strtotime($dueDate)) . '.';
    }
    $state = (string) ($facility['state'] ?? 'not_requested');
    if ($refusal === 'credit_limit_exceeded') {
        return 'This basket is ' . Money::format($total) . ' and ' . Money::format((int) $facility['available_subunit'])
             . ' is available today. Settle some of your balance, or choose another way to pay.';
    }
    if ($state === 'suspended') { return 'Your credit account is on hold. Talk to us and we will sort it out.'; }
    if ($state === 'withdrawn') { return 'This account no longer runs on credit. Choose another way to pay.'; }
    if ($state === 'requested')  { return 'Your application is with our team. We will let you know once it is reviewed.'; }
    if ($state === 'declined')   { return 'Your last application was not approved. You can apply again from the Pro Portal.'; }
    return 'Apply for credit in the Pro Portal before you can pay on account.';
};

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

<main class="okv-container pt-8 md:pb-16 md:pt-12 <?= $step === 4 && $basket['lines'] ? 'pb-44' : 'pb-24' ?>">
  <div class="okv-enter">
    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-gold-ink">Your order</p>
    <h1 class="mt-2 font-display text-4xl font-extrabold text-ink">Checkout</h1>
  </div>

  <!-- The progress line: one circle per step, icon in the circle, a line
       between them, done steps ticked. It is a real list, so a screen reader
       hears "list, 4 items" and the current step is named with aria-current. -->
  <ol class="okv-enter okv-enter-2 mt-8 flex items-start" aria-label="Checkout progress">
    <?php foreach ($steps as $number => $label): ?>
      <?php
      $done    = $number < $step;
      $currentStep = $number === $step;
      ?>
      <li class="min-w-0 flex-1<?= $number === count($steps) ? ' flex-none' : '' ?>"<?= $currentStep ? ' aria-current="step"' : '' ?>>
        <div class="flex items-center">
          <span class="okv-step-dot <?= $done ? 'okv-step-dot-done' : ($currentStep ? 'okv-step-dot-current' : '') ?>">
            <?php okv_icon($done ? 'check' : $stepIcons[$number], 'h-5 w-5'); ?>
          </span>
          <?php if ($number < count($steps)): ?>
            <span class="mx-1.5 h-0.5 flex-1 rounded-full <?= $done ? 'bg-forest' : 'bg-mist' ?>" aria-hidden="true"></span>
          <?php endif; ?>
        </div>
        <p class="mt-2 text-okv-label font-semibold leading-tight <?= $currentStep ? 'text-ink' : ($done ? 'text-forest' : 'text-ink-40') ?>">
          <span class="sr-only">Step <?= (int) $number ?> of <?= count($steps) ?>. </span><?= okv_e($label) ?>
        </p>
      </li>
    <?php endforeach; ?>
  </ol>

  <?php if (!$basket['lines']): ?>
    <section class="okv-enter okv-enter-3 mt-8 rounded-xl bg-white px-6 py-14 text-center shadow-okv-1">
      <span class="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-forest-tint text-forest">
        <?php okv_icon('basket-empty', 'h-10 w-10'); ?>
      </span>
      <h2 class="mt-4 font-display text-2xl font-bold text-ink">Your basket is empty</h2>
      <p class="mt-2 text-ink-60">Add produce or a ready basket before checkout.</p>
      <div class="mt-6 flex flex-col justify-center gap-3 sm:flex-row">
        <a class="okv-btn w-full justify-center sm:w-auto" href="/shop.php">Shop produce <?php okv_icon('arrow-right', 'h-4 w-4'); ?></a>
        <a class="okv-btn-outline w-full justify-center sm:w-auto" href="/combos.php">See combos</a>
      </div>
    </section>
  <?php else: ?>
    <div class="mt-8 grid gap-8 lg:grid-cols-12">
      <section class="okv-enter okv-enter-3 rounded-xl bg-white p-5 shadow-okv-1 md:p-7 lg:col-span-8">
        <?php if ($step === 1): ?>
          <h2 class="flex items-center gap-3 font-display text-2xl font-bold text-ink">
            <span class="flex h-10 w-10 flex-none items-center justify-center rounded-full bg-forest-tint text-forest"><?php okv_icon('plate', 'h-5 w-5'); ?></span>
            Check your basket
          </h2>
          <ul class="mt-5 divide-y divide-mist">
            <?php foreach ($basket['lines'] as $line): ?>
              <li class="flex items-center gap-4 py-4">
                <span class="flex h-12 w-12 flex-none items-center justify-center rounded-lg bg-forest-tint text-forest"><?php okv_icon($line['item_type'] === 'combo' ? 'basket' : 'leaf', 'h-5 w-5'); ?></span>
                <span class="min-w-0 flex-1">
                  <strong class="block truncate text-ink"><?= okv_e($line['name']) ?></strong>
                  <span class="block text-sm text-ink-60"><?= okv_e($line['quantity_display']) ?> <?= okv_e($line['unit']) ?> at <?= okv_e($line['unit_price_display']) ?></span>
                </span>
                <span class="font-mono font-semibold text-forest"><?= okv_e($line['line_total_display']) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
          <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:justify-between">
            <a class="okv-btn-outline w-full justify-center px-4 sm:w-auto" href="/cart.php"><?php okv_icon('arrow-left', 'h-4 w-4'); ?> Edit basket</a>
            <a class="okv-btn w-full justify-center px-4 sm:w-auto" href="/checkout.php?step=2">Continue <?php okv_icon('arrow-right', 'h-4 w-4'); ?></a>
          </div>

        <?php elseif ($step === 2): ?>
          <h2 class="flex items-center gap-3 font-display text-2xl font-bold text-ink">
            <span class="flex h-10 w-10 flex-none items-center justify-center rounded-full bg-forest-tint text-forest"><?php okv_icon('user', 'h-5 w-5'); ?></span>
            Your details
          </h2>
          <p class="mt-2 text-sm text-ink-60">Who receives this order, and where we bring it.</p>
          <form class="mt-6 grid gap-4 sm:grid-cols-2" method="post" action="/api/v1/checkout.php">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save_step">
            <input type="hidden" name="step" value="customer">
            <input type="hidden" name="customer_type" value="<?= okv_e($customerType) ?>">
            <label class="okv-label sm:col-span-2">Recipient name
              <input class="okv-input mt-1" name="recipient_name" autocomplete="name" value="<?= okv_e(trim($value('recipient_name', trim($value('first_name') . ' ' . $value('last_name'))))) ?>" required>
            </label>
            <label class="okv-label">Phone number
              <input class="okv-input mt-1" name="recipient_phone" type="tel" inputmode="tel" autocomplete="tel" value="<?= okv_e($value('recipient_phone', $value('phone'))) ?>" required>
            </label>
            <label class="okv-label">Email address
              <input class="okv-input mt-1" type="email" name="email" inputmode="email" autocomplete="email" value="<?= okv_e($value('email')) ?>" required>
            </label>
            <label class="okv-label sm:col-span-2">Address
              <input class="okv-input mt-1" name="address_line_1" autocomplete="address-line1" value="<?= okv_e($value('address_line_1')) ?>" required>
            </label>
            <label class="okv-label sm:col-span-2">Address details, optional
              <input class="okv-input mt-1" name="address_line_2" autocomplete="address-line2" value="<?= okv_e($value('address_line_2')) ?>">
            </label>
            <label class="okv-label">City
              <input class="okv-input mt-1" name="city" autocomplete="address-level2" value="<?= okv_e($value('city', 'Lagos')) ?>" required>
            </label>
            <label class="okv-label">State
              <input class="okv-input mt-1" name="state" autocomplete="address-level1" value="<?= okv_e($value('state', 'Lagos')) ?>" required>
            </label>
            <label class="okv-label sm:col-span-2">Landmark, optional
              <input class="okv-input mt-1" name="landmark" value="<?= okv_e($value('landmark')) ?>">
            </label>
            <?php if (!Customer::isLoggedIn()): ?>
              <!-- Offered, never required. A guest order is a real order: the
                   confirmation email carries a link that opens it, with no
                   sign in. An account is for the person who wants their
                   details and their history kept for next time. -->
              <div class="rounded-xl border border-ink-10 bg-forest-tint p-4 text-sm text-ink sm:col-span-2">
                <label class="flex items-start gap-3">
                  <input type="checkbox" name="create_account" value="1" class="mt-0.5 h-6 w-6 flex-none accent-forest" <?= !empty($savedCustomer['create_account']) ? 'checked' : '' ?>>
                  <span class="pt-0.5 font-medium">Save my details for next time</span>
                </label>
                <p class="mt-2 pl-9 text-ink-60">Not needed. Order as a guest and we email you a link that opens your order. Paying on delivery is the one choice that needs a verified account.</p>
              </div>
            <?php endif; ?>
            <div class="flex flex-col-reverse gap-3 sm:col-span-2 sm:flex-row sm:justify-between">
              <a class="okv-btn-text justify-center px-2" href="/checkout.php?step=1"><?php okv_icon('arrow-left', 'h-4 w-4'); ?> Back</a>
              <button class="okv-btn w-full justify-center px-6 sm:w-auto">Continue <?php okv_icon('arrow-right', 'h-4 w-4'); ?></button>
            </div>
          </form>

        <?php elseif ($step === 3): ?>
          <h2 class="flex items-center gap-3 font-display text-2xl font-bold text-ink">
            <span class="flex h-10 w-10 flex-none items-center justify-center rounded-full bg-forest-tint text-forest"><?php okv_icon('map-pin', 'h-5 w-5'); ?></span>
            Choose delivery
          </h2>
          <form class="mt-6 space-y-5" method="post" action="/api/v1/checkout.php">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save_step">
            <input type="hidden" name="step" value="delivery">
            <div><?php okv_delivery_picker($customerType, 'delivery_date', (string) ($savedDelivery['delivery_date'] ?? '')); ?></div>
            <div>
              <label class="okv-label" for="delivery_zone_id">Delivery area</label>
              <div class="relative">
                <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-forest"><?php okv_icon('map-pin', 'h-5 w-5'); ?></span>
                <select class="okv-input min-h-[56px] pl-12" id="delivery_zone_id" name="delivery_zone_id" required>
                  <option value="">Choose your area</option>
                  <?php foreach ($zones as $zone): ?>
                    <option value="<?= (int) $zone['id'] ?>" <?= (int) ($savedDelivery['delivery_zone_id'] ?? 0) === (int) $zone['id'] ? 'selected' : '' ?>><?= okv_e($zone['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="flex items-start gap-3 rounded-xl bg-clay-tint p-4">
              <span class="flex h-8 w-8 flex-none items-center justify-center rounded-full bg-white text-clay-ink"><?php okv_icon('info', 'h-4 w-4'); ?></span>
              <p class="text-sm text-clay-ink">
                Delivery is arranged and settled separately, after we confirm your area.
                <button type="button" class="inline-flex min-h-[44px] items-center gap-1 font-semibold underline underline-offset-2" data-sheet-open="fee-sheet" aria-haspopup="dialog">How it works</button>
              </p>
            </div>
            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-between">
              <a class="okv-btn-text justify-center px-2" href="/checkout.php?step=2"><?php okv_icon('arrow-left', 'h-4 w-4'); ?> Back</a>
              <button class="okv-btn w-full justify-center px-6 sm:w-auto">Continue <?php okv_icon('arrow-right', 'h-4 w-4'); ?></button>
            </div>
          </form>

        <?php else: ?>
          <h2 class="flex items-center gap-3 font-display text-2xl font-bold text-ink">
            <span class="flex h-10 w-10 flex-none items-center justify-center rounded-full bg-forest-tint text-forest"><?php okv_icon('card', 'h-5 w-5'); ?></span>
            Choose how to pay
          </h2>
          <form id="checkout-payment-form" class="mt-6" method="post" action="/api/v1/checkout.php">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="place_order">
            <fieldset>
              <legend class="sr-only">How would you like to pay?</legend>
              <div class="space-y-3" data-payment-options>
                <label class="okv-choice okv-enter okv-enter-3">
                  <span class="flex flex-none items-center pt-1">
                    <input type="radio" class="okv-radio peer" name="payment_option" value="pay_in_full" <?= $payment === 'pay_in_full' ? 'checked' : '' ?> required>
                    <span class="pointer-events-none -ml-5 h-3 w-3 scale-50 rounded-full bg-forest opacity-0 transition duration-bounce ease-bounce peer-checked:scale-100 peer-checked:opacity-100"></span>
                  </span>
                  <span class="min-w-0 flex-1">
                    <span class="flex flex-wrap items-baseline justify-between gap-x-3">
                      <span class="font-semibold text-ink">Pay in full now</span>
                      <span class="font-mono text-sm font-semibold text-forest"><?= okv_e(Money::format((int) $basket['subtotal_subunit'])) ?></span>
                    </span>
                    <span class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-ink-60">
                      <span class="flex items-center gap-1"><?php okv_icon('card', 'h-4 w-4'); ?><span class="text-xs">Card</span></span>
                      <span class="flex items-center gap-1"><?php okv_icon('banknote', 'h-4 w-4'); ?><span class="text-xs">Bank transfer</span></span>
                      <span class="flex items-center gap-1"><?php okv_icon('phone', 'h-4 w-4'); ?><span class="text-xs">USSD</span></span>
                    </span>
                    <span class="mt-1 block text-xs text-ink-60">On Paystack, in one go.</span>
                  </span>
                  <span class="flex h-12 w-12 flex-none items-center justify-center rounded-full bg-forest-tint text-forest"><?php okv_icon('card', 'h-5 w-5'); ?></span>
                </label>

                <label class="okv-choice okv-enter okv-enter-4">
                  <span class="flex flex-none items-center pt-1">
                    <input type="radio" class="okv-radio peer" name="payment_option" value="deposit" <?= $payment === 'deposit' ? 'checked' : '' ?>>
                    <span class="pointer-events-none -ml-5 h-3 w-3 scale-50 rounded-full bg-forest opacity-0 transition duration-bounce ease-bounce peer-checked:scale-100 peer-checked:opacity-100"></span>
                  </span>
                  <span class="min-w-0 flex-1">
                    <span class="flex flex-wrap items-baseline justify-between gap-x-3">
                      <span class="font-semibold text-ink">Pay a <?= okv_e($depositPercent) ?>% deposit</span>
                      <span class="font-mono text-sm font-semibold text-forest"><?= okv_e(Money::format($deposit)) ?></span>
                    </span>
                    <span class="mt-1 block text-xs text-ink-60">The rest is settled when your order arrives.</span>
                  </span>
                  <span class="flex h-12 w-12 flex-none items-center justify-center rounded-full bg-gold-tint text-gold-ink"><?php okv_icon('banknote', 'h-5 w-5'); ?></span>
                </label>

                <label class="okv-choice okv-enter okv-enter-5">
                  <span class="flex flex-none items-center pt-1">
                    <input type="radio" class="okv-radio peer" name="payment_option" value="pay_on_delivery" <?= $payment === 'pay_on_delivery' ? 'checked' : '' ?> <?= Customer::isActivated() ? '' : 'disabled' ?>>
                    <span class="pointer-events-none -ml-5 h-3 w-3 scale-50 rounded-full bg-forest opacity-0 transition duration-bounce ease-bounce peer-checked:scale-100 peer-checked:opacity-100"></span>
                  </span>
                  <span class="min-w-0 flex-1">
                    <span class="font-semibold text-ink">Pay on delivery</span>
                    <span class="mt-1 block text-xs text-ink-60"><?= Customer::isActivated() ? 'Nothing is taken now. You pay our team at your door.' : 'Needs a verified account. Save your details in step 2, or sign in.' ?></span>
                  </span>
                  <span class="flex h-12 w-12 flex-none items-center justify-center rounded-full bg-foliage-tint text-forest"><?php okv_icon('handshake', 'h-5 w-5'); ?></span>
                </label>

                <?php if (Customer::isBusiness()): ?>
                  <label class="okv-choice okv-enter okv-enter-6">
                    <span class="flex flex-none items-center pt-1">
                      <input type="radio" class="okv-radio peer" name="payment_option" value="on_account"
                             <?= $payment === 'on_account' && $creditRefusal === '' ? 'checked' : '' ?>
                             <?= $creditRefusal === '' ? '' : 'disabled' ?>>
                      <span class="pointer-events-none -ml-5 h-3 w-3 scale-50 rounded-full bg-forest opacity-0 transition duration-bounce ease-bounce peer-checked:scale-100 peer-checked:opacity-100"></span>
                    </span>
                    <span class="min-w-0 flex-1">
                      <span class="font-semibold text-ink">Use approved business credit</span>
                      <span class="mt-1 block text-xs text-ink-60"><?= okv_e($creditNote($creditFacility, $creditRefusal, (int) $basket['subtotal_subunit'], $creditDueDate)) ?></span>
                      <?php if ($creditRefusal !== ''): ?>
                        <a class="mt-1 inline-flex min-h-[44px] items-center text-xs font-semibold text-forest underline underline-offset-2" href="/pro/credit.php">Open your credit page</a>
                      <?php endif; ?>
                    </span>
                    <span class="flex h-12 w-12 flex-none items-center justify-center rounded-full bg-clay-tint text-clay-ink"><?php okv_icon('receipt', 'h-5 w-5'); ?></span>
                  </label>
                <?php endif; ?>
              </div>
            </fieldset>

            <!-- A gold rule, never a gold fill, with the grocer's promise on it. -->
            <div class="my-6 flex items-center gap-3" aria-hidden="true">
              <span class="flex-1 border-t border-gold"></span>
              <span class="flex items-center gap-1.5 text-okv-label font-semibold uppercase tracking-[0.2em] text-gold-ink">
                <?php okv_icon('sparkle', 'h-3.5 w-3.5'); ?> Sourced right
              </span>
              <span class="flex-1 border-t border-gold"></span>
            </div>

            <!-- The trust panel sits below the whole radio group, as two small
                 cards and the Paystack mark, so it can never read as a payment
                 choice itself. That placement is fix 13 and the finding in the
                 M10 review, Section 4. -->
            <section class="rounded-xl border border-forest/15 bg-forest-tint p-4 sm:p-5" aria-labelledby="checkout-trust-heading" data-trust-panel>
              <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 id="checkout-trust-heading" class="font-display text-lg font-bold text-ink">Your order stays clear</h3>
                <img src="<?= okv_e(okv_asset('/assets/img/payments/paystack.svg')) ?>" alt="Paystack" width="116" height="25" class="h-5 w-auto">
              </div>
              <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <div class="rounded-xl border border-ink-10 bg-white p-4">
                  <span class="flex h-10 w-10 items-center justify-center rounded-full bg-forest-tint text-forest"><?php okv_icon('shield', 'h-5 w-5'); ?></span>
                  <p class="mt-2 text-sm font-bold text-ink">Secure Paystack payment</p>
                  <p class="mt-1 text-xs text-ink-60">Card, bank transfer or USSD on Paystack. We never see your card details.</p>
                </div>
                <div class="rounded-xl border border-ink-10 bg-white p-4">
                  <span class="flex h-10 w-10 items-center justify-center rounded-full bg-forest-tint text-forest"><?php okv_icon('trail', 'h-5 w-5'); ?></span>
                  <p class="mt-2 text-sm font-bold text-ink">Follow every order</p>
                  <p class="mt-1 text-xs text-ink-60">Your private Order Trail shows sourcing, packing, dispatch and delivery.</p>
                </div>
              </div>
              <p class="mt-3 flex flex-wrap items-center gap-x-2 text-xs text-ink-60">
                <?php okv_icon('leaf', 'mt-0.5 h-4 w-4 flex-none text-forest'); ?>
                <span>We make it right if produce is not as described.</span>
                <a class="inline-flex min-h-[44px] items-center font-semibold text-forest underline underline-offset-2" href="<?= okv_e(okv_make_it_right_policy_url()) ?>">Read how</a>
              </p>
            </section>

            <!-- The cancellation rule, said before the money moves rather than
                 discovered afterwards. Cancellation::policyLine is the same
                 sentence the order screen and the cancellation email use, so
                 the promise made here is the one that is kept. -->
            <div class="mt-4 flex items-start gap-3 rounded-xl bg-clay-tint p-4">
              <span class="mt-0.5 flex h-8 w-8 flex-none items-center justify-center rounded-full bg-white text-clay-ink"><?php okv_icon('info', 'h-4 w-4'); ?></span>
              <div class="min-w-0 flex-1 text-sm text-clay-ink">
                <?= okv_e(Cancellation::policyLine(
                      Settings::str('cancellation_cutoff_time', '18:00'),
                      Settings::bool('cancellation_deposit_forfeit_after_cutoff', true),
                      Settings::bool('cancellation_after_dispatch_allowed', true),
                      Settings::bool('cancellation_dispatched_forfeit_deposit', true),
                      (string) ($savedDelivery['delivery_date'] ?? '')
                    )) ?>
                <span class="mt-1 flex flex-wrap items-center gap-x-4">
                  <button type="button" class="inline-flex min-h-[44px] items-center gap-1 font-semibold underline underline-offset-2" data-sheet-open="cancel-sheet" aria-haspopup="dialog">How cancelling works</button>
                  <a class="inline-flex min-h-[44px] items-center font-semibold underline underline-offset-2" href="<?= okv_e(okv_delivery_policy_url()) ?>#how-it-works">Delivery Policy</a>
                </span>
              </div>
            </div>

            <div class="mt-4 hidden items-center justify-between gap-3 md:flex">
              <a class="okv-btn-text px-2" href="/checkout.php?step=3"><?php okv_icon('arrow-left', 'h-4 w-4'); ?> Back</a>
              <button class="okv-btn h-14 justify-center rounded-xl px-8 text-base shadow-lg shadow-forest/20 active:scale-[0.98]" data-pay-submit>
                <span data-pay-label>Pay now</span>
                <?php okv_icon('arrow-right', 'h-5 w-5'); ?>
              </button>
            </div>
          </form>

          <!-- Info sheet: the fee story, one tap away instead of a paragraph. -->
          <div class="okv-sheet-backdrop" id="fee-sheet" hidden>
            <section class="okv-sheet p-5" role="dialog" aria-modal="true" aria-labelledby="fee-sheet-title" tabindex="-1" data-sheet-panel>
              <div class="flex items-start justify-between gap-4">
                <div>
                  <p class="text-xs font-semibold uppercase tracking-[0.2em] text-gold-ink">Delivery fee</p>
                  <h3 id="fee-sheet-title" class="mt-1 font-display text-xl font-bold text-ink">How delivery is settled</h3>
                </div>
                <button type="button" class="okv-btn-text min-h-[44px]" data-sheet-close>Close</button>
              </div>
              <span class="mx-auto mt-4 flex h-20 w-20 items-center justify-center rounded-full bg-forest-tint text-forest"><?php okv_icon('map-pin', 'h-10 w-10'); ?></span>
              <p class="mt-4 text-sm text-ink-60">Choose your area. We confirm it, agree the fee with you, then bring your order. Nothing is charged online today.</p>
              <a class="okv-btn-outline mt-4 w-full justify-center" href="<?= okv_e(okv_delivery_policy_url()) ?>#how-it-works">Read the Delivery Policy</a>
            </section>
          </div>

          <!-- Info sheet: the cancellation rule, spelled plainly. -->
          <div class="okv-sheet-backdrop" id="cancel-sheet" hidden>
            <section class="okv-sheet p-5" role="dialog" aria-modal="true" aria-labelledby="cancel-sheet-title" tabindex="-1" data-sheet-panel>
              <div class="flex items-start justify-between gap-4">
                <div>
                  <p class="text-xs font-semibold uppercase tracking-[0.2em] text-gold-ink">Cancelling</p>
                  <h3 id="cancel-sheet-title" class="mt-1 font-display text-xl font-bold text-ink">How cancelling works</h3>
                </div>
                <button type="button" class="okv-btn-text min-h-[44px]" data-sheet-close>Close</button>
              </div>
              <span class="mx-auto mt-4 flex h-20 w-20 items-center justify-center rounded-full bg-clay-tint text-clay-ink"><?php okv_icon('calendar', 'h-10 w-10'); ?></span>
              <p class="mt-4 text-sm text-ink-60">
                <?= okv_e(Cancellation::policyLine(
                      Settings::str('cancellation_cutoff_time', '18:00'),
                      Settings::bool('cancellation_deposit_forfeit_after_cutoff', true),
                      Settings::bool('cancellation_after_dispatch_allowed', true),
                      Settings::bool('cancellation_dispatched_forfeit_deposit', true),
                      (string) ($savedDelivery['delivery_date'] ?? '')
                    )) ?>
              </p>
              <a class="okv-btn-outline mt-4 w-full justify-center" href="<?= okv_e(okv_delivery_policy_url()) ?>#how-it-works">Read the Delivery Policy</a>
            </section>
          </div>
        <?php endif; ?>
      </section>

      <aside class="lg:col-span-4">
        <div class="okv-enter okv-enter-4 rounded-xl bg-white p-5 shadow-okv-2 lg:sticky lg:top-24">
          <h2 class="font-display text-xl font-bold text-ink">Order summary</h2>
          <span class="mt-2 block w-12 border-t-2 border-gold" aria-hidden="true"></span>
          <ul class="mt-4 divide-y divide-mist">
            <?php foreach ($basket['lines'] as $line): ?>
              <li class="flex items-baseline justify-between gap-3 py-2.5">
                <span class="min-w-0 text-sm text-ink-60">
                  <?= okv_e($line['name']) ?>
                  <span class="block text-xs text-ink-60"><?= okv_e($line['quantity_display']) ?> <?= okv_e($line['unit']) ?></span>
                </span>
                <span class="font-mono text-sm font-semibold text-forest"><?= okv_e($line['line_total_display']) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
          <p class="mt-3 flex items-center justify-between border-t border-mist pt-3 font-semibold">
            <span>Total</span>
            <span class="font-mono text-forest"><?= okv_e($basket['subtotal_display']) ?></span>
          </p>
          <p class="okv-trust-line mt-4"><?php okv_icon('leaf', 'mt-0.5 h-4 w-4 flex-none text-forest'); ?> Sourced from <?= okv_e($sourceRegions) ?>.</p>
          <p class="okv-trust-line mt-2"><?php okv_icon('trail', 'mt-0.5 h-4 w-4 flex-none text-forest'); ?> Delivery is settled after we confirm your area.</p>
        </div>
      </aside>
    </div>

    <?php if ($step === 4): ?>
      <!-- The pay bar. The primary action lives in the thumb zone on a phone,
           above the tab bar and the bottom safe area. The button submits the
           payment form by its form attribute, so it works with JavaScript off. -->
      <div class="okv-pay-bar" data-pay-bar>
        <div class="flex items-center gap-3">
          <div class="min-w-0 flex-none">
            <p class="text-okv-label font-semibold uppercase tracking-[0.14em] text-ink-60">Total</p>
            <p class="font-mono text-lg font-semibold leading-tight text-ink"><?= okv_e($basket['subtotal_display']) ?></p>
          </div>
          <button class="okv-btn ml-auto h-14 min-w-0 flex-1 justify-center rounded-xl px-4 text-base shadow-lg shadow-forest/20 active:scale-[0.98]" form="checkout-payment-form" data-pay-submit>
            <span data-pay-label>Pay now</span>
            <?php okv_icon('arrow-right', 'h-5 w-5'); ?>
          </button>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</main>

<?php okv_shop_footer(); ?>
<script src="<?= okv_e(okv_asset('/assets/js/okv.min.js')) ?>" defer></script>
<script src="<?= okv_e(okv_asset('/assets/js/checkout.min.js')) ?>" defer></script>
</body>
</html>

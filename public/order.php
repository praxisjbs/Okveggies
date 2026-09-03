<?php
/**
 * public/order.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Two views from one page. With a share token in the URL it is the
 * public Order Trail: anyone with the link can follow the order, but the money
 * is withheld. Without a token, a signed-in owner sees their own confirmation,
 * money included. A missing or wrong reference renders a branded 404.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/components/shop/header.php';
require_once __DIR__ . '/../includes/components/shop/footer.php';

$token   = trim((string) okv_input('token', ''));
$orderId = (int) okv_input('order', 0);

$order = null;
if ($token !== '') {
    $order = OrderTrail::findByToken($token);
} elseif ($orderId > 0 && Customer::id() !== null) {
    $order = OrderTrail::findForCustomer($orderId, (int) Customer::id());
}

if (!$order) {
    http_response_code(404);
    $notFoundTitle = 'Order not found. OK Veggies';
    ?><!doctype html>
    <html lang="en">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title><?= okv_e($notFoundTitle) ?></title>
      <meta name="robots" content="noindex">
      <?php okv_head_meta(['og_title' => 'Order not found']); ?>
      <link rel="stylesheet" href="<?= okv_e(okv_asset('/assets/css/tailwind.css')) ?>">
    </head>
    <body class="min-h-screen bg-forest-tint">
    <?php okv_shop_header(); ?>
    <main class="okv-container py-16 text-center">
      <h1 class="font-display text-3xl font-extrabold text-ink">We could not find that order</h1>
      <p class="mt-3 text-ink-60">Check the link, or sign in to open your order.</p>
      <a class="okv-btn mt-6 px-4" href="/account.php?mode=signin">Sign in</a>
    </main>
    <?php okv_shop_footer(); ?>
    </body>
    </html>
    <?php
    exit;
}

$publicTrail = $token !== '';

// The owner, fresh from checkout, gets the share token from the bag so the page
// can offer a share link. A public visitor already has it in the URL.
$shareToken = $token;
if (!$publicTrail) {
    $placed = Checkout::bag()['placed'] ?? [];
    if ((int) ($placed['order_id'] ?? 0) === (int) $order['id']) {
        $shareToken = (string) ($placed['trail_token'] ?? '');
    }
}

$labels = [
    'pay_in_full'     => 'Pay in full',
    'deposit'         => 'Pay a deposit',
    'pay_on_delivery' => 'Pay on delivery',
    'on_account'      => 'On account',
];
$trailUrl = OrderTrail::isValidToken($shareToken)
    ? rtrim((string) APP_URL, '/') . '/public/order.php?token=' . rawurlencode($shareToken)
    : '';
$shareUrl = $trailUrl !== ''
    ? 'https://wa.me/?text=' . rawurlencode('Follow order ' . $order['order_number'] . ': ' . $trailUrl)
    : '';

// What the customer can still pay online, and what the Paystack return told us.
// Only ever for the signed-in owner: the public trail shows no money and offers
// no payment.
$pendingPayment = $publicTrail ? null : Payments::pendingOnlinePayment((int) $order['id']);
$paymentFlag    = (string) okv_input('payment', '');
$paymentNotices = [
    'paid'        => ['Payment received. Thank you.', 'ok'],
    'pending'     => ['We are still confirming your payment with the bank. This page updates once it clears.', 'wait'],
    'review'      => ['Payment received. The amount differs from what we expected, so we are checking it and will be in touch.', 'wait'],
    'failed'      => ['That payment did not go through. Nothing has been taken. You can try again below.', 'problem'],
    'abandoned'   => ['That payment was not completed. Nothing has been taken. You can try again below.', 'problem'],
    'unavailable' => ['We could not reach the payment provider just now. Your order is saved. Try paying again below.', 'problem'],
    'missing'     => ['We could not match that payment. If money left your account, send us a message and we will sort it out.', 'problem'],
];
$paymentNotice = $paymentNotices[$paymentFlag] ?? null;

$pageTitle = 'Order ' . $order['order_number'] . '. OK Veggies';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= okv_e($pageTitle) ?></title>
  <meta name="robots" content="noindex">
  <?php okv_head_meta(['og_title' => 'Order ' . $order['order_number']]); ?>
  <link rel="stylesheet" href="<?= okv_e(okv_asset('/assets/css/tailwind.css')) ?>">
</head>
<body class="min-h-screen bg-forest-tint">
<?php okv_shop_header(); ?>

<main class="okv-container py-8 md:py-12">
  <p class="text-xs font-semibold uppercase tracking-[0.2em] text-gold-ink">Order <?= okv_e($order['order_number']) ?></p>
  <h1 class="mt-2 font-display text-4xl font-extrabold text-ink"><?= $publicTrail ? 'Follow this order' : 'We have your order' ?></h1>
  <p class="mt-3 text-ink-60">
    Status: <?= okv_e(ucfirst((string) $order['order_status'])) ?><?php
      if (!$publicTrail) {
          $paidLabels = [
              'paid'      => '. Paid in full.',
              'part_paid' => '. Part paid, a balance is still owed.',
              'unpaid'    => '. Nothing has been paid yet.',
          ];
          echo okv_e($paidLabels[(string) $order['payment_status']] ?? '');
      }
    ?>
  </p>

  <?php if ($paymentNotice !== null): ?>
    <p class="mt-4 rounded-xl border px-4 py-3 text-sm <?= $paymentNotice[1] === 'ok'
          ? 'border-foliage bg-foliage-tint text-ink'
          : ($paymentNotice[1] === 'problem' ? 'border-clay bg-clay-tint text-ink' : 'border-mist bg-white text-ink') ?>" role="status">
      <?= okv_e($paymentNotice[0]) ?>
    </p>
  <?php endif; ?>

  <div class="mt-8 grid gap-6 lg:grid-cols-3">
    <section class="okv-card lg:col-span-2">
      <h2 class="font-display text-xl font-bold text-ink">Items</h2>
      <ul class="mt-4 space-y-3">
        <?php foreach ($order['items'] as $item): ?>
          <li class="flex justify-between gap-4">
            <span><?= okv_e(okv_quantity($item['quantity'])) ?> <?= okv_e($item['unit_name']) ?> <?= okv_e($item['item_name']) ?></span>
            <?php if (!$publicTrail): ?>
              <span class="font-mono"><?= okv_e(Money::format((int) $item['line_total_subunit'])) ?></span>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>

    <aside class="okv-card">
      <h2 class="font-display text-xl font-bold text-ink">Order details</h2>
      <dl class="mt-4 space-y-3">
        <div>
          <dt class="text-sm text-ink-60">Delivery day</dt>
          <dd><?= okv_e(date('l jS F', strtotime((string) $order['preferred_delivery_date']))) ?></dd>
        </div>
        <div>
          <dt class="text-sm text-ink-60">Payment choice</dt>
          <dd><?= okv_e($labels[$order['payment_option']] ?? (string) $order['payment_option']) ?></dd>
        </div>
        <?php if (!$publicTrail && $order['amount_due_subunit'] !== null): ?>
          <div>
            <dt class="text-sm text-ink-60">Amount due now</dt>
            <dd class="font-mono"><?= okv_e(Money::format((int) $order['amount_due_subunit'])) ?></dd>
          </div>
        <?php endif; ?>
      </dl>
      <?php if ($pendingPayment !== null): ?>
        <?php $due = Money::balance((int) $pendingPayment['expected_amount_subunit'], (int) $pendingPayment['paid_amount_subunit']); ?>
        <form action="/api/v1/payments.php" method="POST" class="mt-5">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="initialise">
          <input type="hidden" name="payment_id" value="<?= (int) $pendingPayment['id'] ?>">
          <button class="okv-btn w-full justify-center min-h-[44px]">
            Pay <?= okv_e(Money::format($due)) ?> now
          </button>
          <p class="mt-2 text-center text-xs text-ink-60">
            You will be taken to Paystack to pay by card, transfer or USSD. We never see your card details.
          </p>
        </form>
      <?php endif; ?>

      <p class="mt-5 text-sm text-ink-60">Delivery fee is arranged and settled separately after we confirm your area.</p>
      <?php if ($shareUrl !== ''): ?>
        <a class="okv-btn-outline mt-5 w-full justify-center" href="<?= okv_e($shareUrl) ?>" rel="noopener" target="_blank">Share on WhatsApp</a>
      <?php endif; ?>
    </aside>
  </div>

  <section class="okv-card mt-6">
    <h2 class="font-display text-xl font-bold text-ink">Order Trail</h2>
    <ol class="mt-4 space-y-3">
      <?php foreach ($order['history'] as $event): ?>
        <li>
          <strong><?= okv_e(ucfirst((string) $event['new_status'])) ?></strong>
          <span class="text-sm text-ink-60"><?= okv_e(date('j M Y, H:i', strtotime((string) $event['created_at']))) ?></span>
        </li>
      <?php endforeach; ?>
    </ol>
  </section>
</main>

<?php okv_shop_footer(); ?>
<script src="<?= okv_e(okv_asset('/assets/js/okv.min.js')) ?>"></script>
</body>
</html>

<?php
/**
 * public/payment/receipt.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The "Payment received" screen (PRD Section 9.3a).
 *
 * A customer lands here in three ways:
 *
 *   1. Straight after Paystack confirms a charge (public/payment/callback.php).
 *   2. From the email or in-app notification that tells them a bank transfer
 *      has been verified by the team.
 *   3. From the Payments panel on their order page, any time later.
 *
 * It shows the amount received, the order details and what is still due, all
 * from PaymentSummary, the same numbers the order page reads.
 *
 * Who may open it, and never a fourth way:
 *   - the signed-in owner of the order;
 *   - the browser that just placed or paid a guest order (its trail token is in
 *     the session, exactly as the order page recognises it); or
 *   - the holder of a private receipt link (?token=), issued into the email.
 * The shareable Order Trail link is NOT one of them: that link deliberately
 * shows no money (PRD 14.2), so it cannot open this screen.
 *
 * Everything here is money, so the page is never cached or indexed.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/components/shop/header.php';
require_once __DIR__ . '/../../includes/components/shop/footer.php';
require_once __DIR__ . '/../../includes/components/shop/empty_state.php';
require_once __DIR__ . '/../../includes/components/shop/icons.php';
require_once __DIR__ . '/../../includes/components/shop/payment_summary.php';

header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');

$token       = trim((string) okv_input('token', ''));
$requestedId = (int) okv_input('order', 0);

$orderId = 0;
$orderHref = null;
if ($token !== '') {
    $orderId = ReceiptLink::findOrderId($token) ?? 0;
} elseif ($requestedId > 0) {
    $ownerId = Customer::id();
    if ($ownerId !== null) {
        $mine = Database::one('SELECT id FROM orders WHERE id = :id AND user_id = :user', [':id' => $requestedId, ':user' => $ownerId]);
        if ($mine !== null) {
            $orderId   = $requestedId;
            $orderHref = '/public/order.php?order=' . $orderId;
        }
    }
    if ($orderId === 0) {
        $sessionToken = OrderTrail::sessionToken($requestedId);
        if ($sessionToken !== null && OrderTrail::findByToken($sessionToken) !== null) {
            $orderId   = $requestedId;
            $orderHref = '/public/order.php?token=' . rawurlencode($sessionToken);
        }
    }
}

$summary = $orderId > 0 ? PaymentSummary::forOrder($orderId) : null;

if ($summary === null) {
    http_response_code(404);
    ?><!doctype html>
    <html lang="en">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>Receipt not found. OK Veggies</title>
      <meta name="robots" content="noindex">
      <?php okv_head_meta(['og_title' => 'Receipt not found']); ?>
      <link rel="stylesheet" href="<?= okv_e(okv_asset('/assets/css/tailwind.css')) ?>">
    </head>
    <body class="min-h-screen bg-forest-tint">
    <?php okv_shop_header(); ?>
    <main id="okv-main" class="okv-container py-16">
      <?php okv_empty_state('leaf', 'We could not open that receipt', 'The link may be out of date, or it belongs to another account. Sign in to look up your order.', [
          ['href' => '/account.php?mode=signin', 'label' => 'Sign in', 'icon' => 'user'],
          ['href' => '/shop.php', 'label' => 'Browse the shop', 'style' => 'outline', 'icon' => 'leaf'],
      ], ['heading_tag' => 'h1']); ?>
    </main>
    <?php okv_shop_footer(); ?>
    </body>
    </html>
    <?php
    exit;
}

$pageTitle = 'Payment for order ' . $summary['order_number'] . '. OK Veggies';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= okv_e($pageTitle) ?></title>
  <meta name="robots" content="noindex, nofollow">
  <?php okv_head_meta(['og_title' => 'Payment for order ' . $summary['order_number']]); ?>
  <link rel="stylesheet" href="<?= okv_e(okv_asset('/assets/css/tailwind.css')) ?>">
</head>
<body class="min-h-screen bg-forest-tint">
<?php okv_shop_header(); ?>

<main id="okv-main" class="okv-container py-8 md:py-12">
  <nav class="mb-6 flex min-h-[44px] flex-wrap items-center gap-2 text-sm text-ink-60" aria-label="Breadcrumb">
    <a href="/" class="hover:text-forest">Home</a>
    <span aria-hidden="true">/</span>
    <?php if ($orderHref !== null): ?>
      <a href="<?= okv_e($orderHref) ?>" class="hover:text-forest">Order <?= okv_e($summary['order_number']) ?></a>
      <span aria-hidden="true">/</span>
    <?php else: ?>
      <span>Order <?= okv_e($summary['order_number']) ?></span>
      <span aria-hidden="true">/</span>
    <?php endif; ?>
    <span aria-current="page">Payment</span>
  </nav>

  <div class="mx-auto max-w-3xl">
    <?php okv_payment_received_screen($summary, [
        'order_href'  => $orderHref,
        'order_label' => 'View your order',
        'shop_href'   => '/shop.php',
    ]); ?>
  </div>
</main>

<?php okv_shop_footer(); ?>
<script src="<?= okv_e(okv_asset('/assets/js/okv.min.js')) ?>"></script>
</body>
</html>

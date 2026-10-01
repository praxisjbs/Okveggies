<?php
/**
 * public/shortage.php
 * -----------------------------------------------------------------------------
 * OK Veggies. "One item was out of stock": where a customer says how they want
 * their money back.
 *
 * Two ways in, and never a third. The link in the email carries a token that
 * opens exactly this shortage (only its hash is stored), so a guest can answer
 * with no account. A signed-in customer can open the same page from their
 * order with ?id=, for the day the email is lost.
 *
 * Less text, more buttons. The amount is the loudest thing on the page, there are
 * two big buttons, and the sentence that explains each sits behind an info icon.
 * Without JavaScript the bank button is a details element, so the form is one
 * tap away and every button is a real form post.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/components/shop/header.php';
require_once __DIR__ . '/../includes/components/shop/footer.php';
require_once __DIR__ . '/../includes/components/shop/icons.php';

$token    = trim((string) okv_input('t', ''));
$id       = (int) okv_input('id', 0);
$shortage = null;
$formKey  = '';
if ($token !== '') {
    $shortage = Shortages::byToken($token);
    $formKey  = '<input type="hidden" name="t" value="' . okv_e($token) . '">';
} elseif ($id > 0) {
    if (!Customer::isLoggedIn()) {
        okv_redirect('/account.php?mode=signin');
    }
    $shortage = Shortages::forOwner($id, (int) Customer::id());
    $formKey  = '<input type="hidden" name="id" value="' . (int) $id . '">';
}

$errors = [
    'bad_bank'     => 'Check your bank details and try again.',
    'no_account'   => 'The wallet needs an account. Send it to your bank instead.',
    'bad_choice'   => 'Choose the wallet or your bank account.',
    'rate_limited' => 'Too many attempts. Wait a few minutes and try again.',
];
$errorCode = (string) okv_input('error', '');
$done      = (string) okv_input('done', '');
$bankOpen  = $errorCode === 'bad_bank' || (string) okv_input('choice', '') === 'bank';

$status   = $shortage === null ? '' : (string) $shortage['status'];
$resolved = $shortage !== null && in_array($status, [Shortages::STATUS_SETTLED, Shortages::STATUS_REFUND_PENDING], true);
$amount   = $shortage === null ? '' : Money::format((int) $shortage['refund_due_subunit']);
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>An item was out of stock . OK Veggies</title>
  <meta name="robots" content="noindex, nofollow">
  <?php okv_head_meta(); ?>
  <link rel="stylesheet" href="<?= okv_e(okv_asset('/assets/css/tailwind.css')) ?>">
</head>
<body class="min-h-screen bg-forest-tint">
<?php okv_shop_header(); ?>

<main id="okv-main" class="okv-container py-8 md:py-12">
<?php if ($shortage === null): ?>
  <section class="mx-auto max-w-xl rounded-xl bg-white p-6 text-center shadow-okv-1">
    <h1 class="font-editorial text-okv-h5 text-ink">We could not open this link</h1>
    <p class="mt-3 text-ink-60">It may have been mistyped, or it is no longer needed. If you are signed in, open the order from your account.</p>
    <a href="/account.php" class="okv-btn mt-5 inline-flex min-h-[44px] items-center px-5">Go to my account</a>
  </section>
<?php else: ?>
  <div class="mx-auto max-w-xl">
    <p class="okv-eyebrow">Order <?= okv_e((string) $shortage['order_number']) ?></p>
    <h1 class="mt-2 font-editorial text-okv-h5 text-ink md:text-okv-h4">One item was out of stock</h1>

    <section class="mt-6 rounded-xl bg-white p-5 shadow-okv-1" aria-labelledby="shortage-item-h">
      <div class="flex items-start justify-between gap-4">
        <div>
          <h2 id="shortage-item-h" class="font-semibold text-ink"><?= okv_e((string) $shortage['item_line']) ?></h2>
          <p class="text-sm text-ink-60">We could not source it. We are sorry.</p>
        </div>
        <details class="relative">
          <summary class="flex min-h-[44px] min-w-[44px] cursor-pointer list-none items-center justify-center rounded-xl text-forest hover:bg-forest-tint" aria-label="More about this">
            <?php okv_icon('info', 'h-5 w-5'); ?>
          </summary>
          <p class="absolute right-0 top-full z-10 mt-1 w-64 rounded-md border border-mist bg-white p-3 text-sm text-ink-60 shadow-okv-2">
            You paid for this item. It comes off your order, and the rest is unchanged. The amount below is yours to take back.
          </p>
        </details>
      </div>
      <p class="mt-4 font-mono text-4xl font-semibold text-forest" data-shortage-amount><?= okv_e($amount) ?></p>
      <p class="text-sm text-ink-60">is yours</p>
    </section>

    <?php if ($errorCode !== '' && isset($errors[$errorCode])): ?>
      <p class="mt-4 rounded-xl border border-clay bg-clay-tint px-4 py-3 text-sm text-ink" role="alert"><?= okv_e($errors[$errorCode]) ?></p>
    <?php endif; ?>

    <?php if ($status === Shortages::STATUS_AWAITING): ?>
      <section class="mt-6 space-y-4" aria-label="Choose what happens to it">
        <?php if (!empty($shortage['can_use_wallet'])): ?>
          <div class="flex items-stretch gap-2">
            <form action="/api/v1/shortages.php" method="POST" class="min-w-0 flex-1" data-once>
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="decide">
              <?= $formKey ?>
              <input type="hidden" name="choice" value="wallet">
              <button type="submit" class="okv-btn inline-flex min-h-[56px] w-full items-center justify-center gap-2 rounded-xl px-5">
                <?php okv_icon('wallet', 'h-5 w-5'); ?> <span>Add <?= okv_e($amount) ?> to my wallet</span>
              </button>
            </form>
            <details class="relative">
              <summary class="flex min-h-[56px] min-w-[44px] cursor-pointer list-none items-center justify-center rounded-xl text-forest hover:bg-forest-tint" aria-label="More about the wallet">
                <?php okv_icon('info', 'h-5 w-5'); ?>
              </summary>
              <p class="absolute right-0 top-full z-10 mt-1 w-64 rounded-md border border-mist bg-white p-3 text-sm text-ink-60 shadow-okv-2">
                It is in your wallet at once, ready to spend. It is offered first the next time you pay, and you get a credit note for it.
              </p>
            </details>
          </div>
        <?php endif; ?>

        <div class="flex items-start gap-2">
          <details class="min-w-0 flex-1 rounded-xl border border-forest bg-white" <?= $bankOpen ? 'open' : '' ?>>
            <summary class="flex min-h-[56px] cursor-pointer list-none items-center justify-center gap-2 rounded-xl px-5 font-semibold text-forest">
              <?php okv_icon('banknote', 'h-5 w-5'); ?> <span>Send it to my bank</span>
            </summary>
            <form action="/api/v1/shortages.php" method="POST" class="grid gap-3 border-t border-mist p-4" data-once>
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="decide">
              <?= $formKey ?>
              <input type="hidden" name="choice" value="bank">
              <div>
                <label class="okv-label" for="sh_bank">Bank</label>
                <input class="okv-input" id="sh_bank" name="bank_name" required maxlength="100" autocomplete="off" placeholder="For example GTBank">
              </div>
              <div>
                <label class="okv-label" for="sh_number">Account number</label>
                <input class="okv-input" id="sh_number" name="account_number" required inputmode="numeric" pattern="[0-9 \-]{10,14}" maxlength="14" autocomplete="off" placeholder="10 digits">
              </div>
              <div>
                <label class="okv-label" for="sh_name">Name on the account</label>
                <input class="okv-input" id="sh_name" name="account_name" required maxlength="150" autocomplete="off">
              </div>
              <button type="submit" class="okv-btn inline-flex min-h-[56px] w-full items-center justify-center rounded-xl px-5">Send <?= okv_e($amount) ?> to this account</button>
            </form>
          </details>
          <details class="relative">
            <summary class="flex min-h-[56px] min-w-[44px] cursor-pointer list-none items-center justify-center rounded-xl text-forest hover:bg-forest-tint" aria-label="More about a bank refund">
              <?php okv_icon('info', 'h-5 w-5'); ?>
            </summary>
            <p class="absolute right-0 top-full z-10 mt-1 w-64 rounded-md border border-mist bg-white p-3 text-sm text-ink-60 shadow-okv-2">
              A member of our team sends it by hand and we email you when it has gone. Check the account number: we cannot recall a transfer sent to the wrong one.
            </p>
          </details>
        </div>
        <p class="text-sm text-ink-60">Nothing happens until you choose.</p>
      </section>
    <?php elseif ($resolved): ?>
      <?php $resolution = (string) $shortage['resolution']; ?>
      <section class="mt-6 rounded-xl border border-foliage bg-foliage-tint p-5" role="status">
        <p class="flex items-center gap-2 font-semibold text-ink">
          <?php okv_icon('check', 'h-5 w-5 text-forest'); ?>
          <?php if ($resolution === Shortages::RESOLUTION_WALLET): ?>Added to your wallet
          <?php elseif ($status === Shortages::STATUS_SETTLED): ?>Sent to your bank
          <?php else: ?>On its way to your bank<?php endif; ?>
        </p>
        <p class="mt-1 text-sm text-ink-60">
          <?php if ($resolution === Shortages::RESOLUTION_WALLET): ?>It is ready to spend. It is offered first the next time you pay.
          <?php elseif ($status === Shortages::STATUS_SETTLED): ?>Most banks show it within a few hours.
          <?php else: ?>We send it by hand and email you when it has gone.<?php endif; ?>
        </p>
        <?php if ($resolution === Shortages::RESOLUTION_WALLET): ?>
          <a href="/wallet.php" class="okv-btn-outline mt-4 inline-flex min-h-[44px] items-center px-5">Open my wallet</a>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <?php if (Customer::isLoggedIn() && $shortage['user_id'] !== null && (int) $shortage['user_id'] === (int) Customer::id()): ?>
      <p class="mt-8 text-center text-sm">
        <a class="okv-btn-text inline-flex min-h-[44px] items-center" href="/public/order.php?order=<?= (int) $shortage['order_id'] ?>">Back to my order</a>
      </p>
    <?php endif; ?>
  </div>
<?php endif; ?>
</main>

<?php okv_shop_footer(); ?>
<script src="<?= okv_e(okv_asset('/assets/js/okv.min.js')) ?>"></script>
</body>
</html>

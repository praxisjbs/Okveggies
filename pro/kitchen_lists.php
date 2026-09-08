<?php
/**
 * pro/kitchen_lists.php
 * -----------------------------------------------------------------------------
 * OK Veggies. My Kitchen Lists, in the Pro Portal (PRD Section 4.2).
 *
 * A thin screen on purpose. It shows a business customer the Kitchen Runs they
 * have sent us, what each one is doing, and the way into a new one. It writes
 * nothing and it holds no domain logic of its own: every figure on it comes
 * from KitchenRuns::allForCustomer(), the same read the storefront uses, so the
 * two surfaces cannot disagree about the state of a request.
 *
 * Saved, reusable lists are M8. kitchen_run_templates exists for them and is
 * deliberately left alone here. The screen says so in one line rather than
 * pretending the feature is missing by accident.
 *
 * Access: signed in, and a business account. The Pro Portal is for B2B
 * customers, so a household account is told plainly and sent to the storefront
 * version of the same feature rather than shown an empty portal.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_business_customer();

$okv_pro_title  = 'My Kitchen Lists';
$okv_pro_note   = 'Every list you have sent us, what it is doing now, and the way to send another.';
$okv_pro_active = '/pro/kitchen_lists.php';

$runs = KitchenRuns::allForCustomer((int) Customer::id());

/** Waiting on us first, because those are the ones a kitchen is chasing. */
$waiting = 0;
foreach ($runs as $run) {
    if (in_array((string) $run['status'], ['submitted', 'quoted'], true)) {
        $waiting++;
    }
}

require __DIR__ . '/../includes/components/pro/header.php';
?>

  <div class="space-y-6">

    <!-- The way in, first, because sending a list is what this screen is for. -->
    <div class="okv-panel">
      <div class="okv-panel-head">
        <div>
          <p class="okv-eyebrow">Kitchen Runs</p>
          <h2 class="okv-panel-title mt-1">Send us this week's list</h2>
        </div>
        <a href="/kitchen-runs.php" class="okv-btn px-5 min-h-[44px] inline-flex items-center">Start a Kitchen Run</a>
      </div>
      <div class="p-4 md:p-5">
        <p class="text-sm text-ink">
          Write it out, photograph the paper, or pick from the shop. We price every line and send it back to you.
          Nothing is charged until you have read the prices and approved them.
        </p>
        <?php if ($waiting > 0): ?>
          <p class="mt-3 text-sm text-forest">
            <?= (int) $waiting ?> of your <?= (int) $waiting === 1 ? 'lists is' : 'lists are' ?> with us right now.
          </p>
        <?php endif; ?>
        <p class="mt-3 text-sm text-ink-60">
          Saving a list to reuse next week is coming. For now, open any run below and send the same items again.
        </p>
      </div>
    </div>

    <!-- What they have sent us. -->
    <div class="okv-panel">
      <div class="okv-panel-head">
        <div>
          <p class="okv-eyebrow">History</p>
          <h2 class="okv-panel-title mt-1">Your Kitchen Runs</h2>
        </div>
      </div>

      <?php if (!$runs): ?>
        <p class="p-4 text-sm text-ink-60 md:p-5">
          You have not sent a list yet. Start one above and it appears here, with its price as soon as we have it.
        </p>
      <?php else: ?>
        <div class="overflow-x-auto">
          <table class="w-full min-w-[40rem] text-left text-sm">
            <caption class="sr-only">Every Kitchen Run on this account, newest first</caption>
            <thead class="border-b border-mist text-ink-60">
              <tr>
                <th scope="col" class="px-4 py-2 font-medium md:px-5">Run</th>
                <th scope="col" class="px-4 py-2 font-medium">Sent</th>
                <th scope="col" class="px-4 py-2 font-medium">Items</th>
                <th scope="col" class="px-4 py-2 font-medium">Delivery</th>
                <th scope="col" class="px-4 py-2 font-medium">Status</th>
                <th scope="col" class="px-4 py-2 font-medium text-right md:px-5">Total</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($runs as $run): ?>
                <tr class="border-b border-mist/60">
                  <td class="px-4 py-2 md:px-5">
                    <a class="font-mono font-semibold text-forest underline-offset-2 hover:underline"
                       href="/kitchen-runs.php?request=<?= (int) $run['id'] ?>">
                      <?= okv_e((string) $run['request_number']) ?>
                    </a>
                  </td>
                  <td class="px-4 py-2 text-ink-60"><?= okv_e(date('j M Y', strtotime((string) $run['created_at']))) ?></td>
                  <td class="px-4 py-2"><?= (int) $run['line_count'] ?></td>
                  <td class="px-4 py-2 text-ink-60">
                    <?= $run['preferred_delivery_date'] === null
                      ? 'Not set'
                      : okv_e(date('l jS F', strtotime((string) $run['preferred_delivery_date']))) ?>
                  </td>
                  <td class="px-4 py-2"><?= okv_e((string) $run['status_label']) ?></td>
                  <td class="px-4 py-2 text-right font-mono md:px-5">
                    <?= $run['quoted_total_subunit'] === null
                      ? '<span class="text-ink-60">Waiting</span>'
                      : okv_e(Money::format((int) $run['quoted_total_subunit'])) ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <p class="text-sm text-ink-60">
      Looking for what you owe or what is on the way?
      <a class="inline-flex min-h-[44px] items-center text-forest underline underline-offset-2" href="/pro/orders.php">Orders and invoices</a> has it.
    </p>

  </div>

<?php require __DIR__ . '/../includes/components/pro/footer.php'; ?>

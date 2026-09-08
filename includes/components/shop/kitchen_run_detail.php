<?php
/**
 * includes/components/shop/kitchen_run_detail.php
 * -----------------------------------------------------------------------------
 * OK Veggies. One Kitchen Run as the customer sees it: what they asked for,
 * what we quoted, and the one or two things they can do about it.
 *
 * Included by kitchen-runs.php when ?request= names a run that belongs to the
 * signed-in customer. The ownership check is done by the page through
 * KitchenRuns::findForCustomer(), which returns nothing for anybody else's run.
 *
 * Expects, from the including scope:
 *   $request  array  the decorated request row
 *   $lines    array  its item lines
 *   $history  array  its append-only status trail
 *
 * The approve button carries the state version it was rendered with, so a
 * customer who leaves the page open while we re-price the list is told the
 * figures moved rather than approving a total they never saw.
 * -----------------------------------------------------------------------------
 */

$total   = $request['quoted_total_subunit'] === null ? null : (int) $request['quoted_total_subunit'];
$deposit = $request['deposit_subunit'] === null ? null : (int) $request['deposit_subunit'];
?>
<section class="mt-8 rounded-xl border border-mist bg-white p-5 md:p-6" aria-labelledby="run-heading">

  <div class="flex flex-wrap items-baseline justify-between gap-3">
    <h2 id="run-heading" class="font-editorial text-2xl text-ink">
      Kitchen Run <span class="font-mono text-xl"><?= okv_e($request['request_number']) ?></span>
    </h2>
    <span class="rounded-full border border-mist px-3 py-1 text-sm text-ink-60"><?= okv_e($request['status_label']) ?></span>
  </div>

  <p class="mt-2 text-sm text-ink-60">
    Sent <?= okv_e(date('l jS F', strtotime((string) $request['created_at']))) ?>.
    <?= okv_e(KitchenRuns::modeLabel((string) $request['input_mode'])) ?>.
    <?php if (!empty($request['is_open_budget'])): ?>
      Open budget<?= $request['spend_cap_subunit'] === null
        ? ', with no cap set'
        : ', capped at ' . okv_e(Money::format((int) $request['spend_cap_subunit'])) ?>.
    <?php endif; ?>
  </p>

  <!-- The list. -->
  <div class="mt-5 overflow-x-auto">
    <table class="w-full min-w-[32rem] text-left text-sm">
      <caption class="sr-only">The items on this Kitchen Run</caption>
      <thead class="border-b border-mist text-ink-60">
        <tr>
          <th scope="col" class="py-2 pr-3 font-medium">Item</th>
          <th scope="col" class="py-2 pr-3 font-medium">How much</th>
          <th scope="col" class="py-2 pr-3 font-medium text-right">Price</th>
          <th scope="col" class="py-2 font-medium text-right">Line total</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($lines as $line): ?>
          <tr class="border-b border-mist/60">
            <td class="py-2 pr-3">
              <?= okv_e($line['product_name'] ?? $line['item_name']) ?>
              <?php if (trim((string) ($line['note'] ?? '')) !== ''): ?>
                <span class="block text-ink-60"><?= okv_e($line['note']) ?></span>
              <?php endif; ?>
            </td>
            <td class="py-2 pr-3">
              <?= $line['quantity'] === null ? 'We decide' : okv_e(okv_quantity($line['quantity'])) ?>
              <?= okv_e($line['unit_name'] ?? $line['unit_label'] ?? '') ?>
            </td>
            <td class="py-2 pr-3 text-right font-mono">
              <?= $line['unit_price_subunit'] === null ? 'Pending' : okv_e(Money::format((int) $line['unit_price_subunit'])) ?>
            </td>
            <td class="py-2 text-right font-mono">
              <?= $line['line_total_subunit'] === null ? 'Pending' : okv_e(Money::format((int) $line['line_total_subunit'])) ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <?php if ($total !== null): ?>
        <tfoot>
          <tr>
            <th scope="row" colspan="3" class="py-3 pr-3 text-right font-semibold">Total</th>
            <td class="py-3 text-right font-mono font-semibold"><?= okv_e(Money::format($total)) ?></td>
          </tr>
        </tfoot>
      <?php endif; ?>
    </table>
  </div>

  <?php if (trim((string) ($request['customer_note'] ?? '')) !== ''): ?>
    <p class="mt-4 rounded-md bg-canvas px-4 py-3 text-sm">
      <span class="font-medium">What you told us:</span> <?= okv_e($request['customer_note']) ?>
    </p>
  <?php endif; ?>

  <?php if ($request['attachment_url']): ?>
    <p class="mt-4">
      <a class="okv-btn-text text-sm" href="/public/kitchen_run_attachment.php?request=<?= (int) $request['id'] ?>">
        Download the list you sent
      </a>
    </p>
  <?php endif; ?>

  <!-- The quote, and what to do about it. -->
  <?php if ($request['status'] === 'quoted'): ?>
    <div class="mt-6 rounded-lg border border-forest bg-foliage-tint p-4">
      <?php if (!empty($request['is_expired'])): ?>
        <p class="text-sm text-ink">
          This quote has expired. Produce prices move, so we will not hold a stale price against you.
          Send the list again and we will price it fresh.
        </p>
      <?php else: ?>
        <p class="font-semibold text-ink">Your quote is ready.</p>
        <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-3">
          <div>
            <dt class="text-ink-60">Total</dt>
            <dd class="font-mono text-base"><?= okv_e(Money::format((int) $total)) ?></dd>
          </div>
          <div>
            <dt class="text-ink-60">Deposit to start</dt>
            <dd class="font-mono text-base">
              <?= $deposit === null || $deposit < 1 ? 'None yet' : okv_e(Money::format($deposit)) ?>
            </dd>
          </div>
          <div>
            <dt class="text-ink-60">Balance on delivery</dt>
            <dd class="font-mono text-base"><?= okv_e(Money::format((int) $request['balance_subunit'])) ?></dd>
          </div>
        </dl>
        <?php if ($request['preferred_delivery_date']): ?>
          <p class="mt-3 text-sm text-ink-60">
            Delivery <?= okv_e(date('l jS F', strtotime((string) $request['preferred_delivery_date']))) ?><?php
              ?><?= $request['zone_name'] ? ', ' . okv_e($request['zone_name']) : '' ?>.
            The delivery fee is settled with you separately.
          </p>
        <?php endif; ?>
        <?php if ($request['expires_at']): ?>
          <p class="mt-1 text-sm text-ink-60">
            These prices stand until <?= okv_e(date('l jS F', strtotime((string) $request['expires_at']))) ?>.
          </p>
        <?php endif; ?>
        <?php if (trim((string) ($request['admin_note'] ?? '')) !== ''): ?>
          <p class="mt-3 rounded-md bg-white px-4 py-3 text-sm">
            <span class="font-medium">From the team:</span> <?= okv_e($request['admin_note']) ?>
          </p>
        <?php endif; ?>

        <form action="/api/v1/kitchen_runs.php" method="post" class="mt-4">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="approve">
          <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
          <input type="hidden" name="state_version" value="<?= (int) $request['state_version'] ?>">
          <button class="okv-btn" type="submit">Approve this quote</button>
        </form>
      <?php endif; ?>
    </div>
  <?php elseif ($request['status'] === 'converted' && $request['converted_order_id']): ?>
    <p class="mt-6 rounded-lg border border-forest bg-foliage-tint p-4 text-sm">
      This is now order
      <a class="font-mono font-semibold text-forest underline" href="/public/order.php?order=<?= (int) $request['converted_order_id'] ?>">
        <?= okv_e((string) $request['order_number']) ?>
      </a>.
      Follow it there for packing and delivery.
    </p>
  <?php elseif ($request['status'] === 'declined'): ?>
    <p class="mt-6 rounded-lg border border-clay bg-clay-tint p-4 text-sm">
      We could not take this one on.
      <?= trim((string) ($request['admin_note'] ?? '')) !== '' ? okv_e($request['admin_note']) : '' ?>
      Nothing has been charged. Send us another list whenever you are ready.
    </p>
  <?php elseif ($request['status'] === 'submitted'): ?>
    <p class="mt-6 rounded-lg border border-mist bg-canvas p-4 text-sm text-ink-60">
      We have your list and we are pricing it. The quote lands here, and in your email.
    </p>
  <?php endif; ?>

  <!-- Approved, and waiting on us to make the order. -->
  <?php if ($request['status'] === 'approved'): ?>
    <p class="mt-6 rounded-lg border border-forest bg-foliage-tint p-4 text-sm">
      You have approved this at <span class="font-mono"><?= okv_e(Money::format((int) $total)) ?></span>.
      We are turning it into an order and we will be in touch about the deposit.
    </p>
  <?php endif; ?>

  <!-- Withdrawing it, while there is still time. -->
  <?php if (!empty($request['may_cancel'])): ?>
    <form action="/api/v1/kitchen_runs.php" method="post" class="mt-4 border-t border-mist pt-4">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="cancel">
      <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
      <input type="hidden" name="state_version" value="<?= (int) $request['state_version'] ?>">
      <button class="okv-btn-text text-sm min-h-[44px]" type="submit">Withdraw this Kitchen Run</button>
      <?php if ($request['status'] === 'approved'): ?>
        <span class="text-sm text-ink-60">
          Nothing has been charged. We may already be at the market for this list, so please call us as well if you need it stopped today.
        </span>
      <?php else: ?>
        <span class="text-sm text-ink-60">Nothing is charged, and you can send a new list any time.</span>
      <?php endif; ?>
    </form>
  <?php endif; ?>

  <!-- What has happened so far. -->
  <?php if ($history): ?>
    <details class="mt-5">
      <summary class="cursor-pointer text-sm text-ink-60 min-h-[44px] py-2">What has happened so far</summary>
      <ol class="mt-2 space-y-2 text-sm">
        <?php foreach ($history as $event): ?>
          <li class="flex flex-wrap gap-2">
            <span class="font-mono text-ink-60"><?= okv_e(date('j M, H:i', strtotime((string) $event['created_at']))) ?></span>
            <span class="font-medium"><?= okv_e(KitchenRuns::statusLabel((string) $event['new_status'])) ?></span>
            <?php if (trim((string) ($event['note'] ?? '')) !== ''): ?>
              <span class="text-ink-60"><?= okv_e($event['note']) ?></span>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ol>
    </details>
  <?php endif; ?>

  <p class="mt-5">
    <a class="okv-btn-text text-sm" href="/kitchen-runs.php">Back to all your Kitchen Runs</a>
  </p>
</section>

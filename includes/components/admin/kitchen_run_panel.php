<?php
/**
 * includes/components/admin/kitchen_run_panel.php
 * -----------------------------------------------------------------------------
 * OK Veggies. One Kitchen Run open on the staff screen: what the customer sent,
 * the line editor that prices it, and the decision the request is waiting for.
 *
 * Included by admin/kitchen_runs.php. Expects, from the including scope:
 *   $request        array  the decorated request row
 *   $lines          array  its item lines
 *   $history        array  its append-only status trail
 *   $units, $zones, $products, $eligibleDates
 *
 * The state version is carried on every form, so two colleagues working the
 * same request in two tabs cannot silently overwrite one another: the second
 * one is told the request moved and reloads it.
 * -----------------------------------------------------------------------------
 */

$status    = (string) $request['status'];
$mayQuote  = Rbac::can('kitchen_runs.quote') && KitchenRuns::mayTransition($status, 'quoted');
$mayDecline = Rbac::can('kitchen_runs.decline') && KitchenRuns::mayTransition($status, 'declined');
$mayConvert = Rbac::can('kitchen_runs.convert') && KitchenRuns::mayTransition($status, 'converted');
$total     = $request['quoted_total_subunit'] === null ? null : (int) $request['quoted_total_subunit'];
$deposit   = $request['deposit_subunit'] === null ? null : (int) $request['deposit_subunit'];
$editable  = $lines ?: [[]];
?>
<section class="okv-card" aria-labelledby="run-panel-heading">

  <div class="flex flex-wrap items-baseline justify-between gap-3 border-b border-mist pb-4">
    <div>
      <h2 id="run-panel-heading" class="font-display text-xl font-bold text-ink">
        <span class="font-mono"><?= okv_e($request['request_number']) ?></span>
        <span class="ml-2 text-base font-normal text-ink-60"><?= okv_e($request['status_label']) ?></span>
      </h2>
      <p class="mt-1 text-sm text-ink-60">
        <?= okv_e(trim((string) $request['customer_name']) ?: (string) $request['contact_name']) ?>,
        <?= okv_e((string) $request['customer_email']) ?>,
        <?= okv_e((string) $request['contact_phone']) ?>.
        <?= okv_e(KitchenRuns::modeLabel((string) $request['input_mode'])) ?>,
        <?= okv_e(KitchenRuns::pricingLabel((string) $request['pricing_mode'])) ?>.
      </p>
    </div>
    <a class="okv-btn-text text-sm" href="?<?= okv_e(okv_input('status', '') !== '' ? 'status=' . okv_input('status', '') : '') ?>">Close this request</a>
  </div>

  <!-- What the customer sent, before anybody touched it. -->
  <div class="mt-4 grid gap-4 lg:grid-cols-3">
    <div class="rounded-lg border border-mist bg-canvas p-4 text-sm">
      <h3 class="font-semibold text-ink">Deliver to</h3>
      <p class="mt-1 text-ink-60">
        <?= okv_e((string) ($request['delivery_recipient_name'] ?: $request['contact_name'])) ?><br>
        <?= okv_e((string) ($request['delivery_recipient_phone'] ?: $request['contact_phone'])) ?><br>
        <?= okv_e((string) $request['delivery_address_line_1']) ?><br>
        <?php if ($request['delivery_address_line_2']): ?><?= okv_e((string) $request['delivery_address_line_2']) ?><br><?php endif; ?>
        <?= okv_e((string) $request['delivery_city']) ?>, <?= okv_e((string) $request['delivery_state']) ?>
        <?php if ($request['delivery_landmark']): ?><br>Landmark: <?= okv_e((string) $request['delivery_landmark']) ?><?php endif; ?>
      </p>
      <?php if (trim((string) ($request['delivery_address_line_1'] ?? '')) === ''): ?>
        <p class="mt-2 text-tomato">
          This request was sent before addresses were captured with the list. Ask the customer for it before converting.
        </p>
      <?php endif; ?>
    </div>

    <div class="rounded-lg border border-mist bg-canvas p-4 text-sm">
      <h3 class="font-semibold text-ink">Budget</h3>
      <p class="mt-1 text-ink-60">
        <?php if (!empty($request['is_open_budget'])): ?>
          Open budget. Source it and set a deposit you are comfortable with.<br>
          Spend cap:
          <span class="font-mono"><?= $request['spend_cap_subunit'] === null ? 'none agreed' : okv_e(Money::format((int) $request['spend_cap_subunit'])) ?></span>.
          <?php if ($request['spend_cap_subunit'] !== null): ?>
            A quote above the cap is refused.
          <?php endif; ?>
        <?php else: ?>
          Standard request, priced line by line.
        <?php endif; ?>
      </p>
    </div>

    <div class="rounded-lg border border-mist bg-canvas p-4 text-sm">
      <h3 class="font-semibold text-ink">What they told us</h3>
      <p class="mt-1 text-ink-60">
        <?= trim((string) ($request['customer_note'] ?? '')) !== '' ? okv_e($request['customer_note']) : 'Nothing beyond the list.' ?>
      </p>
      <?php if ($request['attachment_url']): ?>
        <p class="mt-3">
          <a class="okv-btn-outline-sm" href="/public/kitchen_run_attachment.php?request=<?= (int) $request['id'] ?>">
            Open the list they sent
          </a>
        </p>
        <p class="mt-2 text-ink-60">Type every line from it into the editor below, then price it.</p>
      <?php endif; ?>
    </div>
  </div>

  <!-- ---------------------------------------------------------------------
       The quote workshop. Lines can be added, edited and removed, which is
       what makes transcribing an uploaded list possible at all.
       --------------------------------------------------------------------- -->
  <?php if ($mayQuote): ?>
    <form method="post" action="/api/v1/kitchen_runs.php" class="mt-6 border-t border-mist pt-5"
          data-kr-admin-form
          data-spend-cap="<?= okv_e((string) ($request['spend_cap_subunit'] ?? '')) ?>">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="quote">
      <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
      <input type="hidden" name="state_version" value="<?= (int) $request['state_version'] ?>">

      <h3 class="font-display text-lg font-bold text-ink">
        <?= $status === 'quoted' ? 'Correct this quote' : 'Price this list' ?>
      </h3>
      <p class="mt-1 text-sm text-ink-60">
        Prices are in naira. Every line needs a quantity, a unit and a price.
        <?php if ($status === 'quoted'): ?>
          Sending it again replaces the quote and clears the customer's approval, so they always approve figures they have seen.
        <?php endif; ?>
      </p>

      <div class="mt-4 space-y-3" data-kr-admin-rows>
        <?php foreach ($editable as $index => $line): ?>
          <div class="grid gap-3 rounded-lg border border-mist p-3 sm:grid-cols-12" data-kr-admin-row>
            <input type="hidden" name="items[<?= (int) $index ?>][product_id]" value="<?= okv_e((string) ($line['product_id'] ?? '')) ?>">
            <div class="sm:col-span-4">
              <label class="okv-label" for="kr-a-name-<?= (int) $index ?>">Item</label>
              <input class="okv-input" id="kr-a-name-<?= (int) $index ?>" name="items[<?= (int) $index ?>][item_name]"
                     maxlength="200" value="<?= okv_e((string) ($line['item_name'] ?? '')) ?>">
            </div>
            <div class="sm:col-span-2">
              <label class="okv-label" for="kr-a-qty-<?= (int) $index ?>">Quantity</label>
              <input class="okv-input" id="kr-a-qty-<?= (int) $index ?>" name="items[<?= (int) $index ?>][quantity]"
                     inputmode="decimal" value="<?= okv_e(($line['quantity'] ?? null) === null ? '' : okv_quantity($line['quantity'])) ?>">
            </div>
            <div class="sm:col-span-2">
              <label class="okv-label" for="kr-a-unit-<?= (int) $index ?>">Unit</label>
              <select class="okv-input" id="kr-a-unit-<?= (int) $index ?>" name="items[<?= (int) $index ?>][unit_id]">
                <option value="">Choose</option>
                <?php foreach ($units as $unit): ?>
                  <option value="<?= (int) $unit['id'] ?>" <?= (int) ($line['unit_id'] ?? 0) === (int) $unit['id'] ? 'selected' : '' ?>>
                    <?= okv_e($unit['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="sm:col-span-3">
              <label class="okv-label" for="kr-a-price-<?= (int) $index ?>">Price per unit, naira</label>
              <input class="okv-input" id="kr-a-price-<?= (int) $index ?>" name="items[<?= (int) $index ?>][unit_price]"
                     inputmode="decimal"
                     value="<?= ($line['unit_price_subunit'] ?? null) === null ? '' : okv_e((string) Money::toNaira((int) $line['unit_price_subunit'])) ?>">
            </div>
            <div class="flex items-end sm:col-span-1">
              <button type="button" class="okv-btn-text text-sm min-h-[44px]" data-kr-admin-remove hidden>Remove</button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="mt-3 flex flex-wrap items-center gap-4">
        <button type="button" class="okv-btn-outline-sm" data-kr-admin-add hidden>Add a line</button>
        <p class="text-sm text-ink-60" data-kr-admin-total hidden></p>
      </div>

      <div class="mt-5 grid gap-4 sm:grid-cols-3">
        <div>
          <label class="okv-label" for="deposit">Deposit, naira</label>
          <input class="okv-input" id="deposit" name="deposit" inputmode="decimal"
                 value="<?= $deposit === null ? '' : okv_e((string) Money::toNaira($deposit)) ?>">
          <p class="mt-1 text-sm text-ink-60">
            <?= !empty($request['is_open_budget'])
              ? 'Required on an open-budget run. Your judgement, based on the list and the customer.'
              : 'Optional. Leave it blank to use the standard deposit percentage at conversion.' ?>
          </p>
        </div>
        <div>
          <label class="okv-label" for="preferred_delivery_date">Delivery day</label>
          <select class="okv-input" id="preferred_delivery_date" name="preferred_delivery_date" required>
            <?php foreach ($eligibleDates as $date): ?>
              <option value="<?= okv_e($date['date']) ?>" <?= (string) $request['preferred_delivery_date'] === $date['date'] ? 'selected' : '' ?>>
                <?= okv_e(date('l jS F', strtotime((string) $date['date']))) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if (!$eligibleDates): ?>
            <p class="mt-1 text-sm text-tomato">No delivery days are open for this customer type. Fix that in Delivery settings first.</p>
          <?php endif; ?>
        </div>
        <div>
          <label class="okv-label" for="delivery_zone_id">Delivery area</label>
          <select class="okv-input" id="delivery_zone_id" name="delivery_zone_id" required>
            <?php foreach ($zones as $zone): ?>
              <option value="<?= (int) $zone['id'] ?>" <?= (int) $request['delivery_zone_id'] === (int) $zone['id'] ? 'selected' : '' ?>>
                <?= okv_e($zone['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="mt-4">
        <label class="okv-label" for="admin_note">A note the customer will read with the quote</label>
        <textarea class="okv-input" id="admin_note" name="admin_note" rows="2" maxlength="2000"><?= okv_e((string) ($request['admin_note'] ?? '')) ?></textarea>
      </div>

      <div class="mt-5">
        <button class="okv-btn" type="submit"><?= $status === 'quoted' ? 'Send the corrected quote' : 'Send the quote' ?></button>
      </div>
    </form>
  <?php else: ?>
    <!-- Not priceable now, so the lines are read only. -->
    <div class="mt-6 overflow-x-auto border-t border-mist pt-5">
      <h3 class="font-display text-lg font-bold text-ink">The list</h3>
      <table class="mt-3 w-full min-w-[32rem] text-left text-sm">
        <thead class="border-b border-mist text-ink-60">
          <tr>
            <th scope="col" class="py-2 pr-3 font-medium">Item</th>
            <th scope="col" class="py-2 pr-3 font-medium">Quantity</th>
            <th scope="col" class="py-2 pr-3 font-medium text-right">Price</th>
            <th scope="col" class="py-2 font-medium text-right">Line total</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($lines as $line): ?>
            <tr class="border-b border-mist/60">
              <td class="py-2 pr-3"><?= okv_e($line['product_name'] ?? $line['item_name']) ?></td>
              <td class="py-2 pr-3">
                <?= $line['quantity'] === null ? '' : okv_e(okv_quantity($line['quantity'])) ?>
                <?= okv_e($line['unit_name'] ?? $line['unit_label'] ?? '') ?>
              </td>
              <td class="py-2 pr-3 text-right font-mono"><?= $line['unit_price_subunit'] === null ? '' : okv_e(Money::format((int) $line['unit_price_subunit'])) ?></td>
              <td class="py-2 text-right font-mono"><?= $line['line_total_subunit'] === null ? '' : okv_e(Money::format((int) $line['line_total_subunit'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <?php if ($total !== null): ?>
          <tfoot>
            <tr>
              <th scope="row" colspan="3" class="py-3 pr-3 text-right font-semibold">Quoted total</th>
              <td class="py-3 text-right font-mono font-semibold"><?= okv_e(Money::format($total)) ?></td>
            </tr>
          </tfoot>
        <?php endif; ?>
      </table>
    </div>
  <?php endif; ?>

  <!-- The decision this request is waiting for. -->
  <?php if ($mayConvert): ?>
    <form method="post" action="/api/v1/kitchen_runs.php" class="mt-6 rounded-lg border border-forest bg-foliage-tint p-4">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="convert">
      <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
      <input type="hidden" name="state_version" value="<?= (int) $request['state_version'] ?>">

      <h3 class="font-display text-lg font-bold text-ink">Turn it into an order</h3>
      <p class="mt-1 text-sm text-ink-60">
        The customer approved <span class="font-mono"><?= okv_e(Money::format((int) $total)) ?></span>.
        This writes a real order with the delivery address, the payment rows and the order trail, exactly like a checkout order.
      </p>

      <div class="mt-4 grid gap-4 sm:grid-cols-3">
        <div>
          <label class="okv-label" for="payment_option">How it is settled</label>
          <select class="okv-input" id="payment_option" name="payment_option" required>
            <option value="deposit">Deposit now, balance on delivery</option>
            <?php if (empty($request['is_open_budget'])): ?>
              <option value="pay_in_full">Paid in full</option>
            <?php endif; ?>
            <?php if ((string) $request['customer_type'] === 'business'): ?>
              <option value="on_account">On account, approved credit</option>
            <?php endif; ?>
          </select>
        </div>
        <div>
          <label class="okv-label" for="convert_date">Delivery day</label>
          <select class="okv-input" id="convert_date" name="preferred_delivery_date">
            <?php foreach ($eligibleDates as $date): ?>
              <option value="<?= okv_e($date['date']) ?>" <?= (string) $request['preferred_delivery_date'] === $date['date'] ? 'selected' : '' ?>>
                <?= okv_e(date('l jS F', strtotime((string) $date['date']))) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <p class="mt-1 text-sm text-ink-60">The quoted day, unless it has passed while this sat waiting.</p>
        </div>
        <div class="flex items-end">
          <button class="okv-btn" type="submit">Make the order</button>
        </div>
      </div>
    </form>
  <?php elseif ($status === 'converted' && $request['converted_order_id']): ?>
    <p class="mt-6 rounded-lg border border-forest bg-foliage-tint p-4 text-sm">
      Made into order
      <a class="font-mono font-semibold text-forest underline" href="/admin/orders.php?order=<?= (int) $request['converted_order_id'] ?>">
        <?= okv_e((string) $request['order_number']) ?>
      </a>.
      It is on the delivery day manifest like any other order.
    </p>
  <?php elseif ($status === 'approved'): ?>
    <p class="mt-6 rounded-lg border border-mist bg-canvas p-4 text-sm text-ink-60">
      The customer approved this. Somebody with permission to convert a Kitchen Run has to make the order.
    </p>
  <?php endif; ?>

  <?php if ($mayDecline): ?>
    <form method="post" action="/api/v1/kitchen_runs.php" class="mt-6 border-t border-mist pt-5">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="decline">
      <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
      <input type="hidden" name="state_version" value="<?= (int) $request['state_version'] ?>">
      <label class="okv-label" for="decline_note">Decline this request, and say why</label>
      <div class="flex flex-wrap gap-3">
        <input class="okv-input max-w-xl" id="decline_note" name="admin_note" required maxlength="500"
               placeholder="We cannot get pomo at a price we would stand behind this week.">
        <button class="okv-btn-outline" type="submit">Decline</button>
      </div>
      <p class="mt-1 text-sm text-ink-60">The customer is emailed this sentence, so write it for them to read.</p>
    </form>
  <?php endif; ?>

  <!-- The trail. -->
  <?php if ($history): ?>
    <div class="mt-6 border-t border-mist pt-5">
      <h3 class="font-display text-lg font-bold text-ink">What has happened</h3>
      <ol class="mt-3 space-y-2 text-sm">
        <?php foreach ($history as $event): ?>
          <li class="flex flex-wrap gap-2">
            <span class="font-mono text-ink-60"><?= okv_e(date('j M Y, H:i', strtotime((string) $event['created_at']))) ?></span>
            <span class="font-medium"><?= okv_e(KitchenRuns::statusLabel((string) $event['new_status'])) ?></span>
            <span class="text-ink-60"><?= okv_e($event['source']) ?><?= trim((string) ($event['actor_name'] ?? '')) !== '' ? ', ' . okv_e($event['actor_name']) : '' ?></span>
            <?php if (trim((string) ($event['note'] ?? '')) !== ''): ?>
              <span class="text-ink-60"><?= okv_e($event['note']) ?></span>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ol>
    </div>
  <?php endif; ?>

</section>

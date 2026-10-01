<?php
/**
 * includes/components/admin/payment_search_results.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The orders a payment search turned up, as the "Record a payment"
 * panel lists them: the empty answer, or the table with the customer's name and
 * the day first, because those are the two facts that settle "is this the right
 * order" on a phone call.
 *
 * One renderer, two callers. /admin/payments.php prints it on a plain load and
 * api/v1/payments.php (action browse) prints the same markup into the live
 * search response, so what a colleague sees while typing is exactly what a
 * reload of the same URL shows. The two cannot drift.
 *
 *   okv_admin_payment_search_results($matches, $search, $openOrderId);
 * -----------------------------------------------------------------------------
 */
if (!defined('OKV_BOOTSTRAPPED')) {
    http_response_code(500);
    exit;
}

if (!function_exists('okv_payments_customer_name')) {
    /** The name to put on an order: whoever it is going to, else the account. */
    function okv_payments_customer_name(array $order): string
    {
        $recipient = trim((string) ($order['recipient_name'] ?? ''));
        if ($recipient !== '') {
            return $recipient;
        }
        $account = trim((string) ($order['account_name'] ?? ''));
        return $account !== '' ? $account : 'No name on this order';
    }
}

if (!function_exists('okv_payment_badge')) {
    /** The badge tone for a transaction status. Colour is never the only signal. */
    function okv_payment_badge(string $status): string
    {
        switch ($status) {
            case 'success':  return 'okv-badge-available';
            case 'failed':
            case 'reversed': return 'okv-badge-out';
            case 'mismatch':
            case 'awaiting_review':
            case 'unknown':  return 'okv-badge-warn';
            default:         return 'okv-badge-neutral';
        }
    }
}

if (!function_exists('okv_admin_payment_search_results')) {
    /**
     * Render the search answer for one typed term. An empty term renders
     * nothing at all: before anybody types, the panel explains itself and a
     * blank results region is the honest answer.
     *
     * @param array<int, array<string, mixed>> $matches
     */
    function okv_admin_payment_search_results(array $matches, string $search, int $openOrderId): void
    {
        if ($search === '') {
            return;
        }
        ?>
    <?php if (!$matches): ?>
      <p class="mt-4 rounded-md border border-clay bg-clay-tint px-3 py-2 text-sm text-ink" role="status">
        Nothing matches <?= okv_e($search) ?>. Try the phone number, or part of the name.
      </p>
    <?php endif; ?>

    <?php if (count($matches) > 1 || ($matches && $openOrderId > 0)): ?>
      <!-- The result list. Name and date first, because those are what a
           colleague can check against the person on the phone. -->
      <div class="okv-table-wrap mt-4">
        <table class="okv-table">
          <caption class="sr-only">Orders matching <?= okv_e($search) ?></caption>
          <thead>
            <tr>
              <th scope="col">Customer</th>
              <th scope="col">Order</th>
              <th scope="col">Ordered</th>
              <th scope="col">Delivery</th>
              <th scope="col">Total</th>
              <th scope="col">Outstanding</th>
              <th scope="col">Payment</th>
              <th scope="col"><span class="sr-only">Choose</span></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($matches as $match): ?>
              <?php $isOpen = $openOrderId > 0 && $openOrderId === (int) $match['id']; ?>
              <tr<?= $isOpen ? ' class="bg-foliage-tint"' : '' ?>>
                <td>
                  <span class="font-medium text-ink"><?= okv_e(okv_payments_customer_name($match)) ?></span>
                  <span class="okv-table-sub"><?= okv_e(Phone::display((string) ($match['account_phone'] ?? ''))) ?></span>
                </td>
                <td class="font-mono"><?= okv_e($match['order_number']) ?></td>
                <td><?= okv_e(date('j M Y', strtotime((string) $match['created_at']))) ?></td>
                <td><?= okv_e(date('D j M', strtotime((string) $match['preferred_delivery_date']))) ?></td>
                <td><?= okv_e(Money::format((int) $match['order_total_subunit'])) ?></td>
                <td><?= okv_e(Money::format((int) $match['balance_due_subunit'])) ?></td>
                <td>
                  <span class="okv-badge <?= okv_e(okv_payment_badge((string) $match['payment_status'] === 'paid' ? 'success' : 'pending')) ?>">
                    <?= okv_e(str_replace('_', ' ', (string) $match['payment_status'])) ?>
                  </span>
                </td>
                <td>
                  <?php if ($isOpen): ?>
                    <span class="text-sm text-ink-60">Open below</span>
                  <?php else: ?>
                    <a class="okv-btn-outline-sm inline-flex min-h-[44px] items-center"
                       href="?q=<?= rawurlencode($search) ?>&amp;order_id=<?= (int) $match['id'] ?>#record-heading">Record</a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
        <?php
    }
}

<?php
/**
 * includes/components/admin/kitchen_run_queue.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The Kitchen Runs queue table on /admin/kitchen_runs.php: the
 * waiting lists, who sent them, how they came in and where the quote stands,
 * or the plain sentence when the queue (or the search) is empty.
 *
 * One renderer, two callers. The screen prints it on a plain load and
 * api/v1/kitchen_runs.php (action browse) prints the same markup into the live
 * search response, so typing and reloading the same URL agree exactly.
 *
 *   okv_admin_kitchen_run_queue($runs, $customer, $filter, $openId, $queryWith);
 *
 * $queryWith maps a set of query changes to a screen query string, the same
 * closure shape the page builds, so the row links keep both filters.
 * -----------------------------------------------------------------------------
 */
if (!defined('OKV_BOOTSTRAPPED')) {
    http_response_code(500);
    exit;
}

if (!function_exists('okv_admin_kitchen_run_queue')) {
    /**
     * @param array<int, array<string, mixed>> $runs
     * @param callable(array<string, mixed>): string $queryWith
     */
    function okv_admin_kitchen_run_queue(array $runs, string $customer, string $filter, int $openId, callable $queryWith): void
    {
        if (!$runs) {
            ?>
      <p class="mt-4 rounded-lg border border-mist bg-canvas px-4 py-6 text-center text-sm text-ink-60">
        <?php if ($customer !== ''): ?>
          Nothing matches "<?= okv_e($customer) ?>"<?= $filter === '' ? '' : ' with that status' ?>.
        <?php else: ?>
          Nothing here<?= $filter === '' ? ' yet' : ' with that status' ?>.
        <?php endif; ?>
      </p>
            <?php
            return;
        }
        ?>
      <div class="mt-4 overflow-x-auto">
        <table class="w-full min-w-[46rem] text-left text-sm">
          <thead class="border-b border-mist text-ink-60">
            <tr>
              <th scope="col" class="py-2 pr-3 font-medium">Request</th>
              <th scope="col" class="py-2 pr-3 font-medium">Customer</th>
              <th scope="col" class="py-2 pr-3 font-medium">How it came in</th>
              <th scope="col" class="py-2 pr-3 font-medium">Items</th>
              <th scope="col" class="py-2 pr-3 font-medium">Status</th>
              <th scope="col" class="py-2 font-medium text-right">Quote</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($runs as $run): ?>
              <tr class="border-b border-mist/60 <?= $openId === (int) $run['id'] ? 'bg-foliage-tint' : '' ?>">
                <td class="py-2 pr-3">
                  <a class="font-mono font-semibold text-forest underline-offset-2 hover:underline" href="<?= okv_e($queryWith(['request' => (int) $run['id']])) ?>">
                    <?= okv_e($run['request_number']) ?>
                  </a>
                  <span class="block text-ink-60"><?= okv_e(date('j M', strtotime((string) $run['created_at']))) ?></span>
                </td>
                <td class="py-2 pr-3">
                  <?= okv_e(trim((string) $run['customer_name']) ?: (string) $run['contact_name']) ?>
                  <span class="block text-ink-60"><?= okv_e((string) $run['customer_email']) ?></span>
                </td>
                <td class="py-2 pr-3">
                  <?= okv_e(KitchenRuns::modeLabel((string) $run['input_mode'])) ?>
                  <?php if (!empty($run['is_open_budget'])): ?>
                    <span class="block text-ink-60">Open budget</span>
                  <?php endif; ?>
                </td>
                <td class="py-2 pr-3"><?= (int) $run['line_count'] ?></td>
                <td class="py-2 pr-3"><?= okv_e($run['status_label']) ?></td>
                <td class="py-2 text-right font-mono">
                  <?= $run['quoted_total_subunit'] === null ? '<span class="text-ink-60">Not priced</span>' : okv_e(Money::format((int) $run['quoted_total_subunit'])) ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
        <?php
    }
}

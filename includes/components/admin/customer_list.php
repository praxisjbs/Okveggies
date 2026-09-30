<?php
/**
 * includes/components/admin/customer_list.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The left-hand column of /admin/customers.php: the matching
 * households and businesses, or the plain sentence when nobody matches, plus
 * the page switcher under them.
 *
 * One renderer, two callers. The screen prints it on a plain load and
 * api/v1/customers.php (action browse) prints the same markup into the live
 * search response, so typing and reloading the same URL agree exactly.
 *
 *   okv_admin_customer_list($listing['customers'], $selectedId, $urlFor,
 *                           $listing['page'], $listing['lastPage']);
 *
 * $urlFor maps a set of query changes to a screen URL, the same closure shape
 * the page builds, so the row links and the page links keep every filter that
 * is in play.
 * -----------------------------------------------------------------------------
 */
if (!defined('OKV_BOOTSTRAPPED')) {
    http_response_code(500);
    exit;
}

require_once __DIR__ . '/../pagination.php';

if (!function_exists('okv_admin_customer_list')) {
    /**
     * @param array<int, array<string, mixed>> $customers
     * @param callable(array<string, mixed>): string $urlFor
     */
    function okv_admin_customer_list(array $customers, int $selectedId, callable $urlFor, int $page, int $lastPage): void
    {
        if (!$customers) {
            ?>
      <p class="p-5 text-sm text-ink-60">No customer matches that search. Clear it to see everyone.</p>
            <?php
            return;
        }
        ?>
      <ul class="divide-y divide-mist">
        <?php foreach ($customers as $row): ?>
          <?php $isSelected = (int) $row['id'] === $selectedId; ?>
          <li>
            <a href="<?= okv_e($urlFor(['customer' => (int) $row['id']])) ?>"
               class="block min-h-[44px] px-4 py-3 hover:bg-forest-tint <?= $isSelected ? 'bg-forest-tint' : '' ?>"
               <?= $isSelected ? 'aria-current="page"' : '' ?>>
              <span class="flex flex-wrap items-center justify-between gap-2">
                <strong class="text-ink"><?= okv_e(Customers::displayName($row)) ?></strong>
                <span class="okv-badge okv-badge-neutral"><?= okv_e(Customers::typeLabel((string) $row['user_type'])) ?></span>
              </span>
              <span class="mt-1 block text-sm text-ink-60"><?= okv_e((string) $row['email']) ?></span>
              <span class="mt-1 flex flex-wrap justify-between gap-2 text-xs text-ink-60">
                <span><?= (int) $row['order_count'] ?> order<?= (int) $row['order_count'] === 1 ? '' : 's' ?></span>
                <span><?= $row['last_order_at'] ? 'Last order ' . okv_e(date('j M Y', strtotime((string) $row['last_order_at']))) : 'No orders yet' ?></span>
              </span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php okv_pagination($page, $lastPage, static fn(int $n): string => $urlFor(['page' => $n, 'customer' => null]), 'Customer pages'); ?>
        <?php
    }
}

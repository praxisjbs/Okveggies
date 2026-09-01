<?php
/**
 * includes/components/admin/placeholder.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The panel a scaffolded admin screen shows until its milestone is
 * built. It sits inside the real admin shell, so the sidebar, the breadcrumbs
 * and the way back are all still there: a screen that is not built yet is never
 * a dead end (PRD Section 2.3).
 *
 * It renders nothing but copy and links. The page above it still runs its own
 * Rbac::requirePermission check, exactly as a built screen does.
 * -----------------------------------------------------------------------------
 */

if (!function_exists('okv_admin_placeholder')) {
    /**
     * @param string $milestone  The milestone that builds it, for example 'M6'.
     * @param string $prd        The PRD section holding the plan, for example 'Section 14'.
     * @param string $summary    One plain line on what will live here.
     * @param array  $whatsNext  Links that are live today: [['label' => ..., 'href' => ...], ...]
     */
    function okv_admin_placeholder(string $milestone, string $prd, string $summary, array $whatsNext = []): void
    {
        ?>
        <div class="okv-panel max-w-3xl">
          <div class="okv-panel-body">
            <p class="okv-eyebrow">Not built yet</p>
            <p class="mt-3 text-ink">
              <?= okv_e($summary) ?>
            </p>
            <p class="mt-3 text-sm text-ink-60">
              <?php if ($milestone !== ''): ?>
                This screen is scaffolded and waiting on milestone <?= okv_e($milestone) ?>.
              <?php else: ?>
                This screen is scaffolded and not scheduled into a milestone yet.
              <?php endif; ?>
              The plan for it is in <span class="font-mono text-xs">docs/PRD.md</span> <?= okv_e($prd) ?>.
            </p>

            <?php if ($whatsNext): ?>
              <p class="okv-eyebrow mt-6">What you can do today</p>
              <ul class="mt-2 space-y-1">
                <?php foreach ($whatsNext as $link): ?>
                  <li>
                    <a href="<?= okv_e($link['href']) ?>" class="inline-flex min-h-[44px] items-center gap-2 text-sm font-medium text-forest underline underline-offset-4">
                      <?= okv_e($link['label']) ?>
                      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h13M13 6l6 6-6 6"/></svg>
                    </a>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        </div>
        <?php
    }
}

<?php
/**
 * includes/components/pro/nav.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Pro Portal navigation, rendered from includes/config/nav.php so
 * the portal and any other surface can never disagree about what a business
 * customer can reach. The Pro Portal is the utilitarian, denser surface of the
 * one brand (bible 1.9): same seal, same promise, more information per screen.
 *
 * Navigation only. Every Pro screen still decides its own access on the server
 * when its milestone (M8) is built.
 * -----------------------------------------------------------------------------
 */

if (!function_exists('okv_pro_nav')) {
    /** The horizontal Pro nav. $active is the href of the current screen. */
    function okv_pro_nav(string $active = ''): void
    {
        // Read into this function's own scope. config/nav.php only declares
        // arrays, so including it here is cheap and cannot depend on whether
        // some other file already pulled it in somewhere else.
        $OKV_PRO_NAV = [];
        require __DIR__ . '/../../config/nav.php';
        ?>
        <nav class="border-b border-mist bg-white" aria-label="Pro Portal">
          <div class="okv-container flex gap-1 overflow-x-auto">
            <?php foreach ($OKV_PRO_NAV as $item):
                $isActive = $item['href'] === $active;
            ?>
              <a href="<?= okv_e($item['href']) ?>"
                 class="inline-flex min-h-[44px] shrink-0 items-center border-b-2 px-3 text-sm font-medium transition-colors duration-botanical <?= $isActive ? 'border-forest text-forest' : 'border-transparent text-ink-60 hover:text-forest' ?>"
                 <?= $isActive ? 'aria-current="page"' : '' ?>>
                <?= okv_e($item['label']) ?>
              </a>
            <?php endforeach; ?>
          </div>
        </nav>
        <?php
    }
}

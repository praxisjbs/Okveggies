<?php
/**
 * includes/components/shop/empty_state.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The empty, error and success block used across the shop: an 80px
 * line illustration, a four-word heading, one short line, and two forest
 * buttons with icons. Never a paragraph wall, never a dead end.
 */
require_once __DIR__ . '/icons.php';

if (!function_exists('okv_empty_state')) {
    /**
     * @param list<array{href:string,label:string,style?:string,icon?:string}> $actions
     * @param array{heading_tag?:string,role?:string,id?:string,class?:string} $opts
     */
    function okv_empty_state(string $icon, string $heading, string $line, array $actions, array $opts = []): void
    {
        $tag = in_array(($opts['heading_tag'] ?? 'h2'), ['h1', 'h2', 'h3', 'p'], true) ? (string) $opts['heading_tag'] : 'h2';
        $role = (string) ($opts['role'] ?? 'status');
        $id = (string) ($opts['id'] ?? '');
        $class = (string) ($opts['class'] ?? '');
        $headingId = $id !== '' ? $id : ('okv-empty-' . substr(sha1($heading), 0, 8));
        ?>
        <section class="okv-empty <?= okv_e($class) ?>" role="<?= okv_e($role) ?>" aria-labelledby="<?= okv_e($headingId) ?>">
          <span class="okv-empty-art"><?php okv_icon($icon, 'h-20 w-20'); ?></span>
          <<?= $tag ?> id="<?= okv_e($headingId) ?>" class="mt-5 font-editorial text-okv-h5 text-ink"><?= okv_e($heading) ?></<?= $tag ?>>
          <p class="mx-auto mt-2 max-w-md text-ink-60"><?= okv_e($line) ?></p>
          <?php if ($actions): ?>
            <div class="mt-6 flex flex-col justify-center gap-3 sm:flex-row">
              <?php foreach ($actions as $action):
                  $style = (string) ($action['style'] ?? 'primary');
                  $btn = $style === 'outline' ? 'okv-btn-outline' : 'okv-btn';
                  $actionIcon = (string) ($action['icon'] ?? '');
              ?>
                <a href="<?= okv_e((string) $action['href']) ?>" class="<?= $btn ?> w-full justify-center rounded-xl sm:w-auto">
                  <?php if ($actionIcon !== ''): ?><?php okv_icon($actionIcon, 'h-4 w-4'); ?><?php endif; ?>
                  <?= okv_e((string) $action['label']) ?>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>
        <?php
    }
}

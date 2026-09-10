<?php
/** Shared, server-filtered command palette for every admin screen. */
if (!defined('OKV_BOOTSTRAPPED')) {
    exit;
}
require_once __DIR__ . '/../../config/nav.php';
$okv_command_items = okv_admin_nav_commands(
    $OKV_ADMIN_NAV,
    static fn(string $permission): bool => Rbac::can($permission)
);
?>
<div id="okv-command-palette" class="okv-command-backdrop" data-command-palette hidden>
  <section class="okv-command-panel" role="dialog" aria-modal="true" tabindex="-1"
           aria-labelledby="okv-command-title" aria-describedby="okv-command-description">
    <div class="flex items-start justify-between gap-4 border-b border-mist px-4 py-3 md:px-5">
      <div>
        <h2 id="okv-command-title" class="okv-panel-title">Go to a page</h2>
        <p id="okv-command-description" class="mt-1 text-xs text-ink-60">Search the admin pages available to your role.</p>
      </div>
      <button type="button" class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-md text-ink-60 hover:bg-forest-tint hover:text-forest"
              data-command-close aria-label="Close page search">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>
      </button>
    </div>
    <div class="p-4 md:p-5">
      <label for="okv-command-search" class="okv-label">Find a page</label>
      <div class="relative">
        <svg class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-ink-40" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m16 16 4 4"/></svg>
        <input id="okv-command-search" type="search" class="okv-input pl-12" autocomplete="off"
               role="combobox" aria-autocomplete="list" aria-expanded="true"
               aria-controls="okv-command-results" placeholder="Try orders, pay or manifest"
               data-command-search>
      </div>
      <p class="sr-only" aria-live="polite" data-command-status></p>
      <div id="okv-command-results" class="mt-3 max-h-80 overflow-y-auto" role="listbox" aria-label="Available admin pages">
        <?php foreach ($okv_command_items as $okv_command_index => $okv_command): ?>
          <a id="okv-command-option-<?= okv_e((string) $okv_command_index) ?>"
             href="<?= okv_e($okv_command['href']) ?>" role="option" aria-selected="false" tabindex="-1"
             class="okv-command-option"
             data-command-item
             data-command-search-text="<?= okv_e(implode(' ', array_merge(
                 [$okv_command['label'], $okv_command['heading']],
                 $okv_command['keywords']
             ))) ?>">
            <span class="font-medium text-ink" data-command-label><?= okv_e($okv_command['label']) ?></span>
            <span class="text-xs text-ink-40"><?= okv_e($okv_command['heading']) ?></span>
          </a>
        <?php endforeach; ?>
        <p class="px-3 py-6 text-center text-sm text-ink-60" data-command-empty hidden>No permitted page matches that search.</p>
      </div>
      <p class="mt-3 hidden text-xs text-ink-40 md:block">Use the arrow keys to move, Enter to open and Escape to close.</p>
    </div>
  </section>
</div>
<?php unset($okv_command_items, $okv_command_index, $okv_command); ?>

<?php
/**
 * admin/permissions.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The Permissions screen. The Owner picks a role on the left and
 * ticks what that role may do on the right, grouped by module, with the quick
 * presets a person actually reaches for (everything in this module, nothing in
 * this module, just the view keys, and so on).
 *
 * Who may do what here:
 *   - the Owner changes assignments, and is the only one who can;
 *   - anyone with rbac.roles.view can read the screen and the history;
 *   - the Owner role itself is shown with every box ticked and locked. It holds
 *     everything implicitly through Rbac::hasPermission('*'), so there is
 *     nothing to store and no way to lock the Owner out of this screen;
 *   - Owner and Manager are protected system roles (PRD Section 17.1). Their
 *     meaning is fixed by the product, so this screen shows them read-only and
 *     says so rather than offering a control that would be refused.
 *
 * The 15 modules and their keys come from includes/config/permissions.php, which
 * is the same list the code checks against, cross-read with the `permissions`
 * table that the guard actually grants from. Where the two disagree the screen
 * says so, because a tick that grants nothing is worse than no tick at all.
 *
 * Screen order follows the admin sidebar: what the business sells, then the
 * money, then delivery and care, then setup.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/bootstrap.php';
Rbac::requirePermission('rbac.roles.view');

$canAssign = PermissionMatrix::canAssign();

// --- The role in view --------------------------------------------------------

$roles  = PermissionMatrix::roles();
$counts = PermissionMatrix::grantCounts();

$selectedId = (int) okv_input('role', 0);
$role = null;
foreach ($roles as $candidate) {
    if ((int) $candidate['id'] === $selectedId) {
        $role = $candidate;
        break;
    }
}
if ($role === null && $roles) {
    $role = $roles[0];
}

$roleId      = $role ? (int) $role['id'] : 0;
$isOwnerRole = PermissionMatrix::isOwnerRole($role);
$isSystem    = PermissionMatrix::isSystemRole($role);
$isActive    = $role && (string) $role['status'] === 'active';
$editable    = $canAssign && $role !== null && !$isSystem;

// --- The catalogue and what this role already carries ------------------------

$catalogue = PermissionMatrix::catalogue();
$grantable = PermissionMatrix::grantableKeys();
$granted   = $roleId > 0 ? array_flip(PermissionMatrix::grants($roleId)) : [];
$versions  = $roleId > 0 ? PermissionMatrix::versions($roleId, 12) : [];
$drift     = PermissionMatrix::drift();
$totalKeys = count($grantable);

$grantedCount = 0;
foreach ($grantable as $key) {
    if ($isOwnerRole || isset($granted[$key])) {
        $grantedCount++;
    }
}

// Per module: how many of the grantable keys are on. A key that exists in the
// code but not yet in the database cannot be granted, so it is left out of both
// halves of the count rather than making the total unreachable.
$moduleStats = [];
foreach ($catalogue as $module => $definition) {
    $on = 0;
    $all = 0;
    foreach ($definition['permissions'] as $permission) {
        if (!$permission['grantable']) {
            continue;
        }
        $all++;
        if ($isOwnerRole || isset($granted[$permission['key']])) {
            $on++;
        }
    }
    $moduleStats[$module] = ['on' => $on, 'all' => $all];
}

// A module opens when it is partly granted, because that is the state worth
// looking at, and the first module always opens so the page is never just a
// column of closed doors.
$firstModule = array_key_first($catalogue);

$historyCount = $roleId > 0 ? PermissionMatrix::versionCount($roleId) : 0;

$okv_admin_title  = 'Permissions';
$okv_admin_note   = 'Choose what each role can do. Only the Owner can change these, and every change is kept so it can be put back.';
$okv_admin_crumbs = [['label' => 'Users', 'href' => '/admin/users.php']];
require __DIR__ . '/../includes/components/admin/header.php';
?>
  <div class="grid gap-6 lg:grid-cols-4">

    <!-- The roles ------------------------------------------------------------ -->
    <section class="lg:col-span-1 space-y-3">
      <h2 class="okv-eyebrow">Roles<?= $roles ? ', ' . count($roles) : '' ?></h2>

      <?php if (!$roles): ?>
        <div class="okv-panel okv-panel-body">
          <p class="text-sm text-ink-60">No roles yet. Create the first one on <a href="/admin/users.php" class="text-forest underline underline-offset-2">Users</a>.</p>
        </div>
      <?php else: ?>
        <nav class="okv-panel overflow-hidden" aria-label="Roles">
          <?php foreach ($roles as $item):
              $id       = (int) $item['id'];
              $selected = $id === $roleId;
              $itemOwner = PermissionMatrix::isOwnerRole($item);
              $itemCount = $itemOwner ? $totalKeys : ($counts[$id] ?? 0);
          ?>
            <a href="/admin/permissions.php?role=<?= $id ?>"
               class="flex items-start justify-between gap-3 px-4 py-3 min-h-[44px] border-b border-mist last:border-b-0 hover:bg-forest-tint <?= $selected ? 'bg-forest-tint' : '' ?>"
               <?= $selected ? 'aria-current="true"' : '' ?>>
              <span class="min-w-0">
                <span class="block text-sm font-semibold text-ink"><?= okv_e(okv_role_label((string) $item['name'])) ?></span>
                <span class="block text-xs text-ink-60 truncate">
                  <?php if ($itemOwner): ?>Full access, always
                  <?php elseif (PermissionMatrix::isSystemRole($item)): ?>Protected system role
                  <?php elseif ((string) $item['status'] !== 'active'): ?>Switched off
                  <?php else: ?><?= (int) $itemCount ?> of <?= $totalKeys ?> granted<?php endif; ?>
                </span>
              </span>
              <span class="okv-badge <?= $selected ? 'okv-badge-info' : 'okv-badge-neutral' ?> shrink-0"><?= $itemOwner ? 'All' : (int) $itemCount ?></span>
            </a>
          <?php endforeach; ?>
        </nav>
        <p class="text-xs text-ink-40">Add a role, or give one to a colleague, on <a href="/admin/users.php" class="text-forest underline underline-offset-2">Users</a>.</p>
      <?php endif; ?>
    </section>

    <!-- The assignment ------------------------------------------------------- -->
    <section class="lg:col-span-3 space-y-4">
      <?php if (!$role): ?>
        <div class="okv-panel okv-panel-body"><p class="text-ink-60">There is no role to show yet.</p></div>
      <?php else: ?>

        <?php if ($drift['missing_in_db'] || $drift['missing_in_code']): ?>
          <div class="okv-note-bad" role="status">
            <p class="font-medium">The permission list and the database do not agree.</p>
            <?php if ($drift['missing_in_db']): ?>
              <p class="mt-1">The code checks these, but no role can hold them yet, so they are shown unticked and switched off: <span class="font-mono"><?= okv_e(implode(', ', $drift['missing_in_db'])) ?></span>.</p>
            <?php endif; ?>
            <?php if ($drift['missing_in_code']): ?>
              <p class="mt-1">These are held in the database but missing from the catalogue the code ships, so they are drawn from the database row alone: <span class="font-mono"><?= okv_e(implode(', ', $drift['missing_in_code'])) ?></span>.</p>
            <?php endif; ?>
            <p class="mt-1">A migration adds a key the code checks; the other kind is a line missing from <span class="font-mono">includes/config/permissions.php</span>.</p>
          </div>
        <?php endif; ?>

        <!-- The role in view ------------------------------------------------- -->
        <div class="okv-panel">
          <div class="okv-panel-head">
            <div class="min-w-0">
              <h2 class="okv-panel-title"><?= okv_e(okv_role_label((string) $role['name'])) ?></h2>
              <p class="text-sm text-ink-60 mt-0.5">
                <?= okv_e(trim((string) ($role['description'] ?? '')) !== '' ? (string) $role['description'] : 'No description yet.') ?>
              </p>
              <p class="text-xs text-ink-40 mt-1 font-mono"><?= okv_e((string) ($role['slug'] ?? '')) ?></p>
            </div>
            <div class="text-right shrink-0">
              <span class="okv-badge <?= $isActive ? 'okv-badge-available' : 'okv-badge-out' ?>"><?= $isActive ? 'Active' : 'Switched off' ?></span>
              <p class="text-xs text-ink-60 mt-2">
                <?php if ($isOwnerRole): ?><strong class="font-semibold">All permissions, always.</strong>
                <?php elseif ($isSystem): ?><strong class="font-semibold">Protected system role.</strong>
                <?php else: ?><strong class="font-semibold" data-perm-tally><?= $grantedCount ?></strong> of <?= $totalKeys ?> granted
                <?php endif; ?>
              </p>
            </div>
          </div>

          <?php if ($isOwnerRole): ?>
            <p class="okv-panel-body text-sm text-ink-60 border-t border-mist">
              The Owner holds every permission there is, including ones added later, so every box below
              is ticked and cannot be changed. This is what stops the Owner being locked out of the
              screen that hands out permissions.
            </p>
          <?php elseif ($isSystem): ?>
            <p class="okv-panel-body text-sm text-ink-60 border-t border-mist">
              <?= okv_e(okv_role_label((string) $role['name'])) ?> is a system role, so its meaning is fixed when it ships
              and it is not changed here. What it carries is shown below, read only. To give someone a
              different mix, create a role of your own on
              <a href="/admin/users.php" class="text-forest underline underline-offset-2">Users</a>.
            </p>
          <?php elseif (!$canAssign): ?>
            <p class="okv-panel-body text-sm text-ink-60 border-t border-mist">
              You can see what this role carries below. Only the Owner can change it.
            </p>
          <?php endif; ?>
        </div>

        <?php
        // The grid is drawn for every role, not only the ones the Owner can
        // change. A read-only grid is the answer to "what does this role
        // actually carry?", which is the whole question for the Owner role
        // (everything, always) and the only way to see the Manager's set at all.
        // What is withheld when the role cannot be edited is the means to change
        // it: no Select all, no Clear, no quick adds, no save bar, and every box
        // disabled so nothing can be posted even by hand.
        ?>
        <form id="okv-perm-form" action="/api/v1/permissions.php" method="POST" data-perm-form<?= $editable ? '' : ' data-perm-readonly' ?>>
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="role_id" value="<?= $roleId ?>">

          <?php if ($editable): ?>
          <div data-okv-error role="alert" aria-live="polite" class="okv-note-bad mb-4" hidden></div>
          <?php endif; ?>

          <!-- Finding one key in a list of eighty --------------------------- -->
          <div class="okv-panel okv-panel-body mb-4">
            <div class="flex flex-wrap items-end gap-3">
              <div class="flex-1 min-w-[14rem]">
                <label class="okv-label" for="perm-search">Find a permission</label>
                <input id="perm-search" type="search" class="okv-input-sm w-full" autocomplete="off"
                       placeholder="orders.cancel, refund, kitchen runs" data-perm-search>
              </div>
              <button type="button" class="okv-btn-outline-sm" data-perm-expand="all">Open all</button>
              <button type="button" class="okv-btn-outline-sm" data-perm-expand="none">Close all</button>
            </div>
            <p class="text-sm text-ink-60 mt-3" data-perm-search-note hidden aria-live="polite"></p>
          </div>

          <!-- One block per module ------------------------------------------ -->
          <?php foreach ($catalogue as $module => $definition):
              $stats = $moduleStats[$module] ?? ['on' => 0, 'all' => 0];
              $partial = $stats['on'] > 0 && $stats['on'] < $stats['all'];
              $open = ($module === $firstModule) || $partial;
          ?>
            <details class="rounded-md border border-mist bg-white mb-3" data-perm-module="<?= okv_e($module) ?>" <?= $open ? 'open' : '' ?>>
              <summary class="flex min-h-[44px] cursor-pointer flex-wrap items-center justify-between gap-3 px-4 py-3">
                <span class="font-semibold text-ink"><?= okv_e($definition['label']) ?></span>
                <span class="okv-badge okv-badge-neutral shrink-0" data-perm-count><?= (int) $stats['on'] ?> of <?= (int) $stats['all'] ?></span>
              </summary>

              <div class="border-t border-mist px-4 py-4">
                <?php if ($editable): ?>
                <div class="flex flex-wrap items-center gap-2 mb-4">
                  <button type="button" class="okv-btn-outline-sm" data-perm-all="1">Select all</button>
                  <button type="button" class="okv-btn-outline-sm" data-perm-all="0">Clear</button>
                  <?php if ($definition['presets']): ?>
                    <span class="text-xs text-ink-40 ml-1">Quick add:</span>
                    <?php foreach ($definition['presets'] as $preset): ?>
                      <button type="button" class="okv-btn-outline-sm"
                              data-perm-preset="<?= okv_e(implode(',', $preset['keys'])) ?>"
                              title="Ticks every <?= okv_e(strtolower($preset['label'])) ?> permission in this module">+ <?= okv_e($preset['label']) ?></button>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="grid gap-1 sm:grid-cols-2">
                  <?php foreach ($definition['permissions'] as $permission):
                      $key     = (string) $permission['key'];
                      $checked = $isOwnerRole || isset($granted[$key]);
                      // Two different reasons a box will not move, so the reader
                      // is told which one it is: a key no migration has inserted
                      // cannot be granted to anybody, and a role the Owner may
                      // not change is shown rather than offered.
                      $ungrantable = !$permission['grantable'];
                      $locked      = $ungrantable || !$editable;
                  ?>
                    <label class="flex gap-2.5 items-start rounded-md px-2 py-2 min-h-[44px] <?= $editable ? 'hover:bg-forest-tint' : '' ?>"
                           data-perm-row data-perm-text="<?= okv_e(strtolower($key . ' ' . $permission['description'])) ?>">
                      <input type="checkbox" name="permissions[]" value="<?= okv_e($key) ?>"
                             class="mt-0.5 h-5 w-5 shrink-0 rounded border-mist text-forest"
                             data-perm-key="<?= okv_e($key) ?>"
                             data-original="<?= $checked ? '1' : '0' ?>"
                             <?= $checked ? 'checked' : '' ?>
                             <?= $locked ? 'disabled' : '' ?>>
                      <span class="min-w-0">
                        <span class="block text-sm font-mono text-ink break-all"><?= okv_e($key) ?></span>
                        <span class="block text-xs text-ink-60"><?= okv_e($permission['description']) ?></span>
                        <?php if ($ungrantable): ?>
                          <span class="block text-xs text-tomato mt-0.5">Not in the database yet, so it cannot be granted.</span>
                        <?php endif; ?>
                      </span>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>
            </details>
          <?php endforeach; ?>

          <?php if ($editable): ?>
          <!-- The save bar --------------------------------------------------- -->
          <div class="sticky bottom-0 z-10 mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-mist bg-white px-4 py-3 shadow-okv-1">
            <p class="text-sm text-ink-60">
              <strong class="font-semibold text-ink" data-perm-tally><?= $grantedCount ?></strong>
              of <?= $totalKeys ?> granted for <?= okv_e(okv_role_label((string) $role['name'])) ?>.
              <span class="font-medium text-gold-ink" data-perm-dirty hidden>Unsaved changes.</span>
            </p>
            <div class="flex flex-wrap items-center gap-2">
              <button type="button" class="okv-btn-outline-sm" data-perm-reset hidden>Undo changes</button>
              <button type="submit" class="okv-btn-sm" data-perm-save disabled>Save permissions</button>
            </div>
          </div>
          <?php endif; ?>
        </form>

        <!-- Change history -------------------------------------------------- -->
        <div class="okv-panel mt-4">
          <div class="okv-panel-head">
            <h2 class="okv-panel-title">Change history</h2>
            <p class="text-xs text-ink-40">Newest first<?= $historyCount > count($versions) ? ', last ' . count($versions) . ' of ' . $historyCount : '' ?></p>
          </div>
          <div class="okv-panel-body">
            <?php if (!$versions): ?>
              <p class="text-sm text-ink-60">
                Nothing has been changed for this role yet<?= $isOwnerRole ? '' : ', so the ticks you see are the ones it shipped with' ?>.
              </p>
            <?php else: ?>
              <ol class="space-y-3">
                <?php foreach ($versions as $version):
                    $when   = strtotime((string) $version['created_at']);
                    $actor  = trim((string) ($version['actor_name'] ?? ''));
                    $change = ['added' => $version['added'], 'removed' => $version['removed']];
                ?>
                  <li class="flex flex-wrap items-start justify-between gap-3 border-b border-mist pb-3 last:border-b-0 last:pb-0">
                    <div class="min-w-0">
                      <p class="text-sm text-ink">
                        <time datetime="<?= okv_e(date('c', $when ?: time())) ?>"><?= okv_e($when ? date('j M Y, H:i', $when) : (string) $version['created_at']) ?></time>
                        <?php if ($actor !== ''): ?> by <?= okv_e($actor) ?><?php endif; ?>
                        <?php if ((string) $version['action'] === 'rbac.permission.restore'): ?>
                          <span class="okv-badge okv-badge-warn ml-1">Put back</span>
                        <?php endif; ?>
                      </p>
                      <p class="text-xs text-ink-60 mt-0.5">
                        <?= (int) $version['permission_count'] ?> permissions afterwards. <?= okv_e(PermissionMatrix::summarise($change)) ?>.
                      </p>
                      <?php if ($version['added']): ?>
                        <p class="text-xs text-forest mt-1 break-all">On: <span class="font-mono"><?= okv_e(implode(', ', $version['added'])) ?></span></p>
                      <?php endif; ?>
                      <?php if ($version['removed']): ?>
                        <p class="text-xs text-tomato mt-1 break-all">Off: <span class="font-mono"><?= okv_e(implode(', ', $version['removed'])) ?></span></p>
                      <?php endif; ?>
                    </div>
                    <?php if ($editable): ?>
                      <button type="button" class="okv-btn-outline-sm shrink-0"
                              data-perm-restore="<?= (int) $version['id'] ?>"
                              data-perm-restore-label="<?= okv_e($when ? date('j M Y, H:i', $when) : (string) $version['created_at']) ?>">
                        Put back
                      </button>
                    <?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ol>
            <?php endif; ?>
          </div>
        </div>

      <?php endif; ?>
    </section>
  </div>
<?php
$okv_admin_script = '/assets/js/admin-permissions.js';
require __DIR__ . '/../includes/components/admin/footer.php';

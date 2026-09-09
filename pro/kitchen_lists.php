<?php
/** Saved Kitchen Lists and the existing M7 Kitchen Run history. */
require_once __DIR__ . '/../includes/bootstrap.php';
require_business_customer();

$userId = (int) Customer::id();
$lists = KitchenLists::allForCustomer($userId);
$runs = KitchenRuns::allForCustomer($userId);
$listProducts = KitchenLists::catalogueOptions();
$listUnits = KitchenLists::unitOptions();
$selectedId = (int) okv_input('list', 0);
$selectedList = $selectedId > 0 ? KitchenLists::findForCustomer($selectedId, $userId) : null;

$notice = null;
$errorCode = trim((string) okv_input('error', ''));
if ($errorCode !== '') {
    $notice = ['bad' => true, 'text' => KitchenLists::message($errorCode)];
} elseif (okv_input('saved', '') !== '') {
    $notice = ['bad' => false, 'text' => 'Saved. This list is ready to use again.'];
} elseif (okv_input('deleted', '') !== '') {
    $notice = ['bad' => false, 'text' => 'The saved list has been deleted. Your Kitchen Run history is unchanged.'];
} elseif ($selectedId > 0 && $selectedList === null) {
    $notice = ['bad' => true, 'text' => KitchenLists::message('not_found')];
}

$waiting = 0;
foreach ($runs as $run) {
    if (in_array((string) $run['status'], ['submitted', 'quoted'], true)) {
        $waiting++;
    }
}

$okv_pro_title = 'My Kitchen Lists';
$okv_pro_note = 'Save the lists your kitchen repeats, then review one before sending it as a Kitchen Run.';
$okv_pro_active = '/pro/kitchen_lists.php';
require __DIR__ . '/../includes/components/pro/header.php';
?>

<div class="space-y-6">
  <?php if ($notice): ?>
    <p class="rounded-md border px-4 py-3 text-sm <?= $notice['bad'] ? 'border-tomato bg-tomato-tint text-tomato' : 'border-foliage bg-foliage-tint text-forest' ?>" role="status">
      <?= okv_e($notice['text']) ?>
    </p>
  <?php endif; ?>

  <section class="okv-panel" aria-labelledby="saved-lists-heading">
    <div class="okv-panel-head">
      <div>
        <p class="okv-eyebrow">Reusable lists</p>
        <h2 id="saved-lists-heading" class="okv-panel-title mt-1">Saved Kitchen Lists</h2>
      </div>
      <a href="#new-kitchen-list" class="okv-btn px-5">Create a saved list</a>
    </div>
    <?php if (!$lists): ?>
      <div class="okv-panel-body">
        <p class="font-medium text-ink">No saved lists yet.</p>
        <p class="mt-2 text-sm text-ink-60">Create one below, or save an earlier Kitchen Run from your history.</p>
      </div>
    <?php else: ?>
      <div class="grid gap-3 p-4 md:grid-cols-2 md:p-5 lg:grid-cols-3">
        <?php foreach ($lists as $savedList): ?>
          <article class="rounded-lg border border-mist p-4 <?= (int) $savedList['id'] === $selectedId ? 'bg-forest-tint' : 'bg-white' ?>">
            <h3 class="font-semibold text-ink"><?= okv_e((string) $savedList['name']) ?></h3>
            <p class="mt-1 text-sm text-ink-60"><?= (int) $savedList['item_count'] ?> <?= (int) $savedList['item_count'] === 1 ? 'item' : 'items' ?>. Updated <?= okv_e(date('j M Y', strtotime((string) $savedList['updated_at']))) ?>.</p>
            <?php if ($savedList['note']): ?><p class="mt-2 text-sm text-ink"><?= okv_e((string) $savedList['note']) ?></p><?php endif; ?>
            <div class="mt-4 flex flex-wrap gap-2">
              <form action="/api/v1/kitchen_lists.php" method="post">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="start_run">
                <input type="hidden" name="list_id" value="<?= (int) $savedList['id'] ?>">
                <button class="okv-btn-sm min-h-[44px]" type="submit">Start a Kitchen Run</button>
              </form>
              <a href="?list=<?= (int) $savedList['id'] ?>#edit-kitchen-list" class="okv-btn-text">Edit</a>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <?php if ($selectedList): ?>
    <section id="edit-kitchen-list" class="okv-panel scroll-mt-6" aria-labelledby="edit-list-heading">
      <div class="okv-panel-head">
        <div>
          <p class="okv-eyebrow">Edit saved list</p>
          <h2 id="edit-list-heading" class="okv-panel-title mt-1"><?= okv_e((string) $selectedList['name']) ?></h2>
        </div>
      </div>
      <form action="/api/v1/kitchen_lists.php" method="post" class="okv-panel-body" data-kl-form>
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="list_id" value="<?= (int) $selectedList['id'] ?>">
        <div class="grid gap-4 sm:grid-cols-2">
          <div><label class="okv-label" for="edit-list-name">List name</label><input class="okv-input" id="edit-list-name" name="name" maxlength="150" required value="<?= okv_e((string) $selectedList['name']) ?>"></div>
          <div><label class="okv-label" for="edit-list-note">List note, optional</label><input class="okv-input" id="edit-list-note" name="note" maxlength="255" value="<?= okv_e((string) ($selectedList['note'] ?? '')) ?>"></div>
        </div>
        <div class="mt-5 space-y-3" data-kl-rows>
          <?php foreach ($selectedList['items'] ?: [[]] as $row => $line): ?>
            <?php require __DIR__ . '/../includes/components/pro/kitchen_list_row.php'; ?>
          <?php endforeach; ?>
        </div>
        <div class="mt-4 flex flex-wrap items-center gap-3">
          <button type="button" class="okv-btn-outline-sm" data-kl-add hidden>Add another item</button>
          <span class="text-sm text-ink-60">Up to 50 items.</span>
        </div>
        <button class="okv-btn mt-5" type="submit">Save changes</button>
      </form>
      <form action="/api/v1/kitchen_lists.php" method="post" class="border-t border-mist p-4 md:p-5">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="list_id" value="<?= (int) $selectedList['id'] ?>">
        <label class="flex min-h-[44px] items-center gap-3 text-sm text-ink">
          <input type="checkbox" name="confirm_delete" value="1" required class="h-5 w-5">
          I understand this deletes the saved list. Kitchen Runs already sent are unchanged.
        </label>
        <button class="okv-btn-outline mt-3 border-tomato text-tomato hover:bg-tomato-tint" type="submit">Delete saved list</button>
      </form>
    </section>
  <?php endif; ?>

  <section id="new-kitchen-list" class="okv-panel scroll-mt-6" aria-labelledby="new-list-heading">
    <div class="okv-panel-head">
      <div>
        <p class="okv-eyebrow">New saved list</p>
        <h2 id="new-list-heading" class="okv-panel-title mt-1">Build a reusable list</h2>
      </div>
    </div>
    <form action="/api/v1/kitchen_lists.php" method="post" class="okv-panel-body" data-kl-form>
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="create">
      <div class="grid gap-4 sm:grid-cols-2">
        <div><label class="okv-label" for="new-list-name">List name</label><input class="okv-input" id="new-list-name" name="name" maxlength="150" required placeholder="Tuesday restock"></div>
        <div><label class="okv-label" for="new-list-note">List note, optional</label><input class="okv-input" id="new-list-note" name="note" maxlength="255" placeholder="For the Victoria Island kitchen"></div>
      </div>
      <div class="mt-5 space-y-3" data-kl-rows>
        <?php for ($row = 0; $row < 3; $row++): $line = []; ?>
          <?php require __DIR__ . '/../includes/components/pro/kitchen_list_row.php'; ?>
        <?php endfor; ?>
      </div>
      <div class="mt-4 flex flex-wrap items-center gap-3">
        <button type="button" class="okv-btn-outline-sm" data-kl-add hidden>Add another item</button>
        <span class="text-sm text-ink-60">Up to 50 items.</span>
      </div>
      <button class="okv-btn mt-5" type="submit">Save Kitchen List</button>
    </form>
  </section>

  <section class="okv-panel" aria-labelledby="runs-heading">
    <div class="okv-panel-head">
      <div>
        <p class="okv-eyebrow">Kitchen Runs</p>
        <h2 id="runs-heading" class="okv-panel-title mt-1">Your Kitchen Run history</h2>
      </div>
      <div class="flex flex-wrap items-center gap-3">
        <?php if ($waiting > 0): ?><span class="text-sm text-forest"><?= (int) $waiting ?> with us now</span><?php endif; ?>
        <a href="/kitchen-runs.php" class="okv-btn px-5">Start a Kitchen Run</a>
      </div>
    </div>
    <?php if (!$runs): ?>
      <p class="p-4 text-sm text-ink-60 md:p-5">You have not sent a Kitchen Run yet. Start one above and it appears here.</p>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="w-full min-w-[48rem] text-left text-sm">
          <caption class="sr-only">Every Kitchen Run on this account, newest first</caption>
          <thead class="border-b border-mist text-ink-60"><tr><th class="px-4 py-2 font-medium md:px-5" scope="col">Run</th><th class="px-4 py-2 font-medium" scope="col">Sent</th><th class="px-4 py-2 font-medium" scope="col">Items</th><th class="px-4 py-2 font-medium" scope="col">Delivery</th><th class="px-4 py-2 font-medium" scope="col">Status</th><th class="px-4 py-2 font-medium text-right" scope="col">Total</th><th class="px-4 py-2 font-medium md:px-5" scope="col">Reuse</th></tr></thead>
          <tbody>
            <?php foreach ($runs as $run): ?>
              <tr class="border-b border-mist/60">
                <td class="px-4 py-2 md:px-5"><a class="inline-flex min-h-[44px] items-center font-mono font-semibold text-forest hover:underline" href="/kitchen-runs.php?request=<?= (int) $run['id'] ?>"><?= okv_e((string) $run['request_number']) ?></a></td>
                <td class="px-4 py-2 text-ink-60"><?= okv_e(date('j M Y', strtotime((string) $run['created_at']))) ?></td>
                <td class="px-4 py-2"><?= (int) $run['line_count'] ?></td>
                <td class="px-4 py-2 text-ink-60"><?= $run['preferred_delivery_date'] === null ? 'Not set' : okv_e(date('l jS F', strtotime((string) $run['preferred_delivery_date']))) ?></td>
                <td class="px-4 py-2"><?= okv_e((string) $run['status_label']) ?></td>
                <td class="px-4 py-2 text-right font-mono"><?= $run['quoted_total_subunit'] === null ? 'Waiting' : okv_e(Money::format((int) $run['quoted_total_subunit'])) ?></td>
                <td class="px-4 py-2 md:px-5">
                  <details>
                    <summary class="inline-flex min-h-[44px] cursor-pointer items-center text-forest underline underline-offset-2">Save as list</summary>
                    <form action="/api/v1/kitchen_lists.php" method="post" class="mt-2 w-64 space-y-2 rounded-md border border-mist bg-white p-3">
                      <?= Csrf::field() ?>
                      <input type="hidden" name="action" value="save_from_run">
                      <input type="hidden" name="run_id" value="<?= (int) $run['id'] ?>">
                      <label class="okv-label" for="run-name-<?= (int) $run['id'] ?>">Saved list name</label>
                      <input class="okv-input" id="run-name-<?= (int) $run['id'] ?>" name="name" maxlength="150" required>
                      <label class="okv-label" for="run-note-<?= (int) $run['id'] ?>">List note, optional</label>
                      <input class="okv-input" id="run-note-<?= (int) $run['id'] ?>" name="note" maxlength="255" value="<?= okv_e((string) ($run['customer_note'] ?? '')) ?>">
                      <button class="okv-btn-sm min-h-[44px]" type="submit">Save list</button>
                    </form>
                  </details>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>

<script src="<?= okv_e(okv_asset('/assets/js/pro-kitchen-lists.min.js')) ?>" defer></script>
<?php require __DIR__ . '/../includes/components/pro/footer.php'; ?>

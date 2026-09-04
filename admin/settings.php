<?php
/**
 * admin/settings.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Order, site and notification settings. Built in milestone M0,
 * which is the milestone the scaffold here claimed from the first commit and
 * then went unbuilt through M1, M2 and M3. See docs/PRD.md Section 17.2.
 *
 * Two tabs, each saved on its own in one transaction so a tab never lands half
 * applied. The five order settings change what a customer pays or whether they
 * can order at all, so every one of them goes through a confirmation step that
 * spells the change out in words before anything is written.
 *
 * The three edit permissions are Owner-only. A Manager holds settings.view, so
 * a Manager reads the whole screen with every input disabled and a line saying
 * who makes the change. That is a courtesy, not the gate: api/v1/settings.php
 * re-checks the permission on the server for every write.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/bootstrap.php';
Rbac::requirePermission('settings.view');

$groups = SettingsEditor::groups();
$fixed  = SettingsEditor::fixed();

// Flat key to field map, so the history panel can name a key the way the form does.
$fieldByKey = [];
foreach ($groups as $groupKey => $group) {
    foreach ($group['fields'] as $key => $field) {
        $fieldByKey[$key] = $field;
    }
}

// The notification tab is content rather than settings, so it does not come
// from SettingsEditor::groups(). It gets its own tab and panel below.
$canEditTemplates = Rbac::can('settings.notifications.edit');
try {
    $templates = SettingsEditor::templates();
} catch (Throwable $e) {
    error_log('settings templates: ' . $e->getMessage());
    $templates = [];
}

$canEditAny = false;
foreach ($groups as $group) {
    if (Rbac::can($group['permission'])) {
        $canEditAny = true;
        break;
    }
}

// The audit trail is the point of the screen as much as the fields are, so a
// database hiccup here shows an honest empty panel rather than a blank page.
try {
    $history = Audit::recent(SettingsEditor::AUDIT_ENTITY, 20);
} catch (Throwable $e) {
    error_log('settings history: ' . $e->getMessage());
    $history = [];
}

/** A stored kobo figure back in the naira a person types. */
function okv_settings_money_input(int $subunit): string
{
    return $subunit % 100 === 0
        ? (string) intdiv($subunit, 100)
        : number_format($subunit / 100, 2, '.', '');
}

/** One side of an audit row, named and formatted by its field rule. */
function okv_settings_audit_value(array $fieldByKey, string $key, $raw): string
{
    if ($raw === null) {
        return 'Not set';
    }
    $field = $fieldByKey[$key] ?? null;
    if ($field === null) {
        return (string) $raw;
    }
    if ($field['type'] === 'bool') {
        return in_array(strtolower((string) $raw), ['1', 'true', 'yes', 'on'], true) ? 'On' : 'Off';
    }
    return SettingsEditor::display($field, $raw);
}

$okv_admin_title  = 'Settings';
$okv_admin_note   = 'The deposit, the cutoff, the details the shop shows, and a record of every change.';
$okv_admin_script = '/assets/js/admin-settings.js';
require __DIR__ . '/../includes/components/admin/header.php';
?>
  <div class="space-y-6">

    <?php if (!$canEditAny): ?>
      <div class="okv-note okv-note-ok" role="status">
        You can see every setting here. The Owner makes the changes.
      </div>
    <?php endif; ?>

    <div class="okv-panel">
      <div class="border-b border-mist px-4 md:px-5">
        <div class="flex gap-1 overflow-x-auto" role="tablist" aria-label="Settings sections">
          <?php $first = true; foreach ($groups as $groupKey => $group): ?>
            <button type="button" role="tab"
                    id="tab-<?= okv_e($groupKey) ?>"
                    aria-controls="panel-<?= okv_e($groupKey) ?>"
                    aria-selected="<?= $first ? 'true' : 'false' ?>"
                    data-settings-tab="<?= okv_e($groupKey) ?>"
                    class="min-h-[44px] px-4 text-sm font-medium border-b-2 -mb-px <?= $first ? 'border-forest text-forest' : 'border-transparent text-ink-60 hover:text-ink' ?>">
              <?= okv_e($group['label']) ?>
            </button>
          <?php $first = false; endforeach; ?>
            <button type="button" role="tab" id="tab-notifications"
                    aria-controls="panel-notifications" aria-selected="false"
                    data-settings-tab="notifications"
                    class="min-h-[44px] px-4 text-sm font-medium border-b-2 -mb-px border-transparent text-ink-60 hover:text-ink">
              Notifications
            </button>
        </div>
      </div>

      <?php $first = true; foreach ($groups as $groupKey => $group):
        $canEdit = Rbac::can($group['permission']);
        $values  = SettingsEditor::values($groupKey);
        $action  = 'save_' . ($groupKey === 'order' ? 'order' : ($groupKey === 'payment' ? 'payment' : 'site')) . '_settings';
      ?>
        <div class="okv-panel-body <?= $first ? '' : 'hidden' ?>"
             id="panel-<?= okv_e($groupKey) ?>" role="tabpanel"
             aria-labelledby="tab-<?= okv_e($groupKey) ?>" data-settings-panel="<?= okv_e($groupKey) ?>">

          <p class="text-sm text-ink-60 max-w-2xl"><?= okv_e($group['note']) ?></p>

          <?php if (!empty($group['guide'])): $guide = $group['guide']; ?>
            <!-- Settings that belong to the Paystack account rather than to us.
                 Saying so plainly beats a control that cannot do what it says. -->
            <aside class="mt-5 rounded-lg border border-mist bg-forest-tint p-5" aria-labelledby="guide-<?= okv_e($groupKey) ?>">
              <h3 id="guide-<?= okv_e($groupKey) ?>" class="font-semibold text-ink"><?= okv_e($guide['title']) ?></h3>
              <p class="mt-2 text-sm text-ink-60 max-w-2xl"><?= okv_e($guide['intro']) ?></p>

              <dl class="mt-4 space-y-4">
                <?php foreach ($guide['items'] as $item): ?>
                  <div>
                    <dt class="text-sm font-semibold text-ink"><?= okv_e($item['heading']) ?></dt>
                    <dd class="mt-1 text-sm text-ink-60 max-w-2xl"><?= okv_e($item['body']) ?></dd>
                  </div>
                <?php endforeach; ?>
              </dl>

              <div class="mt-4">
                <p class="text-sm font-semibold text-ink">Your webhook URL</p>
                <p class="mt-1 text-xs text-ink-60">Copy this into the Webhook URL field on Paystack.</p>
                <code class="mt-2 block overflow-x-auto rounded-md border border-mist bg-white px-3 py-2 font-mono text-sm text-ink"><?= okv_e(rtrim((string) APP_URL, '/') . '/api/v1/paystack_webhook.php') ?></code>
              </div>

              <a class="okv-btn-outline mt-4 inline-flex min-h-[44px] items-center"
                 href="<?= okv_e($guide['link']) ?>" target="_blank" rel="noopener noreferrer">
                <?= okv_e($guide['link_label']) ?>
                <span class="sr-only">, opens in a new tab</span>
              </a>
            </aside>
          <?php endif; ?>

          <!-- novalidate on purpose: the server is the authority on every rule
               here, so one error path shows one message in our own words rather
               than a native bubble for a number and our copy for everything else. -->
          <form action="/api/v1/settings.php" method="POST" class="mt-5 space-y-5" novalidate
                data-settings-form data-group="<?= okv_e($groupKey) ?>" autocomplete="off">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="<?= okv_e($action) ?>">
            <input type="hidden" name="rendered_fields" value="<?= okv_e(implode(',', array_keys($group['fields']))) ?>">

            <div class="grid gap-5 md:grid-cols-2">
              <?php foreach ($group['fields'] as $key => $field):
                $value  = $values[$key];
                $helpId = 'help-' . $key;
              ?>
                <div<?= $field['type'] === 'bool' ? ' class="md:col-span-2"' : '' ?>>

                  <?php if ($field['type'] === 'bool'): ?>
                    <label class="flex items-start gap-3 min-h-[44px]">
                      <input type="checkbox" name="<?= okv_e($key) ?>" value="1"
                             class="mt-1 h-5 w-5 rounded border-mist text-forest"
                             aria-describedby="<?= okv_e($helpId) ?>"
                             data-settings-field="<?= okv_e($key) ?>"
                             data-original="<?= $value ? '1' : '0' ?>"
                             <?= $value ? 'checked' : '' ?>
                             <?= $canEdit ? '' : 'disabled' ?>>
                      <span>
                        <span class="block text-sm font-medium text-ink"><?= okv_e($field['label']) ?></span>
                        <span id="<?= okv_e($helpId) ?>" class="block text-sm text-ink-60 mt-0.5"><?= okv_e($field['help']) ?></span>
                      </span>
                    </label>

                  <?php else: ?>
                    <label class="okv-label" for="field-<?= okv_e($key) ?>"><?= okv_e($field['label']) ?></label>

                    <?php if ($field['type'] === 'time'): ?>
                      <input type="time" id="field-<?= okv_e($key) ?>" name="<?= okv_e($key) ?>"
                             class="okv-input font-mono tabular-nums" value="<?= okv_e($value) ?>"
                             aria-describedby="<?= okv_e($helpId) ?>"
                             data-settings-field="<?= okv_e($key) ?>" data-original="<?= okv_e($value) ?>"
                             <?= $canEdit ? '' : 'disabled' ?>>

                    <?php elseif (in_array($field['type'], ['percent', 'days'], true)): ?>
                      <div class="flex items-center gap-2">
                        <input type="number" inputmode="numeric" id="field-<?= okv_e($key) ?>" name="<?= okv_e($key) ?>"
                               class="okv-input font-mono tabular-nums" value="<?= (int) $value ?>"
                               min="<?= (int) ($field['min'] ?? 0) ?>" max="<?= (int) ($field['cap'] ?? 100) ?>" step="1"
                               aria-describedby="<?= okv_e($helpId) ?>"
                               data-settings-field="<?= okv_e($key) ?>" data-original="<?= (int) $value ?>"
                               <?= $canEdit ? '' : 'disabled' ?>>
                        <span class="text-sm text-ink-60 shrink-0"><?= $field['type'] === 'percent' ? 'percent' : 'days' ?></span>
                      </div>

                    <?php elseif ($field['type'] === 'money'): ?>
                      <div class="flex items-center gap-2">
                        <span class="text-sm text-ink-60 shrink-0" aria-hidden="true">&#8358;</span>
                        <input type="text" inputmode="decimal" id="field-<?= okv_e($key) ?>" name="<?= okv_e($key) ?>"
                               class="okv-input font-mono tabular-nums"
                               value="<?= okv_e(okv_settings_money_input((int) $value)) ?>"
                               aria-describedby="<?= okv_e($helpId) ?>"
                               data-settings-field="<?= okv_e($key) ?>"
                               data-original="<?= okv_e(okv_settings_money_input((int) $value)) ?>"
                               <?= $canEdit ? '' : 'disabled' ?>>
                      </div>

                    <?php else: ?>
                      <input type="<?= $field['type'] === 'email' ? 'email' : 'text' ?>"
                             id="field-<?= okv_e($key) ?>" name="<?= okv_e($key) ?>"
                             class="okv-input<?= $field['type'] === 'phone' ? ' font-mono tabular-nums' : '' ?>"
                             value="<?= okv_e($value) ?>"
                             <?php if (!empty($field['max'])): ?>maxlength="<?= (int) $field['max'] ?>"<?php endif; ?>
                             <?php if (!empty($field['placeholder'])): ?>placeholder="<?= okv_e($field['placeholder']) ?>"<?php endif; ?>
                             aria-describedby="<?= okv_e($helpId) ?>"
                             data-settings-field="<?= okv_e($key) ?>" data-original="<?= okv_e($value) ?>"
                             <?= $canEdit ? '' : 'disabled' ?>>
                    <?php endif; ?>

                    <p id="<?= okv_e($helpId) ?>" class="text-sm text-ink-60 mt-1.5"><?= okv_e($field['help']) ?></p>
                  <?php endif; ?>

                  <p class="text-sm text-tomato mt-1.5 hidden" data-settings-error="<?= okv_e($key) ?>" role="alert"></p>
                </div>
              <?php endforeach; ?>
            </div>

            <?php if ($canEdit): ?>
              <div class="pt-2 border-t border-mist flex flex-wrap items-center gap-3">
                <button type="submit" class="okv-btn-sm" data-settings-save>Save changes</button>
                <button type="button" class="okv-btn-text hidden" data-settings-reset>Undo my edits</button>
                <span class="text-sm text-ink-60 hidden" data-settings-dirty>You have unsaved changes.</span>
              </div>

              <!-- The confirmation step. Filled in by the browser from what the
                   server said the change would be, never from the form alone. -->
              <div class="hidden rounded-md border border-gold bg-white p-4" data-settings-confirm>
                <p class="okv-eyebrow">Check this before it saves</p>
                <ul class="mt-3 space-y-2 text-sm text-ink" data-settings-confirm-list></ul>
                <div class="mt-4 flex flex-wrap gap-3">
                  <button type="button" class="okv-btn-sm" data-settings-confirm-yes>Yes, save these changes</button>
                  <button type="button" class="okv-btn-outline-sm" data-settings-confirm-no>Go back</button>
                </div>
              </div>
            <?php else: ?>
              <p class="pt-2 border-t border-mist text-sm text-ink-60">
                These are read-only for you. The Owner changes them.
              </p>
            <?php endif; ?>
          </form>
        </div>
      <?php $first = false; endforeach; ?>

      <!-- Notification templates. The words each automated email sends, editable
           by an Owner without a deploy. The letterhead is deliberately not here:
           it lives in Mail::brandedHtml() where the brand guard can see it, so a
           template can never carry a second set of brand values. -->
      <div class="okv-panel-body hidden" id="panel-notifications" role="tabpanel"
           aria-labelledby="tab-notifications" data-settings-panel="notifications">
        <p class="text-sm text-ink-60 max-w-2xl">
          Every automated email the platform sends. Edit the words. The letterhead, the
          button and the footer are added around them, so every message looks the same.
          "Send one to me" posts a real message through the real mail server to your own
          address, with made-up figures, so you can prove email works without placing an
          order. It can only ever go to you.
        </p>
        <?php if (!$canEditTemplates): ?>
          <div class="okv-note okv-note-ok mt-4" role="status">You can read every message here. The Owner makes the changes.</div>
        <?php endif; ?>
        <?php if (!$templates): ?>
          <p class="okv-note mt-5 bg-clay-tint">The templates could not be read just now. Reload the page.</p>
        <?php endif; ?>

        <div class="mt-5 space-y-4">
          <?php foreach ($templates as $key => $template): ?>
            <details class="rounded-md border border-mist bg-white">
              <summary class="flex min-h-[44px] cursor-pointer flex-wrap items-center justify-between gap-3 px-4 py-3">
                <span>
                  <span class="font-semibold text-ink"><?= okv_e($template['label']) ?></span>
                  <span class="ml-2 okv-badge <?= $template['audience'] === 'staff' ? 'okv-badge-neutral' : 'okv-badge-available' ?>">
                    <?= $template['audience'] === 'staff' ? 'To the team' : 'To the customer' ?>
                  </span>
                </span>
                <span class="font-mono text-xs text-ink-60"><?= okv_e($key) ?></span>
              </summary>

              <?php if ($template['missing']): ?>
                <p class="okv-note m-4 bg-clay-tint">This message has no template in the database yet. Run the migrations.</p>
              <?php else: ?>
                <form action="/api/v1/settings.php" method="POST" class="space-y-4 border-t border-mist p-4"
                      data-template-form data-key="<?= okv_e($key) ?>" novalidate>
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="save_template">
                  <input type="hidden" name="template_key" value="<?= okv_e($key) ?>">

                  <div>
                    <label class="okv-label" for="subject-<?= okv_e($key) ?>">Subject</label>
                    <input class="okv-input mt-1" id="subject-<?= okv_e($key) ?>" name="subject_template"
                           maxlength="255" value="<?= okv_e($template['subject']) ?>"
                           <?= $canEditTemplates ? '' : 'disabled' ?>>
                  </div>
                  <div>
                    <label class="okv-label" for="body-<?= okv_e($key) ?>">Message</label>
                    <textarea class="okv-input mt-1" id="body-<?= okv_e($key) ?>" name="body_template" rows="7"
                              maxlength="5000" <?= $canEditTemplates ? '' : 'disabled' ?>><?= okv_e($template['body']) ?></textarea>
                    <p class="mt-1 text-xs text-ink-60">A blank line starts a new paragraph. No em dash.</p>
                  </div>

                  <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-ink-60">Tokens this message accepts</p>
                    <ul class="mt-2 flex flex-wrap gap-2">
                      <?php foreach ($template['tokens'] as $token): ?>
                        <li class="rounded border border-mist bg-forest-tint px-2 py-1 font-mono text-xs text-ink">{{<?= okv_e($token) ?>}}</li>
                      <?php endforeach; ?>
                    </ul>
                  </div>

                  <div class="flex flex-wrap items-center gap-3">
                    <button type="button" class="okv-btn-outline min-h-[44px] px-4" data-template-preview>Preview it</button>
                    <?php if ($canEditTemplates): ?>
                      <button class="okv-btn min-h-[44px] px-4">Save these words</button>
                      <button type="button" class="okv-btn-outline min-h-[44px] px-4" data-template-test>Send one to me</button>
                    <?php endif; ?>
                    <span class="text-sm" data-template-message role="status"></span>
                  </div>

                  <iframe class="hidden mt-1 h-96 w-full rounded-md border border-mist bg-white" title="Preview of <?= okv_e($template['label']) ?>" data-template-frame></iframe>
                </form>
              <?php endif; ?>
            </details>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-2 items-start">

      <div class="okv-panel min-w-0">
        <div class="okv-panel-head">
          <h2 class="okv-panel-title">Set at launch</h2>
        </div>
        <div class="okv-panel-body">
          <p class="text-sm text-ink-60">
            These two are real settings and they are not editable here, because changing either
            would break records already written.
          </p>
          <dl class="mt-4 space-y-4">
            <?php foreach ($fixed as $key => $reason): ?>
              <div>
                <dt class="text-sm font-medium text-ink">
                  <?= okv_e(ucfirst(str_replace('_', ' ', $key))) ?>
                  <span class="font-mono text-xs text-ink-60 ml-1 break-words"><?= okv_e(Settings::str($key, 'Not set')) ?></span>
                </dt>
                <dd class="text-sm text-ink-60 mt-0.5"><?= okv_e($reason) ?></dd>
              </div>
            <?php endforeach; ?>
          </dl>
        </div>
      </div>

      <div class="okv-panel min-w-0">
        <div class="okv-panel-head">
          <h2 class="okv-panel-title">What changed</h2>
          <span class="text-sm text-ink-60">The last 20 changes</span>
        </div>
        <div class="okv-panel-body">
          <?php if (!$history): ?>
            <p class="text-sm text-ink-60">
              No setting has been changed yet. The first change made here will show up in this list,
              with who made it and what it was before.
            </p>
          <?php else: ?>
            <ul class="space-y-3" data-settings-history>
              <?php foreach ($history as $row):
                $old = json_decode((string) ($row['old_values'] ?? ''), true) ?: [];
                $new = json_decode((string) ($row['new_values'] ?? ''), true) ?: [];
                $key = (string) (array_key_first($new) ?? array_key_first($old) ?? '');
                if ($key === '') { continue; }
                $label = $fieldByKey[$key]['label'] ?? ucfirst(str_replace('_', ' ', $key));
                $when  = strtotime((string) $row['created_at']);
              ?>
                <li class="text-sm border-b border-mist pb-3 last:border-0 last:pb-0">
                  <p class="text-ink break-words">
                    <span class="font-medium"><?= okv_e($label) ?></span>
                    <span class="text-ink-60">changed from</span>
                    <span class="font-mono text-xs break-words"><?= okv_e(okv_settings_audit_value($fieldByKey, $key, $old[$key] ?? null)) ?></span>
                    <span class="text-ink-60">to</span>
                    <span class="font-mono text-xs break-words"><?= okv_e(okv_settings_audit_value($fieldByKey, $key, $new[$key] ?? null)) ?></span>
                  </p>
                  <p class="text-ink-60 mt-0.5">
                    <?= okv_e($row['actor_name'] !== null && $row['actor_name'] !== '' ? $row['actor_name'] : 'A signed-out session') ?>,
                    <?= okv_e($when ? date('j M Y, H:i', $when) : (string) $row['created_at']) ?>
                  </p>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <p class="text-sm text-ink-60">
      Notification templates are the third part of this screen in the PRD. They are built in M9,
      alongside the sending they belong to. Until then the wording lives in migrations.
    </p>
  </div>
<?php require __DIR__ . '/../includes/components/admin/footer.php'; ?>

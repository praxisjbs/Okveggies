<?php
/**
 * api/v1/settings.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Read and write site and order settings. Built in milestone M0,
 * which is where the scaffold claimed it. See docs/PRD.md Section 17.2.
 *
 * Reads are GET and need settings.view. Every write is a POST with a valid CSRF
 * token and the tab's own permission, which is Owner-only: settings.order.edit
 * for the order tab, settings.edit for the site tab. A Manager holds
 * settings.view and nothing else, so a Manager reaches every read here and no
 * write, checked on the server rather than by hiding a button.
 *
 * Every write goes through SettingsEditor::save(), which validates the whole
 * tab, applies it in one transaction, and writes an audit_logs row for each
 * value that actually moved. There is no path through this file that changes a
 * setting without recording who changed it.
 *
 * Actions:
 *   get_group             (GET,  settings.view)             one tab's fields and values
 *   preview               (POST, the tab's edit permission) what a save would change, writing nothing
 *   save_order_settings   (POST, settings.order.edit)       apply the order tab
 *   save_site_settings    (POST, settings.edit)             apply the site tab
 *   history               (GET,  settings.view)             recent changes, newest first
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../../includes/bootstrap.php';

$action = okv_action();

/** How many past changes the history action returns. */
const SETTINGS_HISTORY_LIMIT = 20;

/**
 * Every write shares one gate: POST, the tab's permission, a valid CSRF token.
 * The order matters. Method first so a GET never reaches a permission lookup,
 * permission next so an unauthorised caller learns nothing from the CSRF reply.
 */
function settings_guard_write(string $permission): void
{
    if (!okv_is_post()) {
        okv_error('Use POST for this action.', 405, 'method_not_allowed');
    }
    Rbac::requirePermission($permission);
    if (!Csrf::validate()) {
        okv_error('Your session expired. Reload the page and try again.', 419, 'csrf_expired');
    }
}

function settings_fail(Throwable $e, string $context): void
{
    if ($e instanceof DomainException && $e->getMessage() === 'unknown_group') {
        okv_error('That is not a settings tab.', 404, 'unknown_group');
    }
    error_log('settings.' . $context . ' failed: ' . $e->getMessage());
    okv_error('Something went wrong at our end. Nothing was changed.', 500, 'failed');
}

/** The submitted values for one tab, taken only from that tab's registered keys. */
function settings_input_for(string $groupKey): array
{
    $group = SettingsEditor::group($groupKey);
    if ($group === null) {
        return [];
    }
    $input = [];
    foreach (array_keys($group['fields']) as $key) {
        if (array_key_exists($key, $_POST)) {
            $input[$key] = $_POST[$key];
        }
    }
    // A checkbox that is off posts nothing, so an absent bool means false. The
    // form sends a marker naming every bool it rendered, which is how we tell
    // "unticked" apart from "this tab was not on screen".
    $rendered = okv_input('rendered_fields', '');
    $rendered = is_string($rendered) ? array_filter(explode(',', $rendered)) : [];
    foreach ($group['fields'] as $key => $field) {
        if ($field['type'] === 'bool' && in_array($key, $rendered, true) && !array_key_exists($key, $input)) {
            $input[$key] = '0';
        }
    }
    return $input;
}

/** One tab as the screen and the API both describe it. */
function settings_group_payload(string $groupKey): array
{
    $group  = SettingsEditor::group($groupKey);
    $values = SettingsEditor::values($groupKey);
    $fields = [];
    foreach ($group['fields'] as $key => $field) {
        $fields[] = [
            'key'      => $key,
            'label'    => $field['label'],
            'help'     => $field['help'],
            'type'     => $field['type'],
            'confirm'  => !empty($field['confirm']),
            'value'    => $values[$key],
            'display'  => SettingsEditor::display($field, $values[$key]),
        ];
    }
    return [
        'group'      => $groupKey,
        'label'      => $group['label'],
        'note'       => $group['note'],
        'can_edit'   => Rbac::can($group['permission']),
        'fields'     => $fields,
    ];
}

/** Apply one tab. Shared by both save actions, which differ only in permission. */
function settings_save(string $groupKey): void
{
    $input  = settings_input_for($groupKey);
    $result = SettingsEditor::validate($groupKey, $input);

    if ($result['errors']) {
        okv_json([
            'status'  => 'error',
            'code'    => 'invalid',
            'message' => 'Some values need a look. Nothing was changed.',
            'errors'  => $result['errors'],
        ], 422);
    }

    try {
        $changes = SettingsEditor::save($groupKey, $result['clean'], Rbac::userId());
    } catch (Throwable $e) {
        settings_fail($e, 'save.' . $groupKey);
        return;
    }

    okv_json([
        'status'  => 'ok',
        'changed' => $changes,
        'message' => $changes
            ? (count($changes) === 1 ? 'One setting saved.' : count($changes) . ' settings saved.')
            : 'Nothing had changed, so nothing was saved.',
        'group'   => settings_group_payload($groupKey),
    ]);
}

switch ($action) {

    case 'get_group':
        Rbac::requirePermission('settings.view');
        $groupKey = (string) okv_input('group', '');
        if (SettingsEditor::group($groupKey) === null) {
            okv_error('That is not a settings tab.', 404, 'unknown_group');
        }
        okv_json(settings_group_payload($groupKey));
        break;

    case 'preview':
        // Says what a save would do and writes nothing. It still needs the write
        // permission: what the deposit is about to become is not a read.
        $groupKey = (string) okv_input('group', '');
        $group    = SettingsEditor::group($groupKey);
        if ($group === null) {
            okv_error('That is not a settings tab.', 404, 'unknown_group');
        }
        settings_guard_write($group['permission']);

        $result = SettingsEditor::validate($groupKey, settings_input_for($groupKey));
        if ($result['errors']) {
            okv_json([
                'status'  => 'error',
                'code'    => 'invalid',
                'message' => 'Some values need a look.',
                'errors'  => $result['errors'],
            ], 422);
        }
        $changes = SettingsEditor::diff($groupKey, $result['clean']);
        okv_json([
            'status'        => 'ok',
            'changed'       => $changes,
            'needs_confirm' => (bool) array_filter($changes, static fn($c) => $c['confirm']),
        ]);
        break;

    case 'save_order_settings':
        settings_guard_write('settings.order.edit');
        settings_save('order');
        break;

    case 'save_site_settings':
        settings_guard_write('settings.edit');
        settings_save('site');
        break;

    case 'save_payment_settings':
        settings_guard_write('settings.edit');
        settings_save('payment');
        break;

    // ---------------------------------------------------------------------
    // Notification templates. The words live in notification_templates, not in
    // PHP, because they are content an Owner rewrites without a deploy. The
    // letterhead is not editable here on purpose: it lives in Mail::brandedHtml
    // where the brand guard can see it.
    // ---------------------------------------------------------------------
    case 'save_template':
        settings_guard_write('settings.notifications.edit');
        $key = trim((string) okv_input('template_key', ''));
        $subject = trim((string) okv_input('subject_template', ''));
        $body = trim((string) okv_input('body_template', ''));
        if (!isset(Notifications::TOKENS[$key])) {
            okv_error('That is not a notification we send.', 422, 'unknown_template');
        }
        if ($subject === '' || mb_strlen($subject) > 255) {
            okv_error('Give the email a subject of 255 characters or fewer.', 422, 'bad_subject');
        }
        if ($body === '' || mb_strlen($body) > 5000) {
            okv_error('Write a message of 5,000 characters or fewer.', 422, 'bad_body');
        }
        if (str_contains($subject . $body, "\u{2014}")) {
            okv_error('The house style has no em dash. Use a full stop, a comma or a colon.', 422, 'em_dash');
        }
        try {
            $before = Database::one(
                'SELECT subject_template, body_template FROM notification_templates WHERE template_key = :k',
                [':k' => $key]
            );
            if (!$before) {
                okv_error('That notification has no template yet.', 404, 'not_found');
            }
            Database::run(
                'UPDATE notification_templates
                    SET subject_template = :subject, body_template = :body, updated_by = :actor
                  WHERE template_key = :key',
                [':subject' => $subject, ':body' => $body, ':actor' => Rbac::userId(), ':key' => $key]
            );
            Audit::record('settings.template.update', 'notification_template', null, $before, [
                'template_key'     => $key,
                'subject_template' => $subject,
                'body_template'    => $body,
            ]);
        } catch (Throwable $e) {
            settings_fail($e, 'save_template');
            return;
        }
        okv_json([
            'status'  => 'ok',
            'message' => 'The words for this email have been saved.',
            'preview' => Mail::brandedHtml(
                SettingsEditor::fillTemplate($subject, SettingsEditor::sampleTokens($key)),
                SettingsEditor::fillTemplate($body, SettingsEditor::sampleTokens($key)),
                Mail::ctaFromVars(SettingsEditor::sampleTokens($key))
            ),
        ]);
        break;

    case 'preview_template':
        Rbac::requirePermission('settings.view');
        $key = trim((string) okv_input('template_key', ''));
        if (!isset(Notifications::TOKENS[$key])) {
            okv_error('That is not a notification we send.', 422, 'unknown_template');
        }
        $sample = SettingsEditor::sampleTokens($key);
        okv_json([
            'status'  => 'ok',
            'subject' => SettingsEditor::fillTemplate((string) okv_input('subject_template', ''), $sample),
            'preview' => Mail::brandedHtml(
                SettingsEditor::fillTemplate((string) okv_input('subject_template', ''), $sample),
                SettingsEditor::fillTemplate((string) okv_input('body_template', ''), $sample),
                Mail::ctaFromVars($sample)
            ),
        ]);
        break;

    case 'history':
        Rbac::requirePermission('settings.view');
        try {
            $rows = Audit::recent(SettingsEditor::AUDIT_ENTITY, SETTINGS_HISTORY_LIMIT);
        } catch (Throwable $e) {
            settings_fail($e, 'history');
            return;
        }
        okv_json(['status' => 'ok', 'changes' => $rows]);
        break;

    default:
        okv_error('We do not know that action.', 400, 'unknown_action');
}

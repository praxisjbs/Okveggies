<?php
/**
 * api/v1/permissions.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The Permissions module endpoint behind /admin/permissions.php.
 *
 *   list      read the catalogue, the roles and each role's grants
 *   save      replace one role's permission set
 *   versions  one role's change history
 *   restore   put a role back to a recorded version
 *
 * Reads need rbac.roles.view. Writes need the Owner, checked on the server on
 * every call: PermissionMatrix::canAssign() asks Rbac::isOwner(), which reads
 * the role the session was loaded from, not anything the browser sent.
 *
 * Every write validates the CSRF token and every submitted key against the
 * catalogue, so a tampered form cannot write a permission the product does not
 * have, and cannot write one that has no row in `permissions` to hang off.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../../includes/bootstrap.php';

/** A read action: staff only, and only with a view permission. */
function okv_perm_guard_read(): void
{
    if (!Rbac::isLoggedIn()) {
        okv_error('Please sign in.', 401, 'unauthenticated');
    }
    if (!PermissionMatrix::canView()) {
        okv_error('You do not have access to permissions.', 403, 'forbidden');
    }
}

/**
 * A write action: POST, CSRF, and the Owner.
 *
 * The Owner check comes before the CSRF check on purpose. A signed-in colleague
 * who simply is not allowed to be here should be told which door they are
 * standing at, not sent to fetch a fresh token for a write that was never going
 * to be accepted.
 */
function okv_perm_guard_write(): void
{
    if (!okv_is_post()) {
        okv_error('Use POST for this action.', 405, 'method_not_allowed');
    }
    if (!Rbac::isLoggedIn()) {
        okv_error('Please sign in.', 401, 'unauthenticated');
    }
    if (!PermissionMatrix::canAssign()) {
        okv_error('Only the Owner can change what a role can do.', 403, 'owner_only');
    }
    if (!Csrf::validate()) {
        okv_error('Your session expired. Reload the page and try again.', 419, 'csrf_expired');
    }
}

/** The role this request is about. */
function okv_perm_role_id(): int
{
    return (int) okv_input('role_id', 0);
}

/**
 * The submitted permission list, as strings. A form that unticks every box sends
 * no `permissions` field at all, and that has to mean "this role now carries
 * nothing", not "leave it alone". So a missing field reads as an empty list.
 */
function okv_perm_submitted_keys(): array
{
    $raw = $_POST['permissions'] ?? [];
    if (!is_array($raw)) {
        return [];
    }
    return $raw;
}

switch (okv_action()) {

case 'list':
    okv_perm_guard_read();
    $roles = PermissionMatrix::roles();
    $counts = PermissionMatrix::grantCounts();
    foreach ($roles as $i => $role) {
        $roles[$i]['granted_count'] = $counts[(int) $role['id']] ?? 0;
        $roles[$i]['is_system']     = PermissionMatrix::isSystemRole($role);
    }
    $catalogue = PermissionMatrix::catalogue();
    $roleId    = okv_perm_role_id();
    okv_json([
        'status'      => 'ok',
        'roles'       => $roles,
        'catalogue'   => $catalogue,
        'grantable'   => PermissionMatrix::grantableKeys(),
        'drift'       => PermissionMatrix::drift(),
        'grants'      => $roleId > 0 ? PermissionMatrix::grants($roleId) : [],
    ]);
    break;

case 'save':
    okv_perm_guard_write();
    $roleId  = okv_perm_role_id();
    $keys    = okv_perm_submitted_keys();
    $allowed = array_flip(PermissionMatrix::grantableKeys());

    foreach ($keys as $key) {
        if (!is_scalar($key) || !isset($allowed[(string) $key])) {
            okv_error('One or more of those permissions is not one we can grant.', 422, 'unknown_permission');
        }
    }

    try {
        $result = PermissionMatrix::save($roleId, $keys);
    } catch (InvalidArgumentException $e) {
        // The message is ours, written for a person, and carries no internals.
        $code = str_contains($e->getMessage(), 'protected') ? 403 : 404;
        okv_error($e->getMessage(), $code, $code === 403 ? 'protected_role' : 'not_found');
    } catch (Throwable $e) {
        okv_error('We could not save those permissions.', 500, 'save_failed');
    }

    // The actor's own session is refreshed, so a grant change that touches the
    // person making it takes effect on their very next request either way.
    Rbac::forceReload();

    okv_json([
        'status'  => 'ok',
        'changed' => $result['changed'],
        'added'   => $result['added'],
        'removed' => $result['removed'],
        'summary' => PermissionMatrix::summarise($result),
        'message' => $result['changed']
            ? 'Permissions saved. ' . PermissionMatrix::summarise($result) . '.'
            : 'Nothing changed, so nothing was saved.',
    ]);
    break;

case 'versions':
    okv_perm_guard_read();
    $roleId = okv_perm_role_id();
    $limit  = (int) okv_input('limit', 20);
    okv_json([
        'status'   => 'ok',
        'role_id'  => $roleId,
        'versions' => PermissionMatrix::versions($roleId, $limit),
    ]);
    break;

case 'restore':
    okv_perm_guard_write();
    $versionId = (int) okv_input('version_id', 0);
    if ($versionId <= 0) {
        okv_error('Choose a version to restore.', 422, 'bad_version');
    }

    try {
        $result = PermissionMatrix::restore($versionId);
    } catch (InvalidArgumentException $e) {
        $code = str_contains($e->getMessage(), 'protected') ? 403 : 404;
        okv_error($e->getMessage(), $code, $code === 403 ? 'protected_role' : 'not_found');
    } catch (Throwable $e) {
        okv_error('We could not restore that version.', 500, 'restore_failed');
    }

    Rbac::forceReload();

    okv_json([
        'status'  => 'ok',
        'changed' => $result['changed'],
        'added'   => $result['added'],
        'removed' => $result['removed'],
        'message' => $result['changed']
            ? 'Role put back. ' . PermissionMatrix::summarise($result) . '.'
            : 'That role already carries those permissions, so nothing was written.',
    ]);
    break;

default:
    okv_error('This action is not available.', 400, 'unknown_action');
}

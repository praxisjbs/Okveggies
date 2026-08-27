<?php
/**
 * api/v1/rbac.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Roles and permissions, Owner only (rbac.*). For milestone M1 this
 * serves the role list that the Users screen needs. Editing the role to
 * permission map at runtime is a later milestone; the permission catalogue and
 * the two launch roles are seeded in migration 002.
 * See docs/PRD.md Section 17.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../../includes/bootstrap.php';

$action = okv_action();

switch ($action) {

    case 'list_roles': {
        Rbac::requirePermission('rbac.roles.view');
        $roles = Database::all('SELECT id, name, description FROM roles ORDER BY name');
        $out = array_map(static fn($r) => [
            'id'          => (int) $r['id'],
            'name'        => $r['name'],
            'description' => $r['description'],
        ], $roles);
        okv_json(['status' => 'ok', 'roles' => $out]);
        break;
    }

    default:
        okv_error('This action is not available.', 400, 'unknown_action');
}

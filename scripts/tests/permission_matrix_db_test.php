<?php
/**
 * scripts/tests/permission_matrix_db_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The Permissions module against a live database. PermissionMatrixTest
 * covers the pure rules with no database; this covers what rules cannot:
 *
 *   1. a save writes the junction rows, one history snapshot and one audit row,
 *      and commits all three together;
 *   2. a save that changed nothing writes nothing at all, so the history stays a
 *      record of changes rather than of visits;
 *   3. a submitted key that cannot be granted is never written;
 *   4. the Owner and Manager roles refuse to be edited, so the Owner cannot be
 *      locked out of the screen that hands out permissions;
 *   5. a recorded version can be put back, and the restore is itself recorded.
 *
 * Needs a migrated database and the app .env, same as the other *_db_test.php
 * files. Run it directly:
 *   php scripts/tests/permission_matrix_db_test.php
 * -----------------------------------------------------------------------------
 */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_once __DIR__ . '/lib/scratch_guard.php';

$tests = 0; $passed = 0; $fails = [];
function t_ok($cond, string $label): void
{
    global $tests, $passed, $fails;
    $tests++;
    if ($cond) { $passed++; } else { $fails[] = $label; fwrite(STDERR, "  FAIL: $label\n"); }
}
function t_eq($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        $label .= '  (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')';
    }
    t_ok($expected === $actual, $label);
}

const OKV_PM_ROLE = 'permission-matrix-test';

/** Audit rows written against one role, newest first. */
function pm_audit(int $roleId): array
{
    return Database::all(
        "SELECT id, action, old_values, new_values
           FROM audit_logs
          WHERE entity_type = 'roles' AND entity_id = :id
       ORDER BY id DESC",
        [':id' => $roleId]
    );
}

// --- A scratch role, and the keys to move around ------------------------------

Database::run('DELETE FROM roles WHERE name = :n', [':n' => OKV_PM_ROLE]);
Database::run(
    "INSERT INTO roles (name, slug, description, status) VALUES (:n, :s, :d, 'active')",
    [':n' => OKV_PM_ROLE, ':s' => OKV_PM_ROLE, ':d' => 'Scratch role for the permission matrix suite. Safe to delete.']
);
$roleId = (int) Database::one('SELECT id FROM roles WHERE name = :n', [':n' => OKV_PM_ROLE])['id'];
t_ok($roleId > 0, 'the scratch role exists');

$grantable = PermissionMatrix::grantableKeys();
t_ok(count($grantable) > 20, 'the catalogue reaches the database and is not empty');

// Two sets of real keys, so the foreign key holds. They go through the same
// cleaning rule the save path uses, which also puts them in the sorted order
// grants() reads them back in, so these comparisons test the module rather than
// two orderings that happen to agree.
$setA = PermissionMatrix::normaliseKeys(array_slice($grantable, 0, 3), $grantable);
$setB = PermissionMatrix::normaliseKeys(array_slice($grantable, 0, 1), $grantable);
t_eq(3, count($setA), 'three real keys were chosen for the suite');

try {
    // --- A first save ---------------------------------------------------------

    t_eq([], PermissionMatrix::grants($roleId), 'a new role carries nothing');

    $first = PermissionMatrix::save($roleId, $setA);
    t_ok($first['changed'], 'the first save reports a change');
    t_eq($setA, $first['permissions'], 'the save reports the set it wrote');
    t_eq($setA, $first['added'], 'every key in the first save is reported as added');
    t_eq([], $first['removed'], 'nothing is reported as removed from an empty set');
    t_ok(is_int($first['version_id']) && $first['version_id'] > 0, 'the save records a version');

    t_eq($setA, PermissionMatrix::grants($roleId), 'the role carries exactly what was saved');

    $versions = PermissionMatrix::versions($roleId);
    t_eq(1, count($versions), 'one history snapshot was written');
    t_eq(3, (int) $versions[0]['permission_count'], 'the snapshot counts what the role ended up with');
    t_eq($setA, $versions[0]['permissions'], 'the snapshot holds the keys, decoded from JSON');
    t_eq(1, PermissionMatrix::versionCount($roleId), 'the count agrees with the list');

    $audit = pm_audit($roleId);
    t_eq(1, count($audit), 'one audit row was written');
    t_eq('rbac.permission.assign', (string) $audit[0]['action'], 'the audit row names the action');
    $auditNew = json_decode((string) $audit[0]['new_values'], true);
    t_eq($setA, array_values((array) $auditNew['permissions']), 'the audit row records the set that landed');

    // --- A save of the same thing writes nothing ------------------------------

    $again = PermissionMatrix::save($roleId, $setA);
    t_ok(!$again['changed'], 'saving an identical set reports no change');
    t_ok($again['version_id'] === null, 'saving an identical set records no version');
    t_eq(1, count(PermissionMatrix::versions($roleId)), 'the history did not grow');
    t_eq(1, count(pm_audit($roleId)), 'the audit trail did not grow');

    // --- Removing one key -----------------------------------------------------

    $second = PermissionMatrix::save($roleId, $setB);
    t_ok($second['changed'], 'removing a key is a change');
    t_eq($setB, PermissionMatrix::grants($roleId), 'the role now carries the smaller set');
    t_eq([], $second['added'], 'nothing was added');
    t_eq(array_slice($setA, 1), $second['removed'], 'the keys that came off are named');
    t_eq('2 removed', PermissionMatrix::summarise($second), 'the change reads plainly');
    t_eq(2, count(PermissionMatrix::versions($roleId)), 'a second snapshot was written');

    // --- A key that cannot be granted -----------------------------------------

    $withJunk = PermissionMatrix::save($roleId, array_merge($setB, ['not.a.real.permission']));
    t_eq($setB, PermissionMatrix::grants($roleId), 'a key that is not in the catalogue is never written');
    t_ok(!$withJunk['changed'], 'a save whose only new key was junk reports no change');
    t_eq($setB, PermissionMatrix::normaliseKeys(array_merge($setB, ['not.a.real.permission']), $grantable), 'the cleaning rule drops it');

    // --- Protected system roles -----------------------------------------------

    $ownerId   = (int) Database::one("SELECT id FROM roles WHERE name = 'owner'")['id'];
    $managerId = (int) Database::one("SELECT id FROM roles WHERE name = 'manager'")['id'];

    foreach (['owner' => $ownerId, 'manager' => $managerId] as $label => $protectedId) {
        $before = PermissionMatrix::grants($protectedId);
        $threw  = false;
        try {
            PermissionMatrix::save($protectedId, array_merge($before, $grantable));
        } catch (InvalidArgumentException $e) {
            $threw = true;
        }
        t_ok($threw, 'the ' . $label . ' role refuses to be edited');
        t_eq($before, PermissionMatrix::grants($protectedId), 'the ' . $label . ' role carries exactly what it did');
    }

    // --- Putting a version back -----------------------------------------------

    $oldestVersionId = (int) Database::one(
        'SELECT id FROM role_permission_versions WHERE role_id = :r ORDER BY id ASC LIMIT 1',
        [':r' => $roleId]
    )['id'];

    $restored = PermissionMatrix::restore($oldestVersionId);
    t_ok($restored['changed'], 'putting a version back is a change');
    t_eq($setA, PermissionMatrix::grants($roleId), 'the role carries the recorded set again');
    t_eq(array_slice($setA, 1), $restored['added'], 'the keys that came back are named');

    $newest = PermissionMatrix::versions($roleId)[0];
    t_eq('rbac.permission.restore', (string) $newest['action'], 'the restore is recorded as a restore, not as an edit');
    t_eq(3, count(PermissionMatrix::versions($roleId)), 'the restore wrote its own snapshot, so nothing was undone quietly');

    $restoreAudit = pm_audit($roleId)[0];
    t_eq('rbac.permission.restore', (string) $restoreAudit['action'], 'the audit row names the restore too');

    // --- Putting back a version that recorded nothing ---------------------------

    // A role that carried nothing is a real state. Restoring it has to take
    // everything off, not be refused as an empty answer.
    $emptied = PermissionMatrix::save($roleId, []);
    t_ok($emptied['changed'], 'taking every permission off is a change');
    t_eq([], PermissionMatrix::grants($roleId), 'the role carries nothing');
    $emptyVersionId = (int) $emptied['version_id'];

    PermissionMatrix::save($roleId, $setB);
    t_eq($setB, PermissionMatrix::grants($roleId), 'the role carries a set again');

    $putBack = PermissionMatrix::restore($emptyVersionId);
    t_ok($putBack['changed'], 'putting back a version that recorded nothing is a change');
    t_eq([], PermissionMatrix::grants($roleId), 'the role carries nothing again');
    t_eq($setB, $putBack['removed'], 'the keys that came off are named');

    // Back to setA, so the final count below is predictable.
    PermissionMatrix::restore($oldestVersionId);

    // --- A version that is gone ------------------------------------------------

    $gone = false;
    try {
        PermissionMatrix::restore(999999999);
    } catch (InvalidArgumentException $e) {
        $gone = true;
    }
    t_ok($gone, 'restoring a version that does not exist is refused');

    // --- The guard --------------------------------------------------------------

    t_ok(!PermissionMatrix::canAssign(), 'with nobody signed in, nobody may hand out permissions');
    t_ok(!PermissionMatrix::canView(), 'with nobody signed in, nobody may read the screen');

} finally {
    // The role cascades to role_permissions and role_permission_versions. The
    // audit rows stay, which is the point of an append-only trail, and they are
    // keyed to an id that no longer exists rather than to a live role.
    Database::run('DELETE FROM roles WHERE name = :n', [':n' => OKV_PM_ROLE]);
}

t_eq(null, Database::one('SELECT id FROM roles WHERE name = :n', [':n' => OKV_PM_ROLE]), 'the scratch role is cleaned up');

// -----------------------------------------------------------------------------

fwrite(STDOUT, "\n$passed / $tests assertions passed.\n");
if ($passed !== $tests) {
    fwrite(STDERR, count($fails) . " failed.\n");
    exit(1);
}
fwrite(STDOUT, "All green.\n");
exit(0);

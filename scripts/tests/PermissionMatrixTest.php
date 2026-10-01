<?php
/**
 * scripts/tests/PermissionMatrixTest.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The pure half of the Permissions module. Nothing here touches a
 * database, so it runs in the unit suite that needs no MySQL at all.
 *
 * Two kinds of assertion live here:
 *
 *   1. The small rules the screen leans on. Which slot a key falls into, what a
 *      submitted list is cleaned to, what moved between two lists, and what a
 *      module's quick presets may offer. Every one of these is a rule that, if
 *      wrong, either hides a key the Owner wanted or writes one they did not.
 *
 *   2. The catalogue in includes/config/permissions.php against the migrations
 *      that seed the `permissions` table. A key the code checks but no migration
 *      inserts can never be granted to anyone, and the screen would show it
 *      unticked and switched off forever. This catches that at build time,
 *      which is where the note at the top of the config file asks for it.
 * -----------------------------------------------------------------------------
 */
require_once dirname(__DIR__, 2) . '/includes/classes/PermissionMatrix.php';

// --- The action slot of a key ------------------------------------------------

okv_test_eq('view', PermissionMatrix::actionOf('orders.view'), 'orders.view is a view key');
okv_test_eq('delete', PermissionMatrix::actionOf('combos.delete'), 'combos.delete is a delete key');
okv_test_eq('review', PermissionMatrix::actionOf('payments.proof.review'), 'a three part key reports its last segment, so review is not mistaken for view');
okv_test_eq('set', PermissionMatrix::actionOf('credit.limit.set'), 'credit.limit.set reports set, which no preset claims');
okv_test_eq('single', PermissionMatrix::actionOf('single'), 'a key with no dot is its own action');

// --- What a module is called -------------------------------------------------

okv_test_eq('Kitchen Runs', PermissionMatrix::moduleLabel('kitchen_runs'), 'an underscore reads as a space');
okv_test_eq('Make It Right', PermissionMatrix::moduleLabel('make_it_right'), 'every word is capitalised');
okv_test_eq('Rbac', PermissionMatrix::moduleLabel('rbac'), 'a single word module is left alone');

// --- Cleaning a submitted list -----------------------------------------------

$grantable = ['orders.view', 'orders.cancel', 'products.view'];

okv_test_eq(
    ['orders.cancel', 'orders.view'],
    PermissionMatrix::normaliseKeys(['orders.view', 'orders.cancel', 'orders.view'], $grantable),
    'a submitted list is deduplicated and sorted'
);
okv_test_eq(
    ['orders.view'],
    PermissionMatrix::normaliseKeys(['orders.view', 'orders.delete'], $grantable),
    'a key that cannot be granted is dropped rather than written'
);
okv_test_eq(
    ['orders.view'],
    PermissionMatrix::normaliseKeys(['orders.view', ['nested'], null, ''], $grantable),
    'a value that is not a plain string is dropped'
);
okv_test_eq(
    [],
    PermissionMatrix::normaliseKeys([], $grantable),
    'an emptied form means this role carries nothing, which is a real answer'
);

// --- What moved --------------------------------------------------------------

okv_test_eq(
    ['added' => ['products.view'], 'removed' => ['orders.cancel']],
    PermissionMatrix::diff(['orders.cancel', 'orders.view'], ['orders.view', 'products.view']),
    'a diff names the keys that went on and the keys that came off'
);
okv_test_eq(
    ['added' => [], 'removed' => []],
    PermissionMatrix::diff(['orders.view'], ['orders.view']),
    'an unchanged pair has an empty diff'
);

// --- Saying what moved, in a sentence ----------------------------------------

okv_test_eq('3 added, 1 removed', PermissionMatrix::summarise(['added' => ['a', 'b', 'c'], 'removed' => ['d']]), 'both halves are named');
okv_test_eq('2 added', PermissionMatrix::summarise(['added' => ['a', 'b'], 'removed' => []]), 'a grant alone reads plainly');
okv_test_eq('1 removed', PermissionMatrix::summarise(['added' => [], 'removed' => ['a']]), 'a revoke alone reads plainly');
okv_test_eq('No change', PermissionMatrix::summarise(['added' => [], 'removed' => []]), 'nothing moved reads honestly');
okv_test_eq('No change', PermissionMatrix::summarise([]), 'a shapeless diff does not throw');

// --- The quick presets a module offers ---------------------------------------

$presets = PermissionMatrix::crudPresets([
    'combos.view', 'combos.create', 'combos.edit', 'combos.publish', 'combos.delete',
]);
okv_test_eq(
    ['view', 'create', 'edit', 'delete'],
    array_keys($presets),
    'the presets read view, create, edit, delete whatever order the keys arrived in'
);
okv_test_eq(['combos.edit'], $presets['edit']['keys'], 'edit takes the edit key');
okv_test_ok(
    !in_array('combos.publish', array_merge(...array_column($presets, 'keys')), true),
    'a key no preset claims is offered by no preset'
);

$short = PermissionMatrix::crudPresets(['dashboard.view', 'dashboard.analytics.view']);
okv_test_eq(['view'], array_keys($short), 'a module with only view keys offers only a view preset');

okv_test_eq([], PermissionMatrix::crudPresets([]), 'a module with no keys offers no presets');

$withUpdate = PermissionMatrix::crudPresets(['pricing.view', 'pricing.update']);
okv_test_eq(['pricing.update'], $withUpdate['edit']['keys'], 'update lands in the edit slot, the same as edit');

// --- The catalogue in the code ------------------------------------------------

$catalogue = PermissionMatrix::codeCatalogue();
okv_test_ok(count($catalogue) > 50, 'the catalogue is the full list, not a stub');
okv_test_ok(isset($catalogue['orders.cancel']), 'a known key is in the catalogue');
okv_test_eq('orders', $catalogue['orders.cancel']['module'], 'a key knows its module');
okv_test_eq('Cancel an order', $catalogue['orders.cancel']['description'], 'a key carries its description');

$keys = array_keys($catalogue);
okv_test_eq('dashboard.view', $keys[0], 'the catalogue keeps the order the config file declares, so the screen reads the same way twice');

// The second read is the important one. The loader uses `require` inside a
// function, so the array is a function local; a `require_once` there would leave
// the variable undefined on every read after the first, and the catalogue would
// quietly collapse to empty. This is the assertion that would have caught it.
$again = PermissionMatrix::codeCatalogue();
okv_test_eq(count($catalogue), count($again), 'reading the catalogue twice gives the same list');

// --- The catalogue in the code against the migrations that seed it -----------

$seeded = '';
foreach (glob(dirname(__DIR__, 2) . '/migrations/*.sql') ?: [] as $migration) {
    $seeded .= (string) file_get_contents($migration);
}
$ungrantable = [];
foreach (array_keys($catalogue) as $key) {
    if (!str_contains($seeded, "'" . $key . "'")) {
        $ungrantable[] = $key;
    }
}
okv_test_eq(
    [],
    $ungrantable,
    'every permission the code checks is inserted by a migration, so the Owner can actually grant it'
);

// --- The Owner-only list sits inside the catalogue ---------------------------

// The config file is read inside a closure so the two arrays it defines are
// closure locals. A plain `require` at file scope would put them into the global
// scope of the test runner, where a later `require_once` elsewhere would skip
// the file and find nothing.
$orphanedOwnerOnlyKeys = (static function (): array {
    $path = dirname(__DIR__, 2) . '/includes/config/permissions.php';
    $OKV_PERMISSIONS = [];
    $OKV_OWNER_ONLY  = [];
    if (is_readable($path)) {
        require $path;
    }

    $known = [];
    foreach ((array) $OKV_PERMISSIONS as $rows) {
        foreach (array_keys((array) $rows) as $key) {
            $known[(string) $key] = true;
        }
    }

    $orphans = [];
    foreach ((array) $OKV_OWNER_ONLY as $key) {
        if (!isset($known[(string) $key])) {
            $orphans[] = (string) $key;
        }
    }
    return $orphans;
})();

okv_test_eq(
    [],
    $orphanedOwnerOnlyKeys,
    'every key the Manager is held back from is a permission that exists'
);


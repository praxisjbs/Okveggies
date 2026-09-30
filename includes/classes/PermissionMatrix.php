<?php
/**
 * includes/classes/PermissionMatrix.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The permission assignment module behind /admin/permissions.php.
 *
 * The Owner decides what each role carries. A role is a named bag of
 * dot-notation permission keys (module.entity.action), the keys live in the
 * `permissions` table, and the assignment lives in `role_permissions`. This
 * class reads and writes that assignment, and nothing else: it never invents a
 * permission key and never changes what a key means.
 *
 * Two things it does that the raw junction table does not:
 *
 *   1. Every write leaves a snapshot in `role_permission_versions`, with the
 *      keys that went on and the keys that came off, so a role can be put back
 *      the way it was. A permission change is the one edit that can lock a
 *      colleague out of their own job, so "what did it look like before?" has
 *      to be answerable.
 *   2. It reports drift between the catalogue in `includes/config/permissions.php`
 *      (what the code checks) and the `permissions` table (what the guard can
 *      actually grant). A key the code checks but the database has never heard
 *      of is inert: ticking it would do nothing, so the screen says so instead.
 *
 * The Owner role is never written here. It holds everything implicitly through
 * Rbac::hasPermission('*'), so there are no rows to edit and no way to lock the
 * Owner out of the module that hands out permissions.
 *
 * The pure half (normaliseKeys, diff, crudPresets, actionOf, moduleLabel,
 * summarise) touches no database and is covered by PermissionMatrixTest.php.
 * -----------------------------------------------------------------------------
 */

final class PermissionMatrix
{
    /** Roles whose meaning is fixed by the product. Never edited on this screen. */
    public const SYSTEM_ROLES = ['owner', 'manager'];

    /** The role that holds every permission implicitly. */
    public const OWNER_ROLE = 'owner';

    /** The quick presets offered per module, in the order they are offered. */
    public const CRUD_ACTIONS = ['view', 'create', 'edit', 'delete'];

    /** How a preset is labelled. */
    public const CRUD_LABELS = ['view' => 'View', 'create' => 'Create', 'edit' => 'Edit', 'delete' => 'Delete'];

    /** Longest history a caller may ask for in one read. */
    private const VERSION_LIMIT_MAX = 100;

    private static ?array $codeCache = null;

    // -------------------------------------------------------------------------
    // Catalogue
    // -------------------------------------------------------------------------

    /**
     * The catalogue as the developers wrote it: includes/config/permissions.php,
     * flattened to key => [key, module, description].
     *
     * Loaded with `require`, not `require_once`. A required file runs in the
     * scope of the function that required it, so the array it defines is a local
     * variable here, and `require_once` would leave it undefined on the second
     * call in the same request. Cached in a static, so the file is read once.
     */
    public static function codeCatalogue(): array
    {
        if (self::$codeCache !== null) {
            return self::$codeCache;
        }

        $OKV_PERMISSIONS = [];
        $path = dirname(__DIR__) . '/config/permissions.php';
        if (is_readable($path)) {
            require $path;
        }

        $out = [];
        foreach ((array) $OKV_PERMISSIONS as $module => $rows) {
            foreach ((array) $rows as $key => $description) {
                $key = (string) $key;
                $out[$key] = [
                    'key'         => $key,
                    'module'      => (string) $module,
                    'description' => (string) $description,
                ];
            }
        }
        self::$codeCache = $out;
        return $out;
    }

    /**
     * The catalogue as the database has it: the `permissions` table, keyed by
     * key. This is the list the guard can actually grant, because
     * role_permissions.permission_id is a foreign key into it.
     */
    public static function dbCatalogue(): array
    {
        $rows = Database::all('SELECT `id`, `key`, `module`, `description` FROM permissions ORDER BY `module`, `key`');
        $out = [];
        foreach ($rows as $row) {
            $key = (string) $row['key'];
            $out[$key] = [
                'key'         => $key,
                'id'          => (int) $row['id'],
                'module'      => (string) $row['module'],
                'description' => (string) ($row['description'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * The screen's view of the catalogue: every module in the order the code
     * declares it, each with its permissions. A key the code checks but the
     * database has never seen is carried through and marked, never dropped,
     * because an Owner looking at a missing tick deserves to know why.
     */
    public static function catalogue(): array
    {
        $code = self::codeCatalogue();
        $db   = self::dbCatalogue();

        $order = [];
        foreach ($code as $row) {
            if (!in_array($row['module'], $order, true)) {
                $order[] = $row['module'];
            }
        }
        // A module the database has and the code does not (a migration added a
        // key before the config file caught up) still gets a section, at the end.
        $extra = [];
        foreach ($db as $row) {
            if (!in_array($row['module'], $order, true) && !in_array($row['module'], $extra, true)) {
                $extra[] = $row['module'];
            }
        }
        sort($extra, SORT_NATURAL | SORT_FLAG_CASE);
        $order = array_merge($order, $extra);

        $modules = [];
        foreach ($order as $module) {
            $permissions = [];
            foreach ($code as $row) {
                if ($row['module'] !== $module) {
                    continue;
                }
                $inDb = isset($db[$row['key']]);
                $permissions[] = [
                    'key'         => $row['key'],
                    'description' => $inDb ? $db[$row['key']]['description'] : $row['description'],
                    'action'      => self::actionOf($row['key']),
                    'in_code'     => true,
                    'in_db'       => $inDb,
                    'grantable'   => $inDb,
                ];
            }
            foreach ($db as $row) {
                if ($row['module'] !== $module || isset($code[$row['key']])) {
                    continue;
                }
                $permissions[] = [
                    'key'         => $row['key'],
                    'description' => $row['description'],
                    'action'      => self::actionOf($row['key']),
                    'in_code'     => false,
                    'in_db'       => true,
                    'grantable'   => true,
                ];
            }
            if (!$permissions) {
                continue;
            }
            $modules[$module] = [
                'module'      => (string) $module,
                'label'       => self::moduleLabel((string) $module),
                'permissions' => $permissions,
                'presets'     => self::crudPresets(array_column($permissions, 'key')),
            ];
        }
        return $modules;
    }

    /**
     * Every key the guard could grant, in catalogue order. The save path filters
     * a submitted list against this, so a key that is not here is never written.
     */
    public static function grantableKeys(): array
    {
        return array_keys(self::dbCatalogue());
    }

    /**
     * Where the code and the database disagree about what exists. Both halves
     * are worth showing: a key the code checks but cannot grant is inert, and a
     * key the database holds but no code checks is dead weight the Owner would
     * otherwise tick and wonder about.
     *
     * @return array{missing_in_db: string[], missing_in_code: string[]}
     */
    public static function drift(): array
    {
        $code = array_keys(self::codeCatalogue());
        $db   = array_keys(self::dbCatalogue());
        $missingInDb   = array_values(array_diff($code, $db));
        $missingInCode = array_values(array_diff($db, $code));
        sort($missingInDb, SORT_STRING);
        sort($missingInCode, SORT_STRING);
        return ['missing_in_db' => $missingInDb, 'missing_in_code' => $missingInCode];
    }

    // -------------------------------------------------------------------------
    // Roles and their grants
    // -------------------------------------------------------------------------

    /** Every role, Owner first, then Manager, then the rest alphabetically. */
    public static function roles(): array
    {
        return Database::all(
            "SELECT id, name, slug, description, status, created_at
               FROM roles
           ORDER BY FIELD(name, 'owner', 'manager') DESC, name ASC"
        );
    }

    public static function role(int $roleId): ?array
    {
        return Database::one(
            'SELECT id, name, slug, description, status, created_at FROM roles WHERE id = :id',
            [':id' => $roleId]
        );
    }

    /**
     * Owner and Manager. Their meaning is fixed by the product (PRD Section
     * 17.1), so this screen shows them and never edits them.
     */
    public static function isSystemRole(?array $role): bool
    {
        if ($role === null) {
            return false;
        }
        return in_array(strtolower((string) ($role['name'] ?? '')), self::SYSTEM_ROLES, true);
    }

    public static function isOwnerRole(?array $role): bool
    {
        return $role !== null && strtolower((string) ($role['name'] ?? '')) === self::OWNER_ROLE;
    }

    /** The keys one role carries, sorted. */
    public static function grants(int $roleId): array
    {
        $rows = Database::all(
            'SELECT p.`key`
               FROM role_permissions rp
               JOIN permissions p ON p.id = rp.permission_id
              WHERE rp.role_id = :r
           ORDER BY p.`key`',
            [':r' => $roleId]
        );
        return array_map(static fn($row) => (string) $row['key'], $rows);
    }

    /** How many keys each role carries, keyed by role id. */
    public static function grantCounts(): array
    {
        $rows = Database::all('SELECT role_id, COUNT(*) AS total FROM role_permissions GROUP BY role_id');
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['role_id']] = (int) $row['total'];
        }
        return $out;
    }

    // -------------------------------------------------------------------------
    // Pure helpers (no database, unit tested)
    // -------------------------------------------------------------------------

    /** The action token of a key: the last dot segment. orders.cancel -> cancel. */
    public static function actionOf(string $key): string
    {
        $pos = strrpos($key, '.');
        return $pos === false ? $key : substr($key, $pos + 1);
    }

    /** "kitchen_runs" reads as "Kitchen Runs", the same way the Users screen says it. */
    public static function moduleLabel(string $module): string
    {
        return ucwords(str_replace('_', ' ', $module));
    }

    /**
     * The submitted list, cleaned: strings only, no duplicates, only keys that
     * exist in $grantable, sorted. Anything else is dropped rather than written.
     */
    public static function normaliseKeys(array $keys, array $grantable): array
    {
        $allowed = [];
        foreach ($grantable as $key) {
            $allowed[(string) $key] = true;
        }

        $out = [];
        foreach ($keys as $key) {
            if (!is_scalar($key)) {
                continue;
            }
            $key = (string) $key;
            if ($key === '' || !isset($allowed[$key])) {
                continue;
            }
            $out[$key] = true;
        }
        $out = array_keys($out);
        sort($out, SORT_STRING);
        return $out;
    }

    /**
     * What moved between two sorted key lists.
     *
     * @return array{added: string[], removed: string[]}
     */
    public static function diff(array $before, array $after): array
    {
        $added   = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));
        sort($added, SORT_STRING);
        sort($removed, SORT_STRING);
        return ['added' => $added, 'removed' => $removed];
    }

    /**
     * A one line account of a change: "3 added, 1 removed". Plain, because it
     * sits next to a colleague's name in the history list and in the toast.
     */
    public static function summarise(array $diff): string
    {
        $added   = count((array) ($diff['added'] ?? []));
        $removed = count((array) ($diff['removed'] ?? []));
        $parts   = [];
        if ($added > 0) {
            $parts[] = $added . ' added';
        }
        if ($removed > 0) {
            $parts[] = $removed . ' removed';
        }
        return $parts ? implode(', ', $parts) : 'No change';
    }

    /**
     * The quick presets a module can offer, built from the keys it actually
     * carries. A module with no delete key never shows a Delete chip, so the
     * control can never promise more than the module has.
     *
     * @return array<string, array{action: string, label: string, keys: string[]}>
     */
    public static function crudPresets(array $keys): array
    {
        $slots = ['view' => 'view', 'create' => 'create', 'update' => 'edit', 'edit' => 'edit', 'delete' => 'delete'];

        $found = [];
        foreach ($keys as $key) {
            $slot = $slots[self::actionOf((string) $key)] ?? null;
            if ($slot !== null) {
                $found[$slot][] = (string) $key;
            }
        }
        // The menu reads view, create, edit, delete, whatever order the keys came in.
        $out = [];
        foreach (self::CRUD_ACTIONS as $slot) {
            if (!empty($found[$slot])) {
                $out[$slot] = [
                    'action' => $slot,
                    'label'  => self::CRUD_LABELS[$slot],
                    'keys'   => array_values($found[$slot]),
                ];
            }
        }
        return $out;
    }

    // -------------------------------------------------------------------------
    // Guard
    // -------------------------------------------------------------------------

    /** Anyone who may look at the screen. */
    public static function canView(): bool
    {
        return Rbac::isLoggedIn() && (Rbac::isOwner() || Rbac::hasPermission('rbac.roles.view'));
    }

    /**
     * Who may change an assignment. The Owner, and only the Owner. Handing out
     * permissions is the act that decides what everyone else can do, so it is
     * not delegable by a permission: no role can be granted the right to grant.
     */
    public static function canAssign(): bool
    {
        return Rbac::isLoggedIn() && Rbac::isOwner() && Rbac::hasPermission('rbac.roles.edit');
    }

    // -------------------------------------------------------------------------
    // Writes
    // -------------------------------------------------------------------------

    /**
     * Replace one role's permission set, leaving a snapshot behind.
     *
     * The whole thing happens in one transaction: the junction rows, the
     * version, and the audit row land together or not at all. A save that
     * changed nothing writes nothing, so the history stays a record of changes
     * rather than of visits.
     *
     * @param  string $action Audit action, so a restore is distinguishable from an edit.
     * @return array{changed: bool, added: string[], removed: string[], permissions: string[], version_id: int|null}
     * @throws InvalidArgumentException When the role is missing or protected.
     */
    public static function save(int $roleId, array $requestedKeys, ?int $actorId = null, string $action = 'rbac.permission.assign'): array
    {
        $role = self::role($roleId);
        if ($role === null) {
            throw new InvalidArgumentException('Role not found.');
        }
        if (self::isSystemRole($role)) {
            throw new InvalidArgumentException('System roles are protected.');
        }

        $after  = self::normaliseKeys($requestedKeys, self::grantableKeys());
        $before = self::grants($roleId);

        if ($before === $after) {
            return ['changed' => false, 'added' => [], 'removed' => [], 'permissions' => $after, 'version_id' => null];
        }

        $diff = self::diff($before, $after);
        $pdo  = Database::getInstance()->getConnection();

        try {
            $pdo->beginTransaction();

            $pdo->prepare('DELETE FROM role_permissions WHERE role_id = :r')->execute([':r' => $roleId]);

            $insert = $pdo->prepare(
                'INSERT INTO role_permissions (role_id, permission_id)
                 SELECT :r, id FROM permissions WHERE `key` = :k'
            );
            foreach ($after as $key) {
                $insert->execute([':r' => $roleId, ':k' => $key]);
            }

            $pdo->prepare(
                'INSERT INTO role_permission_versions
                    (role_id, actor_user_id, permissions, permission_count, added, removed, action)
                 VALUES (:r, :a, :p, :c, :add, :rem, :act)'
            )->execute([
                ':r'   => $roleId,
                ':a'   => $actorId ?? Rbac::userId(),
                ':p'   => json_encode($after, JSON_UNESCAPED_UNICODE),
                ':c'   => count($after),
                ':add' => json_encode($diff['added'], JSON_UNESCAPED_UNICODE),
                ':rem' => json_encode($diff['removed'], JSON_UNESCAPED_UNICODE),
                ':act' => $action,
            ]);
            $versionId = (int) $pdo->lastInsertId();

            Audit::record(
                $action,
                'roles',
                $roleId,
                ['permissions' => $before],
                ['permissions' => $after, 'added' => $diff['added'], 'removed' => $diff['removed']],
                $actorId
            );

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('permission save: ' . $e->getMessage());
            throw new RuntimeException('We could not save those permissions.');
        }

        return [
            'changed'     => true,
            'added'       => $diff['added'],
            'removed'     => $diff['removed'],
            'permissions' => $after,
            'version_id'  => $versionId,
        ];
    }

    /**
     * A role's history, newest first, with the name of whoever made each change.
     * A deleted actor leaves the row intact and names nobody, which is honest:
     * the change happened, the person is gone.
     */
    public static function versions(int $roleId, int $limit = 20): array
    {
        $limit = max(1, min(self::VERSION_LIMIT_MAX, $limit));
        $rows  = Database::all(
            'SELECT v.id, v.role_id, v.permissions, v.permission_count, v.added, v.removed,
                    v.action, v.created_at,
                    TRIM(CONCAT(COALESCE(u.first_name, \'\'), \' \', COALESCE(u.last_name, \'\'))) AS actor_name
               FROM role_permission_versions v
          LEFT JOIN users u ON u.id = v.actor_user_id
              WHERE v.role_id = :r
           ORDER BY v.created_at DESC, v.id DESC
              LIMIT ' . $limit,
            [':r' => $roleId]
        );

        foreach ($rows as $i => $row) {
            $rows[$i]['added']       = self::decodeList($row['added'] ?? null);
            $rows[$i]['removed']     = self::decodeList($row['removed'] ?? null);
            $rows[$i]['permissions'] = self::decodeList($row['permissions'] ?? null);
        }
        return $rows;
    }

    /** How many changes this role has on record, for the "last 12 of 40" line. */
    public static function versionCount(int $roleId): int
    {
        $row = Database::one(
            'SELECT COUNT(*) AS total FROM role_permission_versions WHERE role_id = :r',
            [':r' => $roleId]
        );
        return (int) ($row['total'] ?? 0);
    }

    /**
     * Put a role back to a recorded version. The restore is itself a change, so
     * it writes its own snapshot: the history stays a list of what happened,
     * never a list of what was undone.
     *
     * @throws InvalidArgumentException When the version or its role is gone.
     */
    public static function restore(int $versionId, ?int $actorId = null): array
    {
        $row = Database::one(
            'SELECT id, role_id, permissions FROM role_permission_versions WHERE id = :id',
            [':id' => $versionId]
        );
        if ($row === null) {
            throw new InvalidArgumentException('That version no longer exists.');
        }

        // The column is JSON NOT NULL, so a value that will not decode means the
        // row is damaged rather than empty. An empty set is a real answer: a role
        // that carried nothing at the time is a state worth being able to put
        // back, so [] goes through to the save and takes everything off.
        $keys = json_decode((string) ($row['permissions'] ?? ''), true);
        if (!is_array($keys)) {
            throw new InvalidArgumentException('That version could not be read.');
        }

        return self::save((int) $row['role_id'], $keys, $actorId, 'rbac.permission.restore');
    }

    /** A JSON column read back as a list of strings. */
    private static function decodeList($value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $item) {
            if (is_scalar($item)) {
                $out[] = (string) $item;
            }
        }
        return $out;
    }
}

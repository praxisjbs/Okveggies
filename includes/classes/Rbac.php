<?php
/**
 * includes/classes/Rbac.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Role based access control for staff (the admin panel and the
 * staff-facing parts of the Pro Portal). A user has one or more roles; each role
 * carries a set of dot-notation permissions (module.entity.action). The Owner
 * role is a superuser and holds every permission.
 *
 * On login, auth loads the user's permission set into the session cache; every
 * later request reads from the session. The database is only touched on login
 * or when an admin edits a role (forceReload).
 *
 * The frontend gate (data-perm in HTML) is UX only. The server re-checks on
 * every action. Never trust the client.
 * -----------------------------------------------------------------------------
 */

final class Rbac
{
    private static array $cache = ['loaded' => false, 'permissions' => [], 'roles' => [], 'is_owner' => false];

    public static function init(): void
    {
        if (!empty($_SESSION['rbac']) && is_array($_SESSION['rbac'])) {
            self::$cache = $_SESSION['rbac'] + ['loaded' => true];
        }
    }

    public static function userId(): ?int
    {
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    public static function isLoggedIn(): bool
    {
        return self::userId() !== null;
    }

    /** Load a user's roles and permissions from the database into the session. */
    public static function loadFromDb(int $userId): void
    {
        $roles = Database::all(
            'SELECT r.name FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = :u',
            [':u' => $userId]
        );
        $roleNames = array_map(static fn($r) => $r['name'], $roles);
        $isOwner   = in_array('owner', $roleNames, true);

        if ($isOwner) {
            $perms = ['*'];
        } else {
            $rows = Database::all(
                'SELECT DISTINCT p.`key`
                   FROM user_roles ur
                   JOIN role_permissions rp ON rp.role_id = ur.role_id
                   JOIN permissions p ON p.id = rp.permission_id
                  WHERE ur.user_id = :u',
                [':u' => $userId]
            );
            $perms = array_map(static fn($r) => $r['key'], $rows);
        }

        self::$cache = [
            'loaded'      => true,
            'permissions' => $perms,
            'roles'       => $roleNames,
            'is_owner'    => $isOwner,
        ];
        $_SESSION['rbac'] = self::$cache;
    }

    public static function forceReload(): void
    {
        $id = self::userId();
        if ($id !== null) {
            self::loadFromDb($id);
        }
    }

    public static function roles(): array
    {
        return self::$cache['roles'] ?? [];
    }

    public static function isStaff(): bool
    {
        return !empty(self::$cache['roles']);
    }

    /** Does the current user hold a permission? Supports '*' and 'module.*'. */
    public static function hasPermission(string $perm): bool
    {
        $perms = self::$cache['permissions'] ?? [];
        if (in_array('*', $perms, true)) {
            return true;
        }
        if (in_array($perm, $perms, true)) {
            return true;
        }
        $module = strstr($perm, '.', true);
        if ($module !== false && in_array($module . '.*', $perms, true)) {
            return true;
        }
        return false;
    }

    public static function can(string $perm): bool
    {
        return self::hasPermission($perm);
    }

    public static function canAny(array $perms): bool
    {
        foreach ($perms as $p) {
            if (self::hasPermission($p)) return true;
        }
        return false;
    }

    /** Require a logged-in staff user, or stop with a 401 (API) or a redirect. */
    public static function requireAuth(): void
    {
        if (self::isLoggedIn() && self::isStaff()) {
            return;
        }
        self::deny(self::isLoggedIn() ? 403 : 401);
    }

    /** Require a permission, or stop with a 403 (API) or a redirect. */
    public static function requirePermission(string $perm): void
    {
        if (!self::isLoggedIn()) {
            self::deny(401);
        }
        if (!self::hasPermission($perm)) {
            self::deny(403);
        }
    }

    private static function deny(int $code): void
    {
        $isApi = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false
              || stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
        if ($isApi) {
            http_response_code($code);
            header('Content-Type: application/json; charset=utf-8');
            $msg = $code === 401 ? 'Please sign in.' : 'You do not have access to this.';
            echo json_encode(['status' => 'error', 'code' => $code === 401 ? 'unauthenticated' : 'forbidden', 'message' => $msg]);
            exit;
        }
        if ($code === 401) {
            header('Location: /admin/login.php');
            exit;
        }
        http_response_code(403);
        echo '<!doctype html><meta charset="utf-8"><title>No access</title>'
           . '<p style="font-family:sans-serif;padding:2rem">You do not have access to this page. '
           . '<a href="/admin/">Back to the dashboard</a>.</p>';
        exit;
    }

    /** Send a signed-in user to the right home: staff to admin, everyone else to the shop. */
    public static function redirectToLanding(): void
    {
        header('Location: ' . (self::isStaff() ? '/admin/' : '/'));
        exit;
    }

    /** Inline script that exposes the permission set to the browser (UX gating only). */
    public static function jsBootstrap(): string
    {
        $perms = self::$cache['permissions'] ?? [];
        return '<script>window.OKV=window.OKV||{};window.OKV.rbac={permissions:'
             . json_encode(array_values($perms)) . '};</script>';
    }
}

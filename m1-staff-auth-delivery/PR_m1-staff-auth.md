# M1 Part 1: staff authentication and RBAC

Base: `main`  ·  Head: `m1-staff-auth`

Staff sign in and role based access control for the admin panel. This is Part 1 of Milestone 1 (staff only). Customer accounts, OTP activation and the guest-cart merge are Part 2 and are not in this change.

## What this adds

**Sign in, sign out, password change** (`api/v1/auth.php`)
- Login validates the CSRF token, rate limits by IP and by identifier, treats the identifier as an email when it contains "@" and otherwise as a phone number, verifies the password with `password_verify` against `users.password_hash`, checks the account status is active, regenerates the session id, sets `$_SESSION['user_id']`, calls `Rbac::loadFromDb()`, records `last_login_at`, and returns JSON with a redirect to `/admin` for staff.
- Works with or without JavaScript: a fetch request gets JSON, a plain form post gets a real 302 redirect. Failures show a plain message, never an exception. An unknown identifier runs the same hash comparison as a real one, so timing does not reveal who exists.
- `logout` destroys the session. `change_password` lets a signed-in staff member set a new password (current, new, confirm), then regenerates the session id.

**Password helper** (`includes/classes/Password.php`)
- bcrypt at `BCRYPT_COST`, `verify`, `needsRehash`, and the shared policy: at least 10 characters (from `PASSWORD_MIN_LENGTH`, floored at 8), not a common password, not the person's own email or phone, at most 72 bytes. Customers reuse it in Part 2.

**First Owner on a shell-less host** (`public/setup.php`)
- A token-guarded one-time endpoint with the same fail-closed shape as `public/migrate.php`: returns 404 when `SETUP_TOKEN` is unset or wrong, checks CSRF on the POST, creates the first Owner from name, email, phone and password only when no staff user exists, and refuses once one does. Remove `SETUP_TOKEN` from the server `.env` after the Owner is made.

**Staff management** (`api/v1/users.php`, `api/v1/rbac.php`, `admin/users.php`, `admin/account.php`)
- `api/v1/users.php`: list, create, set or reset a password, switch an account on or off, and set a role, each gated by the `users.*` permissions. Guards against removing the last active Owner and against changing your own status or role.
- `api/v1/rbac.php`: `list_roles`, gated by `rbac.roles.view`.
- `admin/users.php` is the Owner-only screen where the Owner creates the Manager. `admin/account.php` is where a staff member changes their own password.

**Admin shell** (`includes/components/admin/sidebar.php`, `header.php`, `footer.php`; `admin/index.php`)
- A permission-gated sidebar rendered from `includes/config/nav.php`: an item the user cannot use is not rendered, and a whole section drops when all of its items are hidden. This is a real server-side gate, not only the client `okv-rbac.js` one. The sidebar carries the signed-in name and a CSRF-protected sign out.

Staff records carry `user_type = 'staff'` plus a role. No schema change (the `users`, `roles` and `user_roles` tables were already in place from M0), and no change to the deployment pipeline.

## How it was tested

Stood up a scratch MariaDB 10.11 in the cloud, ran `php scripts/migrate.php` (59 tables, Owner 57 permissions, Manager 42), then:

- `php scripts/tests/run.php` : 42 unit assertions (Money, OrderNumber, and the new `PasswordTest.php`).
- `php scripts/tests/auth_db_test.php` : 26 assertions for password verification against a stored hash, the rate-limit lockout after 5 failed tries, and RBAC gating (Owner is a superuser, Manager is scoped).
- `bash scripts/smoke_roles.sh` : 26 checks over real HTTP. The setup endpoint fails closed without a token, creates the Owner once and refuses the second time; a guest at `/admin/` is redirected to sign in; the Owner signs in, reaches the dashboard and Users and Roles, and adds the Manager; the Manager signs in, is refused Users and Roles (403) and never sees it in the nav; a signed-in password change works and the old password stops working; the login locks an identifier after 5 failed tries; and sign out ends the session.

`php -l` is clean on every touched file. A grep for the em dash character and the banned words returns nothing in the changed files.

## Notes for review

- The login and throttle values are tunable in `.env` (`PASSWORD_MIN_LENGTH`, `LOGIN_MAX_PER_IDENTIFIER`, `LOGIN_MAX_PER_IP`, `LOGIN_WINDOW_SECONDS`), documented in `.env.example`.
- No push to `main`, and the deployment setup is untouched.

🤖 Generated with [Claude Code](https://claude.com/claude-code)

https://claude.ai/code/session_01WtPSCHa384VpAoViRVU3Z7

# OK Veggies, Build Progress

Living tracker for the Phase 1 build. Update it at the end of every working session. It answers three questions at a glance: what is done, what is in progress, and what is next.

**How to use this file.** Pick the next unchecked item in the current milestone. Read the PRD section named beside it. Ask your five clarifying questions. Build, test, tick the boxes, and add a session-log entry at the bottom. A milestone is done when all its boxes are ticked and its tests pass.

**Status key:** `[ ]` not started, `[~]` in progress, `[x]` done and tested.

**Definition of done (every feature):** acceptance criteria met, unit tests written and passing, `php -l` clean, role smoke test passing, works on a phone and a laptop, and it meets the PRD Section 2 experience principles. No em dash, no jargon, on brand.

---

## Current focus

**Milestone M1, Authentication and RBAC** (right after the first go-live). M0 (foundation) is complete and verified, and the deployment pipeline is built (see Deployment below). The first production deploy of M0 is in progress: the code is on GitHub `main`, the GitHub Actions secrets are set, and what remains are the one-time cPanel steps (create the database, place the server `.env`, clear the old landing page from `public_html`, confirm PHP 8.3). After that, M1 is sign in (phone or email plus password), OTP activation, and the Owner and Manager roles.

---

## Deployment (how this project ships)

The host is a Truehost shared cPanel account with **SFTP only, no SSH shell**, so migrations cannot be run from a server terminal. The pipeline works around that:

- A push to `main` triggers `.github/workflows/deploy.yml`, which uploads the files over SFTP, then calls the token-guarded web runner `public/migrate.php` to apply migrations on the server. No shell needed.
- `Migrator` (`includes/classes/Migrator.php`) is the shared migration engine used by both `scripts/migrate.php` (CLI, for local work) and `public/migrate.php` (web, on the server).
- `vendor/` and the built `assets/css/tailwind.css` and `assets/js/*.min.js` are committed, because the server has no Composer or Node. (`vendor/` was built in the cloud and shipped, because the dev machine's Composer is blocked by a broken `http_proxy` env var.)
- Docroot is `/home/ibbbnlso/public_html`. PHP is 8.3 (MultiPHP Manager). The error log lives under `/home/ibbbnlso/logs/`.
- Secrets live only in GitHub Actions and the server `.env`, never in the repo. The `MIGRATE_TOKEN` in the server `.env` must match the GitHub secret of the same name. Full setup, the secret list, and a manual FileZilla fallback are in `docs/DEPLOYMENT.md`.
- The deploy never deletes remote files, so the server `.env` and everything under `uploads/` survive every deploy.

---

## Phase 1 milestones

### M0. Foundation  (done, verified 26 Aug 2026)
- [x] Folder structure and stub files created (storefront at root, /admin, /pro, api/v1, includes, migrations, scripts)
- [x] `composer.json` (dompdf, PhpSpreadsheet, PHPMailer). Run `composer install` once to populate and commit `vendor/`
- [x] `package.json`, `tailwind.config.js` with the brand tokens; `npm run build` produces `assets/css/tailwind.css`
- [x] `.env.example` with the full key set (DB, SMTP, Paystack, security, WhatsApp)
- [x] `.htaccess`, `.user.ini`, `uploads/.htaccess` (deny PHP execution)
- [x] `includes/config` (env.php, db.php, permissions.php, nav.php) and `includes/bootstrap.php`
- [x] Core classes: Database, Rbac, Csrf, RateLimiter, Money, OrderNumber, Settings, Mail, Paystack, Otp, Uploads
- [x] Migrations 000 to 006, idempotent; `php scripts/migrate.php` runs clean (verified against MariaDB 10.11)
- [x] `scripts/migrate.php`, `deploy.sh`, `verify.sh`, `backup.sh`, and a dependency-free test runner
- [x] Tests: Money and OrderNumber unit tests pass (28 assertions); full-stack integration verified; storefront home renders live data

### M1. Authentication and RBAC
Delivered in two parts. Part 1 is staff sign in and RBAC. Part 2 is customer accounts (household and business) and OTP activation. Guest-cart merge moves to M4, where the basket is built (nothing writes a guest cart yet); a documented seam is left for it.
- [x] Login by phone or email plus password; hardened sessions. Staff in Part 1, customers reuse the same endpoint in Part 2. Phone is normalised to E.164 on both sides so "login by phone" matches
- [x] OTP activation (email now), required before pay-on-delivery. Part 2. Issue and single-use verify, rate limited, activated state stamped on `users.email_verified_at`
- [x] Owner and Manager roles seeded; permission checks on admin. Part 1. The Pro Portal reuses the same RBAC engine when it is built (M8)
- [x] Smoke tests for guest, household, business, Manager, Owner. Guest, Manager and Owner in Part 1; household and business in Part 2 (registration, login routing, activation and reset over HTTP)

### M2. Catalogue and pricing
- [ ] Categories (5) and units seeded; products seeded (Garlic unit corrected to kg)
- [ ] Storefront: shop grid, search, filter by category, product page with "Goes well with"
- [ ] Admin: products list, create/edit, availability toggle
- [ ] Admin: pricing table with inline edit, bulk apply, auto price history
- [ ] CSV / Excel import and export (PhpSpreadsheet)
- [ ] Tests: price change writes history; import/export round-trips

### M3. Combos
- [ ] Combo builder with product picker and live component total
- [ ] Publish / unpublish; The Stew Combo seeded
- [ ] Storefront combos page and combo detail with one-tap add
- [ ] Tests: component total maths

### M4. Basket and checkout
- [ ] Guest basket with session token, merges on login
- [ ] Basket holds products and combos; min/increment respected; mini-cart
- [ ] Checkout: details, delivery day picker (eligibility, cutoff, lead), payment choice
- [ ] Tests: delivery-day eligibility; basket totals

### M5. Payments
- [ ] Paystack init and verify, all channels
- [ ] Webhook endpoint, signature check, idempotent inbox, status history
- [ ] Deposit (configurable percentage) and balance; pay-on-delivery recorded by admin with proof
- [ ] Refunds; settlement reconciliation view
- [ ] Tests: webhook signature and idempotency; deposit and balance maths

### M6. Delivery and the Order Trail
- [ ] Allowed days, cutoff, lead, zones (admin-managed), exceptions
- [ ] Order lifecycle and status history
- [ ] Public Order Trail page by token link (no login), and the "Sourced [day] from [state]" line
- [ ] Admin delivery-day manifest / packing list, grouped by zone, printable
- [ ] Tests: order-number generation; status transitions

### M7. Kitchen Runs
- [ ] Request flow, four input modes (catalogue, priced-by-us, priced-by-customer, upload)
- [ ] Already-priced confirm path; open-budget trust mode with admin deposit and optional cap
- [ ] Admin quote workflow; convert to order
- [ ] Tests: quote totals; convert-to-order

### M8. Pro Portal and credit
- [ ] Pro dashboard, saved kitchen lists, standing-order placeholder
- [ ] Credit: self-serve application, admin approval, manual grant and limit
- [ ] Credit orders draw on limit; outstanding / aging view
- [ ] Tests: credit limit and balance

### M9. Notifications and contact
- [ ] Email templates and delivery: placed, payment confirmed, dispatched, delivered, trail link
- [ ] Floating support widget: WhatsApp click-to-chat and contact form
- [ ] Contact messages surface in admin
- [ ] Tests: template render; notification queued on order events

### M10. Trust and Make It Right
- [ ] Report an issue against an order (category, note, photos)
- [ ] Admin resolves (refund, credit, replacement) and customer sees the outcome
- [ ] Trust signals on storefront and checkout
- [ ] Tests: issue lifecycle

### M11. Admin dashboard and analytics
- [ ] Today's orders, revenue, payments due, credit outstanding
- [ ] Charts: sales over time, top products, order-share by category (fixed colours)
- [ ] Command palette and keyboard shortcuts

### M12. Content pages
- [ ] Home (documentary hero, featured combos, categories)
- [ ] Our Story, How It Works, FAQ, Terms, Privacy, Delivery Policy (editable in admin)

### M13. Hardening, QA and go-live
- [ ] Full role smoke suite green
- [ ] Accessibility pass (WCAG 2.1 AA checklist)
- [ ] Performance pass (mobile first paint, image optimisation)
- [~] Deploy pipeline built and verified: SFTP upload workflow + token-guarded web migration runner (`public/migrate.php`). First production go-live in progress (see Deployment and the session log)
- [ ] Repository set to private (on completion)

---

## Open questions and blockers

- PHP version confirmed: 8.3 (pinned in composer.json).
- Delivery cutoff confirmed: 16:00 (4pm), 1 day lead, and fully editable in admin Settings.
- Delivery zones: 30 Lagos zones seeded, editable in the admin Delivery screen.
- Product content: descriptions now seeded for all 24 products; one image each is in place, expand to five per product as the client delivers them.

---

## Session log (newest first)

### 27 Aug 2026, M1 Part 2: customer accounts and OTP activation
- Built customer registration and the account area on branch `m1-customer-auth`, reusing the Part 1 auth controller. New `Phone` helper normalises Nigerian numbers to E.164 (`+234...`) on registration and on login lookup, retrofitted into the Part 1 login so staff and customers match. New `Auth` helper holds the shared sign-in logic (find by phone or email, landing path, session start) so it is testable outside the HTTP handler, and `Customer` holds the signed-in customer session (household or business, activation flag).
- `api/v1/auth.php`: added `register` (household or business, bcrypt, a `business_customers` row with an optional type and an optional credit request; a duplicate email or phone returns a safe `account_exists` carrying only the email the person typed, for a sign-in deeplink; then auto sign-in and the activation code is sent), `forgot_password` (the same answer whether or not the email is registered) and `reset_password` (verify the code, then set a new password). Login now routes households to `/`, business customers to `/pro`, staff to `/admin`, and transparently upgrades an older password hash on sign in.
- `api/v1/otp.php`: activation. `request` issues and emails a code for the signed-in customer (rate limited: 5 per identifier per 15 minutes, a 60 second resend cooldown); `verify` stamps `users.email_verified_at`. The identifier comes from the session, never the request. If `Mail::send` cannot send (no SMTP), the response says so plainly, never a silent success.
- `api/v1/account.php`: delivery addresses (add, edit, delete, set default) and a profile edit, each scoped to the signed-in customer's own rows.
- Pages: `account.php` (sign in, create account, and the signed-in home with details, an orders placeholder and addresses), `public/auth/activate.php` (enter the code, resend with a cooldown) and `public/auth/password_reset.php` (two steps). All work without JavaScript (real redirects); with it, `assets/js/account.js` adds the native-app feel (slide-up sheets, a resend countdown, the existing-account modal, no full reloads). A sticky activation banner shows until the account is activated. New component `includes/components/shop/activation_banner.php`.
- Data: migration `007_auth_email_templates.sql` seeds the activation and password-reset email templates (006 only had order and payment ones); `008_normalise_user_phones.sql` brings any existing `users.phone` into E.164 (idempotent). Both transactional with verification queries.
- Decisions this session (from the Owner): business signup collects name plus an optional type and an optional credit request; password reset is an email OTP; after signup we sign the person in and show a floating activate banner; a duplicate registration shows a sign-in modal that prefills only what they typed and reveals nothing else; guest-cart merge waits for M4; phone is E.164.
- Tests, all green: `scripts/tests/PhoneTest.php` (phone normalisation, under the unit runner, which is green at 63 assertions), `scripts/tests/customer_auth_db_test.php` (28 assertions: find by phone in every shape and by email, landing path per account type, the full one-time-code lifecycle), and `scripts/tests/customer_http_test.php` (31 assertions over HTTP against the real endpoints: register a household and a business, a refused duplicate, sign in by email and by phone in three shapes, activation, and password reset). `php -l` clean on every touched file. No em dash, no banned words. Rebuilt `assets/css/tailwind.css` and the minified JS. Caught and fixed a real CSS bug in review (the sheet overlay used `flex`, which beat the `hidden` attribute, so the modal never hid). Screenshots at 390px and 1440px confirm the brand and the responsive layout.
- Also handled a Part 1 review note: `Password::needsRehash` is now wired into login. `scripts/verify.sh` reachability checks pass; its path-deny checks need the deployed Apache and were not run in the container.

### 27 Aug 2026, M1 Part 1: staff authentication and RBAC
- Built staff sign in (`api/v1/auth.php`: login, logout, change_password). Login validates CSRF, rate limits by IP and by identifier (5 per identifier, 20 per IP over 15 minutes, all tunable in `.env`), treats the identifier as email when it contains "@" else phone, verifies with `password_verify` against `users.password_hash`, checks the account is active, regenerates the session id, sets `$_SESSION['user_id']`, loads the RBAC set, records `last_login_at`, and returns a JSON redirect to `/admin` for staff. A plain form post still works with no JavaScript (the server sends a real redirect), and an unknown identifier costs the same time as a real one. Wired `admin/login.php` with `assets/js/auth.js`.
- Added a `Password` helper (`includes/classes/Password.php`): bcrypt at `BCRYPT_COST`, verify, needs-rehash, and the shared policy (at least 10 characters, not a common password, not the person's own email or phone). Customers reuse it in Part 2.
- First Owner on a shell-less host: `public/setup.php`, a token-guarded one-time endpoint with the same fail-closed 404 shape as `public/migrate.php`. It creates the first Owner from name, email, phone and password only when no staff user exists, and refuses once one does. Added `SETUP_TOKEN` to `.env.example`. Remove `SETUP_TOKEN` from the server `.env` once the Owner is made.
- Staff management: `api/v1/users.php` (list, create, set or reset password, switch on or off, set role) gated by `users.*`, and `api/v1/rbac.php` (`list_roles`) gated by `rbac.roles.view`. Built `admin/users.php` (Owner only, this is how the Owner creates the Manager), `admin/account.php` (a staff member changes their own password), the shared admin shell (`header.php`, `footer.php`), and the permission-gated sidebar (`sidebar.php` renders `nav.php`, hides what the user cannot reach, and carries a CSRF sign out). Staff records carry `user_type = 'staff'` plus a role.
- Tests: `scripts/tests/PasswordTest.php` (unit, under the runner), `scripts/tests/auth_db_test.php` (password verify, rate-limit lockout, RBAC gating against a scratch database) and `scripts/smoke_roles.sh` (the setup endpoint, a guest at `/admin/` redirected to sign in, the Owner reaching the dashboard and Users and Roles, the Manager refused Users and not seeing it in the nav, the password change, and the login lockout). All green: 42 unit assertions, 26 database assertions, 26 smoke checks. `php -l` clean on every touched file. No em dash, no banned words. Rebuilt `assets/css/tailwind.css` and the minified JS in the cloud, as before.
- No schema change (the `users`, `roles` and `user_roles` tables were already in place from M0) and no change to the deployment pipeline. Built and verified against MariaDB 10.11 on branch `m1-staff-auth`. Not for customer accounts, which are Part 2.

### 26 Aug 2026, deployment pipeline and first push
- Pushed M0 to GitHub `main` (`praxisjbs/Okveggies`).
- The host turned out to be SFTP-only with no shell, so built a shell-less deploy path: a shared `Migrator` engine, a token-guarded web migration runner (`public/migrate.php`), and an SFTP GitHub Actions workflow that uploads then calls the runner. Verified end to end against MariaDB (404 without the token, applies migrations with it); the refactor kept the unit tests at 28/28. Added `docs/DEPLOYMENT.md`.
- Shipped `vendor/` (built in the cloud and transferred, because the dev machine's Composer is blocked by a broken `http_proxy` env var). Provided the PowerShell fixes for the proxy and for generating `MIGRATE_TOKEN` and `APP_ENCRYPTION_KEY`.
- Set the 7 GitHub Actions secrets (`SFTP_HOST`, `SFTP_PORT`, `SFTP_USER`, `SFTP_PASSWORD`, `SFTP_REMOTE_PATH` = `/home/ibbbnlso/public_html`, `APP_BASE_URL`, `MIGRATE_TOKEN`). First go-live pending the cPanel steps: create the database, place the server `.env` with a matching `MIGRATE_TOKEN`, clear the old Olitt/Next landing page out of `public_html`, and confirm PHP 8.3.

### 26 Aug 2026, M0 foundation built
- Scaffolded the full repository: storefront at root, /admin, /pro, api/v1 controllers, includes (config, 11 classes, functions, components), 7 migrations, scripts and tests.
- Ported the drafted schema into migrations and added the PRD tables (RBAC catalogue, delivery zones, kitchen runs, pairings, OTP, contact, Make It Right, content pages, counters, rate limits). 59 tables migrate cleanly.
- Seeded RBAC (Owner 57 permissions, Manager 42), 5 categories, 4 units, 30 Lagos zones, order settings, 24 products with descriptions, The Stew Combo, content pages, email templates, pairings.
- Wrote and verified the core classes; Money and OrderNumber unit-tested (caught and fixed a counter that skipped 1). Storefront home renders live data with the brand tokens.
- Confirmed by the owner: PHP 8.3; cutoff 4pm and 1 lead day, editable in admin; full Lagos zones; product descriptions seeded.

### 26 Aug 2026, foundation planning
- Reverse-engineered the reference architecture (`bureau.lpc.cm`) and confirmed conventions.
- Ran two decision rounds with the Owner (25 architecture and product decisions). Locked in `docs/`.
- Read the OK Veggies Brand Architecture bible v1.0; captured tokens, voice, house laws (no em dash, no jargon, numerals and units always) and the three-surface model (`/`, `/pro`, `/admin`).
- Wrote `docs/PRD.md` v1.0, this `PROGRESS.md`, `README.md` and `CLAUDE.md`.
- Reviewed the drafted `ok_veggies_schema.sql` and product seed. Both approved as the backbone, with additions listed in PRD Section 20.2. Approved The Stew Combo.
- Next: scaffold the repository (M0), then composer / npm setup.

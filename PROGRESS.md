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
- [ ] Login by phone or email plus password; hardened sessions
- [ ] OTP activation (email now), required before pay-on-delivery
- [ ] Owner and Manager roles seeded; permission checks on admin and pro
- [ ] Smoke tests for guest, household, business, Manager, Owner

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

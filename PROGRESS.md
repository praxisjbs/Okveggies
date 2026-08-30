# OK Veggies, Build Progress

Living tracker for the Phase 1 build. Update it at the end of every working session. It answers three questions at a glance: what is done, what is in progress, and what is next.

**How to use this file.** Pick the next unchecked item in the current milestone. Read the PRD section named beside it. Ask your five clarifying questions. Build, test, tick the boxes, and add a session-log entry at the bottom. A milestone is done when all its boxes are ticked and its tests pass.

**Status key:** `[ ]` not started, `[~]` in progress, `[x]` done and tested.

**Definition of done (every feature):** acceptance criteria met, unit tests written and passing, `php -l` clean, role smoke test passing, works on a phone and a laptop, and it meets the PRD Section 2 experience principles. No em dash, no jargon, on brand.

---

## Current focus

**Milestone M3, Combos. In progress, split into three PRs.** M0 through M2 are complete. M3 is being built in three PRs so the domain, the admin builder and the storefront can proceed in parallel without stepping on each other.

- **PR1 (this branch, `m3-pr1-combos-domain`).** The domain foundation. `Combos` class with the full CRUD, the sell-price history writer that mirrors `Pricing::change` in one transaction, publish and unpublish with the "no components, no price" gate, the component-total maths, `isLossMaking()` and `customerSaving()` for the builder's admin-only flag, `isBuyableNow()` for the availability window, and the same referenceCount / delete rule as products. Storefront read helpers on `Catalogue`: `combos()`, `featuredCombos()`, `comboBySlug()`, `comboComponents()`, all filtered on active and inside the window. Unit tests. No schema change (M0 carries `combo_packages`, `combo_package_items`, `combo_price_history`, `cart_items.combo_package_id` and `order_items.combo_package_id` already). No UI yet.
- **PR2 (next).** Admin combo builder: `admin/combos.php`, `api/v1/combos.php`, `assets/js/admin-combos.js`. Product picker, live component total, sell price, publish toggle, availability window, photo. Consumes PR1 only.
- **PR3 (next).** Storefront combos page and combo detail: `combos.php`, `combo.php`, one-tap add-to-basket via a new `Basket::addCombo()` + `api/v1/cart.php` `add_combo` action, `combo_card.php` component, featured combos on the home page. Consumes PR1 only.

Seven clarifying questions were answered before any code was written (loss-making combos are flagged in red to the Manager only, never to the customer; buyability checks the active flag and availability window only, not component availability; a draft is `is_active = 0`, no new column; combo internals respect the unit's decimal rule but not the product's customer-facing minimum or increment; a combo in the basket is one line, fanning out into `order_item_components` at order-snapshot time in M4/M5; the existing `combo_packages.image_url` column carries the hero photo and falls back to the first component's primary photo; combo price history mirrors products exactly, opening row null with reason "Opening price", later changes take an optional reason).

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
Delivered in two parts. The storefront half arrived first and was audited and corrected. The admin and pricing half, which is what the business runs on every week, was built after it.
- [x] Categories (5) and units seeded; products seeded (Garlic unit corrected to kg). Verified against a live MariaDB 10.11: 5 categories, 4 units, 24 products, 24 images, 24 availability rows, 20 pairings, Garlic on unit 1, kg
- [x] Storefront: shop grid, search, filter by category, product page with "Goes well with". Audited and corrected on 29 Aug
- [x] Admin: products list, create/edit, availability toggle. Search, category and on-shop filters; photos with a main one, reordering and removal; a product held by an order, a combo, a pairing or its price history is switched off rather than deleted
- [x] Admin: pricing table with inline edit, bulk apply, auto price history. Every change goes through `Pricing::change()`, which closes the open history row and opens a new one in the same transaction. Bulk moves take a percentage or a flat amount, preview before they write, and are all or nothing
- [x] CSV / Excel import and export (PhpSpreadsheet). Export is `.xlsx`, import accepts `.xlsx` and `.csv`. An import previews first and writes nothing until confirmed, then applies in one transaction. An unknown SKU is reported, never created; an empty price cell means leave that product alone
- [x] Tests: price change writes history; import/export round-trips. 47 pricing assertions in the unit runner and 76 against a live database, including the round trip
- [ ] Carried forward from the audit, not M2 acceptance criteria: paginate the shop grid before the catalogue outgrows one query; give products a real source region instead of the one site-wide setting; replace the regex-over-SQL seed assertions with assertions against a migrated database; decide whether an empty category is hidden or labelled as still being sourced

### M3. Combos
- [~] Combo builder with product picker and live component total (PR1: domain and maths done, admin UI in PR2)
- [~] Publish / unpublish; The Stew Combo seeded (PR1: publish and unpublish done and gated on components + price; The Stew Combo was seeded in M0 migration 005)
- [ ] Storefront combos page and combo detail with one-tap add (PR3)
- [~] Tests: component total maths (PR1: 39 unit assertions in CombosTest.php; database-side history assertions arrive with the admin controller in PR2)

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

### 30 Aug 2026, M3 PR1: combos domain, sell-price history and the component-total maths

Split M3 into three PRs (PR1 domain, PR2 admin builder, PR3 storefront) so two other chats can pick up PR2 and PR3 in parallel without stepping on each other. This PR is the foundation both of them consume, and the seven clarifying questions were answered before a line of code was written.

Also tightened the build contract in `CLAUDE.md`: every clarifying question now has to offer three concrete answer options (A, B, C) and end with the recommendation, not an open-ended "what do you want?". This is how the discovery loop actually runs in practice and it makes an answer a one-tap pick rather than a paragraph.

Money logic first, tested before it was wired to anything.

- `Combos` (`includes/classes/Combos.php`) is the one place a combo is created, edited, priced, published or composed. `create` inserts and, when the combo arrives with a price, opens its history with a null old price and reason "Opening price". `update` never touches the sell price directly: a price bundled with an edit still flows through `changePrice`, which mirrors `Pricing::change` exactly (close the open history row, open a new one, update `combo_packages.price_subunit`, all in one transaction, no history row if the price did not actually move). `publish` refuses when the combo has no components or no sell price, so a half-built combo cannot leak onto the shop. `delete` refuses when an order, a basket or the combo's own history holds it, mirroring the product rule so no history is ever destroyed. A combo the Manager needs to retire is switched off, not deleted, and it leaves the shop straight away.
- `sumComponents`, `isLossMaking` and `customerSaving` are pure and integer-only. The builder in PR2 uses `componentTotalDetailed` for the live per-line breakdown and `isLossMaking` for the admin-only red flag when the sell price falls below the components. The customer never sees the component total; when the sell price is above the components, `customerSaving` powers the "You save ₦X" line on the combo card (never a negative number).
- `isBuyableNow` is the shared truth for the availability window (active plus today inside `available_from` and `available_until`, both nullable and inclusive). Storefront reads on `Catalogue::combos()`, `Catalogue::featuredCombos()` and `Catalogue::comboBySlug()` apply the same window filter in one SQL round-trip, so the home page, the combos grid and the detail page cannot disagree about what is on the shop. Component availability is deliberately not gated (M3 decision Q2): the shop shows the combo, the packing team handles a substitution or a Make It Right at fulfilment time.
- `cleanComponentQuantity` respects only the unit's `allows_decimal` rule, so 0.25 kg ginger keeps its shape and 1.5 bunch rounds up to 2. The product's customer-facing `minimum_quantity` and `quantity_increment` do not apply to Manager-composed internals, which is why the seed sits below the customer minimum on purpose.
- No new migration was needed. The M0 schema already carries `combo_packages`, `combo_package_items`, `combo_price_history`, `cart_items.combo_package_id` and `order_items.combo_package_id`.

Verified: 163 unit assertions across the repo pass (up from 124; the 39 new ones are the combos maths, quantity rules and availability window, all driven by the seeded Stew Combo prices so a change to the seed cannot silently drift the maths). `php -l` clean across every non-vendor PHP file, no em dash anywhere in the shipped copy, no banned jargon in `Combos.php`. Database-side history assertions (create writes a history row, changePrice opens and closes rows, publish gates on components + price, delete refuses when anything holds the combo) arrive with the admin controller in PR2 so a live database is not needed to run PR1's tests.

### 29 Aug 2026, M2 part 2: admin catalogue, pricing and the spreadsheet round trip

Built the half of M2 that had not been started: admin products, the pricing table, and import and export. Seven clarifying questions were answered before any code was written (bulk apply takes both a percentage and a flat amount; an import previews then applies all or nothing; a reason is optional inline and required on a bulk move; export is `.xlsx` and import takes both; a referenced product is switched off rather than deleted; full photo management; effective-now prices only, no scheduling).

Money logic first, tested before it was wired to anything.

- `Pricing` is the only place a price may change. It closes the open history row, writes a new one (old price, new price, reason, who, when) and updates the product, all in one transaction. Nothing else in the codebase writes `current_price_subunit`, so the history cannot be bypassed. Setting the price a product already has is not an error and writes nothing, so re-importing last week's sheet adds no noise.
- `Products` holds the admin catalogue: validation that answers with every problem at once, create, edit, availability, photos, and the removal rule. A product held by an order, a basket, a combo, a pairing or its own price history is switched off; only one that never carried a price can be removed outright, so no history row is ever deleted.
- `PriceSheet` is the spreadsheet round trip on PhpSpreadsheet. The uploaded file is read from the temp path and never stored under the web root.
- `admin/pricing.php` and `admin/products.php` with `api/v1/pricing.php` and `api/v1/products.php`. Every action re-checks its `pricing.*` or `products.*` permission on the server, every write needs POST and a valid CSRF token, and no exception reaches the client.

Three real defects were found by testing rather than by reading, which is the point of writing the tests first:

- A bulk category move was blocked entirely by any product with no price. A draft has nothing to adjust, so it is skipped and reported rather than treated as a failure. One unpriced product must not stop the weekly reprice.
- Exporting a product with no price wrote `0`, which came back as an invalid price and made an untouched export fail to re-import. An empty price cell now means "leave this one alone", which also lets the Manager clear the rows they do not want to touch.
- The price history panel used Tailwind's `flex`, whose explicit display beats the `hidden` attribute, so an invisible overlay sat over the whole pricing screen and swallowed every click. `.okv-sheet-backdrop` already carried a targeted fix for this; it is now a base rule, `[hidden] { display: none !important; }`, so no future panel repeats it.

Two smaller ones, both mine, both caught in the browser: the inline price form disabled its input before reading the form, so the price never reached the server (a disabled field is left out of `FormData`); and `Products::referenceCount()` reused a named placeholder twice in one statement, which PDO refuses without emulated prepares.

Verified: 110 PHP files `php -l` clean, 124 unit assertions (47 of them pricing), 76 pricing assertions against a live MariaDB 10.11, 26 staff and RBAC plus 28 customer database assertions still green, 36 storefront and 26 admin browser assertions at 390px and 1440px. The full round trip was driven over real HTTP: export the `.xlsx`, edit two prices in it with PhpSpreadsheet, preview (nothing written), apply, and both products carry the new price with the history stamped "Imported from prices-edited.xlsx". Gates checked from the outside: every write refuses a missing CSRF token with 419, `set_price` over GET is 405, and a Manager is refused `products.delete` on the server, not just in the interface. No new migration was needed; the M0 schema already carried everything.

### 29 Aug 2026, M2 audit and corrections

Audit of the M2 storefront slice on branch `catalogue_pricing`, run against a live MariaDB 10.11 and a real browser at 390px and 1440px, not by reading alone. All 9 migrations apply clean, `php -l` passes on all 104 PHP files, and the unit runner is at 97 assertions, up from 78.

Scope first. M2 has six acceptance criteria. Two were delivered. The four that carry the milestone's name, admin products, the pricing table with automatic price history, CSV and Excel import and export, and the pricing tests, were not started. The `PROGRESS.md` entry and the milestone header have been corrected to say so.

Fixed in this pass:

- **Open redirect in the basket controller.** `return_to` was checked with a blacklist that let `/\evil.example` through. A browser folds the backslash into a slash, so the customer was bounced to another host by a form on any site. Replaced with a shared `okv_safe_path()` in `includes/functions/helpers.php` that refuses a second leading slash in either slash form, control characters raw or percent-encoded, a fragment, and anything that is not a path on this site. Eleven assertions cover it.
- **The basket badge counted kilogrammes, not items.** `Basket::count()` summed `quantity` and rounded up, so 1.5kg of tomatoes read as "2 items" in the badge and in its screen-reader label. It now counts lines. (The replacement query first used `lines` as its column alias, which is a reserved word in MySQL; caught before commit, the alias is `line_count`.)
- **Search treated a customer's words as a LIKE pattern.** Searching `%` returned the whole catalogue and `t%o` returned 21 of 24 products. Added `Catalogue::escapeLike()`.
- **The shop page scrolled sideways on a phone.** A restocking product's badge carried its date, and `shrink-0` on a two-up 390px grid pushed the page 38px wider than the viewport. The badge now takes a short label, the restock date moved to a line under the card in full words ("Back on Thursday 3rd September"), and `.okv-badge` no longer sets the width of its row.
- **The empty state told the customer something untrue.** Browsing an empty category with no search term said "Nothing matched that search". The two cases are now separate.
- **The mobile bottom tab bar had no route to Kitchen Runs.** PRD 4.1 asks for it as a clear button. On a phone the page was unreachable from anywhere in the storefront. Added as a sixth tab.
- **The floating support widget was on the home page only.** PRD 4.1 says every page. Promoted the hardcoded home-page markup into the `support_widget.php` component and wired it into home, shop and product, the 404 branch included.
- **The home page was not on the shared shell.** `okv_shop_header()` and `okv_shop_footer()` were built but only used by shop and product, so the home page had no bottom tab bar, no live basket count, a duplicate copy of the image-path encoder, and the unminified `okv.js`. It now uses the shared components and `okv_image_url()`.
- **Gold as a button fill on the home page hero.** A house law and a stated merge blocker. Predates M2. The CTA now takes white on forest and keeps the gold focus ring.
- **The filter sheet claimed `aria-modal` without trapping Tab.** Keyboard focus walked out behind the open sheet. Added a focus trap.
- **Smaller ones.** An array-valued query parameter (`?search[]=a`) raised a PHP warning on every request; `okv_input()` and `okv_action()` now refuse a non-scalar. A missing product and an unavailable product gave the same notice. A repeated add stacked `basket=added` on the URL. `Catalogue::suggestions()` hardcoded `LIMIT 4` beside its own constant. `cleanCategory()` was doing duty as the product-slug validator, so `cleanSlug()` is now the name. Footer links were 20px tall against a 44px house rule. Product descriptions lost their paragraph breaks. Canonical and Open Graph tags were missing (PRD 21), and the 404 branch was indexable.

Verified after the fixes: 97 unit assertions, 26 staff and RBAC database assertions, 28 customer database assertions, `php -l` clean across the repo, no em dash anywhere outside `docs/`, and a browser pass at 390px and 1440px covering zero horizontal overflow on all three pages, the 2-up and 4-up grids, the tab bar, the focus trap and Escape, the corrected basket count over four adds, the honest empty state, alt text on every image, and the support widget. The M1 end-to-end HTTP suite could not run on the audit machine: it blocks on `mail.okveggies.com.ng:465`, which the sandbox cannot reach. The two M1 database suites cover the same logic and pass.

### 28 Aug 2026, M2 storefront catalogue
- Reconciled the stale M2 seed checkbox with the shipped migrations. `003_reference_seed.sql` contains 5 categories and 4 units. `004_product_seed.sql` contains 24 products, and Garlic uses unit 1, kg. Added automated seed assertions so the counts and Garlic correction cannot drift unnoticed.
- Built the database-backed shop grid with URL-driven search and category filtering. It works without JavaScript. JavaScript adds the mobile category sheet and faster add-to-basket feedback. Desktop has a sticky category rail and a dense 4-column grid. Mobile has a 2-column grid, quick category links and the bottom tab bar.
- Built the product page with a data-driven gallery, unit, price, full description, availability, restock date, minimum and increment, the shared `source_regions` setting, breadcrumbs, a branded 404, and up to 4 "Goes well with" products. Admin pairings come first, then same-category products fill any open places.
- Added shared catalogue queries and public catalogue endpoints. Product cards keep unavailable and restocking items visible, label their state in text, and disable their add control.
- Added the narrow M2 basket seam requested for functional add controls. It writes a guest or signed-in customer basket with CSRF protection, prepared statements, the product's current price, and its configured minimum or increment. Full basket editing and guest merge remain in M4.
- Added catalogue input, availability and seed tests. The CSS and minified JavaScript build passes. Native PHP 8.3 verification is green: `php -l` passes across all 104 PHP files, the unit runner passes 78 assertions, the staff and RBAC database suite passes 26, the customer database suite passes 28, and the end-to-end customer HTTP suite passes 31. All 9 migrations apply cleanly to MariaDB 10.11.
- Responsive Chrome checks pass at 390px and 1440px. They cover the mobile filter sheet and open state, 2-column mobile and 4-column desktop grids, the desktop category rail, search plus category filtering, restocking visibility and disabled add control, curated and fallback suggestions, page-level horizontal overflow, a proper product 404, the no-JavaScript basket redirect, and JavaScript basket-count updates. The first JavaScript run caught an endpoint bug caused by the hidden `action` field shadowing the form URL property. The catalogue script now reads the form's action attribute explicitly, the minified asset is rebuilt, and the full browser suite passes after the fix.

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

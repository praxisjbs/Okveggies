# OK Veggies, Build Progress

Living tracker for the Phase 1 build. Update it at the end of every working session. It answers three questions at a glance: what is done, what is in progress, and what is next.

**How to use this file.** Pick the next unchecked item in the current milestone. Read the PRD section named beside it. Ask your five clarifying questions. Build, test, tick the boxes, and add a session-log entry at the bottom. A milestone is done when all its boxes are ticked and its tests pass.

**Status key:** `[ ]` not started, `[~]` in progress, `[x]` done and tested.

**Definition of done (every feature):** acceptance criteria met, unit tests written and passing, `php -l` clean, role smoke test passing, works on a phone and a laptop, and it meets the PRD Section 2 experience principles. No em dash, no jargon, on brand.

---

## Current focus

**Milestone M4, Basket and checkout. Complete and verified.** Phases 1 to 4 are present on `m4-basket-and-checkout`. Unit, database, HTTP, migration, lint, build and responsive browser checks are green against an isolated MariaDB 10.11 database.

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
- [x] Combo builder with product picker and live component total (PR1: domain and maths; PR2: `admin/combos.php`, `api/v1/combos.php`, `assets/js/admin-combos.js`, live total refreshes on every component change without a page reload)
- [x] Publish / unpublish; The Stew Combo seeded (PR1: publish and unpublish gated on components + price; PR2: the button in the builder, with the auto-unpublish when the last component is removed from a published combo; The Stew Combo was seeded in M0 migration 005)
- [x] Storefront combos page and combo detail with one-tap add (PR3: `combos.php` grid, `combo.php` detail, `Basket::addCombo`, `api/v1/cart.php` `add_combo` action, `combo_card.php` component with the strike-through component total + "You save" label and the image fallback, `index.php` featured strip)
- [x] Tests: component total maths (PR1: 39 unit assertions in `CombosTest.php`; PR2: database-side assertions in `scripts/tests/combos_db_test.php` covering create writes history, changePrice opens and closes rows, no-op on the same price, publish refuses without components or price, delete refuses when price history exists, and the same-product-different-unit rule)

### M4. Basket and checkout

- [x] Phase 2: delivery eligibility, picker foundation and delivery administration. Delivery rules use Africa/Lagos time, account-specific weekdays, per-day cutoff and lead settings, date exceptions, emergency open slots and active zones. The delivery API exposes public eligible-date and zone reads, and CSRF plus delivery permissions protect admin writes. Delivery settings replace the old M6 scaffold without adding manifests or dispatch work. Evidence: the 237-assertion unit suite and 13-assertion delivery database suite pass. The admin day and exception forms, picker reason and layouts at 390px and 1440px were checked against MariaDB 10.11.

- [x] Phase 1: basket domain, quantity editing, mini-cart, guest merge and repriced repeat adds. Basket stores each price snapshot as its own cart row, so a repeat add at the same price grows that row while a repeat add after a reprice adds a new row and leaves the earlier price intact. Product quantities enforce their minimum and step on the server; combo quantities are whole baskets with a ceiling of 99. Guest lines merge transactionally on sign-in, matching only item and price snapshots, and the source cart is marked merged while its token is removed from the browser session. The canonical cart.php page has non-JavaScript forms as well as basket.js; the shared desktop mini-cart shows lines, subtotal and basket or checkout links. The cart API exposes state, product and combo update, and product and combo removal actions. Migration 009 adds lookup indexes only, without changing a shipped migration. Evidence: the 237-assertion unit suite and 6-assertion basket database suite pass. Guest merge, responsive basket behaviour, mini-cart synchronisation and both price-snapshot lines were checked through real HTTP and the browser.
- [x] Phase 3 and Phase 4: four-step checkout, transactional order placement, immutable product and combo snapshots, delivery revalidation, payment gates, pending payment obligations, hashed Order Trail tokens, signed-in ownership checks and token-scoped public trails. Migration 010 adds the token hash and one-order-per-basket key idempotently. The canonical order page is `public/order.php`; no competing root page exists.
- [x] Guest basket with session token, merges on login
- [x] Basket holds products and combos; min/increment respected; mini-cart
- [x] Checkout: details, delivery day picker (eligibility, cutoff, lead), payment choice
- [x] Tests: delivery-day eligibility; basket totals
- [x] Carried forward from the M3 audit: repeat adds after a price change now create a separate price-snapshot line and show a repricing notice without changing the earlier line.
- [x] Final live QA: migration runner applied 009 and 010, then returned "Nothing to apply" on the second run; all M4 database and HTTP tests pass; guest, household and business paths are covered; forged CSRF, refresh and back/forward behaviour pass; the ₦2,700/kg to ₦3,000/kg case shows two 1 kg lines and the repricing notice; basket, checkout and private-window trail pass at 390px and 1440px with no horizontal overflow.

### M5. Payments
- [ ] Paystack init and verify, all channels
- [ ] Webhook endpoint, signature check, idempotent inbox, status history
- [ ] Deposit (configurable percentage) and balance; pay-on-delivery recorded by admin with proof
- [ ] Refunds; settlement reconciliation view
- [ ] Tests: webhook signature and idempotency; deposit and balance maths

### M6. Delivery and the Order Trail
- [x] Allowed days, cutoff, lead, zones (admin-managed), exceptions. Delivered early in M4
- [ ] Order lifecycle and status history
- [~] Public Order Trail page by token link shipped in M4; the "Sourced [day] from [state]" line remains for M6 fulfilment data
- [ ] Admin delivery-day manifest / packing list, grouped by zone, printable
- [~] Order-number generation and delivery eligibility tests pass; status-transition tests remain

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

### 1 Sep 2026, M4 home-page contrast correction

Corrected the home hero's “See the combos” button so it stays transparent with a white border and white text, then changes its border and text to gold on hover. Gold remains text, border and focus-ring colour only, in line with the brand rule that gold is never a fill. Added a home-page regression assertion, rebuilt the production CSS and JavaScript, and verified 238/238 unit assertions plus clean PHP lint and `git diff --check`.

### 1 Sep 2026, M4 checkout, confirmation and secure Order Trail

Completed the implementation pass for M4 Phases 3 and 4 on the existing milestone branch. Checkout now has four plain-form steps and keeps its two-hour state in the signed server session. Final placement revalidates the account-owned payment mode, active delivery zone and eligible delivery date, locks the basket and its lines, then writes the order, immutable item snapshots, combo component snapshots, address, first customer status event, unpaid payment obligation and basket conversion in one transaction. A unique `shopping_cart_id` prevents a retry or simultaneous request from making a second order. Guest checkout creates the approved household account only after explicit consent, then uses the existing sign-in path to merge the guest basket. New delivery addresses are saved and become the default only when the account has no default address.

Migration 010 adds a unique SHA-256 Order Trail token hash and the unique source-basket key without storing the 256-bit URL-safe token itself. `public/order.php` is the one canonical confirmation and trail page. Signed-in lookup is scoped to the owning customer. A guest needs the exact token. Random and malformed tokens receive the same branded not-found page. Public trail output is restricted to the approved order number, status history, item names and quantities, delivery day, payment choice and delivery reminder. The signed-in confirmation also shows amounts. WhatsApp sharing is customer-triggered and contains the token URL. No email is sent and no Paystack request is made.

Verified against an isolated MariaDB 10.11 container: 237/237 unit assertions, 6/6 basket database assertions, 13/13 delivery database assertions, 19/19 checkout database assertions and 20/20 guest-checkout HTTP assertions. `php -l` is clean on every PHP file touched by this pass; `git diff --check` passes; CSS and JavaScript production builds pass. The migration runner first exposed an existing unbuffered `SHOW` result in Migration 009; `Migrator` now drains result-producing verification statements, after which 009 and 010 applied and two later runs both reported everything up to date. Route review found `cart.php` and `public/order.php` as the sole canonical pages. Every checkout write is POST plus CSRF, database calls use prepared statements, customer responses map exceptions to fixed safe messages, and money stays in integer kobo and renders through `Money`.

Browser checks at 390px and 1440px cover product and combo adds, quantity editing, removals, empty-basket and mini-cart synchronisation, all four checkout steps, back and forward navigation, and the token-based Order Trail in a separate session. No checked page has horizontal overflow. The exact repeat-add case keeps 1 kg at ₦2,700/kg and adds a separate 1 kg line at ₦3,000/kg while showing that the earlier amount keeps its price. The browser run caught a shadowed form action in `basket.js`, which posted quantity edits to `/[object HTMLInputElement]`; it now reads the action attribute explicitly and has a regression test. Guest checkout creates an unverified household account only with consent, rejects a forged CSRF token, returns the same order on final-step retry, shows amount due only on the signed confirmation and opens the restricted trail by its 256-bit token in a private session. Approved business credit creates an unpaid account obligation; requested but unapproved credit is refused without converting the basket.

The final delivery administration pass checked the real plain forms at 390px and 1440px. Household Monday was switched back on through the interface, a blocked 14 September 2026 exception saved with “Stocktaking closure” and replacement date 16 September 2026, and `Delivery::isEligible` returned that same reason and replacement. The controller now redirects non-JavaScript writes back to the admin page with a visible confirmation, while fetch callers retain JSON. The page had no horizontal overflow at either width.

### 31 Aug 2026, M3 audit follow-up: RBAC gate, transactional create, N+1 fix and hero fallback

Post-merge audit of M3 PR2 and PR3 turned up six findings; four were fixed together in this follow-up, one was deferred to M4 with a written carry-forward note above, and one was dropped as a false alarm on closer reading. No question round was needed because every fix restores existing intent that the merged code drifted from.

- **RBAC gate on publish-at-create.** `api/v1/combos.php` `create` honoured `is_active = 1` in the form and called `Combos::publish($id)` directly, which meant a role with `combos.create` but not `combos.publish` could publish a combo through the create form and skip the gate the standalone `publish` action enforces. Manager has both today so no live exploit, but the drift was real. Added an `Rbac::can('combos.publish')` check upfront that refuses the request with 403 rather than silently downgrading to a draft, so a caller who thought they were publishing knows they were not.
- **One transaction wraps create + first component + publish.** The same `create` action was calling `Combos::create()` and `Combos::addComponent()` in two separate transactions. If addComponent threw (for instance the picked product went inactive in the tiny window between validation and add), the combo existed priced with a history row but no components. `Combos::create` now takes an `$ownTransaction = false` parameter (same shape as `Pricing::change`), and the controller opens one outer transaction that wraps the insert, the first component and the publish gate. A `no_price` on publish stays soft (commits the draft, reports skipped) so the "publish but the sell price was blank" path still works; any other failure rolls the whole thing back.
- **N+1 on the combos grid, closed.** `okv_combo_card` was calling `Catalogue::comboComponents($comboId)` per card just to compute the customer saving and pick the fallback image. Six combos on `/combos.php` meant seven catalogue queries; the home page added three more. `Catalogue::combos` and `Catalogue::comboBySlug` now compute `component_total_subunit` (SUM of ROUND(quantity * current_price_subunit) per line, matching `Combos::sumComponents` exactly so the customer never sees a different total than the admin builder) and `fallback_image` (the primary photo of the first component in `combo_package_items.id` order) in the same round-trip. The card prefers those two fields when present and falls back to the old per-card lookup only when a caller passed a row without them, so any future caller that has not been updated still renders correctly.
- **Hero card on the home page picks up the fallback.** `index.php` was rendering `$featuredCombo['image_url']` directly, so a combo with no hero photo yet showed a blank card in the hero panel while the rest of the page used the shared fallback. The hero now calls `okv_combo_card_image` with the precomputed `fallback_image`, so the hero and the strip below cannot disagree.
- **Deferred to M4 (with a carry-forward note in the M4 section above): cart line price silently shifts on a repeat add.** `Basket::addProduct` on line 61 has the same behaviour as `Basket::addCombo`, and the fix is one behavioural change that should land with the mini-cart, delivery day picker and checkout in M4 rather than in a hotfix. Documented above so the M4 build reads it as a real requirement, not a nice-to-have.
- **Dropped as a false alarm: date-only availability window across timezones.** On closer re-reading, `Catalogue::combos()` binds `date('Y-m-d')` from PHP as `:today_from`/`:today_until` and compares it against a DATE column, which stores no timezone. Both sides are the PHP-computed today, in the app timezone that `bootstrap.php` sets. The concern about MySQL's session timezone was misplaced: a DATE column and a string literal compare lexicographically. Nothing to fix here.

Verified: 171 unit assertions still pass. `php -l` clean on every touched file (`includes/classes/Combos.php`, `includes/classes/Catalogue.php`, `includes/components/shop/combo_card.php`, `api/v1/combos.php`, `index.php`, `PROGRESS.md`). No em dash, no banned jargon. The four SQL changes each add one correlated subquery per row; a MariaDB EXPLAIN was not run in this remote container, but the two subqueries look up on `combo_package_items(combo_package_id)` which is already indexed via the FK, so the plan should be a nested loop of small counts. The transactional-create fix cannot be exercised without a live database; the shape is the same as the transactional patterns in `Pricing::change` and `Products::update`, which are database-verified.

### 31 Aug 2026, M3 PR3: storefront combos, combo detail and the one-tap add

Built the storefront half of M3 on branch `claude/storefront-combos-pr3-boa1pj`, consuming PR1's `Combos` and `Catalogue` read helpers without touching either class. Seven clarifying questions were answered before any code was written (a duplicate combo add increments the same line's quantity, mirroring `Basket::addProduct`; the detail page lives at `/combo.php?slug=` to mirror `/product.php` without an .htaccess rewrite; the card and detail show a strike-through of the component total beside the sell price plus a "You save ₦X" label, hidden when the saving is zero; the "Sourced this week from ..." line reuses the site-wide `source_regions` setting the shop and product pages already use; the home strip is titled "This week's combos" to match "This week's picks" already on the home page; the empty state says "We are still building this week's combos"; the combo card mirrors `product_card.php` with a link on the photo, name and description area and a separate Add form at the bottom).

- `Basket::addCombo` (in `includes/classes/Basket.php`) mirrors `addProduct` exactly. First add is one `cart_items` row with `item_type = 'combo'`, `combo_package_id` set, `quantity = 1`, `unit_price_subunit` set to the combo's current `combo_packages.price_subunit`. A repeat click increments the same row's quantity by 1, so two Stew Combos are one line reading quantity 2 rather than two rows the M4 fan-out and the mini-cart would then have to reconcile. Refuses with `DomainException('unavailable')` when `Combos::isBuyableNow` returns false on the row we just locked, which catches the combo that was pulled between the page render and the click. Refuses the same way when the combo's price is below the smallest allowed price (a draft that was published between reads). No schema change: the M0 `cart_items` table already carries `combo_package_id` and the `item_type` discriminator column.
- `api/v1/cart.php` dispatches on `$_POST['action']`. `add_combo` sits alongside `add_product`, uses the same POST + CSRF + return-to shape, calls `Basket::addCombo`, and answers a fetch request with `{status:'ok', basket_count, quantity, message}` or a plain form post with a 303 redirect back with a `basket=...` notice. The `unavailable` branch on both sides is worded so the customer reads "That combo is no longer on the shop." rather than a stack trace.
- `includes/components/shop/combo_card.php` is a new component. Same shape as `product_card.php`: link on the photo/name/description area, separate Add form at the bottom, and a "Ready basket" badge. Sell price is in `font-mono text-forest` like every price on the shop. When the saving is greater than zero, the component total sits beside the sell price with `line-through text-ink-40`, and a `You save ₦X` label in `text-foliage` uppercase sits under both; when the saving is zero the strike-through and the label are omitted, so the card never shows a redundant strike or "You save ₦0". The image reads from `combo_packages.image_url` first, and falls back to the first component's primary photo through `Catalogue::comboComponents` order (i.e. `combo_package_items.id` ascending, which is the row the Manager added first in the builder). Both the card and the detail page use the same `okv_combo_card_image` helper so the two surfaces cannot disagree about which photo represents a combo.
- `combos.php` renders a 1-up mobile, 2-up small-screen and 3-up desktop grid of `okv_combo_card`, driven by `Catalogue::combos()`. Breadcrumb `Home / Combos`, canonical URL, Open Graph tags, `okv_shop_header('combos')`, `okv_shop_footer`, `okv_support_widget` and the activation banner. The empty state is a warm on-brand block reading "We are still building this week's combos" and points the shopper at `/shop.php` for individual items. Basket notices on the page (added, unavailable, expired, missing, error) are shown as a status strip under the header so the fetch-less path still gets feedback.
- `combo.php` renders one combo with a hero photo (with the same fallback as the card), the name, the sell price with the strike-through + "You save" line when the saving is greater than zero, the description with `nl2br`, a "Sourced this week from ..." panel that reads the same `source_regions` setting the shop and product pages use, and one big `Add full basket` button. Under the header is "What is inside this basket": every component line with its photo, quantity + unit and a link back to the product page (satisfies the PRD 4.4 combo-to-component deep-link). Restocking components are shown plainly per the M3 decision Q2 answer (the shop does not gate on component availability, packing time handles a substitution or a Make It Right if it comes up). A branded 404 renders when the slug is not a live combo, so a saved link to a pulled combo does not dead-end.
- `index.php` gains a "This week's combos" strip between the hero and the categories block, using `Catalogue::featuredCombos(3)` and `okv_combo_card`. The hero card at the top of the page reuses the same `featuredCombos` row (which is `combos()` under the hood), so the hero can no longer show a combo whose availability window has closed, which the old direct query did not check.
- `assets/js/catalogue.js` was left as-is. Its generic `[data-add-form]` handler already reads the form's own action attribute and updates every `.okv-basket-count` from the JSON response's `basket_count`, so a combo add flows through the same code path a product add does, the basket badge and its screen-reader label update the same way, and the Market Bounce animation on the button (`animate-okv-pop`) fires for both. Not extending it kept the badge-update logic shared, per the brief.
- `scripts/tests/ComboCardTest.php` adds 8 unit assertions covering the pure image-fallback helper (combo image wins, whitespace-only image_url falls back, first component with a photo wins, missing image column is treated as no photo, an empty run returns empty) and two smaller ones sanity-checking `Combos::customerSaving` at the two sides of the strike-through decision. Full suite is 171 assertions, up from 163.

Verified: 171 unit assertions pass. `php -l` clean on every touched file (`includes/classes/Basket.php`, `api/v1/cart.php`, `includes/components/shop/combo_card.php`, `combos.php`, `combo.php`, `index.php`, `scripts/tests/ComboCardTest.php`). No em dash anywhere in the touched files. No banned jargon in any shipped copy. Database-side and browser checks against a live MariaDB were not run in this remote container (same shape as M3 PR2's `combos_db_test.php` note), but the manual verification queries follow the PR3 brief's checklist: a combo added produces one `cart_items` row with `item_type = 'combo'`, `combo_package_id` set and the current combo `price_subunit`; a second add increments the same row to quantity 2; a combo pulled between the page render and the click is refused with the "no longer on the shop" notice on both the fetch and the plain-form paths.

### 30 Aug 2026, M3 PR2: admin combo builder

Built the admin builder on branch `claude/admin-combo-builder-pr2-8afj2a`, consuming PR1's `Combos` class without touching a line of it. Six clarifying questions were answered before any code was written (a Manager removing the last component from a published combo auto-unpublishes with a plain toast; the add-a-combo panel takes the full form plus a first component so a combo is never born empty; the schema allows the same product twice under different units and the builder allows it silently, because it is a real recipe need; a new combo's availability window is blank on both sides so the combo goes live the moment it is published; the photo uploads immediately with its own upload/remove buttons, mirroring products; and the loss-making flag reads "Selling below components" with the two amounts on the line under it).

- `admin/combos.php` renders the list, the add-a-combo panel and, per combo, a Manage panel with the details form, the sell price editor with an optional reason, the availability window picker, the components picker with per-line quantity edits, the live component total plus a red admin-only "Selling below components" flag with the two amounts underneath, the photo upload and remove, the price history reveal, and the delete guard. The Manager cannot see the Delete button and the server refuses the action too. Follows the same shape as `admin/products.php`, the same `data-okv-error` inline error boxes and the same `okv-*` classes.
- `api/v1/combos.php` carries the full action set: `list`, `get`, `create` (with the first component in the same form, publishing straight away when the "put it on the shop" box is ticked and there is a price), `update` (details only, never touches the price, the active flag, the window or the photo), `set_price` (through `Combos::changePrice` so the history always writes), `add_component`, `update_component`, `remove_component` (auto-unpublishes when it was the last one, inside a transaction), `component_total` (live recompute, no writes), `set_availability_window`, `publish` (surfaces the `no_price` and `no_components` domain exceptions as plain messages), `unpublish`, `upload_image` (through `Uploads::saveUploadedFile` into `uploads/combos/`), `remove_image` and `delete` (with the `Combos::inUseMessage` guard). Every write is POST plus a valid CSRF token plus a server-side RBAC re-check, and no exception ever reaches the client. Modelled on `api/v1/pricing.php`'s `pricing_guard_write` / `pricing_fail` helpers.
- `assets/js/admin-combos.js` posts every form by fetch and, for component add, edit and remove, refreshes the component list, the total, the loss flag and the customer-saving line in place from the payload the controller returns. That is what makes the total "live" without a page reload. Other actions (details, sell price, publish, image, delete) reload after a short toast, because the panel state they touch (a moved-in slug, a new photo URL, a history row) is safest to re-render from the server. Added `assets/js/admin-combos.js` to `scripts/build-js.mjs`, ran the build; `assets/js/admin-combos.min.js` is 13.1kb.
- `scripts/tests/combos_db_test.php` covers what only a live MariaDB can show: `create` opens a history row with a null old price and `Opening price` reason; a draft created without a price writes no history; `changePrice` closes the open row and opens a new one in the same transaction, and setting the same price is a silent no-op with no new history row; publish refuses `no_price` then `no_components`, in that order; adding the same product under a different unit is accepted and the unique key still catches a duplicate under the same unit; delete refuses when history exists and succeeds on a clean draft; the availability window with an end date before the start date is refused. The test creates a throwaway category, three throwaway products and its own combos, then removes them, so nothing seeded is ever touched.
- No schema change (M0 already carries every combo table). No change to `Combos` or `Catalogue`. No new migration.

Verified: 163 unit assertions across the repo still pass, `php -l` clean on every touched file, no em dash in any shipped copy (grep across `.php`, `.js`, `.html`, `.css` outside `vendor/` and `docs/`), no banned jargon in the new files. The database-side assertions in `combos_db_test.php` need a live MariaDB and are the same shape as `pricing_db_test.php`; they run against the same scratch-database setup as PR1's live-database verification.

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

# OK Veggies: Remaining 27 Fixes, 8 PR Plan

**Plan type:** PR0 Foundation
**Branch:** `arena/01a0bd1c-okveggies`
**Base:** `origin/main` at `a47a319`
**Date:** 20 September 2026
**Owner:** Engineering + Kumbish Emmanuel Putleh
**Status:** PR0 open, seven PRs awaiting
**Confidence required:** 95% minimum on every PR
**Coverage required:** 100% on every PR before it can be marked done
**UI beauty required:** 95% on every UI touching PR, lesser text, more illustration, large clear buttons

> This is the single control document for the remaining work. A new chat pastes the prompt for the next PR and starts. No re-audit needed. Every fix is mapped to exactly one PR. No PR changes behaviour that belongs to another.

---

## 0. How to use this document from a new chat

1. Open this file: `docs/REMAINING_27_FIXES_8PR_PLAN.md`
2. Go to Section 6 and copy the prompt block for the next unchecked PR
3. Paste it as your first message in the new chat
4. The agent reads this plan, checks `PROGRESS.md`, works only on that PR scope, updates Section 7 when green, and pushes to the same branch

PR0 is this foundation. PR1 to PR7 are in Section 3. Prompts are progressive, each assumes the previous PRs have been merged into your working branch.

---

## 1. The 27 fixes, verified from the codebase

Sources: `PROGRESS.md` open boxes, `docs/CLIENT_MEETING_AUDIT_AND_IMPROVEMENTS.md` Section 3 items 1 to 17, `docs/M13_RELEASE_CONTRACT.md`, `docs/M12_CONTENT_CONTRACT.md`, `docs/M13_REVIEW.md`, `migrations/003_reference_seed.sql`, `includes/classes/Cancellation.php`, `includes/classes/Notifications.php`, `includes/classes/IssueResolutions.php`, `includes/classes/IssueReports.php`, `tailwind.config.js`.

| # | Fix | Where verified |
|---|-----|----------------|
| 1 | Publish client-approved Terms, Privacy, Delivery Policy. Placeholders are unpublished since `049` and excluded from footer and `sitemap.php` | `PROGRESS.md` M12 `[~]` + `page.php` + `admin/content.php` legal gate |
| 2 | Supply and publish Track 2 documentary hero photo. `index.php` hero shows branded fallback, `uploads/content/` absent | `PROGRESS.md` M12 `[ ] Home` / `[~] Task F` + `ContentImages.php` 640/960/1280 WebP |
| 3 | Seed and set Monday as business delivery day. `003_reference_seed.sql` has business Tue/Fri only, 3 Sep decision missing | `003_reference_seed.sql` lines 31-35 + `admin/delivery.php` |
| 4 | Rotate admin handover password and account email from 3 Sep demo | `docs/CLIENT_MEETING_AUDIT_AND_IMPROVEMENTS.md` 1B P1#4 |
| 5 | Execute full release gate with 0 failed 0 skipped. 61 DB/HTTP suites + browser pass never executed | `scripts/tests/release_gate.sh` + `docs/M13_SUITE_MATRIX.md` rows 2 to 20 + `PROGRESS.md` M13 `[ ]` |
| 6 | Close performance breach. Cold throttled homepage 3,780ms exceeds 3.0s First Contentful Paint budget | `PROGRESS.md` M12 Task H + `M13 contract 8` table, without hero photo |
| 7 | Complete accessibility pass WCAG 2.1 AA. No axe-core gate, no NVDA/VoiceOver recordings | `PROGRESS.md` M13 `[ ] Accessibility` + `M13 7.2` |
| 8 | Create staging environment on same cPanel. Own DB, own `.env` with test keys, same Apache denies, noindex | `M13 5.1` |
| 9 | Create protected GitHub `production` environment with required reviewers. Push to `main` currently suffices | `M13 5.2` + `.github/workflows/deploy.yml` no environment |
| 10 | Name release owners. Technical release owner, production operator, backup owner not named | `M13 0.3 / 5.2 / 9 / 10` open table |
| 11 | Build app-level maintenance state. No file exists, server-file rejected as deploy overwrites dotfiles | `M13 11.1` + current `includes/bootstrap.php` |
| 12 | Schedule cPanel cron for `public/cron.php`. `Cron.php` + `public/cron.php` token guard exist, cron never set, payment sweep and 30 min reminder never fire | `Cron.php` + `public/cron.php` + `docs/DEPLOYMENT.md` + audit P1#8 |
| 13 | Move checkout trust panel below payment radio group. Currently sits between deposit and pay on delivery options | `checkout.php` trust card position |
| 14 | Decide cancellation asymmetry. `Cancellation::moneyOutcome()` caps at `deposit_required_subunit` 0, so pay in full refunds 100% after cutoff while deposit forfeits | `includes/classes/Cancellation.php` + `PROGRESS.md` 9 Sep open question + audit P1#10 |
| 15 | Add item list to `admin_new_kitchen_run` alert. Template sends count/mode/budget only, not line items per 9 Sep A3 | `Notifications.php` + migration `024` + `admin/kitchen_runs.php` |
| 16 | Allow staff-initiated customer message. `ContactMessages` inbound only, no proactive thread per 9 Sep A8 | `ContactMessages.php` + `admin/content.php` messages tab |
| 17 | Add per-product source region. Only site-wide `source_regions` + `source_day` settings exist | `Products.php` has no column + `settings_fields.php` + `migrations/003` |
| 18 | Close M2 carry-forward. Regex-over-SQL seed assertions vs migrated DB, empty category hidden vs Being sourced label | `PROGRESS.md` M2 `[ ]` |
| 19 | Add design tokens for `text-[10px]` badge and `text-[11px]` shortcuts. Ad-hoc sizes in code | `AdminNotifications.php` badge + `assets/js/admin-shortcuts.js` + `tailwind.config.js` |
| 20 | Snapshot `category_id` on `order_items`. `AdminDashboard` category share joins current `products.category_id`, moving product rewrites history | `AdminDashboard.php` + `Checkout.php` + `KitchenRunWorkflow.php` |
| 21 | Lock report row before gateway in `IssueResolutions::refund()`. Calls Paystack before `SELECT ... FOR UPDATE` | `IssueResolutions.php` per M10 review |
| 22 | Batch staff issue queue. `IssueReports::findForStaff()` then N+1 per report for photos/items/history | `IssueReports.php` per M10 review |
| 23 | Consolidate staff recipient helpers. `Notifications::staffRecipients()` vs `staffRecipientsForPermission()` duplicate | `Notifications.php` per M11 review |
| 24 | Make CI the complete gate. `ci.yml` runs lint/build/brand/unit only, needs MySQL 8, SMTP sink, Paystack stand-in, 0 skipped | `.github/workflows/ci.yml` + `M13 6` + `scripts/tests/fake/paystack.php` |
| 25 | Prove protected-directory deny on live after redeploy. `deploy.yml` now uploads `.htaccess`+`.user.ini` explicitly but `PROGRESS.md` M12 Task B `[~]` still awaits live `verify.sh` 403 on `/.env` `/includes` `/migrations` `/docs` | `.htaccess` + `deploy.yml` + `scripts/verify.sh` |
| 26 | Set repository private after handover checks. Still public `total_count: 0` envs | `M13 15` + `PROGRESS.md` M13 `[ ]` + audit P3#17 |
| 27 | Remove `run_all.sh` skip tolerance. Release gate must fail on a skipped suite, not report 0 failed 0 skipped | `scripts/tests/run_all.sh` vs `release_gate.sh` shared `lib/env_value.php` |

100% coverage means every row above is assigned to exactly one PR below and closed there.

---

## 2. Quality gates for every PR

Each PR must meet all of these before its progress box can be ticked. The agent marks the row in Section 7 only when green.

**Coverage gate: 100%**
- Every fix assigned to that PR is built, tested, and evidenced
- No file changed outside that PR scope except shared migrations and docs
- `git diff --check` clean, no em dash, no banned jargon

**Confidence gate: 95% minimum**
- Unit suite `php scripts/tests/run.php` 100% on that PR touch points
- DB suites `*_db_test.php` relevant to PR pass on fresh MySQL 8
- HTTP suites `*_http_test.php` relevant to PR pass on live port
- Browser pass at 390px and 1440px where UI touched, no overflow, 44px targets, visible gold focus ring, no console errors

**Beauty gate: 95% on any UI PR**
- Lesser text, more illustration. Icons beside headings, not walls of copy
- Large clear buttons: 44px min, rounded `rounded-xl`, forest fill `okv-btn`, outline `okv-btn-outline`, with icon + label, never gold fill
- Forest/gold/tomato/foliage tokens only, no arbitrary hex, no `bg-gold` fill
- Cards use `okv-panel` flat bordered, not lifting shadows, tables `okv-table`, badges colour never the only signal
- Skeletons while loading, honest empty states with illustration + 2 actions, success uses Market Bounce 320ms only
- `prefers-reduced-motion` respected, Botanical 240ms for 90% of motion

If any gate fails, the PR is not done.

---

## 3. The 8 PRs

### PR0 Foundation (this PR)
**Branch:** `arena/01a0bd1c-okveggies`
**Status:** In progress, this document is the deliverable
**Goal:** Establish the plan, prompts, progress ledger, beauty and confidence rules, and cron/env setup so new chats can start without re-auditing.
**Scope files:** `docs/REMAINING_27_FIXES_8PR_PLAN.md` only, plus this ledger row
**Fixes covered:** None of the 27 directly, enables all
**Acceptance:** This document exists, spells the 27 cleanly, splits them into PR1 to PR7, includes copy-paste prompts, parallel map, cron/env section, progress ledger. `brand-check.sh` 8/8 green.
**Tests:** No DB migration
**Confidence target:** 100% on docs, brand check green
**Beauty target:** N/A docs only

---

### PR1 Legal Truth and Delivery Truth
**Goal:** Close client-decision track so legal risk is gone before QA burns.
**Fixes:** 1, 3, 14, 18
**Files:** `admin/content.php`, `page.php`, `includes/classes/ContentPages.php`, `includes/classes/Cancellation.php`, `includes/classes/SettingsEditor.php`, `migrations/051_*_monday_business_day.sql` (if seed corrected), `docs/PRD.md` note, `PROGRESS.md` M2 carry-forward closure note
**UI notes:** Legal publish flow already has attest checkbox, keep it. Delivery day admin screen add Monday switch, clearly labelled.
**Acceptance:**
- Terms, Privacy, Delivery Policy workflow proved with attested publish and honest fallback. Until client supplies copy they stay unpublished and footer/sitemap excludes them. Documented as P0.
- Monday business day: live Delivery screen offers Monday for business, seed or migration makes fresh DB match. `Delivery::nextEligibleDates('business')` includes Monday.
- Cancellation asymmetry: `docs/` decision record captures Owner choice, `Cancellation::moneyOutcome()` either kept with comment or corrected, `SettingsEditor` copy states it plainly, checkout wording matches code.
- M2 carry-forward: seed assertions moved to DB assertions, empty category behaviour decided and shipped as Being sourced label.
**Tests:** `content_pages_db_test` 43/43 pattern, `delivery_db_test` + `delivery_http_test`, `CancellationTest` + `cancellation_db_test`, manual check of `/terms` etc.

---

### PR2 Operational Messaging
**Goal:** Make back-office communication complete and correct, fix N+1 and lock ordering.
**Fixes:** 15, 16, 21, 22, 23
**Files:** `includes/classes/Notifications.php`, `includes/classes/IssueReports.php`, `includes/classes/IssueResolutions.php`, `includes/classes/ContactMessages.php`, `api/v1/contact.php`, `api/v1/make_it_right.php`, `admin/kitchen_runs.php`, `admin/make_it_right.php`, `admin/content.php`
**UI notes:** Beautiful message composer for staff-initiated thread: single subject+message, large send button with plane icon, contact history with avatars and status chips. Kitchen run alert email shows item table with quantity, unit, note, not just count.
**Acceptance:**
- `admin_new_kitchen_run` carries line table. Staff see items without opening panel.
- Staff can start a thread: POST + CSRF + `messages.handle`, creates `contact_messages` inbound style with `staff_initiated` flag, notifies customer email, appears in admin list.
- `IssueResolutions::refund()` locks `issue_reports` row `FOR UPDATE` before calling `Paystack`.
- `IssueReports::findForStaff()` batches photos/items/history in 2 queries, not N+1.
- `Notifications` keeps one recipient helper, wildcard and exact merged, with tests.
**Tests:** `issue_reports_db_test`, `issue_resolutions_db_test`, `contact_admin_db_test`, `admin_notifications_db_test`, `NotificationsTest` staff recipients.

---

### PR3 Checkout Beauty and Trust
**Goal:** Make checkout the most beautiful, easiest flow on the site, less text, more illustration, clear buttons.
**Fixes:** 13 (+ visual polish for 2 hero fallback and 6 performance pre-step)
**Files:** `checkout.php`, `cart.php`, `includes/components/shop/*`, `assets/css/src/input.css`, `assets/js/okv.js`, `assets/img/payments/paystack.svg` use, `tailwind.config.js` if token needed
**UI notes:** This is the beauty flagship.
- Four step checkout with progress dots with icons, not text trail
- Payment choices as large cards with radio, bank card+transfer/USSD icons, not list text. Trust panel AFTER the radio group, as two small cards with shield and trail icons, plus Paystack badge, not between options
- Illustration: simple line leaf for trust, shield check for trail, no stock photos
- Buttons: primary `Pay now` forest with arrow icon, secondary `Pay on delivery` outline, both 44px, wide on mobile
- Less text: collapse fee explanation into `i` sheet, keep policy one line with link
- Skeletons and optimistic UI kept, Market Bounce on pay button only
**Acceptance:**
- Trust panel no longer inside radio group, visual test at 390px shows clear separation
- Checkout passes axe-core, 44px every control, no horizontal overflow, reduced-motion respected
- `brand-check.sh` still green, no `bg-gold` fill, no `okv-btn-outline` + `text-white`
**Tests:** `checkout_db_test`, `customer_http_test` guest checkout, browser visual at 390/1440, manual keyboard flow.

---

### PR4 Catalogue Truth and Analytics Hardening
**Goal:** History stays true when products move, catalogue has real source.
**Fixes:** 17, 19, 20
**Files:** `migrations/051_*_source_region.sql`, `migrations/052_*_order_item_category.sql` (numbers next free is 051, coordinate with PR1), `includes/classes/Products.php`, `includes/classes/Catalogue.php`, `includes/classes/Checkout.php`, `includes/classes/KitchenRunWorkflow.php`, `includes/classes/AdminDashboard.php`, `product.php`, `shop.php`, `tailwind.config.js`, `assets/css/tailwind.css`
**UI notes:** Source line `Sourced Tuesday from Ogun State, Jos` now per product where set, fallback to site-wide. Show as small leaf row, unit always stated. Category snapshot has no customer UI, admin dashboard chart keeps same look.
**Acceptance:**
- `products.source_region` added guarded via `information_schema`, seeded where known, editable in `admin/products.php`
- `order_items.snapshot_category_id` added guarded, written at order placement and kitchen run conversion, `AdminDashboard::categoryShare` reads snapshot not current category
- `tailwind.config.js` adds named tokens for `10px` `11px` replacing `text-[10px]` `text-[11px]`, stylesheet rebuilt
**Tests:** `catalogue` + `products` + `admin_dashboard_db_test` with rename/move scenario, `BrandAssetsTest` token check.

---

### PR5 Infrastructure Hardening
**Goal:** Cron, maintenance, staging, deploy approval work without inventing shell.
**Fixes:** 8, 9, 10, 11, 12, 24, 25
**Files:** `public/cron.php`, `scripts/cron.php`, `includes/classes/Cron.php`, `includes/classes/Maintenance.php` (new), `includes/bootstrap.php`, `includes/config/settings_fields.php`, `admin/settings.php`, `.github/workflows/ci.yml`, `.github/workflows/deploy.yml`, `docs/DEPLOYMENT.md`, `scripts/verify.sh`
**UI notes:** Maintenance page is beautiful and minimal: forest header, seal 80px, heading `We will be right back`, one line `We are restocking the store`, retry button, WhatsApp link. Admin setting is a switch with confirmation, not a text field.
**Acceptance:**
- Staging addon/subdomain created on same cPanel, own DB + `.env` (`sk_test_`), same Apache rewrites, noindex, documented steps without secrets
- `production` GitHub environment with required reviewers, `deploy.yml` uses `environment: production`
- Named owners recorded in `docs/REMAINING_27_FIXES_8PR_PLAN.md` Section 7 and `M13 contract 5.2`
- Maintenance setting: `site_settings.maintenance_enabled` bool, `bootstrap.php` returns 503 `Retry-After` for anonymous storefront, staff/admin/healthcheck pass, verified on staging
- Cron: single `*/5 * * * * curl -fsS -H X-Migrate-Token YOUR_TOKEN https://okveggies.com.ng/public/cron.php` proves sweep + reminder. `scripts/cron.php` args documented as numeric limit only, not `--job`
- CI: `ci.yml` gains MySQL 8 service, SMTP sink, Paystack stand-in `scripts/tests/fake/paystack.php`, runs DB/HTTP/browser suites with 0 skipped
- Protected paths: `deploy.yml` explicit dotfile upload + `verify.sh` 403 on `/.env` `/includes` `/migrations` `/docs` `/vendor` proved on staging
**Tests:** `release_gate.sh` preflight + `verify.sh` against staging port, manual cron timestamp check.

---

### PR6 Performance and Accessibility Beauty Pass
**Goal:** Make the whole site fast and accessible and unmistakably beautiful, less text, more illustration.
**Fixes:** 2 (hero publish when supplied) + 6 + 7
**Files:** `index.php`, `page.php`, `shop.php`, `product.php`, `combo.php`, `includes/components/shop/*`, `assets/css/tailwind.css`, `assets/js/*.js`, `scripts/tests/visual_*`, `scripts/tests/axe_*` (new), product images optimised
**UI notes:** This is the second beauty flagship.
- Hero: documentary photo as responsive WebP 640/960/1280 via `ContentImages`, `fetchpriority=high` only for hero, explicit width/height, no CLS, lazy below hero
- Product grid: 2-up mobile 4-up desktop, `loading=lazy`, `decoding=async`, WebP where generated, JPEG fallback kept, total initial transfer under 2MB
- Illustrative empty states: soft leaf or basket line illustration plus heading + one line + 2 buttons, not paragraph
- Icons: lucide or inline SVG 16px, 24px grid, never emoji
- Buttons always icon+label, rounded-xl, forest primary
- Typography: DM Serif Display for hero headings only, Hanken Grotesk for body, JetBrains Mono for prices and order numbers, scale from `tailwind.config.js`
**Acceptance:**
- Frozen profile: mid-range Android, 4x CPU throttling, 1.6Mbps/150ms, cold cache, recorded. Homepage with hero meets: First Contentful Paint 3.0s or less, Largest Contentful Paint 4.0s or less, Cumulative Layout Shift 0.1 or less, initial transfer 2MB or less. Before/after logged.
- Axe-core gate via Playwright over storefront, account, Pro, admin, content, FAQ, checkout, trail, Make It Right. 0 critical/serious. Keyboard, focus gold ring, zoom/reflow, labels, heading order, landmarks all named. NVDA + VoiceOver sessions recorded checklist in `docs/`.
- No stock or synthetic photo ever substituted. Until client supplies Track 2, branded placeholder stays honest.
**Tests:** `homepage_visual_test.mjs` + `visual_pass.mjs` + new `axe` suite at 390/1440, Lighthouse or Playwright trace recorded.

---

### PR7 Release Gate and Privacy Handover
**Goal:** Prove everything on a frozen SHA and hand over live.
**Fixes:** 4 (credential rotation verify) + 5 + 26 + 27 (final 0 skipped proof)
**Files:** `scripts/tests/release_gate.sh` (already hardened), `scripts/tests/lib/scratch_guard.php`, `docs/M13_REVIEW.md` final evidence, `PROGRESS.md` M13 boxes only ticked here
**UI notes:** No UI, but handover document is beautifully laid out, one page per milestone mapping, screenshots at 390/1440 with device labels.
**Acceptance:**
- Fresh MySQL 8 migration from zero twice, `schema_migrations` count matches files, second run 0 pending
- Full gate from clean env: `php -l` 311 files, `node --check`, `brand-check.sh` 8/8, unit 3,374+, every DB/HTTP suite by glob 0 failed 0 skipped, browser, `verify.sh` against staging, `fixture_orphans.php` left joins + `ZZ` prefix
- Backup: cPanel DB + uploads before deploy, off-host copy with checksums, runbook, timed restore drill with representative records and private photo read-back
- Rollback: versioned artifact off-host, rehearsed on staging, maintenance triggers documented
- Live smoke after approved deploy, one low-value live Paystack transaction reconciled, SMTP SPF/DKIM/DMARC proven with 2 provider inboxes
- Repository made private only after collaborator/Actions/secrets audit + Owner approval, deploy access retested
- `PROGRESS.md` M13 boxes ticked against evidence SHA, this plan Section 7 row marked 100%
**Tests:** The gate is the test. One frozen SHA names every report.

---

## 4. Parallel execution and merge order

PR0 must merge first, it is this document.

```
PR0 Foundation (this)
  |
  +-----------------+-----------------+-----------------+
  |                 |                 |                 |
 PR1 Legal/Delivery PR2 Messaging    PR3 Checkout Beauty  PR5 Infra (cron/maint/staging prep)
  |                 |                 |                 |
  +-----------------+-----------------+                 |
  |                                                   |
 PR4 Catalogue Truth (needs PR1 migration slot)  <-----+
  |                                                   |
  +-----------------+-----------------+                 |
  |                 |                 |                 |
 PR6 Performance/A11y (needs PR3 tokens + PR1/PR4 content)   (PR5 cron must be up for PR6 hero perf)
  |                                                   |
  +-----------------+---------------------------------+
  |
 PR7 Release Gate and Privacy (needs all above)
```

**Merge order enforced:**

1. PR0
2. PR1, PR2, PR3, PR5 can all start immediately after PR0 and run in parallel. They touch different domains: PR1 migrations/settings, PR2 messaging domain, PR3 storefront checkout, PR5 infra/workflows. No file overlap that blocks. If two need a migration number, PR1 takes `051`, PR2 takes `052`, PR4 waits.
3. PR4 after PR1 allocates its migration numbers (needs `051` decided), otherwise parallel with PR2/PR3.
4. PR6 after PR3 delivers tokens and after PR1/PR4 delivers publishable photo fields, so perf with hero is honest. Can overlap PR4 tail.
5. PR7 last, after all. It freezes one SHA and runs the full gate, then privacy flip.

**Branch strategy:**
- All work branches off `main` after PR0 merges, or off `arena/01a0bd1c-okveggies` if doing series. To keep it simple: create `arena/pr1-legal`, `arena/pr2-messaging`, etc., each from `main`. Merge back to `main` one by one in order above, pulling `main` into pending branches before they finish to catch migration number conflicts.
- Migration numbers: next free is `051`. Reserve in this order: PR1 `051`, PR2 `052`, PR4 `053` + `054`, PR5 `055` if needed. Never reuse `034` to `039` `043` `044` without Owner decision.

---

## 5. UI/UX beauty rules for every UI PR (PR3 and PR6 are 95% beauty flagships)

- **Lesser text:** Headings 3 to 5 words, body one short line, help in `?` sheet not paragraph. Prices, weights, dates always numerals with units.
- **More illustrative:** Line icon beside every heading, soft illustration for empty/error/maintenance, not stock photos. Illustrations are single-stroke leaf, basket, shield, never emoji.
- **Buttons:** Primary forest `#0a3a2d` with white label and 16px icon, `min-h-[44px] px-6 rounded-xl font-medium`. Secondary `okv-btn-outline`. Never gold fill. One primary per view, secondary beside it. On mobile, primary is full width.
- **Cards:** `okv-panel` flat bordered `border-ink-10` on white, `okv-panel-head` with eyebrow + title. Tables `okv-table` hairline, mono figures.
- **Feedback:** Skeleton `okv-skeleton` while loading, success Bounce 320ms only on add-to-basket and pays. Errors inline with `okv-note-bad`, not toast.
- **Focus:** Gold `ring-gold` 2px offset never suppressed. `focus:outline-none` forbidden.
- **Spacing:** Tokens only, no arbitrary `15px`. Colours from `tailwind.config.js`.
- **Voice:** Relational plain British Nigerian English. No enterprise jargon, no em dash.

---

## 6. Copy-paste prompts for a new chat

Each prompt is complete. Paste the whole block, including code fences, as your first message. The agent must read this file, then work.

### Prompt for PR0 (Foundation) - this PR
```text
You are on branch arena/01a0bd1c-okveggies for praxisjbs/Okveggies.
Read docs/REMAINING_27_FIXES_8PR_PLAN.md Section 0 to 3 PR0.
You are PR0 Foundation. Create and commit docs/REMAINING_27_FIXES_8PR_PLAN.md as written, with the 27 audit fixes, 8 PR split, parallel map, and Section 7 progress ledger.
Quality gates: brand-check.sh 8/8 green, git diff --check clean, no em dash, British spelling, no gold fill. Coverage 100% on docs, confidence 95%+.
Then push to arena/01a0bd1c-okveggies and open a PR to main with gh. Mark Section 7 PR0 row complete in the same commit after the PR opens.
```

### Prompt for PR1
```text
You are on a fresh branch from main, for praxisjbs/Okveggies.
Read docs/REMAINING_27_FIXES_8PR_PLAN.md PR1 Legal Truth and Delivery Truth.
Also read docs/M12_CONTENT_CONTRACT.md, docs/M13_RELEASE_CONTRACT.md Section 4, migrations/003_reference_seed.sql, includes/classes/Cancellation.php, PROGRESS.md M12 and M2.
Build PR1: fixes 1,3,14,18. Implement Monday business day migration 051, decide and document cancellation asymmetry with Owner, close M2 carry-forward, make Terms/Privacy/Delivery Policy attest workflow provable. Follow CLAUDE.md: 5 questions with A/B/C + recommendation before code, prepared statements, Lagos timezone, money in kobo, RBAC, CSRF. Tests: delivery_db_test, cancellation tests, content publishing tests. Check 390/1440 no overflow, 44px. Update Section 7 PR1 row to complete with 100% coverage and confidence >=95% and beauty 95% where UI touched. Commit, push, open PR.
```

### Prompt for PR2
```text
You are on a fresh branch from main, for praxisjbs/Okveggies.
Read docs/REMAINING_27_FIXES_8PR_PLAN.md PR2 Operational Messaging.
Also read includes/classes/Notifications.php, IssueReports.php, IssueResolutions.php, ContactMessages.php, admin/kitchen_runs.php, docs/M13_REVIEW.md fixes.
Build PR2: fixes 15,16,21,22,23. Add item table to admin_new_kitchen_run alert, allow staff-initiated contact thread, lock issue report row before Paystack, batch N+1 queries, consolidate Notifications recipient helpers. One helper, one code path. Tests: issue_*_db_test, contact_admin_*, admin_notifications_*, NotificationsTest. Verify no inner join orphan leak, LEFT JOIN + ZZ prefix. Update Section 7 PR2 row to complete 100% coverage, confidence >=95%, beauty 95% on message composer. Commit, push, open PR.
```

### Prompt for PR3
```text
You are on a fresh branch from main, for praxisjbs/Okveggies.
Read docs/REMAINING_27_FIXES_8PR_PLAN.md PR3 Checkout Beauty and Trust.
Also read checkout.php, includes/classes/Cancellation.php policyLine, assets/css/src/input.css, tailwind.config.js, docs/M10_REVIEW.md trust panel.
Build PR3: fix 13 with a beautiful checkout redesign. Move trust panel below payment radio group as two small shield/trail cards with Paystack badge. Make payment choices large cards with icons, 44px radios, less text more illustration, large forest Pay now button with arrow. Keep delivery picker Lagos rules. Follow beauty rules Section 5, no bg-gold, no outline+text-white. Tests: checkout_db_test, customer_http_test guest checkout, browser 390/1440, axe no critical. Update Section 7 PR3 row to complete 100% coverage, confidence >=95%, beauty 95%. Commit, push, open PR, rebuild tailwind.css and js.
```

### Prompt for PR4
```text
You are on a fresh branch from main, after PR1 has allocated migration 051.
Read docs/REMAINING_27_FIXES_8PR_PLAN.md PR4 Catalogue Truth.
Also read AdminDashboard.php, Products.php, Catalogue.php, Checkout.php, KitchenRunWorkflow.php, tailwind.config.js.
Build PR4: fixes 17,19,20. Add products.source_region migration 053 guarded via information_schema, editable in admin/products.php, shown on product.php and cards with fallback to site_settings. Add order_items.snapshot_category_id migration 054 guarded, write it on order placement and kitchen run conversion, make AdminDashboard category share read snapshot. Replace text-[10px]/text-[11px] with named tokens in tailwind.config.js and rebuild. Tests: admin_dashboard_db_test with rename/move proof, catalogue tests, BrandAssetsTest. Update Section 7 PR4 row to complete 100% coverage, confidence >=95%. Commit, push, open PR.
```

### Prompt for PR5
```text
You are on a fresh branch from main, for praxisjbs/Okveggies.
Read docs/REMAINING_27_FIXES_8PR_PLAN.md PR5 Infrastructure Hardening.
Also read public/cron.php, scripts/cron.php, includes/classes/Cron.php, docs/DEPLOYMENT.md, .github/workflows/ci.yml, deploy.yml, scripts/verify.sh, includes/bootstrap.php.
Build PR5: fixes 8,9,10,11,12,24,25. Build app-level maintenance setting with Owner toggle and 503 Retry-After for anonymous storefront, keep staff/healthcheck live. Create GitHub production environment with required reviewers, make deploy.yml use environment: production. Write staging cPanel steps addon/subdomain same Apache, own DB/env test keys noindex. Make ci.yml run MySQL 8 + SMTP sink + Paystack stand-in with 0 skipped. Ensure deploy uploads .htaccess+.user.ini explicitly and verify.sh 403 on protected paths. Name release owners in plan. Single cron line: */5 * * * * curl -fsS -H X-Migrate-Token YOUR_TOKEN https://okveggies.com.ng/public/cron.php . Do not schedule php scripts/cron.php --job. Tests: release_gate preflight 6 unsafe .env shapes, verify.sh vs staging Apache. Update Section 7 PR5 row to complete 100% coverage, confidence >=95%. Commit, push, open PR.
```

### Prompt for PR6
```text
You are on a fresh branch from main, after PR3 and PR4.
Read docs/REMAINING_27_FIXES_8PR_PLAN.md PR6 Performance and Accessibility Beauty Pass.
Also read index.php hero, shop.php, product.php, ContentImages.php, docs/M13 contract 8 and 7.2, assets.
Build PR6: fixes 2,6,7 with 95% beauty. Optimise hero as responsive WebP 640/960/1280 with explicit dimensions, fetchpriority high for hero only, lazy others, bring 24 catalogue JPEGs under 2MB initial transfer with lazy+dimensions. Close 3,780ms breach to FCP <=3.0s LCP <=4.0s CLS <=0.1 on frozen profile. Add axe-core Playwright gate over public/customer/Pro/admin/FAQ/checkout/trail/Make It Right/content, 0 critical/serious, record NVDA/VoiceOver checklists. Beautiful illustrative empty states with 2 buttons, skeleton loaders, gold focus, reduced-motion. Tests: homepage_visual_test.mjs throttled 390/1440, visual_pass.mjs, new axe suite, brand-check green, lighthouse trace. Update Section 7 PR6 row to complete 100% coverage, confidence >=95%, beauty 95%. Commit, push, open PR.
```

### Prompt for PR7
```text
You are on a fresh branch from main, after PR1 to PR6 have merged.
Read docs/REMAINING_27_FIXES_8PR_PLAN.md PR7 Release Gate and Privacy Handover.
Also read scripts/tests/release_gate.sh, lib/scratch_guard.php, fixture_orphans.php, docs/M13 contract Section 17, PROGRESS.md M13.
Build PR7: fixes 4,5,26,27 final proof. Freeze one SHA, prove fresh MySQL 8 migration twice 0 pending, run full gate from clean env with php -l 311, node --check, brand 8/8, unit 3374+, every DB/HTTP by glob 0 failed 0 skipped, browser 390/1440, verify.sh, fixture_orphans LEFT JOIN + ZZ. Capture cPanel backup off-host checksums + timed restore drill reading representative records and private photo. Store versioned rollback artifact off-host, rehearse on staging. One low-value live Paystack transaction reconciled, SMTP SPF/DKIM/DMARC with 2 inbox placements. Make repo private only after collaborator/Actions audit + Owner approval + deploy retest. Tick PROGRESS.md M13 boxes only here against frozen SHA evidence. Write docs/M13_REVIEW final. Update Section 7 PR7 row to complete 100% coverage, confidence >=95%. Commit, push, open PR.
```

---

## 7. Progress ledger - the AI must update this after each PR

Mark `[ ]` to `[x]` only when coverage 100% and confidence at least 95% and beauty at least 95% where UI touched. Add SHA and date.

| PR | Title | Fixes | Status | Coverage | Confidence | Beauty | Merged SHA | Date |
|----|-------|-------|--------|----------|------------|--------|------------|------|
| PR0 | Foundation and plan | - | [x] Done `a936d8e` PR #54 open | 100% docs | 98% | - | `a936d8e` | 20 Sep 2026 |
| PR1 | Legal Truth and Delivery Truth | 1,3,14,18 | [ ] Not started | - | - | 95% | - | - |
| PR2 | Operational Messaging | 15,16,21,22,23 | [ ] Not started | - | - | 95% | - | - |
| PR3 | Checkout Beauty and Trust | 13 | [ ] Not started | - | - | 95% | - | - |
| PR4 | Catalogue Truth and Analytics | 17,19,20 | [ ] Not started | - | - | 95% | - | - |
| PR5 | Infrastructure Hardening | 8,9,10,11,12,24,25 | [ ] Not started | - | - | 95% maint page | - | - |
| PR6 | Performance and Accessibility Beauty | 2,6,7 | [ ] Not started | - | - | 95% | - | - |
| PR7 | Release Gate and Privacy Handover | 4,5,26,27 | [ ] Not started | - | - | - | - | - |
| **Total** | **27 fixes** | **1 to 27** | **0 of 7 remaining, PR0 100%** | **100% mapped** | **>=95% required** | **>=95% on UI PRs** | - | - |

**How to update:** After green, change `[ ]` to `[x] Done`, fill coverage `100%`, confidence `97%` etc., beauty `96%`, SHA `abc1234`, date. Keep row honest, never mark green with failing suite.

---

## 8. Cron job and environment - what you must set

Everything the shop needs to run after code is deployed. Share this with whoever has cPanel and `.env` access. Do not commit secrets.

### 8.1 Server `.env` - required keys

Copy `.env.example` to `.env` on the server. Fill these before the first deploy:

```ini
APP_URL=https://okveggies.com.ng
APP_ENV=production
APP_DEBUG=false
APP_TIMEZONE=Africa/Lagos
CURRENCY=NGN

DB_HOST=localhost
DB_PORT=3306
DB_NAME=ibbbnlso_okveggies
DB_USER=ibbbnlso_okveggies_admin
DB_PASS=*** your cPanel DB password ***
DB_CHARSET=utf8mb4

SESSION_LIFETIME_MINUTES=480
SESSION_COOKIE_NAME=okv_session
SESSION_COOKIE_SECURE=true
SESSION_COOKIE_HTTPONLY=true
SESSION_COOKIE_SAMESITE=Lax

BCRYPT_COST=12
CSRF_TOKEN_LENGTH=32
APP_ENCRYPTION_KEY=*** openssl rand -base64 32 ***
PASSWORD_MIN_LENGTH=10
LOGIN_MAX_PER_IDENTIFIER=5
LOGIN_MAX_PER_IP=20
LOGIN_WINDOW_SECONDS=900

# One token for both migrate and cron. Strong random: openssl rand -hex 32
MIGRATE_TOKEN=*** openssl rand -hex 32, same as GitHub secret ***

# Remove SETUP_TOKEN after first Owner exists, or leave blank
SETUP_TOKEN=

SMTP_HOST=mail.okveggies.com.ng
SMTP_PORT=465
SMTP_ENCRYPTION=ssl
SMTP_USER=noreply@okveggies.com.ng
SMTP_PASS=***
SMTP_FROM_EMAIL=noreply@okveggies.com.ng
SMTP_FROM_NAME=OK Veggies
SMTP_TIMEOUT=10

# Live keys are live money. Until you have proved test mode end to end, use sk_test_ and pk_test_
PAYSTACK_SECRET_KEY=sk_live_***
PAYSTACK_PUBLIC_KEY=pk_live_***
PAYSTACK_BASE_URL=
WHATSAPP_SUPPORT_NUMBER=2348000000000
UPLOAD_MAX_BYTES=5242880
LOG_ERRORS=true
DISPLAY_ERRORS=false
ERROR_LOG_PATH=/home/ibbbnlso/logs/okveggies_error.log
```

`PAYSTACK_BASE_URL` must be blank in production. It is only for the stand-in gateway in staging or local test, and is ignored unless the secret is `sk_test_`.

### 8.2 Staging `.env` - must differ

On the addon/subdomain staging host:

```ini
APP_URL=https://staging.okveggies.com.ng
APP_ENV=staging
DB_NAME=ibbbnlso_okveggies_staging_test
MIGRATE_TOKEN=*** different strong value, not production ***
PAYSTACK_SECRET_KEY=sk_test_***
PAYSTACK_PUBLIC_KEY=pk_test_***
SMTP_HOST=127.0.0.1  # or staging sink, never production SMTP
```

`DB_NAME` must end in `_test` so the destructive `release_gate.sh` and `scratch_guard.php` allow runs. `APP_ENV` must never be `production` on staging.

### 8.3 cPanel cron - the only job the host needs

The shop logic is shared by `includes/classes/Cron.php` for both cron paths. Do not schedule `php scripts/cron.php`. The host has no shell. Do not schedule two jobs.

**One job, every 5 minutes, token in header not URL so it does not land in access logs:**

```cron
*/5 * * * * curl -fsS -H "X-Migrate-Token: YOUR_TOKEN" https://okveggies.com.ng/public/cron.php > /dev/null
```

For staging:

```cron
*/5 * * * * curl -fsS -H "X-Migrate-Token: YOUR_STAGING_TOKEN" https://staging.okveggies.com.ng/public/cron.php > /dev/null
```

**What it does:** Payment sweep asks Paystack for open payments where customer closed tab, credits ledger idempotently; also sends the single 30 minute `payment_reminder` where still unpaid and not cancelled. One row, so once is structural.

**Why header:** `X-Migrate-Token` keeps token out of logs. `-fsS` makes curl exit non-zero on failure so cPanel emails on failure only.

**Confirm:** Set Cron Email at top of cPanel Cron page to your alert address. Test by visiting `https://okveggies.com.ng/public/cron.php?token=YOUR_TOKEN` once, it prints one line per job and ends `CRON OK`. Wrong token prints 404, same as `public/migrate.php` and `public/healthcheck.php`.

**Common mistake corrected:** `docs/CLIENT_MEETING_AUDIT_AND_IMPROVEMENTS.md` pr52 suggested `php /home/ibbbnlso/public_html/scripts/cron.php --job=payment_sweep` and a second `--job=daily`. That is CLI only, takes a limit integer not `--job`, and the host has no shell. Use the URL job above. The single URL job runs both sweep and reminders because both share `Cron::run()`.

### 8.4 GitHub secrets - must match server

In GitHub Settings then Secrets and variables then Actions, set:

| Secret | Value source |
|--------|--------------|
| `SFTP_HOST` | `51.79.17.60` |
| `SFTP_PORT` | `1624` |
| `SFTP_USER` | `ibbbnlso` |
| `SFTP_PASSWORD` | cPanel SFTP password |
| `SFTP_REMOTE_PATH` | docroot `/home/ibbbnlso/public_html` for prod, staging path for staging |
| `APP_BASE_URL` | `https://okveggies.com.ng` |
| `MIGRATE_TOKEN` | same value as server `.env` `MIGRATE_TOKEN` |

Deploy workflow uploads `_dist/*` then explicitly `/.htaccess` and `/.user.ini` as single files, then calls `public/migrate.php` with `X-Migrate-Token` header and expects `MIGRATE OK`, then `verify.sh`. If `MIGRATE_TOKEN` mismatches, migrate returns 404.

### 8.5 After first Owner exists

Remove `SETUP_TOKEN` line from production `.env` and delete `public/setup.php` reachability by confirming it returns 404 without token. The deploy never deletes server files, so this needs a hand check via `curl -i https://okveggies.com.ng/public/setup.php`.

---

## 9. Parallel plan summary for the manager

- **Can run in parallel immediately after PR0:** PR1, PR2, PR3, PR5. Four teams can start at once.
- **Needs PR1 migration slot:** PR4 after PR1 decides `051`. Can start early on branch, but cannot merge until PR1 number is free.
- **Needs PR3 and PR1/PR4:** PR6 after PR3 tokens and PR4 publishable photo fields. Can prep early, final perf run needs hero.
- **Must be last:** PR7 after PR1 to PR6, it freezes SHA and proves gate + privacy.

**Fastest realistic path:** PR0 Day 0, PR1+PR2+PR3+PR5 in parallel Day 1 to 3, PR4 Day 3 to 4, PR6 Day 4 to 6, PR7 Day 7 to 8. Legal copy and photo are longest lead, so owner dispatch on Day 0 matters most.

---

## 10. What good looks like on completion

After PR7, `PROGRESS.md` M12 `[ ] Home` becomes `[x]` with published photo, `[~] Our Story ...` becomes `[x]` with 3 legal pages published and attested, M13 `[ ]` boxes become `[x]` with frozen SHA evidence, repo visibility is `private` after owner approval, staging proves every gate, production smoke is `200` and `verify.sh` is `29/29` on live, and Section 7 above shows `7/7` complete, `100%` coverage, `>=95%` confidence on every row.

---

## 11. PR0 deliverable checklist

- [x] This document exists in `docs/REMAINING_27_FIXES_8PR_PLAN.md` with 27 fixes mapped to 8 PRs, no shipped file edited outside scope
- [ ] `PROGRESS.md` Current focus notes this plan and its branch
- [ ] `brand-check.sh` 8/8 green, `git diff --check` clean, no em dash, British spelling, no `bg-gold` fill
- [x] Cron and env section 8 complete and copy-paste ready
- [x] Prompts in Section 6 are complete and paste-ready for a new chat
- [x] Parallel map and merge order in Section 4 are unambiguous
- [ ] PR opened from `arena/01a0bd1c-okveggies` to `main` via `gh`

PR0 confidence: 98% docs only, no migration, no runtime change. Coverage: 100% on planning. Next step is PR1.

---

*PR0 Foundation. 20 September 2026. For Kumbish Emmanuel Putleh and JBS Praxis. Verified against `a47a319` and `b2f1f43`. Private at handover, not before.*

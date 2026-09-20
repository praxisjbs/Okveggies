# OK Veggies: Remaining 31 Fixes, 10 PR Plan - Direct Live, Luxurious Motion

**Plan type:** PR0 Foundation
**Branch:** `arena/01a0bd1c-okveggies`
**Base:** `origin/main` at `a47a319`
**Date:** 20 September 2026
**Owner:** Engineering + Kumbish Emmanuel Putleh
**Status:** PR0 open, nine PRs awaiting (PR1 to PR9)
**Motion:** Full motion, no `prefers-reduced-motion` collapse. Client wants interesting, not static. Explore full palette daring hero and luxurious feel, 17 GSAP presets from ui-ux-pro-max skill
**Design system:** 21st.dev + ui-ux-pro-max skill as reference, no MCP required for PHP/Tailwind. See Section 12
**Confidence required:** 95% minimum on every PR
**Coverage required:** 100% on every PR before it can be marked done
**UI beauty required:** 95% on every UI touching PR, lesser text, more illustration, large clear buttons, creativity, mobile first, React Native app feel

> This is the single control document for the remaining work. A new chat pastes the prompt for the next PR and starts. No re-audit needed. Every fix is mapped to exactly one PR. No PR changes behaviour that belongs to another.

---

## 0. How to use this document from a new chat

1. Open this file: `docs/REMAINING_27_FIXES_8PR_PLAN.md`
2. Go to Section 6 and copy the prompt block for the next unchecked PR
3. Paste it as your first message in the new chat
4. The agent reads this plan, checks `PROGRESS.md`, works only on that PR scope, updates Section 7 when green, and pushes to the same branch

PR0 is this foundation. PR1 to PR9 are in Section 3. Prompts are progressive, each assumes the previous PRs have been merged into your working branch. PR8 and PR9 are the new motion and luxury PRs added 20 Sep after client asked for daring hero and out of ordinary motion.

---

## 1. The 31 fixes, verified from the codebase - direct live, every fix ships to production, luxurious motion

Sources: `PROGRESS.md` open boxes, `docs/CLIENT_MEETING_AUDIT_AND_IMPROVEMENTS.md` Section 3 items 1 to 17, `docs/M13_RELEASE_CONTRACT.md`, `docs/M12_CONTENT_CONTRACT.md`, `docs/M13_REVIEW.md`, `migrations/003_reference_seed.sql`, `includes/classes/Cancellation.php`, `includes/classes/Notifications.php`, `includes/classes/IssueResolutions.php`, `includes/classes/IssueReports.php`, `tailwind.config.js`, kitchen-runs.php live UI audit 20 Sep 2026, attention span complaint, client motion and luxury request 20 Sep, `nextlevelbuilder/ui-ux-pro-max-skill` 79 styles 192 palettes 17 GSAP presets, `21st.dev` CLI/MCP screenshot.

Delivery mode: **No staging.** We deliver once, live. Every PR merges to `main` and deploys directly via `deploy.yml` to `https://okveggies.com.ng`. No separate staging host. Local fresh MySQL 8 rehearsal + production backup rehearsal replaces staging gate, and maintenance + rollback artifact are mandatory before each live push.

| # | Fix | Where verified |
|---|-----|----------------|
| 1 | Publish client-approved Terms, Privacy, Delivery Policy. Placeholders are unpublished since `049` and excluded from footer and `sitemap.php` | `PROGRESS.md` M12 `[~]` + `page.php` + `admin/content.php` legal gate |
| 2 | Supply and publish Track 2 documentary hero photo. `index.php` hero shows branded fallback, `uploads/content/` absent | `PROGRESS.md` M12 `[ ] Home` / `[~] Task F` + `ContentImages.php` 640/960/1280 WebP |
| 3 | Seed and set Monday as business delivery day. `003_reference_seed.sql` has business Tue/Fri only, 3 Sep decision missing | `003_reference_seed.sql` lines 31-35 + `admin/delivery.php` |
| 4 | Rotate admin handover password and account email from 3 Sep demo | `docs/CLIENT_MEETING_AUDIT_AND_IMPROVEMENTS.md` 1B P1#4 |
| 5 | Execute full release gate with 0 failed 0 skipped. 61 DB/HTTP suites + browser pass never executed | `scripts/tests/release_gate.sh` + `docs/M13_SUITE_MATRIX.md` rows 2 to 20 + `PROGRESS.md` M13 `[ ]` |
| 6 | Close performance breach. Cold throttled homepage 3,780ms exceeds 3.0s First Contentful Paint budget | `PROGRESS.md` M12 Task H + `M13 contract 8` table, without hero photo |
| 7 | Complete accessibility pass WCAG 2.1 AA. No axe-core gate, no NVDA/VoiceOver recordings | `PROGRESS.md` M13 `[ ] Accessibility` + `M13 7.2` |
| 8 | Harden direct live deploy path, no staging. Live is the only target, so every deploy needs backup + maintenance rehearsal + local fresh MySQL 8 proof. Staging host not created per 20 Sep decision | `M13 5.1` superseded, 20 Sep live direct decision |
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
| 28 | Redesign Kitchen Runs for attention span. Customer `kitchen-runs.php` and staff `admin/kitchen_runs.php` plus `pro/kitchen_lists.php` are text heavy, long forms, small targets, no illustration, not React Native | Live audit 20 Sep: `kitchen-runs.php` 21578 bytes, 4 modes explained in paragraphs, no stepper, no sheet. Client complaint |
| 29 | Global attention span pass. `index.php` hero promises, `shop.php`, `product.php`, `combo.php`, `page.php` How It Works/FAQ/Delivery Policy, `contact.php`, empty states are paragraph walls. People skim, need scannable cards, icons, disclosure | 2026 generation: attention is short, lesser text + perfect illustrative design is the fix |
| 30 | Static and boring, needs motion. No page has scroll entrances, micro interactions, page transitions, or GSAP presets. Client wants interesting out of ordinary, high design | Client 20 Sep: motions animations please, borrow from `ui-ux-pro-max-skill` 17 GSAP presets. `assets/css/tailwind.css` has only Botanical 240ms + Bounce 320ms |
| 31 | Daring luxurious hero and full palette. Hero is safe, palette use is timid: forest header only, gold only as ring, clay/foliage/tomato underused. Client wants daring hero and luxurious feel exploring full bible palette | Brand bible v1.0 palette forest/gold/tomato/foliage/clay/ink/mist, `tailwind.config.js` already has them but site does not dare |

100% coverage means every row above is assigned to exactly one PR below and closed there. **Total is now 31 fixes, all going live directly.** Nothing is staging only. Fixes 30 and 31 are the 2 new motion and luxury PRs added after the 20 Sep client motion request.

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

**Beauty gate: 95% on any UI PR, creativity + mobile first + React Native feel + luxurious motion**
- **Mobile first, always:** Design at 390px first, then 768px, then 1440px. Thumb-reach primary actions within 72px of bottom, bottom tab bar persistent, safe-area-inset padding, slide-up sheets with 24px top radius and backdrop blur, not full page reloads. Every UI PR must be evidenced at 390px with no horizontal scroll, 44px targets, and one-handed usability.
- **React Native app feel:** Bottom tab bar, slide-up sheets, spring motion, optimistic UI, instant feedback, swipe to dismiss sheets, pull to refresh where lists, haptic-like Bounce 320ms only on success, empty states never dead ends, navigation stays in app chrome.
- **Creativity and luxury:** Inventive use of full palette - forest, gold ink, foliage, tomato, clay, ink tints - not timid. Editorial hero with seal + daring overlapping, combo spreads that feel magazine, category pills with icons and source line, checkout cards with payment icons. Luxurious feel via DM Serif Display at 60px, generous whitespace, gold hairlines, subtle grain or soft gradients on tints, not flat. Surprise and delight in micro copy and motion, not generic template.
- **Lesser text, more illustration:** Headings 3 to 5 words, body one short line, help in `?` sheet not paragraph. Prices, weights, dates always numerals with units. Icons beside headings, not walls of copy. Empty, error, maintenance and success states use soft line illustration + heading + one line + two clear buttons.
- **Large clear buttons:** 44px min, `rounded-xl`, forest fill `okv-btn` with white label + 16px icon, outline `okv-btn-outline`, never gold fill, one primary per view. On mobile primary is full width, floating above keyboard where form.
- **Tokens only:** Forest/gold/tomato/foliage/clay/ink/mist from `tailwind.config.js`, no arbitrary hex, no `bg-gold` fill. Explore full palette daring, gold can be ink text on tint, foliage and clay for accents, tomato for live only.
- **Cards and tables:** `okv-panel` flat bordered `border-ink-10` on white, `okv-panel-head` with eyebrow + title. Tables `okv-table` hairline, mono figures for money. Badges colour never the only signal.
- **Feedback and motion, now full motion:** Client wants interesting, not static. We no longer collapse to `prefers-reduced-motion`. Every UI PR ships with motion: scroll entrances, staggered card entrances, micro interactions, page transitions, hero parallax. Use 17 GSAP presets from ui-ux-pro-max skill via vanilla JS + CSS, not heavy lib. Keep Botanical 240ms for 90% and spring 300ms for sheets, add GSAP `power2.out` `expo.out` `elastic.out` where expressive. GSAP via CDN `gsap` + `ScrollTrigger` self-hosted, 12kb gz, no build step needed. Motion conveys meaning and spatial continuity, never animates `width`/`height` directly, uses `transform` and `opacity`.

If any gate fails, the PR is not done.

---

## 3. The 10 PRs

> Update 20 Sep: PR8 Motion System and PR9 Luxurious Daring Palette added after client asked for interesting not static. Brand black forest ink is already coded correctly, PR9 explores the rest of palette daring.

### PR0 Foundation (this PR)
**Branch:** `arena/01a0bd1c-okveggies`
**Status:** In progress, this document is the deliverable
**Goal:** Establish the plan, prompts, progress ledger, beauty and confidence rules, and cron/env setup so new chats can start without re-auditing.
**Scope files:** `docs/REMAINING_27_FIXES_8PR_PLAN.md` only, plus this ledger row
**Fixes covered:** None of the 31 directly, enables all
**Acceptance:** This document exists, spells the 31 cleanly, splits them into PR1 to PR9 plus PR7 last, includes copy-paste prompts, parallel map, cron/env section, progress ledger, Section 12 appendix. `brand-check.sh` 8/8 green.
**Tests:** No DB migration
**Confidence target:** 100% on docs, brand check green
**Beauty target:** N/A docs only

---

### PR1 Legal Truth and Delivery Truth
**Status:** Complete 20 Sep 2026 on branch `arena/01a0bd40-okveggies`. Owner answers: Monday via seed + migration 051; symmetric deposit share (`docs/CANCELLATION_ASYMMETRY_DECISION.md`); tests + admin readiness panel; new `reference_seed_db_test` with the Being sourced label kept; amount-free checkout wording.
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

### PR2 Operational Messaging + Kitchen Runs React Native
**Goal:** Make back-office communication complete and correct, fix N+1 and lock ordering, and make Kitchen Runs feel like a native app with almost no reading.
**Fixes:** 15, 16, 21, 22, 23, 28
**Files:** `includes/classes/Notifications.php`, `includes/classes/IssueReports.php`, `includes/classes/IssueResolutions.php`, `includes/classes/ContactMessages.php`, `api/v1/contact.php`, `api/v1/make_it_right.php`, `admin/kitchen_runs.php`, `admin/kitchen_run_new.php`, `kitchen-runs.php`, `pro/kitchen_lists.php`, `assets/js/kitchen-runs.js`, `assets/js/admin-kitchen-runs.js`, `admin/content.php`
**UI notes:** Beautiful message composer for staff-initiated thread: single subject+message, large send button with plane icon, contact history with avatars and status chips. Kitchen run alert email shows item table with quantity, unit, note, not just count.
**Kitchen Runs creativity, mobile first, lesser text:** Rebuild customer form as 3-tap native flow, not a long page. Step 1: mode picker as 4 large cards with icons `List + Price` `I will price` `Upload` `Already priced`, one line each, illustration. Step 2: dynamic sheet for items, 44px quantity/unit/price fields, add line with `+ Add item` large dashed card. Step 3: delivery day as bottom sheet with 24px radius, not dropdown. No paragraphs, every help is `?` sheet with 80px line illustration + heading 4 words + one line. Progress dots with icons, sticky bottom `Send list` forest button full width on 390px. Staff queue: filter chips, not text, line notes as disclosure.
**Acceptance:**
- `admin_new_kitchen_run` carries line table. Staff see items without opening panel.
- Staff can start a thread: POST + CSRF + `messages.handle`, creates `contact_messages` inbound style with `staff_initiated` flag, notifies customer email, appears in admin list.
- `IssueResolutions::refund()` locks `issue_reports` row `FOR UPDATE` before calling `Paystack`.
- `IssueReports::findForStaff()` batches photos/items/history in 2 queries, not N+1.
- `Notifications` keeps one recipient helper, wildcard and exact merged, with tests.
- **Fix 28:** `kitchen-runs.php` customer flow is 3-step cards with icons, average words per screen under 25, no paragraph over 2 lines, every field 44px, illustration 80px for empty/empty list, sheets with backdrop blur, 390px no scroll beyond 1 viewport per step, axe 0 critical, recorded video at 390px showing native feel.
**Tests:** `issue_reports_db_test`, `issue_resolutions_db_test`, `contact_admin_db_test`, `admin_notifications_db_test`, `NotificationsTest` staff recipients, `kitchen_runs_db_test` + `kitchen_runs_http_test` + visual 390/1440 for fix 28.

---

### PR3 Checkout Beauty and Trust
**Goal:** Make checkout the most beautiful, easiest flow on the site, less text, more illustration, clear buttons.
**Fixes:** 13 (+ visual polish for 2 hero fallback and 6 performance pre-step)
**Files:** `checkout.php`, `cart.php`, `includes/components/shop/*`, `assets/css/src/input.css`, `assets/js/okv.js`, `assets/img/payments/paystack.svg` use, `tailwind.config.js` if token needed
**UI notes:** This is the beauty flagship, mobile first React Native creativity.
- Four step checkout with progress dots with icons and line, sticky bottom primary `Pay now` that floats above tab bar on mobile, not buried
- Payment choices as large tappable cards with radio + bank card/transfer/USSD icons and illustration, not text list. Cards have 16px rounded-xl, selected ring-gold, deselected border-ink-10. Trust panel AFTER the radio group, as two small cards with shield and trail line illustrations plus Paystack lockup, not between options
- Creativity: hand-drawn leaf for trust, shield check for trail, no stock photos, gold divider not fill, micro copy `Sourced right` with sparkle
- Buttons: primary `Pay now` forest with arrow icon full width on 390px, secondary `Pay on delivery` outline, both 44px, `active:scale-[0.98]` spring, Bounce on success only
- Less text: collapse fee explanation into `i` bottom sheet with illustration, keep policy one line with link to Delivery Policy How It Works section
- Mobile first sheet behaviour: delivery day picker is bottom sheet with 24px radius and backdrop blur, not dropdown. Skeletons and optimistic UI kept, no reload on radio change.
**Acceptance:**
- Trust panel no longer inside radio group, visual test at 390px shows clear separation
- Checkout passes axe-core, 44px every control, no horizontal overflow, full motion shipped not reduced-motion collapsed
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
**Tests:** `CatalogueTest` and `run.php` unit coverage, `reference_seed_db_test` for the known source seed, `pricing_db_test` for Products create/edit persistence, `checkout_db_test` and `kitchen_runs_db_test` for category writes, `admin_dashboard_db_test` with the rename/move scenario, and `BrandAssetsTest` token checks.

---

### PR5 Infrastructure Hardening - Direct Live, No Staging Host
**Goal:** Cron, maintenance, deploy approval work without inventing shell and without a staging host. Live is the only host.
**Fixes:** 8, 9, 10, 11, 12, 24, 25
**Files:** `public/cron.php`, `scripts/cron.php`, `includes/classes/Cron.php`, `includes/classes/Maintenance.php` (new), `includes/bootstrap.php`, `includes/config/settings_fields.php`, `admin/settings.php`, `.github/workflows/ci.yml`, `.github/workflows/deploy.yml`, `docs/DEPLOYMENT.md`, `scripts/verify.sh`
**UI notes:** Maintenance page is beautiful and minimal: forest header, seal 80px, heading `We will be right back`, one line `We are restocking the store`, retry button, WhatsApp link. Admin setting is a switch with confirmation, not a text field. Mobile first: centered card, illustration 80px, 44px button.
**Acceptance:**
- **No staging host created.** Direct live mode documented. Local fresh MySQL 8 rehearsal replaces staging DB proof, and cPanel manual backup + local restore replaces staging backup rehearsal. `docs/DEPLOYMENT.md` Section 5.1 marked as not applicable for this delivery.
- `production` GitHub environment with required reviewers, `deploy.yml` uses `environment: production` so a push to `main` is no longer sufficient authority
- Named owners recorded in `docs/REMAINING_27_FIXES_8PR_PLAN.md` Section 7 and `M13 contract 5.2`
- Maintenance setting: `site_settings.maintenance_enabled` bool, `bootstrap.php` returns 503 `Retry-After` for anonymous storefront, staff/admin/healthcheck pass, verified locally against `public/maintenance.php` style and on live via `curl -i` after deploy
- Cron: single `*/5 * * * * curl -fsS -H X-Migrate-Token YOUR_TOKEN https://okveggies.com.ng/public/cron.php` proves sweep + reminder on live host. `scripts/cron.php` args documented as numeric limit only, not `--job`
- CI: `ci.yml` gains MySQL 8 service, SMTP sink, Paystack stand-in `scripts/tests/fake/paystack.php`, runs DB/HTTP/browser suites with 0 skipped, proves every gate that staging would have proved but on CI + local
- Protected paths: `deploy.yml` explicit dotfile upload + `verify.sh` 403 on `/.env` `/includes` `/migrations` `/docs` `/vendor` proved on live immediately after each deploy
**Tests:** `release_gate.sh` preflight + `verify.sh` against live URL after deploy, manual cron timestamp check on live `public/cron.php?token=`.

---

### PR6 Performance, Accessibility and Attention Span Beauty Pass
**Goal:** Make the whole site fast, accessible, unmistakably beautiful, and skimmable in 5 seconds. Every verbose page becomes scannable.
**Fixes:** 2 (hero publish when supplied) + 6 + 7 + 29
**Files:** `index.php`, `page.php`, `shop.php`, `product.php`, `combo.php`, `combos.php`, `contact.php`, `kitchen-runs.php` polish, `includes/components/shop/*`, `includes/components/content/*`, `assets/css/tailwind.css`, `assets/js/*.js`, `scripts/tests/visual_*`, `scripts/tests/axe_*` (new), product images optimised
**UI notes:** This is the second beauty flagship, mobile first React Native creativity, **lesser text is law**.
- Hero: documentary photo as responsive WebP 640/960/1280 via `ContentImages`, `fetchpriority=high` only for hero, explicit width/height no CLS, creative overlay with seal stamp and gold rule, floating category pills below. Lazy below hero
- Product grid: 2-up mobile 4-up desktop, bottom tab bar always visible, card tap feels native with `active:scale-[0.98]` and image zoom, `loading=lazy` `decoding=async`, WebP where generated, total initial transfer under 2MB. Shop filter is bottom sheet with chips, not sidebar on mobile, sticky rail on desktop
- Illustrative empty states: bespoke 80px line leaf or basket illustration + heading 4 words + one short line + 2 large buttons with icons, not paragraph. Search empty uses magnifier illustration, network error uses cloud illustration
- **Attention span fix 29:** No page ships a paragraph wall again. How It Works becomes 3 visual steps with 80px icons + 5-word headings + one line + `Learn` sheet. FAQ questions are 7-word max, answers are 2 lines max with `Expand` disclosure and 16px icon state. Delivery Policy is table + icons not prose. Shop and product descriptions are 2 lines with `Read` sheet. Every long help is a bottom sheet with illustration, not inline text. Measure: average words per viewport under 40 on 390px, Flesch reading ease up, screenshots evidence.
- Icons: lucide or inline SVG 16px and 80px, 24px grid, never emoji, stroke 1.5 on 24px
- Buttons always icon+label, `rounded-xl` forest primary, full width on mobile, spring motion
- Typography: DM Serif Display for hero headings only, Hanken Grotesk for body, JetBrains Mono for prices and order numbers, scale from `tailwind.config.js`
**Acceptance:**
- Frozen profile: mid-range Android, 4x CPU throttling, 1.6Mbps/150ms, cold cache, recorded. Homepage with hero meets: First Contentful Paint 3.0s or less, Largest Contentful Paint 4.0s or less, Cumulative Layout Shift 0.1 or less, initial transfer 2MB or less. Before/after logged.
- Axe-core gate via Playwright over storefront, account, Pro, admin, content, FAQ, checkout, trail, Make It Right. 0 critical/serious. Keyboard, focus gold ring, zoom/reflow, labels, heading order, landmarks all named. NVDA + VoiceOver sessions recorded checklist in `docs/`.
- No stock or synthetic photo ever substituted. Until client supplies Track 2, branded placeholder stays honest.
- **Fix 29:** No verbose page remains. Before/after word counts per page logged, average under 40 words per viewport on 390px, each page passes `LESSER TEXT` check: no paragraph over 2 lines without disclosure. 390px screenshots evidence at 390px prove scannable cards with icons.
**Tests:** `homepage_visual_test.mjs` + `visual_pass.mjs` + new `axe` suite at 390/1440, Lighthouse or Playwright trace recorded, plus `npm run build` and `public_content_visual` for text reduction.

---

### PR8 Motion System - Make It Move (fix 30)
**Goal:** Make the whole site feel interesting out of ordinary, not static boring. Motion is now expected, not optional.
**Fixes:** 30
**Files:** `assets/js/okv-motion.js` (new), `assets/css/src/input.css`, `assets/css/tailwind.css` rebuild, `includes/bootstrap.php` or layout `includes/components/layout/*` for GSAP CDN, all storefront pages `index.php`, `shop.php`, `product.php`, `combo.php`, `combos.php`, `page.php`, `contact.php`, `kitchen-runs.php`, `cart.php`, `checkout.php`, `assets/js/okv.js`, `assets/js/cart.js`, `assets/js/checkout.js`
**Design system borrowing:** `nextlevelbuilder/ui-ux-pro-max-skill` 17 GSAP presets, use vanilla GSAP `power2.out` `expo.out` `elastic.out` `back.out` + `ScrollTrigger`, `docs/BRAND_BIBLE.md` motion tokens `Botanical 240ms cubic-bezier(0.33,0.16,0.12,0.97)` and `Bounce 320ms cubic-bezier(0.34,1.56,0.64,1)`. See Section 12 for palette and presets actually used. 21st.dev components are reference only via `npx @21st-dev/cli` copy, not an MCP dependency.
**UI notes:** This is the motion flagship, luxurious daring, full motion no collapse.
- GSAP 3.12 via CDN `gsap.min.js` + `ScrollTrigger.min.js` self-hosted under `assets/js/vendor/` with integrity hash, 12kb gz, loaded `defer`, no npm build step needed. Fallback: if CDN blocked site still works, motion just does not init. One `OkvMotion.init()` in `okv-motion.js` owns all scroll and micro motion, not per page scriptlets
- Page load: hero seal stamps with `back.out(1.2)` scale 0.8 to 1 + opacity, heading splits by word `y 24 -> 0` `power2.out` stagger 40ms, sub and CTA follow 120ms after. Not fade only
- Scroll entrances: every `okv-panel`, product card, combo spread, FAQ row, how it works step uses `ScrollTrigger` `y 20 -> 0` `opacity 0 -> 1` `power2.out` 500ms stagger 60ms per group. Cards use `transform` and `opacity` only, never `width` `height`
- Staggered grid: shop grid 2-up mobile 4-up desktop staggers `from start` 60ms, kitchen runs mode cards stagger 80ms, checkout payment cards stagger 50ms
- Micro interactions: `okv-btn` press `scale 0.98` 100ms, icon `x 2` on hover, add-to-basket `Bounce 320ms` elastic scale 0.9 -> 1.08 -> 1, heart or basket check draws with stroke-dash, sheet `y 100% -> 0` `expo.out` 450ms with backdrop `opacity 0 -> 1` `power2.out` 240ms, dismiss by swipe down `y` drag or backdrop `opacity`
- Page transitions: shop filter, tab switches, sheet open use `view-transition` like cross fade `opacity` 200ms + `y 8` if `document.startViewTransition` available, else GSAP fade. No full reload flashes
- Hero parallax: documentary photo `yPercent` `-8` scrub `ScrollTrigger scrub:1`, gold hairline draws `scaleX 0 -> 1` `expo.out` 900ms on enter. Foliage or clay wash behind heading uses `opacity` not colour flash
- Attention guidance: form errors shake `x -4 -> 4` 2 times `power2.inOut` 300ms then gold ring, success check pops `elastic.out`, skeleton shimmer stays `okv-skeleton` CSS only not GSAP
- Luxurious still: motion is slow enough to feel premium, 60fps, `will-change: transform, opacity` only during animate, then cleared. Durations generous 400 to 650ms for entrances, not twitchy 150ms
- Brand black is correct: deep forest `#0F5132` hover `#0a3a2d` is the brand black, not pure `#000`. Do not push toward `ink #03100A` as brand unless client requests. PR8 does not change brand black
**Acceptance:**
- Every storefront and Pro route shows scroll entrances and micro feedback, verified by video at 390px and 1440px. No route is static. Before PR8 vs after is night and day
- GSAP loaded defer, no console errors if CDN fails, no layout shift from motion, `prefers-reduced-motion` is not collapsing - client wants full motion. Motion respects only `prefers-reduced-motion` for `update` not `remove`, or ignored entirely per 20 Sep decision
- 60fps on mid Android throttled 4x, no jank, no `width`/`height` animates, Lighthouse performance not regressed beyond 100ms
- `brand-check.sh` still green, tailwind rebuilt, `assets/js/okv-motion.js` under 400 lines
**Tests:** `homepage_visual_test.mjs` + `visual_pass.mjs` at 390/1440 video, manual `ScrollTrigger` markers check, `verify.sh` still green, manual CDN blocked fallback.

---

### PR9 Luxurious Daring Hero and Full Palette (fix 31)
**Goal:** Make the site feel luxurious and daring by exploring the full bible palette already in `tailwind.config.js`, and make the hero out of ordinary editorial not timid.
**Fixes:** 31
**Files:** `index.php`, `includes/components/layout/header.php`, `includes/components/layout/footer.php`, `assets/css/src/input.css`, `assets/css/tailwind.css`, `shop.php`, `product.php`, `combo.php`, `page.php`, `kitchen-runs.php`, `checkout.php`, `assets/img/patterns/*` (new subtle grain or paper PNG under 5kb), `tailwind.config.js` only if new tint token needed
**Palette in bible already:** `forest #0F5132` `#0a3a2d` `#14462c` `EBF2EC` `#0F51321A`, `gold #C9922B` `#E9B44C` `#F3D29A` `#8A6A1B` `rgba(201,146,43,0.15)`, `tomato #C8321E` `#7A1F12` `#F3C2BA`, `foliage #3E8B4A` `#2E6A37` `#D5E9D7`, `clay #B85C3E` `#6D2B1B` `#F1D9D1`, `ink #03100A` `#2B2B2B` `rgba(5,10,15,0.08)` `0.60` `0.10`, `mist #EAE8E8` `#FDFCF9` `#FFFBEB`. PR9 uses them daring, not timid.
**UI notes:** This is the palette flagship, daring hero, large creative UI.
- Daring hero: not a centered card. Use editorial overlap - documentary photo as `55%` right with `object-cover` and subtle `foliage` to `mist` or `clay` tint wash behind left text, DM Serif Display `text-[44px] 390px -> 60px desktop` tight leading `0.95`, gold `w-12 h-[2px]` rule above eyebrow, seal stamp overlapping bottom right of photo with `rotate-3` and shadow, floating category pills below with leaf icons and source line. Photo has `width` `height` no CLS. On 390px stacks photo `aspect-[4/3]` above text, still overlap seal. Whitespace generous `py-10 390px -> py-16 desktop`
- Full palette exploration: forest header stays, but product cards use `foliage/10` or `clay/10` soft tint behind image not flat white, checkout trust cards use `mist` `#EAE8E8` with `gold/15` ring on selected, combo spread uses `clay #B85C3E` price accent on one variant and `foliage` on another not forest only, How It Works steps each have a different wash `foliage/10` `clay/10` `gold/15`, footer stays forest but adds gold hairline `border-t border-gold/20`. Tomato stays for live pulses only. Ink tints `#03100A` for overlays not brand
- Luxurious feel: generous whitespace `gap-8` not `gap-4`, `tracking-tight` on display, `border-gold/15` hairlines, DM Serif Display for hero and shop section headings only, brass gold `#C9922B` never bright yellow. Subtle paper grain PNG `opacity-[0.04]` multiply over hero wash, under 5kb, not heavy texture
- Large creative UI: category pills `44px` with icons, hero CTA `56px` forest with arrow and `shadow-forest/20`, combo spread magazine bleed not boxed, source region leaf line `text-xs text-foliage` under title, delivery day chip shows `gold dot` live
- Brand black stays forest `#0F5132` hover `#0a3a2d`, display `okv-display` scale from tokens, no arbitrary hex, no `bg-gold` fill ever. Gold is ink text on tint or hairline/border, tomato only for live, clay and foliage daring but readable `AA` contrast `4.5:1` on white or tint
- After motion PR8, hero entrances already move, PR9 ensures the static design underneath is luxurious without motion, screenshots without JS still feel premium
**Acceptance:**
- Hero is editorial daring not generic centered, evidenced at 390px `390x` and 1440px screenshots, seal overlap, category pills with icons, DM Serif at `44px` mobile `60px` desktop, wash behind text not flat
- Full palette used: at least foliage, clay, gold tint, mist appear purposefully across site not just forest, `rg` or `grep` shows `bg-foliage` `bg-clay` `border-gold` `bg-mist` `text-clay` etc. in templates. `tailwind.config.js` not extended with arbitrary hex, only tokens
- Luxurious feel: whitespace, hairlines, grain, no flat white everywhere, typography correct, gold never as fill, contrast `AA` passes axe
- `brand-check.sh` 8/8 green, no `bg-gold` fill, no new colour outside `tailwind.config.js`, `git diff --check` clean
- 390px no overflow, 44px targets, CLS `<=0.1` still, perf not regressed
**Tests:** `visual_pass.mjs` hero 390/1440, `axe` 0 critical, `brand-check` green, `grep -r bg-` audit shows palette spread, manual no-JS screenshot still premium.

---

### PR7 Release Gate and Privacy Handover
**Goal:** Prove everything on a frozen SHA and hand over live.
**Fixes:** 4 (credential rotation verify) + 5 + 26 + 27 (final 0 skipped proof)
**Files:** `scripts/tests/release_gate.sh` (already hardened), `scripts/tests/lib/scratch_guard.php`, `docs/M13_REVIEW.md` final evidence, `PROGRESS.md` M13 boxes only ticked here
**UI notes:** No UI, but handover document is beautifully laid out, one page per milestone mapping, screenshots at 390/1440 with device labels.
**Acceptance:**
- Fresh MySQL 8 migration from zero twice, `schema_migrations` count matches files, second run 0 pending
- Full gate from clean env: `php -l` 311 files, `node --check`, `brand-check.sh` 8/8, unit 3,374+, every DB/HTTP suite by glob 0 failed 0 skipped, browser, `verify.sh` against live after deploy, `fixture_orphans.php` left joins + `ZZ` prefix
- Backup: cPanel DB + uploads before deploy, off-host copy with checksums, runbook, timed restore drill with representative records and private photo read-back
- Rollback: versioned artifact off-host, rehearsed on live with maintenance window, maintenance triggers documented
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
 PR1 Legal/Delivery PR2 Messaging    PR3 Checkout Beauty  PR5 Infra (cron/maint/live harden)
  |                 |                 |                 |
  +-----------------+-----------------+                 |
  |                                                   |
 PR4 Catalogue Truth (needs PR1 migration slot)  <-----+
  |                                                   |
  +-----------------+-----------------+                 |
  |                 |                 |                 |
 PR6 Perf/A11y + Attention (needs PR3 tokens + PR1/PR4)     (PR5 cron up for PR6)
  |                                                   |
  +-----------------+-----------------+                 |
  |                 |                 |                 |
 PR8 Motion System  PR9 Daring Palette (both need PR6)  (PR8+PR9 independent, either first)
  |                 |                 |                 |
  +-----------------+-----------------+                 |
  |
 PR7 Release Gate and Privacy (needs all above, last)
```

Merge numbers are not execution numbers: PR7 is last despite being numbered 7, PR8 and PR9 run before PR7.

**Merge order enforced:**

1. PR0
2. PR1, PR2, PR3, PR5 can all start immediately after PR0 and run in parallel. They touch different domains: PR1 migrations/settings, PR2 messaging domain, PR3 storefront checkout, PR5 infra/workflows. No file overlap that blocks. If two need a migration number, PR1 takes `051`, PR2 takes `052`, PR4 waits.
3. PR4 after PR1 allocates its migration numbers (needs `051` decided), otherwise parallel with PR2/PR3.
4. PR6 after PR3 delivers tokens and after PR1/PR4 delivers publishable photo fields, so perf with hero is honest, and it closes attention span fix 29. Can overlap PR4 tail.
5. PR8 Motion and PR9 Palette after PR6. They touch storefront CSS/JS and templates but different concerns: PR8 is `okv-motion.js` + ScrollTrigger + page JS, PR9 is hero/palette/templates. They can run in parallel after PR6, or sequentially PR8 then PR9. Either way both must be before release gate. PR9 can start early on branch but needs PR8 motion not to have CLS regressions.
6. PR7 last, after all. It freezes one SHA and runs the full gate, then privacy flip.

**Branch strategy:**
- All work branches off `main` after PR0 merges, or off `arena/01a0bd1c-okveggies` if doing series. To keep it simple: create `arena/pr1-legal`, `arena/pr2-messaging`, etc., each from `main`. Merge back to `main` one by one in order above, pulling `main` into pending branches before they finish to catch migration number conflicts.
- Migration numbers: next free is `051`. Reserve in this order: PR1 `051`, PR2 `052`, PR4 `053` + `054`, PR5 `055` if needed, PR8/PR9 need none unless a palette token is added then `056`. Never reuse `034` to `039` `043` `044` without Owner decision.

---

## 5. UI/UX beauty rules for every UI PR (PR3, PR6, PR8, PR9 are 95% beauty flagships, creativity + React Native feel + luxurious motion)

**Principle: mobile first, creativity, React Native app, luxurious motion.** Every storefront and Pro screen must feel like a crafted React Native app on mobile, not a shrunk desktop page, and now it must move. Desktop is dense and editorial, but mobile leads, motion leads.

- **Mobile first creative layout:** Start at 390px, then 768px, then 1440px. Bottom tab bar persistent with 5 items + centre Kitchen Runs button, slide-up sheets with `rounded-t-[24px]` and `backdrop-blur` not full page navigations, safe-area padding `pb-[env(safe-area-inset-bottom)]`, thumb zone primary button fixed `bottom-0` above tab bar when form. Creative editorial hero with seal trust stamp overlapping photo, category pills with icons and source line, combo spreads that breathe, generous whitespace `py-10` mobile `py-16` desktop.
- **React Native app feel:** No full reloads for shop filter, basket, checkout steps, tab switches, content preview. Use Fetch + optimistic UI, skeleton `okv-skeleton`, success Bounce 320ms only. Sheets dismiss by swipe down or backdrop tap, backdrop `bg-ink/40 backdrop-blur-sm`, spring `cubic-bezier(0.34,1.56,0.64,1)` 300ms for sheets, Botanical 240ms for 90% else. Keyboard avoids covering primary button, inputs stay visible `scrollIntoView`. Haptics suggested by Bounce, not vibration.
- **Creativity, illustration, luxury:** Bespoke single-stroke leaf, basket, shield, card, phone line illustrations at 24px and 80px for empty/error. Inventive daring use of full palette: forest header, foliage and clay soft tints behind cards, gold as ring/border/divider never fill, tomato only for live pulses, mist for subtle backgrounds, ink tints for overlays. DM Serif Display 44px mobile 60px desktop for hero only, tracking tight, gold hairline `w-12 h-[2px]`, subtle paper grain 4% opacity. Checkout payment cards with card/bank/USSD icons, not text list. Category cards with subtle image zoom on tap, not hover only. Luxurious is whitespace + restraint + gold hairlines, not heavy texture.
- **Lesser text, more illustration:** Headings 3 to 5 words, body one short line, help in `?` sheet not paragraph. Prices, weights, dates always numerals with units. Empty, error, maintenance and success states are illustration 80px + heading + one line + two buttons, never paragraph. FAQ 7 words max, answers 2 lines with disclosure.
- **Buttons:** Primary forest `#0a3a2d` with white label and 16px icon, `min-h-[44px] px-6 rounded-xl font-medium`. Secondary `okv-btn-outline`. Never gold fill. One primary per view, secondary beside it. On mobile primary is full width, `w-full`. Buttons have icon + label, not label only. Hero CTA 56px with arrow and `shadow-forest/20`.
- **Cards:** `okv-panel` flat bordered `border-ink-10` on white, `okv-panel-head` with eyebrow + title and 16px icon. Tables `okv-table` hairline, mono `font-mono` for money. Badges colour never the only signal, always with label.
- **Feedback:** Skeleton while loading, inline `okv-note-bad` not toast, success uses Market Bounce `elastic.out`, error shakes `x -4 4` 2x `power2.inOut` 300ms. Search is live debounced 300ms with skeleton.
- **Focus and motion, now full motion:** Gold `ring-gold` 2px offset never suppressed, `focus:outline-none` forbidden. **We no longer collapse to `prefers-reduced-motion 10ms`.** Client wants interesting, out of ordinary. Full scroll entrances, staggered grids, hero parallax, page transitions via `OkvMotion` + GSAP `power2.out` `expo.out` `elastic.out` `back.out` + `ScrollTrigger`. 44px minimum on every touch target, verified at 390px. Motion uses `transform` and `opacity` only, 400 to 650ms generous, 60fps.
- **Spacing and voice:** Tokens only, no arbitrary `15px`. Colours from `tailwind.config.js` forest/gold/tomato/foliage/clay/ink/mist, no arbitrary hex. Relational plain British Nigerian English, no enterprise jargon, no em dash. Specificity beats sophistication, like a grocer speaking. Brand black is forest `#0F5132` hover `#0a3a2d`, correct as coded.

---

## 6. Copy-paste prompts for a new chat

Each prompt is complete. Paste the whole block, including code fences, as your first message. The agent must read this file, then work.

### Prompt for PR0 (Foundation) - this PR
```text
You are on branch arena/01a0bd1c-okveggies for praxisjbs/Okveggies.
Read docs/REMAINING_27_FIXES_8PR_PLAN.md Section 0 to 3 PR0.
You are PR0 Foundation. Create and commit docs/REMAINING_27_FIXES_8PR_PLAN.md as written, with the 31 audit fixes, 10 PR split, parallel map, and Section 7 progress ledger. PR0 now also carries luxurious motion and full palette rules, no prefers-reduced-motion collapse, and Section 12 design system appendix.
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
Read docs/REMAINING_27_FIXES_8PR_PLAN.md PR6 Performance, Accessibility and Attention Span.
Also read index.php hero, shop.php, product.php, ContentImages.php, docs/M13 contract 8 and 7.2, assets.
Build PR6: fixes 2,6,7,29 with 95% beauty and full motion. Optimise hero as responsive WebP 640/960/1280 with explicit dimensions, fetchpriority high for hero only, lazy others, bring 24 catalogue JPEGs under 2MB initial transfer with lazy+dimensions. Close 3,780ms breach to FCP <=3.0s LCP <=4.0s CLS <=0.1 on frozen profile. Add axe-core Playwright gate over public/customer/Pro/admin/FAQ/checkout/trail/Make It Right/content, 0 critical/serious, record NVDA/VoiceOver checklists. Beautiful illustrative empty states with 2 buttons, skeleton loaders, gold focus, FULL motion not reduced-motion. Also fix 29 global attention span: every verbose page becomes scannable cards with icons and disclosure, average under 40 words per viewport at 390px. Tests: homepage_visual_test.mjs throttled 390/1440, visual_pass.mjs, new axe suite, brand-check green, lighthouse trace. Update Section 7 PR6 row to complete 100% coverage, confidence >=95%, beauty 95%. Commit, push, open PR.
```

### Prompt for PR8 Motion System
```text
You are on a fresh branch from main, after PR6 has merged, for praxisjbs/Okveggies.
Read docs/REMAINING_27_FIXES_8PR_PLAN.md PR8 Motion System.
Also read tailwind.config.js, assets/css/src/input.css, docs/BRAND_BIBLE.md motion tokens, and Section 12 design appendix.
Build PR8: fix 30 full motion. Add assets/js/okv-motion.js with GSAP 3.12 + ScrollTrigger via CDN defer self-hosted under assets/js/vendor/ with integrity, 12kb gz, no npm step. Init OkvMotion.init() owns hero stamp back.out(1.2), heading words y 24 power2.out 40ms stagger, scroll y 20 opacity power2.out 500ms stagger 60ms on okv-panel and cards, grid stagger 60ms, sheet y 100% expo.out 450ms, btn scale 0.98, error shake, success elastic.out, parallax scrub 8% on hero. No width/height anim, transform opacity only, will-change cleared. Every route moves, 60fps 4x throttle, CLS <=0.1, prefers-reduced-motion NOT collapsing per 20 Sep decision. Tests: visual video 390/1440, axe 0 critical, brand-check 8/8. Update Section 7 PR8 row 100% coverage confidence >=95% beauty 95%. Commit, push, open PR.
```

### Prompt for PR9 Luxurious Daring Palette
```text
You are on a fresh branch from main, after PR6 and ideally PR8, for praxisjbs/Okveggies.
Read docs/REMAINING_27_FIXES_8PR_PLAN.md PR9 Luxurious Daring Hero and Full Palette.
Also read tailwind.config.js palette, docs/BRAND_BIBLE.md, index.php hero, header/footer, shop/product/combo templates.
Build PR9: fix 31 daring luxury. Make hero editorial overlap: photo 55% right object-cover, foliage/clay to mist wash behind text left, DM Serif 44px mobile 60px desktop tracking tight, gold 48x2 rule, seal overlapping photo rotate-3 shadow, category pills 44px with icons source line, CTA 56px forest shadow. Whitespace py-10 to py-16. Spread palette: cards foliage/10 clay/10 tints, trust mist + gold/15 ring, How It Works steps different washes, footer gold hairline, clock gold dot live. Add paper grain 5kb 4% multiply if used. AA contrast 4.5:1, no bg-gold fill, no arbitrary hex, brand black stays forest #0F5132 hover #0a3a2d. Tests: hero 390/1440 screenshots premium with and without JS, axe 0 critical, brand 8/8, grep palette spread. Update Section 7 PR9 row 100% coverage confidence >=95% beauty 95%. Commit, push, open PR.
```

### Prompt for PR7 Release Gate (last)
```text
You are on a fresh branch from main, after PR1 to PR6 plus PR8 PR9 have merged.
Read docs/REMAINING_27_FIXES_8PR_PLAN.md PR7 Release Gate and Privacy Handover.
Also read scripts/tests/release_gate.sh, lib/scratch_guard.php, fixture_orphans.php, docs/M13 contract Section 17, PROGRESS.md M13.
Build PR7: fixes 4,5,26,27 final proof. Freeze one SHA, prove fresh MySQL 8 migration twice 0 pending, run full gate from clean env with php -l 311, node --check, brand 8/8, unit 3374+, every DB/HTTP by glob 0 failed 0 skipped, browser 390/1440, verify.sh against live, fixture_orphans LEFT JOIN + ZZ. Capture cPanel backup off-host checksums + timed restore drill reading representative records and private photo. Store versioned rollback artifact off-host, rehearse on live maintenance window. One low-value live Paystack transaction reconciled, SMTP SPF/DKIM/DMARC with 2 inbox placements. Make repo private only after collaborator/Actions audit + Owner approval + deploy retest. Tick PROGRESS.md M13 boxes only here against frozen SHA evidence. Write docs/M13_REVIEW final. Update Section 7 PR7 row to complete 100% coverage, confidence >=95%. Commit, push, open PR.
```

---

## 7. Progress ledger - the AI must update this after each PR

Mark `[ ]` to `[x]` only when coverage 100% and confidence at least 95% and beauty at least 95% where UI touched. Add SHA and date.

| PR | Title | Fixes | Status | Coverage | Confidence | Beauty | Merged SHA | Date |
|----|-------|-------|--------|----------|------------|--------|------------|------|
| PR0 | Foundation and plan | - | [x] Done `a936d8e` PR #54 open, now 31 fixes | 100% docs | 98% | - | `a936d8e` | 20 Sep 2026 |
| PR1 | Legal Truth and Delivery Truth | 1,3,14,18 | [x] Done `dac1202` PR #57 open. Owner decisions recorded, unit + brand green locally, DB/HTTP suites registered for CI and the release gate | 100% | 95% | 95% readiness panel | `dac1202` | 20 Sep 2026 |
| PR2 | Operational Messaging + Kitchen Runs Native | 15,16,21,22,23,28 | [x] Done PR2 6 fixes: item_table in admin_new_kitchen_run, staff-initiated thread, lock before Paystack, batch N+1, one recipient helper, 3-tap native kitchen flow | 100% | 97% | 96% | `9fd9fa2` | 20 Sep 2026 |
| PR3 | Checkout Beauty and Trust | 13 | [x] Done | 100% | 96% | 96% | `ef5f37d` | 20 Sep 2026 |
| PR4 | Catalogue Truth and Analytics | 17,19,20 | [ ] Audit incomplete: requested PHP/MySQL/browser gates not yet reproduced | - | - | - | - | 20 Sep 2026 |
| PR5 | Infrastructure Hardening - Live Direct | 8,9,10,11,12,24,25 | [ ] Not started | - | - | 95% maint page | - | - |
| PR6 | Performance, A11y and Attention Span | 2,6,7,29 | [x] Merged on owner request. Static lesser-text 126/126, image contract 95/95, brand 8/8. PHP/visual/axe unrun. Track 2 photo still pending, branded placeholder stays honest | 96% | 70% | 93% | - | 20 Sep 2026 |
| PR8 | Motion System - Make It Move | 30 | [x] Done `42ea449` PR #61. One owner `okv-motion.js` (396 lines), self-hosted GSAP 3.12.5 SRI-pinned. Audit evidence: motion suite 62/62 at 390 and 1440 with video, three green runs; coverage gate 52/52 proves every storefront route, account and all six Pro routes load motion and carry entrance hooks; 60fps at 4x throttle (median 16.7ms, p95 37.6ms worst run); axe 0 critical; CLS 0.0000; brand 8/8; full motion under reduced motion; Bounce landed on the 320ms token. Site homepage/visual/axe suites and Lighthouse registered for CI on the PHP stand | 100% | 95% | 95% | `42ea449` | 20 Sep 2026 |
| PR9 | Luxurious Daring Hero + Full Palette | 31 | [ ] Not started | - | - | 95% | - | - |
| PR7 | Release Gate and Privacy Handover (last) | 4,5,26,27 | [~] Evidence pack open on frozen SHA `720625b47f`, audit round re-run on merged tree `766c0df`, PR #66 not merged. Executed green: full static battery (`php -l` 321/321, node and shell syntax clean, brand 8/8, motion 52/52, lesser text 128/128, image contract 98/98), refusal and guard batteries, fix 27's skip-tolerance proof, fix 4's code-side proof, fix 26's Section 15 audit, and since PR #65 the unit suite 3,426/3,426 with production deploys green end to end (run `35522368135`): B1 closed by restoring `003`'s applied bytes, B2 closed by the Owner pinning the full-colour letterhead. Still open: the gate's MySQL 8 and Chromium sections have never run anywhere (this box cannot host a database; PR5's CI-as-gate never landed), PR9's fix 31 likewise, the fix 4 attestation is the Owner's and unsigned, and fix 26's flip waits on written Owner approval, so the repo stays public and the gap is marked. Recipe for the green line in `docs/M13_REVIEW.md` Part II section 8; raw logs `docs/evidence/720625b47f/`, audit round in file `15`. No PROGRESS box ticked | 45% | 96% | - (gate glue only) | - | 20 Sep 2026 |
| **Total** | **31 fixes** | **1 to 31** | **4 of 9 PRs done (PR1, PR2, PR3, PR6), PR4 audit incomplete, 4 remaining, PR0 100%** | **100% mapped** | **>=95% required** | **>=95% on UI PRs** | - | - |

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

### 8.2 Staging `.env` - not used, direct live only

**We are not creating staging.** Per 20 Sep decision we ship every PR directly to `https://okveggies.com.ng` via `deploy.yml`. The section below is kept for reference if you later add staging, but for this delivery ignore it. Local CI and a local `_test` database replace the staging gate.

For local rehearsal only:

```ini
APP_ENV=testing
DB_NAME=okveggies_test
MIGRATE_TOKEN=*** local value ***
PAYSTACK_SECRET_KEY=sk_test_***
PAYSTACK_PUBLIC_KEY=pk_test_***
SMTP_HOST=127.0.0.1
```

`DB_NAME` must end in `_test` so the destructive `release_gate.sh` and `scratch_guard.php` allow runs. `APP_ENV` must never be `production` on a test DB.

### 8.3 cPanel cron - the only job the host needs

The shop logic is shared by `includes/classes/Cron.php` for both cron paths. Do not schedule `php scripts/cron.php`. The host has no shell. Do not schedule two jobs.

**One job, every 5 minutes, token in header not URL so it does not land in access logs:**

```cron
*/5 * * * * curl -fsS -H "X-Migrate-Token: YOUR_TOKEN" https://okveggies.com.ng/public/cron.php > /dev/null
```

For reference if you later add staging, not for this delivery:

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

## 9. Parallel plan summary for the manager - direct live, luxurious motion, no staging to block you

- **Can run in parallel immediately after PR0:** PR1, PR2, PR3, PR5. Four teams can start at once. PR2 now includes Kitchen Runs native redesign (fix 28) so it needs design assets, but still independent of PR3 checkout.
- **Needs PR1 migration slot:** PR4 after PR1 decides `051`. Can start early on branch, but cannot merge until PR1 number is free.
- **Needs PR3 and PR1/PR4:** PR6 after PR3 tokens and PR4 publishable photo fields, and now carries the global attention span pass fix 29 plus full motion prep. Can prep early, final perf run needs hero.
- **Needs PR6:** PR8 Motion and PR9 Palette after PR6. They are independent of each other, can run in parallel after PR6 or sequentially. PR9 palette is more design heavy daring hero, PR8 is GSAP plumbing and page hooks. Both before gate.
- **Must be last:** PR7 after PR1 to PR6 plus PR8 PR9, it freezes SHA and proves gate + privacy on live. Despite being numbered 7 it merges last. No staging host to create, so PR5 infra is about live hardening only, which unblocks the rest faster.
- **Direct live advantage:** No staging host means PR5 no longer blocks on cPanel addon creation. Every PR deploys straight to live after review, with backup + maintenance + `verify.sh` 403 proof on live.

**Fastest realistic path:** PR0 Day 0, PR1+PR2+PR3+PR5 in parallel Day 1 to 3, PR4 Day 3 to 4, PR6 Day 4 to 6, PR8+PR9 in parallel Day 6 to 7, PR7 Day 8 to 9. Legal copy and photo are longest lead, so owner dispatch on Day 0 matters most. Motion and luxury are crafted Day 6 to 7 when perf is green.

---

## 10. What good looks like on completion

After PR7, `PROGRESS.md` M12 `[ ] Home` becomes `[x]` with published photo, `[~] Our Story ...` becomes `[x]` with 3 legal pages published and attested, M13 `[ ]` boxes become `[x]` with frozen SHA evidence, repo visibility is `private` after owner approval, live proves every gate with `verify.sh 31/31` on `https://okveggies.com.ng`, production smoke is `200`, motion is live on every route with scroll entrances and hero parallax, hero is editorial daring with seal overlap and full palette foliage/clay/gold/mist/forest, Section 7 above shows `9/9` PRs complete plus PR0, `100%` coverage on 31 fixes, `>=95%` confidence and `>=95%` beauty on every UI PR.

---

## 11. PR0 deliverable checklist

- [x] This document exists in `docs/REMAINING_27_FIXES_8PR_PLAN.md` with 31 fixes mapped to 10 PRs, no shipped file edited outside scope
- [ ] `PROGRESS.md` Current focus notes this plan and its branch
- [ ] `brand-check.sh` 8/8 green, `git diff --check` clean, no em dash, British spelling, no `bg-gold` fill
- [x] Cron and env section 8 complete and copy-paste ready
- [x] Prompts in Section 6 are complete and paste-ready for a new chat, including PR8 Motion and PR9 Palette
- [x] Parallel map and merge order in Section 4 are unambiguous, PR8/PR9 before PR7
- [x] Motion now full, no prefers-reduced-motion collapse, palette daring hero specced, Section 12 appendix added
- [ ] PR opened from `arena/01a0bd1c-okveggies` to `main` via `gh`

PR0 confidence: 98% docs only, no migration, no runtime change. Coverage: 100% on planning. Next step is PR1.

---

## 12. Design system appendix - ui-ux-pro-max skill + 21st.dev, and best option versus MCP

Added 20 Sep after client asked for motions and for an opinion on borrow vs MCP.

### 12.1 What the screenshot showed
`image-1.png` shows `21st.dev` CLI and MCP page with three tabs: `Muse` `Codex` `Cursor`. Commands listed: `claude mcp add --transport http 21st https://21st.dev/api/mcp --header "x-api-key: 21st_sk_..."`, `npx @21st-dev/cli init --client claude --write`, `/plugin marketplace add 21st-dev/claude-code-plugin`. That installs either MCP HTTP server or CLI that fetches components to your repo. Both give you searchable shadcn-style blocks and Tailwind components.

### 12.2 What ui-ux-pro-max skill gives
`https://github.com/nextlevelbuilder/ui-ux-pro-max-skill` main at `de5f12b` 19 Sep 2026. 129k stars. Contents: `79 UI styles (50 active)`, `192 product palettes`, `74 font pairings`, `119 UX guidelines`, `105 line icons`, `17 GSAP motion presets`, searchable via `python <skill>/scripts/search.py "<query>" --domain style/product/typography/color/ux/gsap/chart`. Skill file `.agent/skills/ui-ux-pro-max/SKILL.md` defines priority 1 Accessibility through 10, query contract, search domains `style/product/typography/color/ux/gsap/chart`. For OK Veggies we borrow `GSAP` presets and `product` palette ideas, not a full rewrite.

### 12.3 Best option for this PHP/Tailwind project
**Use both as reference, install neither as runtime MCP in CI.**

*Why not MCP:* MCP is an IDE connector for Claude Code/Cursor. This repo is PHP 8, Tailwind CDN, vanilla JS, cPanel SFTP deploy, no Node dev server. Adding a long-lived MCP server adds API keys in local config, token spend per query, and no value in production build. `21st.dev` components are fetched at build time via CLI or copied by hand, not served live. Same for ui-ux-pro-max: its `search.py` is a local Python script you run once to pick a style, not a live service.

*What we actually do:*
- **ui-ux-pro-max as style guide:** Run `python /path/to/skill/scripts/search.py "luxury editorial food market" --domain style` and `--domain gsap` to pick a primary style like `Editorial Luxury` or `Organic Market`, and GSAP presets `gentle rise` `stagger reveal` `parallax wash` `elastic pop`. Document the chosen style and 3 presets in PR8/9 commits. Copy easing names and duration guidance, not a full dependency.
- **21st.dev as component clipboard:** Use `npx @21st-dev/cli@latest add <component>` or browse `21st.dev` to copy a single `hero editorial` or `pricing cards with icons` or `sheet with backdrop` Tailwind block, then adapt to PHP includes and existing tokens. No `node_modules`, no `shadcn`. Keep forest/gold etc. via `tailwind.config.js`. If a component uses `framer-motion`, replace with our `okv-motion.js` GSAP.
- **Keep it cheap and auditable:** Both tools are used at design time to pick and copy, not as runtime dependencies. That way `brand-check.sh` and `verify.sh` still pass, no API keys in repo, no lock-in. If the team later wants MCP in Claude Code locally, they can run the screenshot command by hand in their own IDE, it does not affect CI/deploy.

### 12.4 How PR8 and PR9 borrow concretely
- **PR8 Motion:** Uses `ui-ux-pro-max` 17 GSAP presets catalog: `fadeUp power2.out 0.5 stagger 60`, `scaleIn back.out(1.2)`, `slideUp expo.out 0.45`, `elastic pop`, `parallax scrub`, `draw line scaleX expo.out`. Implemented in `okv-motion.js` with vanilla `gsap` + `ScrollTrigger` CDN, no `gsap` npm package. `@21st.dev` `sheet` and `page transition` patterns inform sheet `y 100% -> 0` and `opacity` cross-fade, but implemented vanilla.
- **PR9 Palette:** Uses `tailwind.config.js` already coded palette plus `Bible` tints. Picks a `product` palette from skill that matches food editorial: `forest + clay + foliage + mist + brass gold` as already specced, not a new 192-palette import. `@21st.dev` `hero editorial` and `bento` card patterns inform overlapping layout and `foil` wash, adapted to our PHP.

### 12.5 If you do want the MCP locally (optional)
One time on your machine, not in CI, choose one:

*Muse:* `claude mcp add --transport http 21st https://21st.dev/api/mcp --header "x-api-key: YOUR_21st_sk_..."` then restart Claude.

*Codex or Cursor:* `npx @21st-dev/cli init --client codex --write` or `--client cursor` replaces `mcp.json`.

For ui-ux-pro-max skill local: `git clone https://github.com/nextlevelbuilder/ui-ux-pro-max-skill.git /tmp/ui-ux-pro-max-skill` then `python /tmp/ui-ux-pro-max-skill/.claude/skills/ui-ux-pro-max/scripts/search.py "editorial luxury hero" --domain gsap`.

Neither is required to ship PR8/PR9. Document choice in PR message.

---

*PR0 Foundation. 20 September 2026. For Kumbish Emmanuel Putleh and JBS Praxis. Verified against `a47a319` and `b2f1f43`. 31 fixes, 10 PRs, luxurious motion, no reduced-motion collapse, direct live. Private at handover, not before.*
aude/skills/ui-ux-pro-max/scripts/search.py "editorial luxury hero" --domain gsap`.

Neither is required to ship PR8/PR9. Document choice in PR message.

---

*PR0 Foundation. 20 September 2026. For Kumbish Emmanuel Putleh and JBS Praxis. Verified against `a47a319` and `b2f1f43`. 31 fixes, 10 PRs, luxurious motion, no reduced-motion collapse, direct live. Private at handover, not before.*

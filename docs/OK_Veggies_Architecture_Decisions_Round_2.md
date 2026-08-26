# OK Veggies — Architecture & Product Decisions (Round 2)

**Prepared by:** JBS Praxis — Senior Architect / Principal Engineer
**Date:** 26 Aug 2026
**Purpose:** Answer these 15 questions and, combined with Round 1, they become the input brief for the full **PRD** (`docs/PRD.md`). The PRD then drives the repo scaffold.
**How to answer:** Reply compactly, e.g. `11B, 12C, 13C …`, or *"go with your recommendations."* Override any option with your own words and I'll adapt.

---

## 0. Locked so far (Round 1 + confirmed)

**Product context (from the discovery call + brand bible):** OK Veggies is Lagos's trusted fresh-provisions partner — a verified-farm aggregator (Ogun State + Jos) supplying restaurants, hotels and supermarkets (B2B), now opening a direct-to-home storefront (B2C). Positioning is 75% *trust* / 25% *freshness*. Three equal-weight buyers: the Household Curator (B2C), Hospitality Kitchen Ops (B2B), and Retail/Mart Partner (B2B).

**Round 1 decisions:**

| # | Decision | Locked |
|---|----------|--------|
| 1 | Site layout | Public **storefront at `index.php`** + RBAC-gated **`/admin`** panel |
| 2 | Accounts | **Hybrid** — guest checkout for B2C; accounts required for B2B |
| 3 | Money | **Integer subunits (kobo)** + a shared `Money` helper |
| 4 | Language | **English-only** (no i18n engine) |
| 5 | Front-end JS | **Vanilla JS + Fetch** (no jQuery) |
| 6 | Staff roles | **Owner** (full) + **Manager** (broad: sales, ops, pricing, orders, delivery) — both run the business day-to-day |
| 7 | Payment modes | **All three** at launch: full prepay, 30% deposit, B2B 7–10 day credit |
| 8 | Delivery days | **Enforced** weekdays + cutoff time + minimum lead days |
| 9 | Combo pricing | **Fixed price** + live component-sum helper for weekly repricing |
| 10 | Stock | **Availability status toggle only** (no quantity tracking in v1) — "the minimum" |

**Tech stack — LOCKED** ✅
PHP 8.x · MySQL/MariaDB (cPanel/phpMyAdmin) · server-rendered PHP + **self-hosted Tailwind** + vanilla JS/Fetch · Paystack · SMTP email (PHPMailer) · dompdf for PDF invoices/receipts (`vendor/` committed) · bureau.lpc.cm's modular architecture (`api/v1` controllers, `includes`, `modules`, numbered `migrations` + `scripts/migrate.php`, RBAC, CSRF, hardened `bootstrap.php`) · cPanel shared hosting, GitHub → Actions auto-deploy (public repo → private at completion), same as bureau. *(One thing to verify: confirm the host's PHP version so we pin the same as bureau — 8.3.)*

**Brand tokens (from your brand bible) — will map into `tailwind.config.js`:**
Forest Green `#0F5132` (primary) · Harvest Gold `#C9922B` (secondary) · Tomato Red `#C8321E` (accent/alerts) · Foliage Green `#3E8B4A` (success) · Ink `#03100A` (body text) · White canvas. Fonts: **Plus Jakarta Sans** (display) · **Hanken Grotesk** (body/UI/prices) · **JetBrains Mono** (figures/SKUs/order numbers), all self-hosted. Accessibility rule carried over: gold never carries its own text; never rely on colour alone for state.

---

## 1. The 15 questions

### Catalogue & products

**Q11 — Launch catalogue scope.** Your brand bible defines **seven** categories (the seed currently has three). Which go live in v1?

- **A.** All seven at launch (incl. Grains & Cereals).
- **B.** Fresh core only: Vegetables, Herbs & Spices, Tubers & Roots, Fruits + Combo Baskets + Kitchen Runs. Grains & Cereals as a "Coming soon" teaser.
- **C.** Everything except Grains & Cereals *and* Kitchen Runs (pure B2C catalogue first).

→ **Recommendation: B.** Kitchen Runs is your original, highest-trust service — it should ship. Grains is explicitly the "launching/expansion" line in the bible, so a "coming soon" placeholder keeps the seven-category story intact without waiting on new sourcing/pricing data. *(I'll expand the schema's 3 categories → 7 either way.)*

**Q12 — Product page depth & media.** How much trust content per product, and how do we handle images on shared hosting?

- **A.** Rich: image gallery, unit + an "honest weight" line, **named source region** (Ogun/Jos), storage/usage tips, related combos. Client supplies photos + facts; we write descriptions in brand voice.
- **B.** Standard: one image, name, unit, price, short description.
- **C.** Rich as **A**, but source shown at **region level only** (not the exact farm/supplier), and start with the one image/product you already have — expand to 5 as the client delivers them (auto-optimised to WebP for bandwidth).

→ **Recommendation: C.** Delivers the "Named Source" value without exposing supplier specifics competitively, and is realistic about the image assets you have today vs the five-per-product the client promised.

**Q13 — Combo baskets at launch.** The bible names The Stew Kit, The Weekend Basket, The Salad Kit. Fixed or customisable?

- **A.** 3 fixed baskets, fixed contents, one-tap "Add Full Basket."
- **B.** Fixed baskets that customers can swap/remove items from.
- **C.** 3 fixed, non-customisable baskets at launch; add swap/customise in phase 2.

→ **Recommendation: C.** Matches the editorial, curated treatment the brand wants and keeps checkout logic simple; customisation is a clean phase-2 addition on the same tables.

### Kitchen Runs (the B2B signature)

**Q14 — How do we digitise "Kitchen Runs" (submit a list → we price → you approve)?** This isn't a normal cart checkout, and the schema doesn't model it yet.

- **A.** Full on-platform quote flow: customer submits a list (typed or from a saved template) → admin prices it → customer reviews & approves → it becomes an order (deposit or credit).
- **B.** Lightweight: a "Request a Kitchen Run" form (free text or upload) → admin follows up off-platform (WhatsApp/email); nothing quoted on-site in v1.
- **C.** Guided **list builder from the catalogue** → drafts an order at current prices → admin adjusts → customer confirms (reuses the cart/order plumbing).

→ **Recommendation: C.** Delivers the "submit your list, we handle it" promise on-platform (the differentiator), while reusing order tables so it's not a whole separate system. I'll add a small `kitchen_run_requests` table for the pre-order draft/quote stage.

### B2B credit

**Q15 — Credit approval & terms workflow.**

- **A.** Self-serve: business applies on-site → admin approves a limit + term (7/10 days) → credit orders draw against the limit → invoice → admin marks paid → aging/outstanding view.
- **B.** Manual: admin simply flags an account "credit-approved" with terms; no application flow.
- **C.** As **A**, but no automated reminders/dunning in v1 — just an outstanding-balance list the Owner/Manager watches.

→ **Recommendation: C.** Your schema already models the application → transactions → repayment chain; a simple outstanding-balance view covers v1, automated reminders come later.

### Pricing operations

**Q16 — Weekly price-update workflow** (your core recurring task — "we fix prices weekly").

- **A.** Inline-editable admin "Pricing" table with bulk "apply to category" + effective-now; auto-writes price history.
- **B.** Bulk CSV import to refresh all prices at once (you already keep an Excel price sheet).
- **C.** Both — inline table for quick daily tweaks + CSV import for the full weekly refresh.

→ **Recommendation: C.** The CSV path matches how you already work (the `Product images and Prices.xlsx`); the inline table handles mid-week single-item moves. Both auto-log `product_price_history`.

### Delivery

**Q17 — Delivery coverage & scheduling specifics.** I need the concrete rules. Which model?

- **A.** Lagos-wide, single order-by **cutoff** (please give a time, e.g. 6pm) for the next eligible day, date-only (no time windows).
- **B.** Specific Lagos **zones** only (give me the list) + cutoff + lead day; out-of-zone addresses blocked at checkout.
- **C.** Lagos-wide + cutoff + customer picks an **AM/PM window**.

→ **Recommendation: A** for v1 (simplest, matches "delivery arranged manually"), moving to zones/windows in phase 2. **Please confirm: the cutoff time and minimum lead days** (e.g. "order by 6pm the day before").

**Q18 — Delivery fee communication** (fees stay off-platform, settled on delivery).

- **A.** A clear note at checkout ("Delivery is arranged and paid on delivery; our team confirms the fee for your area") — no fee line in totals.
- **B.** An indicative fee-by-area table (informational only), still settled on delivery.
- **C.** Capture the customer's **area** at checkout so an agent can confirm the fee before dispatch, plus the note from **A**.

→ **Recommendation: C.** Keeps the manual model but captures enough for your team to quote the fee before the van rolls — and it feeds future zone-based automation.

### Payments

**Q19 — Deposit & pay-on-delivery rules.** (Confirming the discovery numbers.)

- **A.** B2C chooses **full prepay or 30% deposit** (balance on delivery); approved B2B pays **on account** (credit). ← the discovery decision.
- **B.** Full prepay only for new/guest customers; deposit unlocked after the first successful order.
- **C.** 30% deposit as the default for all B2C, full prepay optional.

→ **Recommendation: A.** Mirrors the meeting exactly. **Please confirm 30% is the deposit figure** and whether pay-on-delivery balance is cash, transfer, or either.

**Q20 — Paystack channels + who confirms non-card payments.**

- **A.** Enable all Paystack channels (card, bank transfer, USSD, bank); admin marks balance / pay-on-delivery amounts paid, with proof.
- **B.** Card + bank transfer only.
- **C.** All channels + rely on the **Paystack webhook** to auto-reconcile online payments (your schema already has the idempotent inbox); admin reviews **manual proofs** (e.g. transfer screenshots) for off-Paystack balances.

→ **Recommendation: C.** Uses the webhook/settlement machinery you already designed and keeps a clean manual-proof path for the deposit-balance case.

### Accounts, notifications & trust

**Q21 — Customer login method.**

- **A.** Email + password (reuses bureau's hardened auth directly).
- **B.** Phone + OTP via SMS (lowest friction in Nigeria, but needs an SMS provider + per-message cost).
- **C.** Email + password for v1; add phone/OTP in phase 2. (Guest checkout stays the primary B2C path regardless.)

→ **Recommendation: C.** Fastest to ship on a fixed budget by reusing bureau's auth, with phone/OTP as a funded upgrade later. *(If SMS budget exists now, B is the more "Nigerian" default — your call.)*

**Q22 — Notification channels + WhatsApp.** WhatsApp is where the business built its trust.

- **A.** Email (SMTP) for all transactional notices (order placed, payment confirmed, out-for-delivery, delivered) + a **click-to-chat WhatsApp** button for support/enquiries.
- **B.** Email + SMS for key events.
- **C.** Email + **WhatsApp order-update deep links** (wa.me), SMS later.

→ **Recommendation: A.** Delivers the discovery's "automated email confirmation for trust" and keeps WhatsApp present as the human, trust-building channel — without the cost/complexity of the WhatsApp Business API in v1.

**Q23 — Trust & "Make It Right" features** (core value #5 + the pay-online fear the brand targets).

- **A.** Build the **"Make It Right"** flow (customer flags an order issue → admin resolves → refund/credit) + visible trust signals (verified-source badges, real produce photos, secure-payment marks).
- **B.** Trust signals only in v1 (badges, testimonials, secure-checkout); issue reports handled via WhatsApp/email.
- **C.** As **A**, plus product reviews/ratings.

→ **Recommendation: A.** The "Make It Right" loop is a brand pillar and the single biggest lever on "will they pay online" — worth building. Reviews (C) can wait.

### Admin & scope

**Q24 — Admin panel scope for v1** (Owner + Manager run everything).

- **A.** Dashboard (today's orders, revenue, pending payments, outstanding credit) + Orders + Products/Pricing + Combos + Kitchen Runs + Customers + Payments + **Delivery-day manifest / packing list** + Settings.
- **B.** Just Orders + Products + Payments to start.
- **C.** As **A**, plus basic analytics charts (sales over time, top products).

→ **Recommendation: A.** The per-day packing manifest and the pricing tools are day-one operational needs; charts (C) are a tidy phase-2 add once there's data to chart.

**Q25 — Lock the Phase 1 (MVP) line.** Everything past it is Phase 2.

- **A.** **Phase 1** = B2C storefront (individual items + combo baskets) + Kitchen Run list builder + B2B accounts/credit + all three payment modes + admin (orders/pricing/customers/manifest/payments) + email notifications + Make-It-Right. **Phase 2** = Grains & Cereals, reviews, owned-fleet delivery tracking, SMS/WhatsApp automation, analytics dashboards, standing orders, combo customisation.
- **B.** Narrower Phase 1 — B2C only; B2B accounts, credit and Kitchen Runs all move to Phase 2.
- **C.** Everything in Phase 1.

→ **Recommendation: A.** Matches the brand's own "Now Launching" scope while deferring its stated "Next / Long-term" horizon (owned fleet, owned farms) — ambitious but shippable by a supervised junior on this budget.

---

## 2. Defaults I'll assume unless you say otherwise

- **Content/marketing pages:** Home, Shop (by category), Product, Combos, Kitchen Runs, Our Story/About, How It Works, FAQ, Contact, + legal (Terms, Privacy, Delivery Policy). Recipes/Journal deferred to phase 2.
- **Order numbers:** `OKV-2026-000123` (year + zero-padded sequence).
- **Schema → migrations:** your monolithic `ok_veggies_schema.sql` split into numbered, idempotent migrations (`000_init` → `001_core_schema` → `002_rbac_seed` → `003_reference_seed` → `004_product_seed`) so `scripts/migrate.php` behaves exactly like bureau's. Plus the additions from answers above (Fruits + Grains categories, `kitchen_run_requests`).
- **Deploy/env:** mirror bureau — single production env on cPanel, GitHub Actions on push to `main`. (I'll ask before adding a staging subdomain.)
- **`APP_DEBUG=false`** for production (your `.env` currently has it `true`); I'll leave your DB/SMTP secrets untouched and ship an `.env.example` with the full key set including Paystack.

---

*Answer these and I'll write the PRD, then scaffold the entire repo (all folders + empty/stub files) so we can start coding.*

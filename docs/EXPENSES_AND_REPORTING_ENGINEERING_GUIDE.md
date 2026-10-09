# Expenses and Reporting: engineering guide

This guide is the contract for two new admin modules, the September and October
data migration that feeds them, and the one-time order reset that clears the
way. It follows `CLAUDE.md`, `docs/PRD.md` and the M11 analytics contract, and
it splits the work into one reset plus four build pull requests.

Author decisions are locked (see "Decisions"). Read this before touching code.
Every PR follows the build loop in `CLAUDE.md`: read the relevant section here,
build, write and run tests, run `php -l`, `scripts/brand-check.sh`,
`scripts/verify.sh` and the unit suite, update `PROGRESS.md`, commit.

---

## 1. Why these modules exist

The business keeps its books in a spreadsheet (`OK_Veggies__Re-imagined.xlsx`):
a Purchases sheet that is really the expense ledger, and a Sales sheet that is
really wholesale invoicing to eight repeat buyers. The app has never had an
expense record or a profit view. These two modules bring the books inside the
app, on a phone, so the owner stops living in a spreadsheet.

Two hard goals set by the owner:

1. **Beauty and mobile first.** It must feel like a native app: cards, pills,
   icons, graphs, the least text possible. Explanations hide behind info icons.
   The current admin dashboard is considered ugly and is being replaced, not
   extended.
2. **The migration is the heavy part.** Real customers must be cleaned and
   imported, products reconciled against the catalogue, and every September and
   October sale and expense carried over so the first profit figure the owner
   sees is their real history, not an empty chart.

---

## 2. Decisions (locked by the owner)

| # | Decision |
|---|----------|
| Orders | The live app has **no real orders**. Every order, and everything strictly hanging off it (payments included, which were fake too), is wiped before we start. Everything seeded stays: products, the price list, delivery zones, settings, content, staff and roles. |
| Sales going forward | There is **no new sales module**. Wholesale and phone sales are raised as **manual orders** (`admin/order_new.php`), so they feed the same revenue, credit and delivery the app already runs. |
| Revenue in reporting | Comes from the **orders and payments** already in the app (the M11 cash definition), now including the migrated September and October invoices. |
| Expense categories | A **fixed, enforced** list, derived from the real data, named in the owner's own words. Every expense must pick one. |
| Cost of goods | Produce bought to resell is an expense under a **cost-of-goods** category; overheads are **operating**. The profit view shows Revenue, minus cost of goods = Gross margin, minus operating = Net profit. |
| Supplier | A **free-text field** on each expense, normalised into a `supplier_key` so "spend by supplier" still groups "Mile 12" and "mile 12" together. No supplier table. |
| Expense payment | Every expense is **money already out**. No paid/unpaid status. |
| Customers | Legacy buyers are **cleaned and imported as real business accounts**, variants folded into one parent, branches kept. Phone and email are left **pending** so the team can fill them and invite the buyer to the portal. |
| Products | Legacy product names are **matched to the catalogue** on a reviewed alias map; genuinely missing ones are **created**. "Delivery fee" is never a product. |
| Money | All amounts stored as **integer kobo** through `Money`. Legacy fractions round to whole kobo. |
| Migration mechanism | **Numbered, idempotent seed migrations** (`071+`), guarded so a re-run is a no-op. |
| Reporting content | KPI cards (Revenue, Expenses, Profit, Outstanding), a monthly Sales-vs-Expenses-vs-Profit chart, an Expenses-by-category chart, top suppliers, top customers, top products, with 7/30/90/this-month/custom presets. |
| Charts | A **self-hosted chart library** (no runtime CDN, to respect the font rule). Chosen library: **Apache ECharts** (bundled build), fed brand token values. Rationale below. |
| Expense entry UX | A **bottom sheet** (slide-up modal): amount keypad first, then category pills, then supplier, with smart defaults and "Save and add another". Not a wizard. |

### Why ECharts, self-hosted

The existing dashboard draws charts as hand-built SVG and the owner finds it
poor. ECharts gives polished donuts, bars and lines with built-in mobile
gestures and accessibility hooks, renders fast, and ships as a single bundled
file we commit under `assets/js/vendor/` so nothing loads from a CDN at runtime.
PHP still prints the exact figures first (the M11 "figures before pictures"
rule), and ECharts only draws the picture from the JSON already on the page. If
the bundle size is judged too large in review, Chart.js is the lighter fallback;
the data contract does not change.

---

## 3. Data model

### 3.1 New tables (PR1)

`expense_categories` (reference, seeded, enforced):

| column | type | note |
|--------|------|------|
| id | SMALLINT UNSIGNED PK | fixed ids, stable for migration references |
| slug | VARCHAR(40) UNIQUE | e.g. `stock-purchase` |
| name | VARCHAR(80) | e.g. `Stock Purchase` |
| kind | ENUM('cost_of_goods','operating') | drives the gross-margin line |
| colour_token | VARCHAR(24) | a brand token name, for the charts |
| sort_order | SMALLINT | display order |
| is_active | TINYINT(1) | soft retire, never delete |
| created_at, updated_at | DATETIME | |

`expenses`:

| column | type | note |
|--------|------|------|
| id | BIGINT UNSIGNED PK | |
| spent_on | DATE | the day money went out |
| category_id | FK -> expense_categories | NOT NULL, enforced, ON DELETE RESTRICT |
| supplier_name | VARCHAR(120) NULL | free text as typed |
| supplier_key | VARCHAR(120) NULL | normalised, for rollups |
| description | VARCHAR(255) NULL | the original line / note |
| quantity | DECIMAL(12,3) NULL | optional |
| unit_cost_subunit | BIGINT UNSIGNED NULL | optional |
| amount_subunit | BIGINT UNSIGNED NOT NULL | the money that matters, kobo |
| source | ENUM('manual','migration') | `migration` marks imported rows |
| external_ref | VARCHAR(80) NULL UNIQUE | migration idempotency key |
| is_void, voided_at, voided_by, void_reason | | reverse, never delete |
| created_by | FK -> users NULL | |
| created_at, updated_at | DATETIME | |

Indexes: `spent_on`, `(category_id, spent_on)`, `supplier_key`,
`(is_void, spent_on)`, unique `external_ref`.

The category taxonomy is owned in one place, the `Expenses::CATEGORIES`
constant, and the `071` seed mirrors it exactly. The constant is what the unit
tests assert and what PR2 and PR4 read.

### 3.2 Derived, enforced category taxonomy

Built from the real Purchases lines, named in the owner's tone:

| id | slug | name | kind | covers (legacy lines) |
|----|------|------|------|-----------------------|
| 1 | stock-purchase | Stock Purchase | cost_of_goods | all produce and goods bought to resell (Mile 12, Agrobiotics, farms) |
| 2 | transport-logistics | Transport & Logistics | operating | load carrier, car park, logistics, transport fare, crates |
| 3 | fuel | Fuel | operating | fuel |
| 4 | vehicle-repairs | Vehicle & Repairs | operating | tyres, oil, gear oil, oil filter, mechanic, workmanship, radiator coolant |
| 5 | airtime-data | Airtime & Data | operating | airtime/data, MIFI |
| 6 | bank-pos-charges | Bank & POS Charges | operating | POS/bank charges |
| 7 | staff-welfare | Staff & Welfare | operating | food/water, tips |
| 8 | government-levies | Government & Levies | operating | road safety, local government papers, C-Caution |
| 9 | professional-services | Professional Services | operating | web design |
| 10 | giving | Giving | operating | tithe |
| 11 | loan-repayment | Loan Repayment | operating | loan repayment (financing outflow; shown in cash out, flagged in the guide for the owner to decide whether it leaves operating profit later) |
| 12 | other | Other | operating | others, and anything a line does not clearly fit |

PR4 maps every one of the 360 legacy purchase lines to exactly one of these.

### 3.3 Customer canonical map (PR4, shown to owner before writing)

Legacy names fold into real business accounts, branches kept:

| Canonical business | Branches (location) | Folds these legacy names |
|--------------------|---------------------|--------------------------|
| VSP Lounge | Ikeja | VSP Lounge, VSP |
| Citysubs | Yaba, Lekki | Citysubs Yaba, Citysubs Lekki |
| Renee Supermarket | Lekki, Ikoyi | Renee, Renee Supermarket |
| Nostalgia | Lekki | Nostalgia |
| King of Fruits | Lekki | King of Fruit, King of Fruits |
| Alhaji Sabo | Lekki | Alhaji Sabo |
| Ms. Memunat | (from first invoice) | Ms. Memunat |

Each lands as a business account with the location as its delivery area and
contact fields blank (status that lets the team invite them to the portal).
This table is a proposal: PR4 prints it for a yes before any row is written.

### 3.4 Product alias map (PR4, shown to owner before writing)

Method, not a guess: PR4 reads the full seeded catalogue and the Excel, then
normalises each legacy product name (lowercase, collapse spaces, fix obvious
typos such as "Yello" and "Green bell bell") and matches it to an existing
product. Representative aliases: `Fresh tomatoes -> Fresh Tomatoes`,
`Green bell bell pepper -> Green bell pepper`, `Yello bell pepper -> Yellow bell
pepper`, `Irish potatoes -> Irish Potatoes`. Names with no match (for example
Pomo, Stock fish, Watermelon, Spring onion, Banana, Plantain, Rodo, Tatashe,
Shombo) are created as real products in the right category. Order lines snapshot
their own price, so a created product's catalogue price is only a starting point
(taken from its first sale line, or left for the team). "Delivery fee" is set as
the order's delivery charge, never a product. The full map is printed for a yes.

### 3.5 Reconciliation targets (PR4)

After import, these must match the spreadsheet Dashboard within rounding:

- Total Sales (September + October) and the two monthly figures.
- Total Expenses and the two monthly figures.
- Outstanding balance (receivables) of about 1,240,040 naira.
- Customer, product and invoice counts.

PR4 ships a reconciliation test that fails if a total drifts.

---

## 4. The reset (PR0, run once before everything)

File: `scripts/reset/wipe_orders.sql`. Not a migration, so it never auto-runs on
deploy. It is deliberately gated: it does nothing unless the operator sets

```sql
SET @okv_confirm_wipe_orders = 'YES';
```

first. Without that, every statement matches zero rows and the transaction is a
no-op. It runs inside one transaction with foreign-key checks left on, so it is
self-verifying: an unexpected survivor errors and rolls the whole thing back
rather than orphaning a row.

Blast radius (owner decision: strict order dependents; keep everything seeded,
and keep non-order credit, wallet, messages and notifications):

- Full wipe: order_items and components, order_addresses, order_status_history,
  order_cancellations, order_reschedules, order_receipt_links,
  order_trail_share_links, order_shortages, delivery_schedules; payments,
  payment_transactions, manual_payment_proofs, payment_status_history,
  payment_webhook_events, settlement_transactions, payment_disputes,
  dispute_evidence, refunds; the issue-report subtree (resolution items,
  history, photos, reports); finally orders. The order sequence counter is
  reset.
- Order-linked rows only: manual_refunds, wallet_entries, credit_notes,
  credit_transactions (`WHERE order_id IS NOT NULL`). Credit facilities, limits
  and non-order wallet entries stay.
- `kitchen_run_requests.converted_order_id` is set back to NULL.

Deletion order is topological (children before parents) because of the many
`ON DELETE RESTRICT` keys. The file documents each step and ends with
verification counts that must all read zero.

This script is handed to the owner to run against the database they confirm is
the pre-launch one. Claude does not run a destructive query against live data.

---

## 5. The four build PRs

### PR1: expense foundation (data and backend). Built in this guide's branch.

Scope: the data model and the server side, no screens yet.

- `migrations/071_expense_module.sql`: `expense_categories` and `expenses`
  tables and indexes (idempotent, MySQL 8 safe), plus the category seed.
- `migrations/072_expense_permissions.sql`: `expenses.view` and
  `expenses.manage`, granted to owner and manager.
- `includes/classes/Expenses.php`: the `CATEGORIES` constant and pure helpers
  (category lookup, cost-of-goods test, supplier normalisation, a pure
  `summarise()` aggregation), plus DB methods `create`, `listRecent`, `void`,
  `categoriesFromDb`, `supplierSuggestions`. All money through `Money`, all SQL
  prepared, category enforced on write.
- `api/v1/expenses.php`: actions `create`, `void` (POST, CSRF, `expenses.manage`),
  `list`, `categories`, `supplier_suggest` (read, `expenses.view`). JSON out,
  `Audit::record` on writes, exception messages never returned to the client.
- Wiring: `Expenses.php` added to `includes/bootstrap.php` and to
  `scripts/tests/run.php`.
- Tests: `scripts/tests/ExpensesTest.php` (pure: taxonomy integrity, cost-of-goods
  split, supplier normalisation, `summarise` money math, amount validation),
  runnable with no database; `scripts/tests/expenses_db_test.php` (create, list,
  void against a scratch MySQL 8).

Acceptance: unit suite green; `php -l` clean; brand check green; permissions
seed is idempotent; an expense cannot be saved without a valid category or a
positive amount; a voided expense is never deleted.

### PR2: the Expense module UI

Scope: the screens, built to feel native.

- `admin/expenses.php`: a mobile-first list of expense cards (date, category
  pill, amount, supplier), a sticky month and category filter row as pills, a
  KPI strip (this month's spend, split into cost of goods and operating), and
  the entry trigger. Deep links both ways, a real back path, empty state.
- The entry **bottom sheet**: amount keypad first, category as a grid of
  coloured pills, supplier with typeahead from `supplier_suggest`, optional
  quantity and note behind a "more" disclosure, "Save" and "Save and add
  another". Remembers today's date and the last supplier. Info icons (a small
  button, `aria-expanded`) reveal any explanation; no paragraphs on the screen.
- `assets/js/admin-expenses.js`: vanilla, Fetch, optimistic add, `textContent`
  only. Market Bounce 320ms on a successful save, Botanical 240ms elsewhere.
- Nav: an `Expenses` item in `includes/config/nav.php` under "Customers and
  money", permission `expenses.view`, with a chosen icon added to the sidebar
  icon set.
- Build the stylesheet (`npm run build:css`) so brand check 8 passes.

Acceptance: works and looks right at phone and desktop widths; 44px targets;
gold focus ring intact; add, filter and void all work; brand and head checks
green; smoke test green.

### PR3: the Reporting Dashboard

Scope: the profit view, replacing the old dashboard's charts.

- `includes/classes/FinancialReport.php`: the service. Revenue from the M11 cash
  definition over the period; expenses from `expenses` grouped by kind and
  category; gross margin and net profit; outstanding receivables; top suppliers
  (by `supplier_key`), top customers, top products; a monthly series. Period
  presets 7/30/90/this-month/custom, computed in PHP and bound, never assembled
  from request text. Pure aggregation separated from the queries so it is unit
  tested.
- `admin/reports.php` (the new dashboard, or the reworked `admin/index.php`): a
  KPI card row, a monthly Sales-vs-Expenses-vs-Profit chart, an expenses-by-category
  donut, and three "top" tables rendered as compact cards. Figures printed by
  PHP first; a single `<script type="application/json">` payload feeds the charts.
- `assets/js/vendor/echarts.min.js` (committed, self-hosted) and
  `assets/js/admin-reports.js` to draw from the payload, using token values
  pulled from CSS variables so brand check 4 stays clean.
- Nav: a `Reports` item, permission `reports.view` (seeded here), granted to
  owner and manager.

Acceptance: profit math matches hand calculation on fixtures; charts render in
both viewports with no CDN call; no arbitrary hex in source; accessible fallback
figures present; tests green.

### PR4: the September and October migration

Scope: the heavy part. Clean, reconcile, import.

- Cleaned, checked-in source data derived from the Excel (customers, the product
  alias map, sales invoices, expenses), each row carrying a stable
  `external_ref` for idempotency.
- Numbered idempotent seed migrations: customers and branches; product aliases
  and creates; sales invoices as back-dated manual orders through the
  `ManualOrder` path (real `OKV` numbers, delivery as a charge, original invoice
  string kept as a reference, marked paid to match status); expenses with their
  category and cost-of-goods flag.
- A reconciliation test asserting the totals in 3.5.
- The customer canonical map (3.3) and product alias map (3.4) printed in the PR
  description for the owner's yes before merge.

Acceptance: a fresh migrate then a second migrate applies nothing new;
reconciliation test green; no legacy fractional naira survives; contact fields
pending so buyers can be invited; no sales line lost.

---

## 6. Standing rules for every PR here

- Money in kobo, through `Money`, always. Never a float in storage.
- Prepared statements only. RBAC re-checked on the server for every action.
- CSRF on every POST. Exception text is logged, never returned.
- No em dash, no banned jargon, numerals with the naira symbol and units,
  design tokens only, info icons instead of body text.
- History is append-only: an expense is voided, never deleted, exactly as
  orders are cancelled, never deleted.
- One concern per file; split a page over 800 lines.

---

## 7. Hand-off

PR1 is implemented in this branch. PR2, PR3 and PR4 are each a fresh chat, in
order, each starting from the latest default branch and reading this guide
first. The PR2 starting prompt is delivered alongside this guide.

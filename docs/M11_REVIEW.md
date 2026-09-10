# Milestone 11 final review

**Branch:** `M11-Admin-dashboard`  
**Reviewed:** 10 September 2026  
**Scope:** Admin dashboard and analytics, command palette and keyboard shortcuts

## Outcome

Milestone 11 is complete. All 3 acceptance criteria are implemented and proved
against a fresh MySQL 8 database, real HTTP responses, the seeded Owner and
Manager roles, restricted custom roles, and a live browser at 390px and 1440px.
No migration was needed and no financial record is changed by the dashboard.

## Requirement to evidence

| Requirement | Final behaviour | Evidence |
|---|---|---|
| Today's orders | Counts each non-cancelled order placed inside the Lagos-local half-open day once, irrespective of item or payment joins | MySQL tests cover the second before midnight, exact midnight, multiple items and cancellation |
| Revenue | Successful credited transaction amounts minus processed refunds on each real movement date | MySQL tests cover successful, reversed and undated receipts plus requested, processing, failed and processed refunds |
| Payments due | Counts the remaining balance of non-cancelled obligations due today or earlier and separates due today from overdue | MySQL and HTTP tests cover unpaid, part-paid, fully paid, future and cancelled obligations |
| Credit outstanding | Sums each business customer's append-only signed journal, floors each account at zero, then sums the accounts | MySQL tests cover open, overdue and settled labels, charges, negative repayments and a net-credit account |
| Sales over time | Returns a dense 7, 30 or 90 day net-cash series, with 30 days as the default | Pure and MySQL tests cover zero, one and multiple records; HTTP tests cover valid and invalid period selection |
| Top products | Ranks the top 10 immutable order-item names by refund-adjusted line value | MySQL tests prove a later live product rename does not rewrite the historical label |
| Category share | Uses refund-adjusted line value and a canonical mapping for the 5 product categories, Combos and Kitchen Runs | Pure tests assert all 7 token mappings and exact 10,000-basis-point allocation; MySQL tests cover a live category move |
| Command palette | Uses the canonical navigation, removes forbidden commands on the server, searches approved keywords and provides a named accessible dialog | HTTP tests compare the exact ordered palette and sidebar URLs for Owner, Manager and restricted roles; browser tests cover search, arrows, Enter, Escape and focus |
| Keyboard shortcuts | Supports Control or Command K and permitted two-key `G` navigation without overriding editable fields or overlays | Source, HTTP and browser tests cover input protection, focus restoration, missing permissions and same-page safety |

## Permissions

`dashboard.view` gates the route. Operational data is independently gated by
`orders.view`, `payments.view` or `credit.view`. Chart markup, chart bootstrap
data and its JavaScript are absent without `dashboard.analytics.view`; the sales
chart also requires `payments.view`, while product and category charts require
`orders.view`. The palette is built only from server-filtered canonical
navigation. The HTTP role matrix proves these rules for the seeded Owner,
seeded Manager and restricted custom roles.

## Accessibility and responsive behaviour

- Every chart has an exact-value HTML table and identifies data with text, not
  colour alone.
- The canonical category colours are design tokens and remain stable.
- The palette has an accessible name and description, traps focus, restores
  focus to its opener and has a plain no-match state.
- Live browser checks at 390px and 1440px found no horizontal overflow. At
  390px, no visible control was under 44px.
- The browser-loaded stylesheet contains the shared reduced-motion rule, and
  the chart enhancer introduces no animation.
- The browser console reported no warning or error during the audit.

## Verification results

- Unit runner: **2,861 / 2,861 assertions passed**.
- Fresh MySQL 8 M11 aggregation suite: **41 / 41 assertions passed**.
- M11 HTTP, permissions and role suite: **96 / 96 assertions passed**.
- Payments regression: **43 / 43 assertions passed**.
- Credit admin regression: **30 / 30 assertions passed**.
- Credit order regression: **48 / 48 assertions passed**.
- Cancellation regression: **28 / 28 assertions passed**.
- PHP lint: all M11 PHP files passed.
- JavaScript syntax: dashboard, shortcut controller and build script passed.
- `scripts/brand-check.sh`: all green.
- `scripts/verify.sh http://127.0.0.1:8124`: all green, including protected paths.
- `git diff --check`: passed.

## Known schema boundary

Order-item names, quantities and values are immutable snapshots. The current
schema does not snapshot a product's category on an order item, so historical
product lines use the product's current category for category share. Combos and
Kitchen Runs are stable because their order-item type is itself the grouping.
This agreed limitation is documented in `docs/M11_ANALYTICS_CONTRACT.md`; no
speculative schema change was introduced.

No Milestone 11 feature or test is deferred.

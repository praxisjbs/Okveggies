# Legacy September and October migration

This folder holds the cleaned bookkeeping data from the business's spreadsheet
and the plan to bring it into the app. It is the PR4 work in
`docs/EXPENSES_AND_REPORTING_ENGINEERING_GUIDE.md`.

Everything here reconciles to the spreadsheet Dashboard, proved with no database
by `scripts/tests/LegacyDataTest.php`:

| Figure | Value |
|--------|-------|
| Sales (69 invoices) | ₦9,227,545 |
| Expenses (354 lines) | ₦8,258,725 |
| Outstanding (receivables) | ₦1,240,040 |
| September sales / expenses | ₦7,161,480 / ₦6,417,843 |
| October sales / expenses | ₦2,066,065 / ₦1,840,882 |

## Order of operations

1. **Reset** the fake orders first: `scripts/reset/wipe_orders.sql` (gated, run by hand).
2. **Expenses** are imported by an ordinary migration: `migrations/075_legacy_expenses.sql`. It runs on the normal deploy, is idempotent, and makes the Expense module and the dashboard's expense, cost-of-goods, operating, category and supplier figures real. Done.
3. **Customers and sales** are imported by the hand-run step below, on staging first. They are deliberately **not** a migration.

## Why customers and sales are a hand-run step, not a migration

Importing the 69 invoices as real orders writes across about eight foreign-key
linked tables (users, business_customers, addresses, orders, order_items,
payments, payment_transactions, status history) and must satisfy the M11 cash
definition for revenue to read correctly. That cannot be validated in the build
container, which has no MySQL, and an auto-applying migration that has never run
against a database is exactly the risk `CLAUDE.md` guards against. It also needs
the Owner's yes on the two mappings below, which the guide requires before any
row is written. So it runs by hand against staging, is checked against the
reconciliation figures above, and only then goes to production.

The cleaned, validated data for it is `legacy_data.json`: the canonical
customers, the product alias map, and every invoice with its lines, totals,
status and balance, all in integer kobo.

## Customer canonical map (for the Owner's yes)

Legacy buyers fold into real business accounts; contact fields are left blank so
the team can fill them and invite the buyer to the Pro portal.

| Business | Branches | Folds |
|----------|----------|-------|
| VSP Lounge | Ikeja | VSP Lounge |
| Citysubs | Yaba, Lekki | Citysubs Yaba, Citysubs Lekki |
| Nostalgia | Lekki | Nostalgia |
| Renee Supermarket | Lekki | Renee Supermarket |
| King of Fruits | Lekki | King of Fruits |
| Alhaji Sabo | Lekki | Alhaji Sabo |
| Ms. Memunat | (from invoice) | Ms. Memunat |

## Product alias map (for the Owner's yes)

25 legacy product names match a catalogue product (case and typos normalised,
for example `Yello bell pepper` to `Yellow bell Pepper`, `Fresh tomatoes` to
`Fresh Tomatoes`). 23 have no catalogue match and are created in the right
category on import:

> Parsley, Plantain, Sweet potatoes, Carrot, Pineapple, Lime, Basil, Banana,
> Green beans, Watermelon, Pomo, Stock fish, Lemon grass, Coloured Cabbage,
> Cucumber, Eforiro, Cauliflower, Coriander, Fresh rosemary, Locust beans,
> Avocado, Brocoli, Rosemary

Two to confirm before the run: `Eforiro` is likely the catalogue's
`Eforiro (Shoko)`, and `Fresh rosemary` / `Rosemary` are likely one product.
`Delivery fee` is never a product; it becomes the order's delivery charge.

Order lines snapshot their own price, so a created product's catalogue price is
only a starting point (taken from its first sale line, or left for the team).

## Running the customers and sales import (staging first)

The importer reads `legacy_data.json` and, for each invoice, resolves its
customer and its product lines (by the maps above), then raises a back-dated
order through the manual-order domain path so every order invariant holds:
a real `OKV` number, the original invoice string kept as a reference, delivery
as a charge, and a recorded payment matching the status (Fully Paid, or the one
Partially Paid invoice left with its balance) so the dashboard's revenue and
outstanding read correctly. Each order is keyed by its invoice reference, so a
second run imports nothing new.

After the run, confirm the three headline figures above against
`admin/reports.php` for a 90-day or this-month period, and the per-month splits
against the monthly trend. If a figure drifts, stop and reconcile before
production.

# M11 admin dashboard and analytics contract

This document fixes the meaning of every Milestone 11 dashboard figure before
the queries and interface are built. It follows `docs/PRD.md` Sections 2, 17,
19, 20 and 22 and the build rules in `CLAUDE.md`.

The Owner confirmed these decisions on 10 September 2026:

1. Today's orders are non-cancelled orders placed today.
2. Revenue is net money confirmed in the period.
3. Payments due are unpaid amounts due today or earlier.
4. Cancelled orders do not contribute to order-based sales, and a completed
   refund is recognised on the day it completes.
5. Sales over time defaults to the last 30 calendar days.
6. Top products are ranked by net sales value.
7. Products, Combos and Kitchen Run lines remain separate sellable lines.
8. All 7 shopping groups have a fixed code-owned visual identity.
9. Dashboard visibility follows layered permissions.
10. Charts offer fixed 7, 30 and 90 day GET presets.
11. Category share is based on item-line sales value.

## 1. Shared time and period rules

- The business timezone is `APP_TIMEZONE`, whose safe default is
  `Africa/Lagos`.
- PHP computes an inclusive start and exclusive end for every query, then binds
  both timestamps to a prepared statement. SQL does not assemble a date range
  from request text.
- Today begins at 00:00:00 and ends immediately before the following midnight
  in the business timezone.
- A 7, 30 or 90 day period includes today. A 30 day period therefore starts at
  midnight 29 calendar days before today and ends at tomorrow's midnight.
- The GET parameter is `period`. Only `7`, `30` and `90` are accepted. Missing,
  array-valued or invalid input becomes `30` without an error page.
- Labels show the exact start and end dates so a member of staff never has to
  infer what a figure covers.
- Tests inject the current time. No calculation test depends on the wall clock.

## 2. Today's orders

`orders_today` is the count of orders whose `orders.created_at` falls inside
today's bound and whose current `order_status` is not `cancelled`.

- Pending orders count because they represent valid orders received today.
- Delivered orders count when they were placed today.
- An order placed earlier but scheduled for delivery today does not count.
- A later cancellation removes that order from the current value. Cancellation
  history remains available in the Orders module.
- The result is an integer count, including `0`.

## 3. Revenue and sales over time

Revenue is cash-based, not order value. The same definition drives the
`revenue_today_subunit` card and every point in `sales_over_time`.

For each calendar day:

1. Gross receipts are the integer `requested_amount_subunit` values of credited
   `payment_transactions` whose `paid_at` falls on that day.
   This is the price of the goods credited to the order. The gateway's
   `amount_subunit` may include a Paystack fee paid on top and is not revenue.
2. Credited transaction states include `success`, `part_refunded` and
   `refunded`. A later refund state must not erase the original receipt.
3. Transactions with current state `reversed` are excluded from every period.
   A manual reversal corrects a payment that should never have been recorded;
   it is not money sent back to a customer.
4. Completed refunds are `refunds` rows with status `processed`. Their integer
   `amount_subunit` is subtracted on `refunded_at`, not on the original payment
   date and not when the refund is merely requested or processing.
5. Net revenue equals gross credited receipts minus completed refunds.

Net revenue may be negative on a day when refunds exceed new receipts. The
service must preserve that signed integer and `Money::format()` must present it
honestly.

A cancelled order does not contribute to order-based product or category
sales. Its receipt and completed refund remain in the cash series on their real
movement dates. This distinction prevents a cancelled payment from being
removed once by order status and again by its refund.

There is no separate revenue table, cached total or mutable daily summary.
Credited transactions without `paid_at` are excluded from dated revenue and
returned as `undated_receipts_count`; no substitute date is invented.

## 4. Payments due

A payment is due when all of these conditions hold:

- Its order is not cancelled.
- Its `due_at` is not null and is earlier than tomorrow's midnight.
- Its expected amount is greater than its paid amount.
- Its current status is `unpaid` or `part_paid`.

The outstanding amount for one row is
`Money::balance(expected_amount_subunit, paid_amount_subunit)`.

The summary returns:

- `payments_due_count`, the number of qualifying payment obligations.
- `payments_due_subunit`, the sum of their outstanding amounts.
- `payments_due_today_count` and `payments_due_today_subunit` for due dates
  inside today.
- `payments_overdue_count` and `payments_overdue_subunit` for due dates before
  today's midnight.

Future obligations do not contribute. Due today and overdue are never merged
in supporting copy even though the main card may show their combined amount.

## 5. Credit outstanding

Credit outstanding reuses the signed-journal rule in `Credit`:

- Calculate each business account independently from all its
  `credit_transactions` entries.
- Charges are positive. Repayments and reducing adjustments are negative.
- One business cannot have less than `0` outstanding.
- The dashboard total is the sum of each business's non-negative outstanding
  amount. A credit on one account must not hide another business's debt.
- Facility status does not erase journal debt. An approved, suspended or
  withdrawn facility can still have an outstanding balance.

The service returns `credit_outstanding_subunit` as integer kobo. Any ageing
detail continues to use the existing `Credit` rules and remains owned by the
Credit module.

## 6. Top products

Top products cover orders placed inside the selected 7, 30 or 90 day period.
Cancelled orders are excluded.

Sellable lines stay recognisable as the customer bought them:

- A catalogue product groups by `product_id` when present, while its displayed
  name and unit come from the immutable order-item snapshot.
- A Combo groups by `combo_package_id` when present and otherwise by its
  snapshot name and unit.
- An order linked from `kitchen_run_requests.converted_order_id` groups its
  lines as Kitchen Run sellable lines, using snapshot name and unit. The lines
  are not expanded into guessed catalogue products.
- A historical line with no stable id groups by a normalised snapshot name,
  unit and sellable-line kind. It is never silently discarded from the top
  products calculation.

Ranking value starts with `order_items.line_total_subunit`. Processed refunds
against a non-cancelled order reduce that order's lines in proportion to their
line totals. Integer remainder kobo are assigned deterministically by ascending
order-item id so the allocated amounts equal the exact processed refund.

The result is ordered by net sales value descending, then snapshot label
ascending, then stable key ascending. The first 10 results are returned. Each
row includes its kind, label, unit, quantity where comparable, order count and
net sales value. Quantities are never added across different units.

## 7. Order share by category

The category chart uses the same selected period, eligible orders and
refund-adjusted line values as top products.

- A catalogue product with a live `product_id` uses that product's current
  category.
- A Combo uses the `combos` shopping group.
- Every line on an order linked to a converted Kitchen Run uses the
  `kitchen-runs` shopping group.
- A product snapshot whose product no longer exists, and a typed manual-order
  line that is not a Kitchen Run, cannot be assigned to one of the 7 groups
  from the current schema. Its value is reported as
  `uncategorised_subunit` beside the chart and is excluded from the percentage
  denominator. It is never assigned a false category.
- Percentage share is category net line value divided by the total
  categorised net line value. The displayed values must total 100%, subject
  only to an explicitly handled rounding remainder.
- A period with no positive categorised value returns an empty series instead
  of dividing by zero.

The schema does not snapshot a product's category on `order_items`. Historical
product lines therefore follow the product's current category. This limitation
must be stated in the analytics test description and M11 handover. Task A does
not add a speculative migration.

## 8. Fixed shopping-group presentation

One canonical M11 configuration will map the 7 group slugs to existing design
tokens. Templates and JavaScript must consume that mapping rather than repeat
it.

| Group | Slug | Token |
|---|---|---|
| Vegetables | `vegetables` | `foliage` |
| Herbs & Spices | `herbs-spices` | `forest` |
| Tubers & Roots | `tubers-roots` | `gold` |
| Fruits | `fruits` | `tomato` |
| Grains & Cereals | `grains-cereals` | `clay` |
| Combos | `combos` | `ink` |
| Kitchen Runs | `kitchen-runs` | `gold.ink` |

Every mark also carries the written group label and numeric value. Colour is
never the only signal. Gold may be a chart mark, but it is never a button fill
and never carries its own text.

## 9. Permission contract

The server applies every gate before it runs the corresponding query or emits
its HTML or JSON data.

| Dashboard content | Required permissions |
|---|---|
| Open the dashboard | `dashboard.view` |
| Today's orders | `dashboard.view` and `orders.view` |
| Revenue | `dashboard.view` and `payments.view` |
| Payments due | `dashboard.view` and `payments.view` |
| Credit outstanding | `dashboard.view` and `credit.view` |
| Sales over time | `dashboard.view`, `dashboard.analytics.view` and `payments.view` |
| Top products | `dashboard.view`, `dashboard.analytics.view` and `orders.view` |
| Category share | `dashboard.view`, `dashboard.analytics.view` and `orders.view` |

Owner, Manager and custom roles are governed by these permission combinations,
not by role-name checks. A hidden metric is absent from server output, browser
bootstrap data and chart payloads. Frontend permission checks remain a UX aid,
not the security boundary.

## 10. Empty and failure states

- Valid empty data returns `0` or an empty list. It is not an exception.
- Each dashboard region states plainly when there are no matching orders,
  receipts, due payments, credit balances or chart values.
- One unavailable or forbidden region does not prevent permitted regions from
  rendering.
- Database exceptions are logged. The dashboard shows a plain retry message
  and never prints an exception or SQL text.
- Links lead to the relevant permitted Orders, Payments or Credit screen. A
  card is not rendered when its destination permission is absent.

## 11. Required verification fixtures

The M11 service and HTTP tests must prove at least:

- The second immediately before and at Lagos midnight.
- Pending, delivered and cancelled orders placed today and yesterday.
- One order with multiple payments and one payment with multiple transaction
  attempts, without duplicated revenue.
- Partial manual payments, successful Paystack payments and an approved manual
  reversal.
- Requested, processing, processed and failed refunds, with subtraction only
  for processed rows on `refunded_at`.
- A day whose net revenue is negative.
- Payments overdue, due today, due tomorrow, fully paid and on a cancelled
  order.
- At least 2 business credit journals so one account's negative balance cannot
  offset another account's debt.
- Product, Combo, Kitchen Run and uncategorised manual lines.
- Proportional refund allocation with an integer remainder.
- Empty 7, 30 and 90 day periods and an invalid period input.
- Owner, Manager and custom roles with partial permission combinations.
- Absence of forbidden metric and chart data from the response body.

## 12. Task boundaries

Task A fixes this contract only. It adds no migration, analytics query,
dashboard card, chart, command palette or keyboard shortcut. Those are built
and verified progressively in Tasks B to G. The 3 headline M11 checklist items
remain open until their working features and acceptance tests pass.

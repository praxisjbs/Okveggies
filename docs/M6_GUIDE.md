# Milestone 6, the build guide

Delivery, the order lifecycle, the Order Trail, and every message that goes out
around an order.

Read this with `docs/PRD.md` Sections 13 and 14 open. Where the two disagree,
the PRD wins and this file is wrong and should be corrected.

---

## 1. The scope decision, and why

The milestone list puts notifications in M9. This guide moves half of M9 into
M6, and the reasoning matters more than the change.

**Order and payment notifications move into M6.** A notification belongs with
the event that fires it. Every order email is triggered by a status change M6
builds, so building them apart means writing every transition twice, once to
change the status and again months later to hang an email on it. Worse, PRD 14.2
says the customer opens the Order Trail "from a link in the confirmation email".
Build the trail in M6 and the email in M9 and the primary route to the thing we
just built does not exist for three milestones.

There is also a backlog waiting. M5 finished with real payment events (payment
confirmed, deposit received, refund processed, refund failed) that have nowhere
to go. They were built and tested and no customer will ever hear about them
until this is wired.

**Contact stays in M9.** The floating support widget, WhatsApp click to chat,
the contact form and contact messages in admin have nothing to do with orders.
That is a clean seam and M9 remains a real milestone with a coherent job.

---

## 2. What already exists, so nobody rebuilds it

Check this list before starting anything. Four of the M6 acceptance criteria are
partly or wholly satisfied already.

| Thing | State | Where |
| --- | --- | --- |
| Allowed days, cutoff, lead, zones, exceptions | **Built in M4.** A real admin screen, not a placeholder | `admin/delivery.php`, `Delivery` |
| Delivery eligibility rules | **Built in M4.** Africa/Lagos, cutoff, lead, dated exceptions | `Delivery::isEligible` |
| Order Trail page | **Partly built.** Resolves by token or signed-in owner, reads status history, withholds money | `public/order.php`, `OrderTrail` |
| Trail token | **Built in M4.** Only the SHA-256 is stored | `orders.order_trail_token_hash` |
| First status event | **Built in M4.** Placement writes `pending` | `Checkout::writeOrder` |
| Order number generation and its tests | **Built in M0** | `OrderNumber`, `OrderNumberTest` |
| Cancellation money rules | **Built in M5.** Cutoff, who may cancel, deposit forfeit, all unit tested | `Cancellation` |
| Refund engine | **Built in M5.** Full and partial, four states from webhooks | `Refunds` |
| Payment ledger | **Built in M5** | `Payments`, `ManualPayments` |
| Invoice and receipt documents | **Built in M5.** Real rows, access controlled | `public/documents/`, `OrderDocument` |
| Five order and payment email templates | **Built and already branded** | migration `010_branded_email_templates.sql` |
| Branded email rendering, HTML and plain text | **Built** | `Mail::sendTemplate`, `Mail::brandedHtml` |
| Notification tables | **Built, and completely unused** | `notifications`, `notification_deliveries` |
| "Sourced [day] from [state]" line | **Built on the storefront.** Settings `source_day`, `source_regions` seeded | migration `009` |

So the email work is not "build branded emails". The branded templates exist and
five of them are seeded. What is missing is the dispatcher that sends them, the
record of what was sent, an admin screen to edit the words, and the templates M6
adds for the events that did not exist when 006 and 010 were written.

Two tables are referenced by **zero** lines of PHP and are M6's to fill:
`delivery_schedules` and `order_cancellations`.

---

## 3. Suggested PR split

Four PRs, in this order, because each depends on the one before it.

1. **The lifecycle spine.** Status machine, transition rules, status history, and
   the Order 360 screen that shows it.
2. **Notifications.** The dispatcher, the delivery log, the wiring on every
   event including M5's orphaned payment events, and the template editor.
3. **Cancellation.** Customer self service and staff cancellation, wired to the
   M5 refund engine and the M5 policy rules.
4. **The trail and the manifest.** The public five-step trail, and the printable
   delivery day packing list grouped by zone.

---

## 4. PR1, the lifecycle spine

### Statuses

From PRD 14.1: `pending`, `confirmed`, `packed`, `dispatched`, `delivered`,
`cancelled`.

### Transition rules

The point of a status machine is refusing the transitions that make no sense. An
order cannot be dispatched before it is packed. A delivered order cannot go
backwards. A cancelled order is finished.

Build the allowed map as a pure, unit tested function, the way `Delivery` and
`Cancellation` are pure. The screens then have no rules of their own.

| From | May move to |
| --- | --- |
| pending | confirmed, cancelled |
| confirmed | packed, cancelled |
| packed | dispatched, cancelled |
| dispatched | delivered, cancelled |
| delivered | nothing |
| cancelled | nothing |

Whether a dispatched order can still be cancelled is a real question and is
listed in Section 9 below.

### What to build

1. `Orders` class holding the transition map, the guard, and the one
   transactional write that changes a status.
2. Every transition writes `order_status_history` with old, new, source, who and
   an optional note. Never update a status without writing the row in the same
   transaction.
3. Stamp `orders.confirmed_at`, `delivered_at`, `cancelled_at` as the matching
   transitions happen.
4. `admin/orders.php`, replacing the placeholder: a filterable list by status,
   delivery day and customer, and a detail view.
5. The Order 360 detail shows, on one screen: the items with their component
   breakdown, the money from the M5 ledger (expected, paid, refunded, net,
   outstanding), the delivery day and zone, the address snapshot, the customer,
   the full status history with who did what, links to the invoice and receipt,
   and the notification history from PR2.
6. An internal staff note on an order, visible to staff and never to the
   customer.
7. RBAC on every action. `orders.view` to see, and the write permissions the
   RBAC seed already carries.

### Tests

- The transition map, exhaustively: every legal move allowed, every illegal one
  refused, both directions.
- A status change writes exactly one history row.
- A refused transition writes nothing at all.

---

## 5. PR2, notifications

This is the part the milestone list underestimates, so it gets the most detail.

### The dispatcher

Build one `Notifications` class that every event calls. Nothing else sends
email. One path in means one place to fix when SMTP breaks, one place that
records what happened, and one place to add SMS in Phase 2.

Each send:

1. Writes a `notifications` row: the event type, what it relates to, the
   template used, the rendered body, and its status.
2. Writes a `notification_deliveries` row per recipient and channel, carrying
   the address, the attempt count, `sent_at`, and `last_error` when it fails.
3. Sends through `Mail::sendTemplate`, which already renders the branded HTML
   and the plain text alternative.
4. Never lets a failed email break the thing that triggered it. An order that
   dispatched successfully has dispatched, even if the email bounced. Catch,
   record, carry on, and surface the failure in admin.

The tables already carry everything needed for this, including
`provider_message_id` for later delivery tracking and `read_at` for later open
tracking. Neither is needed now.

### The event matrix

Five templates exist. Six are missing and this PR adds them.

| Event | Template key | Exists? | Goes to |
| --- | --- | --- | --- |
| Order placed | `order_placed` | Yes | Customer |
| Payment confirmed in full | `payment_confirmed` | Yes | Customer |
| Deposit received, balance outstanding | `deposit_received` | Yes | Customer |
| Order dispatched | `order_dispatched` | Yes | Customer |
| Order delivered | `order_delivered` | Yes | Customer |
| Order confirmed by staff | `order_confirmed` | **No** | Customer |
| Order cancelled | `order_cancelled` | **No** | Customer |
| Refund sent | `refund_processed` | **No** | Customer |
| Refund failed | `refund_failed` | **No** | Staff, and the customer per PR3 copy |
| Manual payment recorded by staff | `payment_recorded` | **No** | Customer |
| New order placed | `admin_new_order` | **No** | Staff |

Every customer email carries the Order Trail link, because PRD 14.2 makes that
link the way a customer follows their order.

### Retrofitting M5

M5 finished with payment events that have no outlet. This PR connects them:

- `Payments::applyVerifiedCharge` succeeding sends `payment_confirmed` for a
  full payment or `deposit_received` for a deposit.
- `ManualPayments::record` sends `payment_recorded`.
- `Refunds` reaching `processed` sends `refund_processed`; reaching `failed`
  alerts staff.

Call the dispatcher from the caller, not from inside the ledger. The ledger
holds a database transaction open and an SMTP round trip does not belong inside
one. Send after the commit.

### The template editor

The permission `settings.notifications.edit` exists in the RBAC seed and there is
no screen behind it. Build one: a Notifications tab on the settings screen
listing every template, letting an Owner edit the subject and body, showing the
tokens each template accepts, and previewing the branded result before saving.

Templates are content, so they live in `notification_templates` where they
already are, not in PHP.

### Order 360 shows what was sent

The detail screen lists every notification for that order: what was sent, to
which address, when, whether it succeeded, and the error if it did not. A
resend button for a failed one, gated on a permission.

This is the part that turns "we sent an email" from a belief into a fact.

### Tests

- The dispatcher writes a notification row and a delivery row per send.
- A failing send records the error and does not throw into the caller.
- Every template key in the matrix renders with its expected tokens and leaves
  no `{{placeholder}}` behind.
- Every rendered email carries the trail link.

---

## 6. PR3, cancellation

The money rules are already built and unit tested in `Cancellation` from M5.
This PR wires them. It does not reinvent them.

1. A customer cancels their own order when `Cancellation::customerMayCancel`
   allows it: unpaid, before the cutoff, and the self service setting on.
2. Staff cancel any order `Cancellation::staffMayCancel` allows.
3. The reason is recorded in `order_cancellations`, which nothing writes today.
4. `Cancellation::moneyOutcome` decides what goes back and what is kept, and the
   refund is raised through the M5 `Refunds` engine.
5. The delivery slot is released so the day frees up.
6. The customer is told, with the money outcome stated plainly.
7. The checkout shows `Cancellation::policyLine` before the customer pays, so
   the deposit rule is never a surprise afterwards.

### Tests

- A customer cancellation outside the cutoff is refused.
- A paid cancellation raises a refund for exactly the amount
  `moneyOutcome` returns, and no more.
- A cancellation writes the status history row and the cancellation row.

---

## 7. PR4, the trail and the manifest

### The Order Trail

1. Five steps with timestamps as they happen: Placed, Sourced, Packed,
   Dispatched, Delivered.
2. Opened by the token link, no login. Already built.
3. The "Sourced [day] from [state]" line, reading the `source_day` and
   `source_regions` settings that migration 009 already seeded.
4. Money stays off the public view.
5. A refund in progress shows the customer line from
   `Refunds::customerStatusLine`, which is already written and tested.

### The delivery day manifest

1. Pick a day, get every order on it.
2. Grouped by delivery zone.
3. Each row: the customer, the phone number, the address, and every item with
   its quantity and unit, with combos fanned out into components.
4. Printable, using the same document rules as the invoice.
5. Writes `delivery_schedules`, the second table nothing references today.

### Tests

- Manifest grouping and per-zone totals.
- The trail shows no money.

---

## 8. Explicitly out of scope

Naming these matters, because two of them look like they belong here.

- **Automated payment reminders and dunning.** The PRD puts these in Phase 2.
  A scheduled "you still owe us" email is not M6. A staff member pressing send
  on an invoice from Order 360 is fine and is in PR2.
- **SMS.** Phase 2, when a provider is funded. The tables carry a channel column
  so adding it later touches the dispatcher and nothing else.
- **WhatsApp Business API messaging.** Phase 2. Click to chat is M9.
- **Live GPS tracking.** Phase 2.
- **Open and click tracking.** `read_at` exists in the schema. Leave it null.

---

## 9. Answer these before writing code

Per `CLAUDE.md`, five questions minimum, each with three options and a
recommendation. These are the ones this guide could not settle.

1. **Can a dispatched order still be cancelled?** The produce is on a van. Does
   staff get a hard refusal, an override with a reason, or free choice?
2. **Who moves an order to confirmed, and is it automatic on payment?** A paid
   order could confirm itself, or always wait for a human.
3. **Does the customer get an email at every step, or only some?** Five emails
   on a two day order may be too many. Placed, dispatched and delivered may be
   the right three, with the rest as settings.
4. **Is the manifest per day, or per day and zone?** A driver may want only
   their own zone, which changes the screen and the print layout.
5. **What happens to an order nobody marks delivered?** It sits dispatched
   forever. Auto-close after N days, a staff chase list, or leave it.
6. **Should a failed email block anything?** Recommended no, and named here so
   it is a decision rather than an accident.

---

## 10. Definition of done

- Every acceptance criterion in the M6 block of `PROGRESS.md` ticked.
- `php -l` clean on every touched file.
- `bash scripts/brand-check.sh` green.
- `php scripts/tests/run.php` green, with new tests for the transition map, the
  dispatcher, cancellation wiring, and manifest grouping.
- `bash scripts/verify.sh` run against staging with a real base URL. This has
  never run across the whole of M5 and should not slip again.
- One order taken end to end on staging: placed, paid, confirmed, packed,
  dispatched, delivered, with every email arriving and every step visible on the
  trail.

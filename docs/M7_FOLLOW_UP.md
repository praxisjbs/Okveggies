# M7 Kitchen Runs, follow-up scope

Paste the block below into a fresh chat as the opening prompt. Everything above
the line is context for you, not for the model.

This file exists because the M7 completion pass (pull request from
`claude/milestone-7-completion-2nlnib`) shipped the milestone but left twelve
items from the full acceptance list unfinished. They were audited against the
code, not guessed at, and none of them is blocked: they are all ordinary work.

Three decisions were taken by the owner before this was written, and they are
baked into the prompt so nobody re-litigates them:

- **Item 40, the Pro Portal.** A thin screen now that lists a business
  customer's own runs and links to the storefront form. Saved, reusable lists
  stay in M8, where the milestone plan already puts them.
- **Item 16, the balance after delivery.** Keep the current model. Do not add a
  `pay_on_delivery` payment option for Kitchen Runs. Prove the existing path
  instead. The reasoning is in the prompt.
- **Item 10, already-priced.** Record it as its own input mode rather than
  reusing `mixed`, so reports can say "already priced".

---

Repository: `praxisjbs/Okveggies`. Work on branch `claude/m7-follow-up`, cut
fresh from `main`. Read `CLAUDE.md` first and follow it exactly, including the
five clarifying questions before any code, each with three concrete options and
your recommendation.

## What already exists, so you do not rebuild it

Milestone 7, Kitchen Runs, is built and merged. Read these before you start:

- `docs/PRD.md` Section 8, the specification.
- `docs/M7_REVIEW.md`, the review of the first attempt. Read section 2 in
  particular: six public rules shipped with passing tests and no production call
  site. `scripts/tests/KitchenRunsTest.php` now opens with a guard that fails if
  any public method on `KitchenRuns` has no call site outside the tests. Do not
  weaken or exempt that guard. If you add a rule and it fires, either wire the
  rule or delete it.
- `includes/classes/KitchenRuns.php`, the rules and the read path.
- `includes/classes/KitchenRunWorkflow.php`, the only thing that writes a
  request. Every state change goes through its private `transition()`, which
  checks `KitchenRuns::mayTransition()`, bumps `state_version` and writes a
  `kitchen_run_status_history` row. Do not add a state change that bypasses it.
- `api/v1/kitchen_runs.php`, six actions, each gated on method, CSRF and then
  either a customer session or an RBAC permission.
- `kitchen-runs.php` and `includes/components/shop/kitchen_run_{row,detail}.php`.
- `admin/kitchen_runs.php` and `includes/components/admin/kitchen_run_panel.php`.
- `scripts/tests/KitchenRunsTest.php` (unit), `kitchen_runs_db_test.php`
  (database), `kitchen_runs_http_test.php` (HTTP over the real routes).

Migrations `022`, `023` and `024` are shipped. Do not edit them. Anything you
need goes in a new numbered migration, guarded against `information_schema` and
applied through a prepared `ALTER`, the way `020_order_staff_note.sql` does.
**The production database is MySQL 8**, which has no `ADD COLUMN IF NOT EXISTS`.

## The twelve items

### 1. An unsigned visitor is told, not redirected (item 2a)

`kitchen-runs.php` line 34 calls `Customer::requireLogin()`, which redirects a
signed-out visitor to `/account.php?mode=signin`. They never see what a Kitchen
Run is.

Render the page for a signed-out visitor instead: the explanation of the
service, a clear line saying a Kitchen Run needs an account so we can price it
and reach them about it, and the sign-in and register links right there. Do not
render the form, or render it disabled. Suggest verifying the account, since
`Customer::isActivated()` already exists and the activation banner component is
`includes/components/shop/activation_banner.php`.

The server stays the gate. `api/v1/kitchen_runs.php` already calls
`Customer::requireLoginApi()` on `submit`; leave it. The page message is a
courtesy (item 2b, already done).

### 2. The customer chooses the delivery day and zone (item 3)

`kitchen_run_requests.preferred_delivery_date` and `delivery_zone_id` exist and
are populated, but by staff at quote time. The customer's request form never
asks.

Capture both on the request screen. `includes/components/shop/delivery_picker.php`
gives you `okv_delivery_picker($customerType, $field, $selected)`, and
`Delivery::zonesActive()` gives the zones. `KitchenRunWorkflow::submit()` must
store them, and `assertDeliverable()` must run on the way in so a customer
cannot pick a day the shop does not serve. Staff keep the ability to change both
at quote time, because a day can pass while a request waits.

### 3. Filter the admin queue by customer (item 17)

`admin/kitchen_runs.php` filters by status only. Add a customer filter, matching
how `admin/orders.php` does its customer search. Read that implementation first:
its customer filter is the one that shipped broken in M6 because it bound the
same named placeholder twice, which MySQL refuses on a native prepared
statement. Do not repeat it, and add an HTTP test that requests the filter.

### 4. Reorder lines, and a per-line note (item 21)

The admin quote editor at `includes/components/admin/kitchen_run_panel.php`
adds, edits and removes lines. It cannot reorder them and has no per-line note
field, although `kitchen_run_items.note` exists, `KitchenRuns::lines()` returns
it and the customer's detail view already displays it.

Add a note input per line, and move up / move down controls in
`assets/js/admin-kitchen-runs.js`. `sort_order` is written from the array index
in `insertLines()`, so reordering the rows before submit is enough; no schema
change. Keep the no-JavaScript path working: the form must still submit the
order the server rendered.

### 5. An internal note the customer never sees (item 22)

This one is a correctness bug, not a gap. `admin_note` is described in the code
as an internal note and is shown to the customer in
`includes/components/shop/kitchen_run_detail.php` and emailed in the decline
template. Staff writing something frank in that box are publishing it.

Keep `admin_note` as what it is, a note written **for** the customer, and rename
its label on the admin screen to say so. Add a separate internal note, exactly
as `020_order_staff_note.sql` added `orders.staff_note`: a new migration, a new
column, visible only on the admin panel, never in a template, never in an API
response a customer can reach. Add a test asserting the internal note does not
appear in the customer's rendered page.

### 6. The `kitchen_runs.approve` permission (item 23)

Seeded in `002_rbac_seed.sql`, listed in `includes/config/permissions.php`, and
used by nothing. Approval today is a customer action gated by ownership.

Decide and implement one of: staff may approve on a customer's behalf (over the
phone is a real case for this business), gated on `kitchen_runs.approve` and
recorded in the status history as staff-sourced with the actor named; or the
permission is genuinely not needed and is removed. Do not leave it seeded and
unused. Put this in your five questions.

### 7. Cancel while Approved (item 26)

`KitchenRuns::canCustomerCancel()` allows `submitted` and `quoted`. The
requirement is "before it is converted", which includes `approved`.

Add `approved` to `canCustomerCancel()` and `['approved', 'cancelled']` to
`KitchenRunWorkflow::TRANSITIONS`. Note in your questions that an approved run
may already have been bought at market, so the copy should say the customer
should call if it is already being sourced. There is no money to reverse: the
deposit is only taken at conversion.

### 8. Link an order back to its Kitchen Run (item 31)

Request to order works both on the storefront and in the admin. The reverse does
not exist: an order shows only a sentence in its status history saying it came
from a Kitchen Run.

On `admin/orders.php`, when an order has a Kitchen Run pointing at it, link to
it. The lookup is `SELECT id, request_number FROM kitchen_run_requests WHERE
converted_order_id = :id`. PRD Section 2 asks for deep links in both directions
between related records, and names "kitchen run to the order" explicitly.

### 9. Tell the customer their request was received (item 33)

Four notifications fire. Only staff hear about a new list; the customer who just
sent one gets nothing. Add a `kitchen_run_received` template in a new migration,
register it in `Notifications::EVENTS` and `TOKENS`, add
`Notifications::announceKitchenRunReceived()` beside the other four, and call it
from the `submit` action after the transaction commits.

The words: confirm what we received, how many items, and that we will send a
price. It must carry `request_url`, because `NotificationsTest.php` asserts
every customer email has a link back to what it is about.

### 10. A thin Pro Portal screen (item 40)

`pro/kitchen_lists.php` is still a placeholder naming M8. The nav on all three
surfaces should stop landing on a placeholder.

Build a thin screen: this business customer's own Kitchen Runs, their status and
totals, each linking to `/kitchen-runs.php?request=`, plus a clear route into
starting a new one. Reuse `KitchenRuns::allForCustomer()`; write no new domain
code.

**Saved, reusable lists stay in M8.** They are on the M8 checklist in
`PROGRESS.md` and `kitchen_run_templates` exists for them. Say on the screen
that saving a list to reuse is coming, and leave the tables alone.

### 11. Two test gaps (items 43 and 46)

- `kitchen_runs_db_test.php` asserts a free-text line survives conversion with
  its name and price. It does not assert the **unit**. Add it: a Kitchen Run
  line's unit resolves through `COALESCE(u.name, i.unit_label, 'unit')`, and a
  packing list with no unit on it is not usable.
- `kitchen_runs_http_test.php` tests the 405 method gate once, on `submit`. Loop
  it over all six actions. Do the same for the permission gate: assert a
  customer session is refused on `quote`, `convert` and `decline`, and that a
  signed-out caller is refused on all six.

### 12. Already-priced is its own input mode (item 10)

Today the already-priced start posts `input_mode = 'mixed'` with
`pricing_mode = 'already_priced'`. The pricing logic is recorded correctly, but
a report grouping by input mode cannot say "already priced".

Add `'priced'` to `KitchenRuns::MODES`, use it for that start in
`kitchen-runs.php`, and give it a label in `KitchenRuns::modeLabel()`. It
behaves exactly like `mixed` in `submissionLines()` and
`hydrateCatalogueLines()`, so the change is the constant, the form, the label
and the tests. Existing rows keep `mixed`; do not rewrite history.

## One thing to leave alone: the balance after delivery (item 16)

You may be tempted to add `pay_on_delivery` to
`KitchenRuns::PAYMENT_OPTIONS`. **Do not.** The decision is made and the
reasoning matters:

A Kitchen Run is produce we buy at the market with our own cash, against a list,
before anyone has paid. A catalogue order draws on stock we hold; a Kitchen Run
does not. A zero-deposit pay-on-delivery Kitchen Run means fronting the entire
cost with no commitment from the customer, which is the one shape of this
service that can lose real money.

The current model already satisfies PRD 8.2, "the balance is reconciled after
delivery". A deposit conversion writes two payment rows through
`Checkout::writePayments()`: the deposit, and a `provider = 'manual'`,
`payment_type = 'balance'` row due on the delivery date. That balance row is an
ordinary payment row, so M5's manual payment flow already settles it.

What is missing is proof, not a feature. Add a database test that converts a
Kitchen Run on a deposit, finds the balance row through the same query the admin
payments screen uses, records it through `ManualPayments`, and asserts the order
reaches paid with the right balance. If that test passes, item 16 is done and
nothing new was built. If it fails, fix what it found.

## Definition of done

- The five clarifying questions asked and answered before any code.
- Every item above either built or, if you and the owner decide against it,
  written down in `PROGRESS.md` with the reason. No silent drops.
- `php -l` on every touched file.
- `bash scripts/brand-check.sh` green. No em dash anywhere, no banned jargon.
- `php scripts/tests/run.php` green, including the wired-rules guard.
- `kitchen_runs_db_test.php` and `kitchen_runs_http_test.php` green against a
  database built only from migrations, with a web server running for the HTTP
  half.
- New migrations applied to an empty database and then re-applied, to prove they
  are idempotent.
- The customer and admin screens loaded at 390px and 1440px, checked for
  horizontal overflow and 44px touch targets.
- `PROGRESS.md` updated: tick what is done in the M7 block, and add a session
  log entry saying what was built, what was found, and what is still open.
- Commit to `claude/m7-follow-up` and push. Do not open a pull request unless
  asked.

## One open item you are inheriting

The authenticated browser journey at 390px and 1440px has never been exercised,
across two attempts at this milestone, for environment reasons both times. If
your environment allows it, do it and record the result. If it does not, say so
plainly rather than ticking the box. That is the specific failure this milestone
has already had twice.

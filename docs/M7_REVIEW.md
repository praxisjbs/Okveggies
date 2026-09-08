# Milestone 7, senior review

**Branch reviewed:** `M7-kitchen-runs-request-flow` (pull request 36), one
commit, 13 files, 537 lines added.
**Reviewed against:** `docs/PRD.md` Section 8, `CLAUDE.md`, and the M7 block of
`PROGRESS.md`.
**Date:** 8 September 2026.
**Finished on:** `claude/milestone-7-completion-2nlnib`, which starts by merging
your branch rather than replacing it, so the history keeps your work.

This is written for you, directly, because that is more useful than being kind.
It is one lesson, and everything else follows from it.

---

## 1. The one thing to take from this

**Convert-to-order had never run. Not once.**

```php
'INSERT INTO orders (..., subtotal_subunit, order_total_subunit, ...)
 VALUES     (..., :total, :total, ...)'
```

`includes/classes/Database.php` sets `PDO::ATTR_EMULATE_PREPARES => false`.
With native prepared statements, MySQL binds each named placeholder exactly
once, so `:total` twice is `SQLSTATE[HY093]: Invalid parameter number`. Every
call. From the first one.

You ticked the box that said "Admin quote workflow; convert to order" and the
box that said "Tests: convert-to-order", and both were green, and the feature
threw on first use. It took four minutes to prove once a database was pointed at
it:

```
quoted total = 4000000
approved, version 3
CONVERT FAILED: PDOException: SQLSTATE[HY093]: Invalid parameter number
```

**Why the tests did not catch it.** This is the part worth sitting with. Your
`kitchen_runs_db_test.php` had a conversion test:

```php
$failed = KitchenRuns::convertAtomically($requestIds[0], $staffId,
    static function (): void { throw new RuntimeException('test failure'); });
krdb_ok(empty($failed['ok'] ?? true), 'an injected conversion failure is reported safely');
krdb_eq($before, (int) ..., 'a failed conversion rolls back every order write');
```

`convertAtomically()` runs the callback **first** and returns on a throw. So
that test proves that a function which returns early returns early. It never
reached `convert()`. The rollback assertion passed because nothing was ever
written to roll back. You had an `$orderIds` array for cleanup and never put an
id in it, which was the tell: **if your teardown has nothing to tear down, your
test did nothing.**

A conversion test that never converts is not a test. The rule to carry forward:

> Assert the success path first, and assert it against real state. Failure paths
> are worth testing, but a suite that only tests failure paths proves the code
> can fail, which was never in doubt.

**And it was the second time.** Read the M6 review: it found the same reused
placeholder in the orders customer filter, which answered 500 to every search.
The lesson did not transfer, because nothing in the suite made it transfer. Now
something does. `kitchen_runs_http_test.php` loads both screens, every status
filter and every start mode, and converts over the real route. A query that is
never executed against a real database is a query nobody has run.

---

## 2. Six rules that nothing called

`validateSubmission()`, `allowedUpload()`, `mayTransition()`, `quoteExpired()`,
`canCustomerEdit()`, `notificationEvents()`. Forty passing assertions between
them. Zero production call sites.

The upload one is the sharpest. You wrote a careful whitelist, tested traversal,
size and a spoofed `.php.jpg`, and it looked like real security work. Then the
controller did this:

```php
$attachment = Uploads::saveUploadedFile($_FILES['attachment'] ?? [], 'kitchen_runs', [...]);
```

`allowedUpload()` was never called. The tested rule guarded nothing. Had
`Uploads` been less careful than it is, you would have had a green test over an
open upload endpoint.

`mayTransition()` is the same story with a different cost: you wrote the legal
state map, tested nine transitions, and then every state change was written as
its own hand-rolled `UPDATE ... WHERE status = 'x'`. The map was documentation
with assertions attached, and the real rules were scattered across six places
where they could drift apart.

**`scripts/tests/KitchenRunsTest.php` now opens with a guard.** It reads every
shipped PHP file and fails if any public method on `KitchenRuns` has no call
site outside the tests. Run it and it tells you, by name, which rule is inert.
It immediately caught `notificationEvents()`, which had no honest home, so that
method is deleted rather than exempted. Deleting is the right answer when a rule
has no caller. Exempting it is how the problem comes back.

> A rule nothing calls is not covered by a test. It is decorated by one.

---

## 3. What the milestone had not built

Read PRD Section 8 again, slowly. It is 27 lines and the milestone lives inside
them.

**A Kitchen Run is a list.** Your form took one item, with this legend:

```html
<legend>One item, add more by submitting another request if needed</legend>
```

A customer with a 14-item market list would have submitted 14 separate requests,
each needing its own quote and its own approval. That is the whole feature, and
the legend is an admission that the form could not do it. Rendering a repeating
row and cloning it in JavaScript is an afternoon; the reason to notice is that
the PRD's first sentence is "a customer submits a list".

**Already-priced was missing.** PRD 8.1 names four ways in. Your form's select
offered three. Mode 4 was simply absent.

**Mixed lists were impossible.** `mixed` was in `MODES` and had a passing test,
but `hydrateCatalogueLines()` threw unless *every* line had a product id. The
constant, the test and the code disagreed, and only the code mattered.

**Transcription could not happen.** An upload became one placeholder line, and
the quote form iterated existing lines only. A photographed list of fourteen
items could never become fourteen lines. PRD 8.1 mode 3 says "we transcribe and
price it" and there was no way to do it.

**Conversion wrote no delivery address and no trail token.** This is the one
with the widest blast radius. Compare `Checkout::writeOrder()`, which was
sitting there as the worked example:

```php
Database::run('INSERT INTO orders (order_number, order_trail_token_hash, ...)');
self::writeAddress($orderId, $userId, $customer);
```

Without the `order_addresses` row, the M6 day manifest, the packing list, the
invoice, the receipt and the order-placed email all lose the recipient.
`Notifications::orderContext()` LEFT JOINs `order_addresses` and would have
emailed "Hi there". Without the trail token, the public Order Trail, M6's
headline feature, is unreachable for exactly these orders. Everything the
previous milestone built would have quietly failed for Kitchen Run orders only,
and nobody would have known until a customer asked where their order was.

The habit worth forming: **when your feature produces something an existing
feature consumes, go and read the consumer.** One `grep` for `order_addresses`
answers it.

**Nothing notified anybody.** You declared `notificationEvents()` and wired
none of them. PRD Section 14 asks for a "new kitchen run" admin alert by name.

---

## 4. Six more, briefly

1. **A failed form post rendered raw JSON as the whole page** and lost
   everything the customer had typed. Your form posted natively; `okv_error()`
   only speaks JSON. Test one failure by hand in a browser and you see it in
   five seconds.
2. **`convertAtomically()` returned `['ok' => false]` and the controller then
   read `$result['already_converted']`.** An undefined key, and a failure
   reported to staff as a success. The wrapper existed only so a test could
   inject a fault. Production code should not carry a seam that exists for a
   test; if a test needs a seam, that usually means the design needs one for a
   real reason too, and if it does not, the test is asking the wrong question.
3. **The delivery day was never checked.** A run could be scheduled on a day the
   shop does not deliver. `Delivery::isEligible()` already existed and checkout
   already called it.
4. **Hand-typed money went through `Money::toSubunit()`.** That helper is
   deliberately forgiving because it also takes values we generated ourselves:
   it reads `abc` as 0, `-5` as minus five hundred kobo, and `1e3` as thirteen
   naira. Every price on a Kitchen Run is typed by a person. `SettingsEditor`
   already showed the right pattern, validate strictly, then convert; there is
   now a `KitchenRuns::nairaToKobo()` that refuses anything that is not plainly
   money.
5. **Migration `022` wrapped DDL in `START TRANSACTION` and `COMMIT`.** MySQL
   commits implicitly on DDL, so that is a safety net that does not exist.
   `020_order_staff_note.sql` says so in its header; it was two files away.
6. **Formatting.** Single lines of 500 characters with six statements on them.
   `CLAUDE.md` asks for one concern per file and readable code. This matters
   less than the rest, but it is why review is slow, and slow review is how the
   real defects get through.

---

## 5. What you got right

Most of the branch's *thinking* was sound, and it is worth naming, because the
gap here was not judgement.

- **`state_version` as a compare-and-swap guard.** Nobody asked for it. Two
  colleagues pricing the same request in two tabs is a real thing, and you saw
  it. It survives unchanged and is now the backbone of every state change.
- **`original_submission_json`.** Keeping what the customer actually sent, so a
  later disagreement is settled by the record. Exactly right for a service that
  is priced by hand.
- **Reading catalogue prices on the server, never from the form.** The single
  most important security decision in the milestone, and you made it without
  being told.
- **`nonNegativeInt()`** rejecting a kobo figure wider than `PHP_INT_MAX`
  instead of letting it wrap. That is careful work.
- **Your `PROGRESS.md` entry was honest** about what you could not verify. You
  said plainly that the browser blocked your POSTs and that the authenticated
  journey was not exercised. That honesty is worth a great deal and you should
  keep it. The problem was not that you hid a gap; it was that you ticked the
  boxes anyway.

---

## 6. Five habits, in order of value

1. **Run the happy path against a real database before you tick a box.** Not the
   unit tests. The actual thing, end to end, and then look at the rows it wrote.
   Fifteen minutes. Every defect in section 1 and most of section 3 would have
   died there.
2. **Grep for your feature's consumers.** You are writing an order. Who reads
   orders? `order_addresses`, `order_trail_token_hash`, `delivery_schedules`,
   `payments`. Read the code that reads your output.
3. **Before you write a helper, write its call site.** If you cannot name the
   line of production code that will call it, you are writing a test fixture.
4. **When a test passes on the first run, be suspicious.** Break the code
   deliberately and check the test goes red. Your conversion test would have
   stayed green with `convert()` deleted entirely.
5. **A checklist box is a claim, not a status.** "Convert to order" means a
   Kitchen Run became an order and you watched it happen. If you have not
   watched it, the honest entry is "built, not yet proved", and that entry is
   always better received than a green tick that turns out to be false.

---

## 7. Where the finished milestone lives

| File | What it is |
| --- | --- |
| `includes/classes/KitchenRuns.php` | The rules and the reads. Pure, testable, called by every screen. |
| `includes/classes/KitchenRunWorkflow.php` | The only thing that changes a request. Same split as `Settings` and `SettingsEditor`. |
| `api/v1/kitchen_runs.php` | Six actions, each gated twice, JSON for fetch and a 303 for a plain post. |
| `kitchen-runs.php` + two `shop/` components | The customer half. Four ways in, a real list, a quote to approve. |
| `admin/kitchen_runs.php` + `admin/kitchen_run_panel.php` | The queue and the quote workshop with a full line editor. |
| `assets/js/kitchen-runs.js`, `admin-kitchen-runs.js` | Rows and running totals. Enhancement only; both forms work without them. |
| `migrations/023`, `024` | Address snapshot, `quoted_at`, the status history table, the quote window and four templates. |
| `scripts/tests/KitchenRunsTest.php` | 1,755 assertions, opening with the wired-rules guard. |
| `scripts/tests/kitchen_runs_db_test.php` | 89 assertions, including a conversion checked row by row. |
| `scripts/tests/kitchen_runs_http_test.php` | 42 assertions over the real routes, including a real conversion. |

Read `KitchenRunWorkflow::convert()` beside `Checkout::writeOrder()`. They do
the same job and now call the same writers, which is why a Kitchen Run order and
a checkout order are indistinguishable to everything downstream.

---

## 8. One caveat on the verification

The database used to verify this was MariaDB 10.11, because no MySQL 8 build was
installable in the review environment. MariaDB accepts syntax MySQL 8 rejects,
notably `ADD COLUMN IF NOT EXISTS`. Migrations `023` and `024` therefore use only
the `information_schema` guard plus a prepared `ALTER`, the pattern `011` and
`020` already use, which works on both, and `022` was corrected to match. Worth
watching on the first production deploy.

The authenticated browser journey at 390px and 1440px is still unexercised, for
the same reason yours was. It is the one open item, and it is written into
`PROGRESS.md` as such rather than ticked.

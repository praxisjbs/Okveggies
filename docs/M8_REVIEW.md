# Milestone 8, senior review

**Branches reviewed:** `M8-Pro-dashboard` (pull request 40) and
`arena/01a08333-okveggies` (pull request 41, which contains 40). Five commits,
77 files, 5,123 lines added.
**Reviewed against:** `docs/PRD.md` Sections 4.2, 12 and 17, `CLAUDE.md`, and
the M8 checklist.
**Date:** 9 September 2026.
**Finished on:** `claude/milestone-8-completion-stc5te`, which starts by merging
your branch rather than replacing it, so the history keeps your work.

This is written for you, directly. Start with section 1; it is the only one
that really matters.

---

## 1. The one thing to take from this

**You marked seven tasks passed on a day when a suite you cited had never
been able to run.**

`scripts/tests/credit_orders_db_test.php`, the file `PROGRESS.md` names as the
proof for Task H, opened like this:

```php
Database::run(
    'INSERT INTO refunds (order_id, payment_id, amount_subunit, reason, status, requested_by)
     VALUES (:o, :p, :a, :r, :s, :u)', ...
```

The `refunds` table has no `payment_id` and no `reason`. It hangs off a payment
transaction (`payment_transaction_id`) and stores `customer_note`. The file
could not reach line 163 on any database in any environment. One command said
so:

```
$ php scripts/tests/credit_orders_db_test.php
Fatal error: Uncaught PDOException: SQLSTATE[42S22]:
Column not found: 1054 Unknown column 'payment_id' in 'field list'
```

Four seconds. And the `PROGRESS.md` entry beside it read:

> "the MySQL 8 suite `credit_orders_db_test.php` **as designed**, marked passed
> per owner instruction of 9 September 2026"

"As designed" is doing a great deal of work in that sentence, and you knew it
when you wrote it. The phrase appears three times in your entries, always
attached to a suite that had not run.

**Here is the part that should sting.** Task H is the credit money path: the
refund and cancellation adjustments, the thing that decides whether a business
that returns half an order gets their limit back. It was the single most
important thing in the milestone to actually run, and it was the one thing that
could not. Once repaired it passed, and it found two more problems on the way
(see section 2), which is exactly what it was written to do. **The test was
good. You just never ran it.**

The M7 review made this point and you took it seriously enough that your Task A
to E entries are careful and honest. Then the last day arrived, and Tasks H, I,
J and K went in with "per owner instruction" attached. An instruction to record
something as passed is not a measurement. If somebody senior tells you to tick a
box you have not verified, the useful reply is "I will tick it once I have run
it, which is ten minutes" - and if the answer is still tick it, then the entry
says who decided and on what evidence, not "passed".

> A test you have not executed is a file. It becomes a test the first time it
> runs and you read the number it printed.

---

## 2. What the repaired suite found

Once `credit_orders_db_test.php` could run, it went red in a second place, and
that failure was a real gap in the test rather than in your code:

```php
$overId = $writeOrder($green['user_id'], 8000001, $delivery);   // outside
$pdo->beginTransaction();                                       // the
co_refuses(fn() => Credit::drawForOrder(...));                  // transaction
$pdo->rollBack();
co_eq(null, Database::one('SELECT id FROM orders WHERE id = :id', ...),
    'a refused credit order leaves no order behind');
```

The order was written **before** the transaction opened, so the rollback could
never remove it. The assertion was checking something the fixture had made
impossible. Real checkout opens the transaction, writes the order, then draws;
the fixture now does the same, and the assertion means what it says.

This is the M7 lesson with a new costume on. There, a conversion test never
reached `convert()`. Here, a rollback test set up state the rollback could not
touch. Both are the same failure of imagination: **you asserted on the outcome
without checking that the setup could produce it.** The tell is identical too.
If your teardown or your rollback has nothing to undo, nothing happened.

Two other suites were red the moment M8 landed, and neither was in your
`PROGRESS.md`:

- **`kitchen_runs_db_test.php`.** M8 made "approved credit" mean a real
  facility, a 7 to 10 day term and a limit. The M7 fixture set
  `credit_status = 'approved'` with neither, so every on-account conversion
  started refusing with `credit_not_approved`. Your change was right; the
  fixture had to follow it. **When you tighten a rule, grep for who was
  relying on the loose one.**
- **`checkout_db_test.php`** could not even load, because `Checkout` now needs
  `Credit` and that file keeps a hand-written `require` list. Chasing it down
  turned up a real bug that has nothing to do with credit: a guest who checked
  out and then added another item hit the unique index on
  `shopping_carts.session_token`, because the token was still attached to the
  cart their order had converted. Live, that is a 500 on the shop for a guest
  who wants to buy twice. It is fixed and covered.

Three suites, three separate reasons, none of them visible from
`php scripts/tests/run.php`. Which is the point: **the unit runner cannot tell
you a database suite is broken, because it never opens the database.** There is
now `scripts/tests/run_all.sh`, which runs all 34 suites and prints one line.
Use it before you write a `PROGRESS.md` entry.

---

## 3. The formatting, which is not really about formatting

Your first three days produced normal code. `Customers.php`, `KitchenLists.php`,
`ProDashboard.php` and `pro/index.php` are well laid out, well commented and a
pleasure to read.

Then something changed:

| File | Lines | Longest line |
| --- | --- | --- |
| `admin/credit.php` | 21 | **2,863 characters** |
| `pro/credit.php` | 93 | 1,680 |
| `includes/classes/Credit.php` | 432 | 1,448 |
| `pro/orders.php` | 104 | 1,165 |

Those are the files from the last two commits, the same ones with "per owner
instruction" in their `PROGRESS.md` entries. The pattern is unmistakable: when
the pressure came on, the work got compressed, and so did the verification.

I am not going to lecture you about line length. Here is the concrete cost.
`admin/credit.php` held the application queue, the review forms, the manual
grant, the facility controls, the repayment form and the ageing table on 21
lines. Nobody can review that, including you a week later. And sitting in the
middle of it, entirely invisible, was this:

```html
<label class="okv-label">Confirmed payment ID
  <input class="okv-input" name="payment_id" inputmode="numeric" required></label>
```

You asked the Owner of a vegetable business to type a **database primary key**
into a text box to record that a restaurant had paid them. That is not a small
miss; it makes the feature unusable without a database client open beside it.
It is exactly the kind of thing a reviewer catches in four seconds and cannot
catch in a 2,863-character line.

> Dense code does not hide small mistakes. It hides them from review, which is
> where small mistakes are meant to die.

All five files are now laid out normally. Nothing about their behaviour
changed, and the suites prove it.

---

## 4. Four more, briefly

1. **A duplicate credit application answered 422, and your own test expected
   409.** `customer_http_test.php` line 324 asserted the conflict code the
   duplicate-registration path already used two hundred lines above it. The
   controller sorted `active_application` in with the validation failures. The
   test was right and had been failing since you wrote it.
2. **Migration `027` collided.** You numbered
   `027_kitchen_list_line_notes.sql` against a base that did not yet have
   `027_guest_checkout_and_payment_reminder.sql`. Two files, one number.
   `git fetch origin main` before picking a migration number, every time; it is
   the one number in this repo that has to be globally unique.
3. **A test that asserts on source formatting will break when somebody
   formats.** `CreditTest.php` matched the literal string
   `'approve'=>'credit.apply.review'`, so reformatting the controller turned it
   red without changing a thing about its behaviour. Same file, same idea, much
   better: match the pairing and ignore the spacing.
4. **`.okv-btn-outline-sm` quietly lost its `md:min-h-10`.** I suspect a browser
   check flagged a 40px control and you "fixed" it. The convention is 44px on
   touch and 40px from `md` up, which is what `.okv-btn-sm` and `.okv-input-sm`
   beside it still did. When one member of a family disagrees with the other
   two, the odd one out is usually the mistake.

---

## 5. What you got right, and it is a lot

The judgement in this milestone was genuinely good. Almost every problem above
is about verification, not design.

- **The signed journal.** You made the balance a calculation over
  `credit_transactions` and refused to store it anywhere. Checklist item 35
  asks for that in one line; you built the whole milestone around it, and it is
  why adding ageing buckets and automatic settlement later was easy rather than
  frightening. This is the single best decision in the branch.
- **`Credit::drawRefusal()` as one pure rule.** Checkout, the Kitchen Run
  conversion panel and the locked draw all ask the same function, so what a
  customer is offered and what the server permits cannot disagree. That is the
  fix to a class of bug, not to a bug.
- **The locking in `drawForOrder()`.** `SELECT ... FOR UPDATE` on the facility,
  then `FOR SHARE` on the journal inside the same transaction, plus a unique
  `source_key`, plus a refusal to run outside a transaction at all. You wrote a
  comment explaining why a plain read under REPEATABLE READ would answer from a
  stale snapshot. That is real concurrency thinking and it is right.
- **Append-only adjustments.** Cancellations and refunds write a signed row and
  never touch the charge. History tables stay history.
- **Standing Orders stayed honest.** Zero forms, zero inputs, zero selects, zero
  buttons on that page. It would have been easy to build a disabled scheduler
  that looked impressive. You wrote the truth instead, and that is worth more.
- **`Customers.php` runs no `INSERT`, `UPDATE` or `DELETE`, on purpose,** with a
  comment saying every action links to the module that owns it so the audit
  trail stays where it belongs. That is architecture, and it is the right call.
- **Permission layering on `admin/customers.php`.** Six separate gates for six
  separate blocks, rather than one gate on the page. Nobody asked for that.

---

## 6. What I added on top

| Change | Why |
| --- | --- |
| Money on an on-account order settles its own credit | Item 33 was manual only. A business paid, and their limit stayed blocked until somebody remembered. `Payments::recomputeOrder()` is the one seam every money path already goes through, so it hangs there. |
| The repayment picker | A type-to-filter combobox over the confirmed payments that business actually has. The `<select>` is the whole control with JavaScript off. |
| Ageing buckets | Not yet due, 1 to 7, 8 to 30, over 30, computed inside the same journal walk your statement uses, plus a totals row. |
| The overdue sentence on `/pro/credit.php` | Item 23 said "said plainly rather than left to be worked out". A table of dates is leaving it to be worked out. |
| `scripts/tests/visual_pass.mjs` | The browser pass, as a script that prints a count. |
| `scripts/tests/run_all.sh` | All 34 suites, one command, one line. |
| `scripts/tests/credit_checkout_http_test.php` | An on-account order placed over the real route, refused past the limit, and freed by payment. |

The browser pass deserves a note. Three milestones running have recorded
"checks at 390px and 1440px" with nothing having driven a browser: M7 said so
honestly, M8 said "as designed". So it is a script now. It signs in as a real
business customer and a real Owner, walks all eight screens at both widths, and
checks the four things that actually break: horizontal overflow, a touch target
under 44px, a suppressed focus ring, and content hidden behind the fixed mobile
bar. **It also found nothing wrong with your screens.** All 103 checks pass. Your
layouts were fine; there was simply never any evidence that they were, and now
there always will be.

One detail from writing it, because it is a good lesson in what a test is
allowed to conclude. The focus-ring check failed at first, on a page where it
had just passed. The ring is `:focus-visible`, so calling `.focus()` after a
mouse click leaves Chromium in pointer modality and paints nothing. The check
now presses Tab. Asserting on the first version would have recorded a fact
about my script as a fact about your page.

---

## 7. Five habits, in order of value

1. **Run the suite you are about to cite, and paste the number.** Not "as
   designed". Not "per instruction". The number the command printed. If you
   cannot paste a number, the honest entry is "built, not yet proved", and that
   entry is always better received than a tick that turns out to be false.
2. **`bash scripts/tests/run_all.sh` before you write a `PROGRESS.md` entry.**
   It exists now precisely so this costs one command.
3. **When you tighten a rule, grep for everyone relying on the loose version.**
   Your credit rule was right and it broke two suites that had been passing.
   Finding that yourself takes one full run.
4. **Check your fixture can produce the outcome you are asserting.** Before
   asserting a rollback removed a row, ask whether the row was inside the
   transaction. Before asserting a conversion worked, ask whether anything
   called `convert()`. This is the third milestone in a row where the answer
   was no.
5. **When the pressure comes on, slow down on exactly the thing you want to
   skip.** Your first three days were careful and your last two were fast, and
   every defect in this review is in the fast part. Nobody was ever going to
   thank you for the hours saved by not running the credit suite.

---

## 8. Where the finished milestone lives

| File | What it is |
| --- | --- |
| `includes/classes/Credit.php` | Applications, the facility, the signed journal, the locked draw, settlement and ageing. One place, all of it. |
| `includes/classes/Customers.php` | The read-only customer 360. Runs no writes, by design. |
| `includes/classes/KitchenLists.php` | Saved lists, save-from-run and start-run prefill. |
| `includes/classes/ProDashboard.php`, `ProOrders.php` | The business dashboard and order history reads, both customer-scoped. |
| `includes/functions/pro_access.php` | One gate, on all six Pro screens. |
| `api/v1/credit.php` | One customer action, eight staff actions, each on its own seeded permission. |
| `admin/credit.php` | The queue, the review, the grant, the facility controls, the repayment picker and the ageing table. |
| `pro/credit.php`, `pro/orders.php`, `pro/kitchen_lists.php` | The business half. |
| `migrations/028` to `033` | Share links, the decision reason, the two unique guards, the credit templates and the list line notes. |
| `scripts/tests/credit_orders_db_test.php` | 48 assertions. The one that could not run. |
| `scripts/tests/credit_checkout_http_test.php` | 18 assertions, on account, over the real route. |
| `scripts/tests/visual_pass.mjs` | 103 checks at 390px and 1440px. |
| `scripts/tests/run_all.sh` | All 34 suites, one line. |

Read `Credit::settleOrderFromPayments()` beside `Credit::drawForOrder()`. One
puts money on the account and one takes it off, both append-only, both computed
from the journal rather than from a stored figure. That symmetry is yours; I
only wrote the second half.

---

## 9. On the verification environment

Everything above was verified on **MySQL 8.0.46**, which is what production
runs, so the M7 caveat about MariaDB does not apply this time. Migrations 000
to 033 applied to a fresh database and the second run reported nothing to apply.
Notification delivery was checked against a local SMTP sink, with the two test
messages captured and read. `settings_db_test.php` needs
`log_bin_trust_function_creators` to create its tripwire trigger; if it is
skipping for you, that is why, and it is not a defect in the test.

`scripts/verify.sh` is a deployed-host check and was not run against production
here. Its `/migrations/` and `/docs/` denial checks were failing before this
milestone and are a deployment matter, still open, recorded in `PROGRESS.md`.

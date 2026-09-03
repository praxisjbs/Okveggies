# Milestone 6, senior review

**Branch reviewed:** `M6-pr1-order-cancellation` (pull request 33), two commits,
32 files, 2,111 lines added.
**Reviewed against:** `docs/PRD.md` Sections 13, 14 and 15, `docs/M6_GUIDE.md`,
and the M6 block of `PROGRESS.md`.
**Date:** 3 September 2026.

This is written for the engineer who built it. It is direct because that is more
useful than being kind, and it starts with what was right because most of it
was.

---

## 1. The verdict

Three of the four pull requests the guide asks for are here and they are good
work. The fourth, notifications, was not started, and the whole M6 block in
`PROGRESS.md` was ticked anyway. That gap is the review's main finding and it is
now closed.

| Guide section | State on arrival |
| --- | --- |
| PR1, the lifecycle spine | Built. Sound. |
| PR2, notifications | **Not started.** Zero lines. |
| PR3, cancellation | Built. Sound, and careful with money. |
| PR4, trail and manifest | Built, with one behaviour gap and one performance problem. |

Everything in this review has been fixed or completed on
`claude/milestone-6-review-3g7l85`, which builds on your two commits rather than
replacing them.

---

## 2. What you got right

Say this first, because it is most of the branch.

**The money is treated with respect.** `OrderCancellation` reads
`Cancellation::moneyOutcome` rather than reinventing it, locks the order row,
rereads it, and refuses a cancellation while a payment attempt is still in
flight. The unique key on `order_cancellations.order_id` plus the row lock makes
a double click harmless. A paid staff cancellation needs both `orders.cancel`
and `payments.refund`. Money paid outside Paystack is labelled as needing a
person rather than quietly counted as refunded. `refundPlan` unwinds newest
transactions first, which is the right default and is unit tested. This is the
part of the branch I trust most.

**The refund is raised after the commit, not inside it.** You saw that an SMTP
or gateway round trip does not belong inside an open transaction. That instinct
is exactly right and it is the same instinct the notification work needed.

**The transition map is pure and exhaustively tested.** Every legal move
allowed, every illegal one refused, in both directions, thirty-six assertions
for six statuses. `staffTargets` correctly keeps cancellation off the stage
dropdown so the money rules cannot be walked around. The optimistic
`expected_status` token on the form, rechecked under the lock, is a real answer
to two people working the same order.

**The public trail withholds money properly.** Items without prices, no payment
choice, no amount due, and an HTTP test that asserts the naira sign never
appears. That test is the right shape: it tests the absence, not the presence.

**Migration 018 is correct MySQL 8.** Guarded against `information_schema`,
applied through a prepared statement, backfill written as an idempotent
`INSERT ... SELECT` with a `LEFT JOIN` guard. I applied every migration from
`000` to `020` twice from an empty database and it is clean.

---

## 3. The finding that matters: half the milestone was missing

`docs/M6_GUIDE.md` Section 1 moves order and payment notifications out of M9 and
into M6, and gives the reason in the guide itself: every order email is fired by
a status change M6 builds, and PRD 14.2 says the customer opens the Order Trail
"from a link in the confirmation email". Section 5 is a full page of detail on
it. None of it was built. `Notifications` did not exist. The `notifications` and
`notification_deliveries` tables were still referenced by zero lines of PHP.
`Mail::sendTemplate` had exactly one caller in the whole repository, and it was
the account activation code.

The practical effect: an order could be placed, confirmed, packed, dispatched
and delivered, and the customer would never hear a word. The Order Trail, the
signature trust pattern of this product, had no route to it at all unless the
customer kept the browser tab open from checkout.

Two habits would have caught this.

**Read the guide's definition of done, not the checklist.** The M6 block in
`PROGRESS.md` has six lines and none of them says "notifications", because the
milestone list was written before the guide moved the scope. The guide says so
in its first section. When a guide and a checklist disagree, the checklist is
the stale one. You then ticked all six and wrote "M6 delivery configuration, the
staff lifecycle, the public Order Trail and the day manifest are complete",
which was true, next to six ticks that implied the milestone was.

**Answer the five questions.** `CLAUDE.md` requires five clarifying questions
with three options each before any code. The guide even lists six of them for
you in Section 9, including "Does the customer get an email at every step, or
only some?" There is no record of any being asked. Question 3 would have
surfaced the whole missing pull request in one sentence.

---

## 4. Defects found in the code you did write

**A brand colour that does not exist.** `bg-clay-tint` and `border-clay` appear
in eleven places across `admin/payments.php`, `admin/orders.php` and
`public/order.php`. `clay` was never in `tailwind.config.js`, so every one of
them compiled to nothing. On the customer's order page, "That payment did not go
through" rendered with no border and no background, visually identical to a
neutral note. The bible has the colour (Clay Terracotta, `#B85C3E`); the config
never got it. Two of those uses are yours. The habit: after adding a utility
class, grep the compiled `assets/css/tailwind.css` for it. If it is not there,
it does nothing.

**The trail's sourcing line was blank when it mattered most.** You only rendered
"Sourced [day] from [state]" once `confirmed_at` and the snapshot were both set.
A customer who has just paid and is anxious opens the trail and sees nothing.
PRD 14.2 lists the trail as one of three places the promise is made. It now
reads the snapshot once confirmed, which is a fact, and the live settings before
then, which is the promise.

**The day manifest was two queries per order.** One for the items and one per
combo line for the components. A Saturday with 60 orders carrying two combos
each was over 180 round trips, on the single screen the team stands at while the
van waits. It is two queries for the whole day now. The tell to watch for: a
query inside a `foreach`.

**Zone totals summed rounded numbers.** `packingLines` formatted each quantity
to three decimal places as a string, and `totals` cast those strings back to
float and added them. Format for display at the last possible moment; add the
exact values.

**`Cancellation::policyLine` was written, tested, and never shown.** The guide's
PR3 item 7 asks for it at checkout so the deposit rule is never a surprise
afterwards. Its only callers were the tests. A method with no production caller
is a job that was not finished.

**Dead ends between related screens.** `CLAUDE.md` asks for deep links both
ways. There was no route from the manifest to an order, from an order to its
delivery day, or from Order 360 to the invoice, the receipt or the customer's
own view of the trail.

Also missing from the guide's Order 360 list: the M5 money ledger (expected,
paid, refunded, net, outstanding, rather than just two figures), the internal
staff note, and the notification history.

---

## 5. The claim that could not be checked

The branch says the checks could not run because "this checkout has no `.env`
database credentials" and "no base URL is configured". That is a fair statement
of the blocker. It is not a fair place to stop: the environment took about
fifteen minutes to build, and `PROGRESS.md` already carried the same admission
across the whole of M5.

I installed MySQL 8.0.46, applied every migration from empty, ran a PHP server
and a local SMTP sink. The result is worth knowing: **everything you wrote
passed on the first run.** 310 database assertions, 94 HTTP assertions,
`verify.sh` green apart from four checks that depend on Apache reading
`.htaccess`, which the PHP built in server does not do.

So the code was right. The problem is that neither of us knew that until today,
and you shipped a pull request asking a reviewer to take it on trust. Stand the
environment up once and it is there for M7 as well.

One related note: `ok_veggies_schema.sql` at the repository root is stale
against `migrations/001_core_schema.sql`. It is missing `orders.delivery_zone_id`
among other things. If you load it and then run migrations, you get a database
that looks fine and fails at runtime. Build test databases from `migrations/`
only. That file is not yours and is not fixed here, but it will bite someone.

---

## 6. What was added

- `includes/classes/Notifications.php`. One dispatcher, two channels (email and
  in the app), a `notifications` row and a `notification_deliveries` row per
  recipient and channel, and a failed send that is caught, recorded with its
  error, and never thrown at the caller.
- Migration `019`: the seven templates that had no words, including
  `order_packed` so every stage is announced, the `notifications.resend`
  permission, and the index the in-app inbox reads.
- Migration `020`: `orders.staff_note`, deliberately not in
  `order_status_history`, because a note written there would appear as a step on
  the public trail.
- Every event wired from its controller after the commit: order placed, all four
  stages, cancellation with its money outcome stated plainly, and M5's four
  orphaned payment events.
- A Notifications tab on the settings screen behind `settings.notifications.edit`
  with the tokens each message accepts, a sandboxed preview of the real branded
  render, and an em dash refused at the API before it can reach an inbox.
- Order 360 completed: the money ledger, the document and trail links, the staff
  note, and every message sent with a resend on a failed one.
- The six defects in Section 4, all fixed.
- 155 new unit assertions, 77 new database assertions, 10 new HTTP assertions,
  and three new routes in `verify.sh`.

---

## 7. What is still not proven

Named here so it is a decision rather than an accident.

- No real or sandbox Paystack charge or refund was sent. The M5 test-mode ledger
  regressions stand in for it.
- No production SMTP account was used. The branded email is captured and read,
  but deliverability, SPF and DKIM are unproven.
- Nothing was printed on paper. The print rules were read off the rendered page.
- The row locks are tested for the stale and repeat paths but were not forced
  under concurrent load.
- Later stage emails link to `/public/order.php?order=N`, which needs a sign in.
  Only the confirmation email carries the no-login token, because only the
  SHA-256 of the token is stored and it cannot be recovered afterwards. Every
  order has an account, so nobody is locked out. If the no-login link is wanted
  on every email, the answer is to store the token encrypted with
  `APP_ENCRYPTION_KEY` beside the hash. That is a real decision and belongs to
  the client, not to a review.

---

## 8. Three habits for M7

1. **Ask the five questions.** They are cheap and they are in `CLAUDE.md` for a
   reason. The guide for M7 will have its own Section 9. Start there.
2. **Stand the environment up before you write code, not after.** A test you
   have not run is a hope.
3. **Grep for your own callers.** Before ticking anything, search the repository
   for the class you built. If the only hits are the tests, it is not wired in.

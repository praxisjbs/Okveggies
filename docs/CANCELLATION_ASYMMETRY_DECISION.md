# Cancellation Asymmetry Decision Record

**Status:** Decided and implemented
**Decided:** 20 September 2026
**Decided by:** Owner, with engineering (PR1 of `docs/REMAINING_27_FIXES_8PR_PLAN.md`, fix 14)
**Closes:** The M13 release dependency "Cancellation policy" (`docs/M13_RELEASE_CONTRACT.md` Section 4) and the 9 September operations review question (audit item P1#10)

---

## 1. The question

A customer who paid a deposit and cancelled after the cutoff forfeited the
deposit, 30 percent of the order. A customer who paid 100 percent up front and
cancelled after the cutoff was refunded everything, because
`Cancellation::moneyOutcome()` capped the forfeit at
`orders.deposit_required_subunit`, which is empty for a pay-in-full order.

So the consequence of a late cancellation depended on which payment option the
customer happened to pick at checkout. The person who paid more money up front
was better off cancelling late than the person who paid less. That may have been
intended generosity or an oversight, and the audit said it had to be settled
with the Owner before launch.

## 2. The options put to the Owner

**A. Symmetric deposit share.** After the cutoff, every paid order forfeits the
same share: the deposit taken at checkout, or the same percentage of an order
that was paid in full. `moneyOutcome()` stays pure; its callers pass one
symmetric cap.

**B. Keep the asymmetry.** Deposit payers forfeit the deposit; pay-in-full
payers are refunded everything, because they never promised a deposit.
Documented as deliberate, no behaviour change.

**C. Never forfeit.** Every late cancellation is refunded in full. The deposit
protects nothing, and the checkout copy that promises the produce has already
been bought becomes hollow.

## 3. The decision

**Option A, symmetric deposit share.** The Owner's reasoning, in one sentence:
the produce has been bought either way, so the consequence of cancelling late
must not depend on how the customer chose to pay.

## 4. What the rule is now

- A cancellation before the cutoff still refunds everything, always.
- After the cutoff, with `cancellation_deposit_forfeit_after_cutoff` on, the
  forfeit is `min(paid, deposit share)` where the deposit share is:
  - `orders.deposit_required_subunit` when the order took a deposit, or
  - the deposit percentage of `orders.order_total_subunit` when it did not,
    using the order's own recorded `deposit_percentage` and falling back to the
    current `deposit_percentage_default` setting for orders that never wrote one
    down.
- Anything paid beyond that share is always refunded. The business protects its
  committed cost; it does not keep the whole order.
- The dispatch rule is unchanged: a dispatched order keeps the same deposit
  share even inside the cutoff, when
  `cancellation_dispatched_forfeit_deposit` is on, because dispatch is a
  stronger fact than the clock.
- Pay-on-delivery orders that are still unpaid forfeit nothing, because nothing
  was paid.

## 5. Where it lives in the code

- `Cancellation::depositShare()` computes the share. Pure, unit tested.
- `OrderCancellation::forfeitCap()` reads the order row, applies
  `depositShare()`, and caps it at what the customer actually paid. Both the
  write path (`cancel()`) and the read path (`decorate()`) use it, so the
  promised figure on the screen is the figure the refund engine keeps.
- `Cancellation::moneyOutcome()` is unchanged in shape and still does the
  `min(paid, cap)` protection. Its callers now pass the symmetric cap instead
  of the raw, sometimes empty, `deposit_required_subunit`.
- Checkout and FAQ copy comes from `Cancellation::policyLine()` and
  `termsLine()`, which now say "the deposit part of what you paid" without
  naming a naira figure (Owner wording choice, option B of the PR1 question).
  The same sentences reach the order screen and the cancellation email, so the
  promise made at checkout is the one that is kept.

## 6. Evidence

- Unit: `scripts/tests/CancellationTest.php`, the deposit-share section and the
  symmetric pay-in-full outcome.
- Database: `scripts/tests/cancellation_db_test.php`, a pay-in-full order
  cancelled after the cutoff keeps the same 30 percent share a deposit customer
  would lose, and returns the rest.
- The settings screen copy (`includes/config/settings_fields.php`) states the
  rule plainly for the person switching it on or off.

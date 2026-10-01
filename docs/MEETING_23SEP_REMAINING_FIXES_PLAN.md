# Client review 23 Sep 2026: remaining fixes, three PR plan

**Source:** OkVeggies Website Review meeting (23 Sep 2026), the error report
(`OkVeggies-website-error-report-2026-09-23.md`) and the Owner's answers to the
twelve clarifying questions on 29 Sep 2026.
**Scope:** the ten items not already shipped by the earlier fix batch
(#76 to #88 and the checkout quantity editor). Item numbers follow the error
report order (1 = ERR-01 ... 19 = VERIFY-02).

| # | Item | PR |
|---|------|----|
| 3 | Business credit order shows unpaid (ERR-03) | PR1 |
| 4 | No credit line option on the amount due view (ERR-04) | PR1 |
| 5 | Pay now only reopens the order (ERR-05) | PR1 |
| 8 | Unpaid orders can move to Sourced (ERR-08) | PR1 |
| 10 | Account credit is invisible (ERR-10) | PR2 |
| 13 | Out of stock refund workflow (GAP-01) | PR2 |
| 2 | Homepage hero is hardcoded (ERR-02) | PR3 |
| 15 | Our Story and every page editable (GAP-03) | PR3 |
| 18 | Complaint photo confirmation (VERIFY-01) | PR3 |
| 19 | Stock status reset (VERIFY-02) | PR3 |
| new | Content and Messages badge stays lit after messages are read | PR3 |

## Decisions (from the Owner's answers)

### PR1: order money
1. **Credit order status (hybrid).** An order placed on the credit line reads as
   paid by the credit line, never as unpaid. Two layers:
   the order layer says **Paid with your credit line**; the credit layer says
   **Repay X by <due date>**. Cash fields (`amount_paid_subunit`, payment rows)
   stay cash only, so `Credit::settleOrderFromPayments`, cancellation, refunds
   and the Payments screen are unchanged. One helper, `OrderMoney`, feeds every
   screen and email so they cannot disagree.
2. **Credit line on an existing order.** Both paths, sharing one locked,
   idempotent draw (`Credit::convertOrderToCredit`): a customer button on the Pay
   sheet, and a staff action **Source on credit line**. Only orders with nothing
   paid, no card attempt in flight, and not yet sourced can switch.
3. **Pay now.** Opens a method sheet. Methods that apply to that order:
   Paystack (card, transfer, USSD), Use my credit line, Repay (on-account
   orders, through Paystack, which frees the limit through the existing
   settlement), Pay deposit (pay on delivery orders). A wallet method arrives in
   PR2. There is no customer bank transfer proof flow, so none is offered.
4. **Sourcing gate (strict, with exceptions).** Placed to Sourced needs what
   checkout asked for: the full total for pay in full; the deposit for deposit
   AND pay on delivery orders; a posted credit charge for on-account orders.
   Exempt: orders that came from a Kitchen Run and orders staff entered by hand.
   The Owner may override with a logged reason (`orders.source.override`).

### PR2: wallet, credit notes, shortages
5. **Wallet for every customer** (household and business), credit only: money
   enters only from OK Veggies (shortage credits, complaint credits, cancellation
   refunds the customer keeps, goodwill credits, credit notes). Customers spend
   it on any order, or ask for it back as a manual bank refund (bank name,
   account number and account name in, staff pay and mark refunded). No top ups.
6. **Wallet pays first**, then the credit line if eligible, then Paystack for the
   rest. Partial use allowed. Pre-ticked on checkout and the Pay sheet, the
   customer can untick it.
7. **Credit notes.** Every credit issues a numbered credit note (PDF) tied to the
   order and reason, visible to the customer and to staff. Wallet entries are
   append only and idempotent; spending locks the wallet so it can never go
   negative.
8. **Out of stock during sourcing.** Staff mark a line short (a quantity or the
   whole line) in the order details. The customer gets an email and in app
   notice with a link and two big buttons: refund to my bank, or add to my
   wallet. Staff can also decide for them. Refunds are manual (Paystack does not
   handle refunds for now). No auto default: it waits for the customer or staff.
   Less text, more buttons, long text behind an info icon.

### PR3: content and small fixes
9. **Every page editable.** One generic editable slot mechanism; every storefront
   page's informational copy and images route through it (headings, eyebrows,
   intros, section copy, button labels and links, images with alt text). System
   messages and validation strings stay in code. The dashboard wins: no seeding.
10. **Hero.** Reads the Content module. After deploy it shows whatever is
    published there; the approved wording goes in the PR body to paste.
11. **Stock reset.** Reproduce in a browser against a local database and fix what
    breaks; if nothing breaks, say so with the evidence.
12. **Complaint photos.** Confirmation states how many photos were attached and
    shows them at once; a test pins the flow.
13. **Admin badge.** Content and Messages keeps showing notifications after the
    messages were read; find and fix.

## Acceptance checks (PR1)
- An on-account order never renders "Nothing has been paid yet", "still to pay"
  or a Pay now button, on the confirmation, the account list, the Pro orders
  list, the admin order, the customer profile, the emails and the documents.
- The credit limit and outstanding figures are identical before and after this
  change for the same ledger.
- A business with approved credit and enough available credit can switch an
  unpaid, untouched order to the credit line from the Pay sheet; a second click,
  a double submit or a parallel request writes one charge only.
- A card attempt in flight blocks the switch; a paid, cancelled, sourced or
  part paid order cannot switch.
- Staff can **Source on credit line** in one step. A refused draw leaves the
  order untouched.
- Placed to Sourced is refused for: household unpaid, business without credit
  and unpaid, deposit order without the deposit, pay on delivery without the
  deposit. It is allowed for: paid, deposit paid, credit charge posted,
  Kitchen Run conversion, staff entered order, and Owner override with a reason.
- Pay now starts Paystack directly (one tap) for a card payment owing.
- Repay on an on-account order posts the repayment and frees the limit through
  the existing settlement, once.

## How PR2 is split, and what was decided while building it

PR2 is two pull requests, because the wallet is useful on its own and the out of
stock flow needs the manual refund queue on top of it.

- **PR2a: the wallet.** Ledger, credit notes, complaint credits, wallet on the
  Pay sheet and at checkout, cancellation refunds back to the wallet, goodwill
  credit by the Owner, wallet pages for households and businesses.
- **PR2b: out of stock and manual refunds.** Staff mark a line short during
  sourcing, the customer picks bank refund or wallet from an emailed link, staff
  can decide for them, the manual refund queue (bank details in, staff pay, mark
  refunded), and wallet cash out on request through the same queue.

Decisions taken in PR2a that were not in the Q and A, and why:

1. **A partial wallet payment gets its own paid payment row.** A card charge
   overwrites the paid amount of its row (one row, one charge), so a wallet part
   on the same row would be lost when the card charge for the rest arrives. The
   wallet part is a `wallet` row, paid on creation, and the row still owed is
   reduced by the same amount (voided if nothing is left, never deleted), so the
   rows keep adding up to the order total and the card charge is an exact match.
2. **Wallet plus the credit line is not offered together.** The credit line
   settlement reads paid cash as repayment of the charge, so a wallet part paid
   before the conversion would be counted twice. A customer picks the wallet
   (with the rest by card) or the credit line. The wallet can repay a credit
   order, in full or in part.
3. **The wallet is withheld while a card attempt is unresolved**, exactly like
   the credit line, because that charge could still arrive for the full amount.
4. **Credit notes print from the browser, like invoices and receipts.** They use
   the same document frame and its Print button, so Save as PDF is one tap. No
   second PDF engine for one document.
5. **Complaint credit always goes to the wallet**, for households too, and no
   longer writes to the business credit ledger. The Owner permission for it is
   unchanged (`credit.grant`).
6. **Cancellation returns wallet money to the wallet at once**, keyed on the
   transaction so a retry cannot pay it twice, with a credit note. Nothing waits
   on a person for money that never left the building.
7. **Checkout uses the wallet only for a pay in full or a deposit order.** On
   account and pay on delivery have nothing to pay online at that moment; a pay
   on delivery customer can pay the deposit from the wallet afterwards.

## Test plan
Unit tests for every pure rule (`OrderMoneyTest`, `SourcingGateTest`, additions to
`CreditTest`, `PayMethodsTest`). Database suites on MySQL 8 for the locked paths
(`credit_line_existing_order_db_test.php`, `sourcing_gate_db_test.php`). Then
`php -l`, `scripts/brand-check.sh`, the unit runner, the affected DB suites
against the pre-change baseline, and a browser pass with screenshots.

## Risks
- Merging to `main` deploys to production and runs migrations. Every migration
  here is idempotent, MySQL 8 safe and verified twice on a fresh database.
- The sourcing gate changes daily operations the moment it deploys. The Owner
  override is the escape hatch, and the message names what is missing.
- The compiled stylesheet and minified bundles are committed. They are rebuilt in
  the same commit as the source change that needs them.

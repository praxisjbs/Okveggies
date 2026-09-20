# Milestone 10, senior review

**Branch reviewed:** `M9-issue-report-make-it-right` (pull request 47). Eight
commits, 52 files, 5,116 lines added.
**Reviewed against:** `docs/PRD.md` Sections 2, 16 and 17, `CLAUDE.md`, and the
M10 checklist.
**Date:** 16 September 2026.
**Finished on:** the same branch, so the history and the authorship stay yours. I
made two small fixes in place, described in Section 3.

This is written for you, directly. Section 1 is the one that matters.

---

## 1. The one thing to take from this

**You spent the rate-limit allowance before you validated the form. That is the
exact bug the M9 review wrote up, on the previous milestone, in the same
codebase.**

In `IssueReports::submit()` the first thing the method did was call
`spendRateAllowance()`, and only after that did it check the category and the
description. The M9 review put it this way, about the contact form:

> a refused submission spent the IP rate limit, so three typos locked a customer
> out of the only support channel for 15 minutes.

Make It Right is worse in one way and better in another. Worse: this is the
channel a customer reaches for when produce arrived wrong and money is at stake,
so being locked out matters more. Better: the account limit is 5 an hour rather
than 3 in 15 minutes, so it takes 5 mistakes, not 3. But the shape is identical.
A customer who forgets to pick a category, or types "Crushed" and nothing else,
burns a token every time, and after 5 attempts at getting the form right they
are told to wait an hour before they can report a genuine problem.

The reason this is easy to miss is that your test **encoded the bug as correct**.
`issue_reports_db_test.php` looped `ACCOUNT_LIMIT` malformed submissions with the
category `bad` and asserted the sixth was `rate_limited`, with the comment "This
attempt still counts." The test passed, so the behaviour looked deliberate. A
green test that asserts the wrong thing is more dangerous than no test, because
it tells the next person the behaviour is a decision rather than an accident.

The fix is an ordering change: validate the cheap in-memory fields first, and
spend the token only once an attempt is well formed enough to reach the database
work the limit actually protects. A malformed request is a customer fixing a
mistake, not an attacker, and the flood protection you want lives on the path
that touches the database, not on `if (!isset($categories[$category]))`.

**The habit to build:** when a review names a bug by class, not just by line, it
is telling you about every future instance of it. "A refused submission must not
spend the allowance" is a rule, and it applies to the next form you write too.
Keep a short list of the rules the reviews have handed you and read it before you
ship a form.

---

## 2. What was strong

Genuinely strong, and worth saying plainly.

- **The privacy boundary is right.** The public token trail withholds the entire
  issue section, and you went further and excluded Make It Right refunds from the
  share-token refund notices in `OrderTrail`, so a shared link cannot even infer
  that a report exists. The photo route checks ownership or `issues.view`,
  validates the stored path against a strict pattern, resolves it against a
  `realpath` base to stop traversal, and serves it under
  `Content-Security-Policy: default-src 'none'; sandbox` with `no-store`. That is
  the standard I want on every private asset.
- **The upload validation is careful.** Extension whitelist, `finfo` MIME sniff,
  a disguised-extension check, `getimagesize` agreement, dimension and pixel
  caps, and a truncation check that reads the real end-of-file marker for JPEG,
  PNG and WebP. You rejected the browser's MIME claim outright. Good instinct.
- **The money paths are capped twice and idempotent.** A refund is bounded by
  both the selected line totals and the gateway's refundable amount, the
  refund-to-issue link is unique so a concurrent double cannot raise two gateway
  calls, and account credit carries an `issue:<id>:credit` source key so a replay
  returns the original entry. Nothing here can pay a customer twice.
- **The migrations obey the house rules.** Numbered inside your reserved range,
  each column and index guarded against `information_schema` and applied through
  a prepared statement (not the MariaDB-only `IF NOT EXISTS`), append-only
  history, and `INSERT IGNORE` so re-applying never clobbers edited copy. The
  notification-copy migration in `048` matches the exact prior default before
  updating, so an Owner's edited wording survives. That is exactly the discipline
  `CLAUDE.md` asks for.
- **The staff workflow is honest about state.** Row locks, expected-state checks,
  a same-actor take that is a harmless no-op, an audited Owner takeover instead of
  a silent `handled_by` rewrite, and terminal replay protection. The state graph
  holds up.

---

## 3. What I changed on your branch

Two fixes, both small, both leaving every runnable check green (2,970 unit
assertions, `php -l`, and all 8 brand checks).

1. **The rate-limit ordering**, in `IssueReports::submit()`, described in Section
   1. I moved field validation and the photo-count check above
   `spendRateAllowance()`, and updated the one test that asserted the old
   behaviour so it now proves the right thing: a malformed attempt is refused on
   its fields without spending a token, and only well-formed attempts count
   towards the limit.

2. **A suppressed focus ring**, in `public/order.php` and
   `admin/make_it_right.php`. The photo-thumbnail links carried
   `focus:outline-none` with no replacement. The global gold ring in
   `input.css` is defined with `:where(...)`, which has zero specificity, so the
   Tailwind utility won over it and the keyboard focus outline vanished on those
   links. `CLAUDE.md` calls the gold focus ring a non-negotiable that is "never
   suppressed", so I removed the utility and let the global ring show. The lesson
   generalises: never reach for `focus:outline-none` unless you are replacing the
   ring in the same class list.

---

## 4. Gaps and improvements for the next milestones

Not blocking the merge, but worth your attention.

- **The checkout trust panel sits inside the radio group.** You placed it between
  the deposit option and the pay-on-delivery option, so a bordered card lands in
  the middle of the payment choices. It reads as though it might be an option
  itself. Consider moving it below the whole group, or making it visually clearly
  not-a-choice. The content is right, the position is what I would revisit.
- **The refund gateway call happens before the report row is locked.** In
  `IssueResolutions::refund()` you call `Refunds::request()` and only then lock
  the report in `finishRefund()`. The unique refund link saves you from a double
  gateway call, so this is safe, but the tidier shape is to take the lock and
  re-check the report is still yours and still in progress before any money
  moves, then release the money, then write the terminal row. Same guarantees,
  less reliance on the database constraint as the backstop.
- **The staff queue has an N+1.** `forStaff()` is fine because it aggregates photo
  counts in one query, but `findForStaff()` then calls `photosForReport()`,
  `order_items` and `historyForStaff()` as separate round trips per open report.
  At 25 reports a page and one detail at a time this is invisible. If the queue
  ever grows a bulk view, batch these.
- **`page.php` grew a real reader, which is M12 work.** You needed the Delivery
  Policy link to land somewhere, so you built a read-only published-page reader
  and deferred editing to M12. That was a reasonable call and you said so in
  `PROGRESS.md`, which is the right way to handle scope you have to touch early.
  Flag it to whoever picks up M12 so they do not build it twice.
- **Notification sends are best-effort and you leaned on that correctly**, but the
  staff alert for a new report goes to everyone holding `issues.view`. On a two
  person team that is fine. When roles multiply, revisit whether every holder of
  the read permission should be emailed on every report, or only a smaller
  on-call set.

---

## 5. Close

This is the strongest milestone you have shipped. The security instincts, the
money discipline and the migration hygiene are all where they need to be, and the
privacy boundary in particular is better than the brief asked for. The one real
defect was a repeat of a lesson from the milestone before, and the way it hid
inside a passing test is the thing to sit with: your tests are only as honest as
the behaviour you assert in them. Write the assertion you wish were true, then
make the code true, not the other way round.

Merged with the two fixes above.

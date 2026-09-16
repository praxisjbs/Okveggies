# Milestone 11, senior review

**Branch reviewed:** `M11-Admin-dashboard` (pull request 48). Six commits, 38
files, about 4,400 lines added.
**Reviewed against:** `docs/PRD.md` Sections 2 and 17, `CLAUDE.md`, and the M11
checklist.
**Date:** 16 September 2026.
**Finished on:** the same branch. I merged current `main` into it and resolved
the collision described in Section 1, so the history and the authorship stay
yours. Your own delivery write-up is kept as `docs/M11_HANDOVER.md`.

This is written for you, directly. Section 1 is the one that matters.

---

## 1. The one thing to take from this

**You branched M11 off `main` before M10 merged, and then rebuilt a thing M10
already owned, with different names. When the two met, they collided.**

Concretely, M10 shipped the `admin_new_issue_report` staff alert: an events
entry, a token list of `category` and `description_preview`, a template in
migration `045`, and a real sender, `announceIssueReportReceived()`, that the
Make It Right controller actually calls. You could not see any of that, because
on the branch you cut, Make It Right was still a scaffold. So you built your own
version of the same alert:

- a second `admin_new_issue_report` entry in `EVENTS` and `TOKENS`, with the
  tokens named `issue_category` and `message_preview`;
- the same template key again in migration `042`, with a body that reads
  `{{issue_category}}` and `{{message_preview}}`, written with
  `ON DUPLICATE KEY UPDATE`;
- a new sender, `announceIssueReport()`, that nothing ever calls.

Every one of those looks harmless on your branch. Here is what they do together
on a live database that already ran M10. Migration `042` runs before `045` is
already applied, so `ON DUPLICATE KEY UPDATE` **overwrites** M10's template with
your `{{issue_category}}` / `{{message_preview}}` body. But the code that sends
that alert is M10's, and it supplies `category` and `description_preview`. The
tokens no longer match the copy, so every staff Make It Right email goes out with
a blank category and a blank preview. A feature that was working breaks, and
nothing in your milestone tests it, because on your branch the sender that fills
those tokens did not exist yet. On top of that, `ON DUPLICATE KEY UPDATE` wipes
any wording an Owner had edited, which is the exact thing M10's migrations went
out of their way to avoid.

I resolved it on the merge: dropped your duplicate `EVENTS`/`TOKENS` keys and the
uncalled `announceIssueReport()`, and reduced migration `042` to the one template
that is genuinely new, `admin_manual_payment_proof`, with `INSERT IGNORE`. M10's
alert stands untouched.

**The habit to build.** This is the same root cause the M9 review named: a branch
cut from a `main` that had already moved on. The fix is a discipline, not a
cleverness. Before you start a milestone, `git fetch` and rebase or merge the
current `main`. Before you add a template, a permission, a migration or a
notification event, `grep` the key you are about to introduce. If it already
exists, you are extending someone's work, not starting your own, and the tokens
and copy are theirs to match. A name is a contract with the rest of the codebase.

---

## 2. What was strong

This milestone is, the collision aside, the most disciplined engineering on the
project so far.

- **The money is exact and never leaves integers.** `AdminDashboard` works in
  kobo throughout. Refunds are allocated across immutable order lines by floor
  plus a remainder walk in ascending line id, never a float. Category share is
  allocated to exactly 10,000 basis points by the largest-remainder method. Net
  revenue is confirmed receipts less processed refunds on their real movement
  dates. This is precisely how the house rules ask money to be handled.
- **The RBAC is layered, not a single gate.** The route opens on
  `dashboard.view`, then each figure re-checks its own permission
  (`orders.view`, `payments.view`, `credit.view`) before its query runs, and
  every chart additionally needs `dashboard.analytics.view` plus the relevant
  data permission. A forbidden figure is never queried, so it cannot leak. The
  service itself holds no user and does no permission logic, which keeps the
  boundary in one readable place.
- **The command palette and bell are genuinely accessible and safe.** Forbidden
  destinations are removed on the server by `okv_admin_nav_commands()` before any
  markup is produced. Both surfaces are real dialogs with focus trapping and
  restoration, `aria-live` announcements, 44px controls, and no `innerHTML`
  anywhere: the charts are built as inline SVG through `createElementNS`, with no
  dependency and no CDN. That is the storefront's standard held in the admin.
- **The analytics contract is written down first.** `docs/M11_ANALYTICS_CONTRACT.md`
  fixes every boundary (Lagos midnight, token mapping, empty states, the current
  category-snapshot limitation) before the code. Deciding the meaning of a
  number before computing it is exactly right, and it is why the money code reads
  so cleanly.
- **Progressive enhancement is honest.** Every chart has an exact-value HTML
  table and works with JavaScript off; the enhancer only decorates permitted,
  non-empty, server-rendered data and adds no motion.

---

## 3. What I changed on your branch

All of it is the Section 1 resolution, applied on the merge of `main` into this
branch. Nothing in your dashboard, analytics, palette or bell logic was altered.

- `includes/classes/Notifications.php`: removed the duplicate
  `admin_new_issue_report` keys from `EVENTS` and `TOKENS`, and removed the
  uncalled `announceIssueReport()`. Kept your `admin_manual_payment_proof` entries
  and `announceManualPaymentProof()`, which are new and correctly wired from
  `api/v1/payments.php`.
- `migrations/042_admin_notification_alerts.sql`: removed the
  `admin_new_issue_report` block and switched to `INSERT IGNORE`, so it adds only
  the new payment-proof template and can never clobber M10's template or an
  Owner's edit.
- `includes/bootstrap.php`, `scripts/tests/run.php`: load the M10 and M11 classes
  side by side.
- Rebuilt `assets/css/tailwind.css` and the JS bundles from the merged source.

Verification after the merge: the combined unit suite passes 3,066 of 3,066
assertions, `php -l` is clean on every touched file, all 8 brand checks are green,
and `git diff --check` is clean. The MySQL and HTTP suites need a database and
were green in your delivery runs; they will run in CI against the merged base.

---

## 4. Gaps and improvements for the next milestones

Not blocking, worth your attention.

- **Two ways to find staff recipients now exist.** You refactored
  `staffRecipients()` to take an optional permission; M10 had added
  `staffRecipientsForPermission()` for the same purpose. They have slightly
  different matching rules (yours treats Owner and a module wildcard as a match,
  M10's is an exact permission match). Both work for their own callers, but the
  next person will not know which to reach for. Pick one, fold the other into it,
  and delete the loser.
- **A couple of arbitrary text sizes slipped in.** The bell badge uses
  `text-[10px]` and the palette shortcut keys use `text-[11px]`. The brand guard
  does not catch these, but `CLAUDE.md` asks for design tokens only. Add the two
  sizes to the scale in `tailwind.config.js` and reference them, or use the
  nearest existing token.
- **The category-snapshot boundary is real and you documented it honestly.**
  Historical order lines borrow the product's current category, so a category
  move rewrites past share. That is the right call for now, but when M12 or later
  touches the order-item schema, snapshot the category slug on the line so the
  history stops moving under you.
- **The dashboard fires several independent queries per load.** Each is bounded
  and indexed, so this is fine at today's volume. If the dashboard ever grows
  more cards, consider whether the per-figure reads can share a pass.

---

## 5. Close

The engineering inside this milestone is excellent: the money arithmetic, the
layered permissions, the accessible palette and the write-it-down-first contract
are all at the level I want to see. The one real problem was not in the code you
wrote but in where you started from, and it is the same lesson as M9: a branch
cut from a stale `main` will quietly rebuild what has landed since, and names are
how those rebuilds collide. Fetch first, grep the name before you claim it, and
this class of defect disappears.

Merged with the integration fixes above.

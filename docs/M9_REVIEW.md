# Milestone 9, senior review

**Branch reviewed:** `M9-notifications_contact` (pull request 43). Two commits,
40 files, 1,969 lines added.
**Reviewed against:** `docs/PRD.md` Sections 4.1, 15 and 18, `CLAUDE.md`, and the
M9 checklist.
**Date:** 9 September 2026.
**Finished on:** the same branch, which starts by merging `main` rather than
replacing your work, so the history and the authorship stay yours.

This is written for you, directly. Section 1 is the one that matters.

---

## 1. The one thing to take from this

**Your migration was numbered `032`. So is `032_credit_notifications.sql`, which
merged with M8 five days before you branched.**

The M9 checklist gave you a range, in writing, as item 41:

> Take migration numbers from the M9 reserved range, 040 to 044, so the parallel
> M7, M8 and M12 branches do not collide on 022.

Here is why it reads as harmless and is not. On a fresh database both files
apply, because `scripts/migrate.php` sorts by filename and `032_contact_messages`
happens to sort before `032_credit_notifications`. Green. On a database that has
already run M8, `032_credit_notifications` is in `schema_migrations` and yours is
not, so yours applies too. Also green. **Every path you could have tested looks
fine.** What breaks is the contract: the number is how anyone reading the folder
knows what ran in what order, and the moment two files share one, that ordering
is a coincidence rather than a fact. The next person to write `032` has no way to
know which one they are after.

You could not have caught this by running your branch. You could have caught it
by running `ls migrations/` before choosing a number, which takes two seconds,
and the checklist told you which number to choose.

**The wider habit.** Your branch was cut from `c3e5518`, before M8 merged. You
built M9 on a `main` that no longer existed, for a day, without fetching. The
collision is one symptom. The stale `SettingsEditor` sample tokens and the stale
`build-js.mjs` source list were two more, and all three landed in my lap as merge
conflicts. Fetch `main` before you branch, and again before you open the pull
request. It costs nothing and it is the difference between your reviewer reading
your work and your reviewer resolving your conflicts.

---

## 2. What was genuinely good

I want to be specific, because most of this branch is better than the milestone
needed.

- **`ContactMessages` is careful where carelessness costs.** `SELECT ... FOR
  UPDATE` before every status change, an `expected_status` the caller has to
  match so a stale tab cannot silently overwrite a colleague, and an audit row
  written inside the same transaction as the change. Nobody asked for optimistic
  concurrency on a contact message. You did it anyway, and it is right.
- **The admin workspace is real software.** Filters that survive into the detail
  and pagination URLs, an empty state that distinguishes "no messages yet" from
  "nothing matched those filters", reply links that say plainly *"The website
  does not send or record a reply"*, and a handling history projected from
  `audit_logs` rather than a second table to keep in step. That last decision is
  the kind a lot of engineers get wrong by adding a table.
- **Escaping and gating are not an afterthought.** `okv_e()` on message text,
  `textContent` in the JavaScript, no `innerHTML` anywhere, `messages.view` and
  `messages.handle` as genuinely separate gates, and no delete path at all on an
  append-only record. Your own HTTP test proves the stored `<script>` renders as
  text.
- **You wrote the tests the checklist asked for, and they run.** After M8, that
  is worth saying out loud. `contact_admin_db_test` and `contact_admin_http_test`
  needed only the token column stripped out of two fixtures to go green.
- **You fixed something you were not asked to fix.** A refund Paystack settles
  immediately was never announced, because only the webhook called
  `announceRefund`. You found it, fixed it across four files, and covered it. It
  is out of M9 scope and it is staying in, on the owner's call. Say so in the
  pull request body next time, though; a reviewer who finds `Refunds.php` in an
  M9 diff has to work out whether it is a fix or an accident.

---

## 3. What was missing, and how to notice next time

Five items on a 43-item checklist were not done. None was hard. All five are
findable by reading your own diff against the list.

| Item | What it asked for | What shipped |
|---|---|---|
| 8 | Add Contact to `$OKV_FOOTER_NAV` | `nav.php` untouched. The page existed with nothing linking to it |
| 21 | A count of new messages where staff will actually see it | Nothing, anywhere |
| 24, 26 | **Two** templates: a staff alert and an acknowledgement to the sender | One. A customer who wrote in heard nothing back |
| 41 | Migrations from 040 to 044 | `032`, colliding |
| 43 | Add `contact.php` and the contact API to `scripts/verify.sh` | `verify.sh` untouched |

The technique that catches all five: **before you push, open the checklist and
your diff side by side, and put a number against every file you touched.** Item 8
names a file. Item 43 names a file. Neither file is in your diff. That is a
30-second check and it is the whole gap.

Item 24 is the one I would sit with. It says "two templates, because neither
exists", and item 26 spells out why: *"so the message does not vanish into
silence"*. Your migration seeds one and your `announceContactMessage()` sends
one. A person writes to us about a Saturday delivery, and nothing comes back. The
checklist told you that was the failure to avoid, in those words.

---

## 4. The two defects, and what they teach

**The rate limit punished the customer, not the flood.**

```php
if (!RateLimiter::hit($ipBucket, 3, 15 * 60)) {
    return self::failure('rate_limited', ...);
}
// ... validation happens after this
```

`hit()` increments. So every *refused* attempt spent an allowance. Mistype your
email address three times on the one public support channel on the platform, and
you are locked out for fifteen minutes with no way to tell us. It now checks with
`isLocked()` first and spends the allowance only after a row is committed, which
still caps a flood at three stored messages per window.

Worth knowing: my own first test of this failed for the same reason in reverse. I
made three valid submissions from one IP and then asserted that a fourth, invalid
one returned `contact_required`; it returned `rate_limited`, correctly. **The
test was wrong and the code was right that time.** When a test goes red, the
first question is which of the two is lying. Fixing the code to make a test pass
is how a real rule gets deleted.

**The session token could refuse a real customer.**

`okv_contact_form()` calls `newSubmissionToken()`, and once the widget moved into
the shop footer, that runs on *every page view*. Twenty tokens are kept; the
twenty-first evicts the oldest. So a visitor who browses twenty-one pages and
then sends a message from the tab they opened first is told the form is no longer
valid. Tokens also expire after two hours, so a page left open over lunch does
the same.

The token is gone, on the owner's decision. CSRF, a honeypot and an IP rate limit
are what items 29 to 31 asked for, and they are enough. The lesson is not "no
extra defences". It is that **moving a component changes how often its code
runs**, and a per-render session write is a very different thing in one page than
in every page. When you move something into shared chrome, re-read it asking what
now happens 200 times a session.

---

## 5. Two smaller things

**The browser was doing your error handling.** The form kept `required` on name
and message and `type="email"` on the email field, with no `novalidate`. Item 12
asked for *"server-side validation in our own words, one error path, no native
browser bubbles"*. Your server-side validation is good and a customer never
reached it, because Chrome refused the submission first, in Chrome's words, in
Chrome's tooltip, which no screen reader announces the way your `role="alert"`
region does. One attribute.

**You deleted M12's seat.** Item 23 says M9 builds the Messages half of
`admin/content.php` and *"leaves the page-copy half untouched"*. You replaced the
whole placeholder, retitled the screen "Messages", and page copy had nowhere to
live and no note saying who owned it. It is now two tabs, and the Page copy tab
says in plain words that M12 builds it. When a checklist tells you a file is
shared, the shared part needs to still be there when you are finished.

---

## 6. Something that was not your fault

`scripts/tests/visual_pass.mjs` could not sign in. The session cookie is marked
`Secure`; over plain http, Playwright's API request context will not send it,
while the browser itself does, because `127.0.0.1` counts as a trustworthy
origin. So `page.request.post()` posted a valid CSRF token with no session behind
it and got a 419.

I mention it because of *how* I established it was not yours: I checked `main`
out into a worktree, ran the same script against it, and got the identical
failure before changing a line. **When something is red and you did not touch it,
prove that on the base branch before you spend an hour on it, and prove it before
you claim it in your notes.** It took four minutes.

The pass now signs in through the page's own fetch, which is what a customer's
browser does anyway, and it has grown 26 checks for your widget: the 56px target
sitting 15px clear of the mobile tab bar, the gold ring at `rgb(201, 146, 43)`,
the focus trap in both directions, Escape returning focus to the trigger, and no
animation under `prefers-reduced-motion`. Every one of those is behaviour you
built correctly and nothing was proving.

---

## 7. Where it landed

`bash scripts/tests/run_all.sh`: **37 suites passed, 0 failed**, on MySQL 8.0.46,
which is what production runs. 2,603 unit assertions, 25 database suites, 10 HTTP
suites, the browser pass at 140/140, and the brand guard. The one suite
`run_all.sh` skips for want of a stand-in gateway was then run against it and
passed 24/24, so nothing in the tree is unrun. Migrations 000 to 040 apply to an
empty database and the second run reports nothing to apply. Both emails were
captured on a local SMTP sink and read.

---

## 8. If you take three things

1. **Read the checklist against your diff before you push.** Five of the six gaps
   were items that named a file you never opened.
2. **Fetch `main` before you branch and before you push.** The migration
   collision, the two stale conflicts and a day of building on a base that no
   longer existed all came from one skipped `git fetch`.
3. **Ask what a rule costs the honest user.** The rate limit and the session
   token were both written to stop an attacker, and both would have turned away
   a customer first. When you add a guard, walk a real person into it and see
   what happens to them.

The engineering underneath this milestone is good. `ContactMessages` and the
admin workspace are the work of someone who thinks about concurrency and about
the person reading the screen. What is missing is the last hour: the pass where
you check the list, check the base, and check that the thing you built does not
bite the customer it was built for.

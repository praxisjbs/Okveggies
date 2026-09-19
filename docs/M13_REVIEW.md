# Milestone 13, senior review

**Pull request:** 53, `arena/01a0af39-okveggies` into `main`
**Reviewed:** 19 September 2026
**Scope as delivered:** 74 files, +3,097 / -14. Two documents, everything else under `scripts/tests/`.
**Reviewer changes:** 6 fixes on top, in the same branch.

---

## 1. Verdict

Merged. This is the strongest milestone this engineer has delivered, and it is
the right shape for the job: M13 is not a feature, it is the argument that the
rest of the work is releasable, and the argument is built out of runnable files
rather than sentences in a progress log.

Two things in particular are above the level I expect from a junior. The first
is `docs/M13_SUITE_MATRIX.md`, which maps 20 release requirements to the files
that prove them and then says, in writing, that rows 2 to 20 have never been
executed. Writing down that your own evidence does not exist yet is the harder
half of engineering honesty. The second is the decision to enumerate suites by
glob in `release_gate.sh` instead of by hand, with the reason stated in a
comment: "A hand-maintained list is how two suites sat in no runner for three
milestones." That is a fix aimed at the cause rather than the symptom.

The defects below are real and two of them are serious, but none of them are
the kind that come from not caring. They all come from the same root: a guard
or a check was written for the case the author was picturing, and not tested
against the case that actually bites.

---

## 2. What is genuinely strong

- **`role_leak_matrix_http_test.php`.** Five one-permission roles, each driven
  over a real session against all five admin screens, asserting both the status
  code and that the body carries no other module's records, including inside
  inline `<script>` tags and JSON refusals. The allow-list for the one marker
  that legitimately crosses screens (an order number on the money screens) is
  documented with its reason. Prepared statements throughout, randomised row
  identities, teardown in a `finally`.
- **`db_reset.php`.** It does not just migrate. It proves the count applied
  matches the count of files, that a second run applies zero, that
  `schema_migrations` holds exactly one row per file, and that 14 named tables
  exist afterwards. That turns "the second run reported nothing to apply" from
  a claim into a check that can fail a build.
- **`smtp_delivery_http_test.php`** asserts that its sink and both sites are
  actually listening before it tests anything. That is the correct pattern, and
  it is the one the other two new suites were missing (fixed below).
- **Coverage discipline.** All 61 database and HTTP suites carry the guard. Not
  58 of them, not "the important ones". The completeness is real.

---

## 3. The six defects I fixed

**1. The scratch guard failed open (high).** `scratch_guard.php` is the file
standing between 61 fixture-writing suites and a live shop. It only refused when
it could positively identify `APP_ENV` as production, so every case where it
could not tell was a case where it let the run through. Proven against the real
file, all of these ran:

| `.env` | Old guard |
| --- | --- |
| `APP_ENV=production # live server` | allowed |
| no `APP_ENV` line at all | allowed |
| no `.env` file | allowed |
| `APP_ENV=staging`, `DB_NAME=okveggies` | allowed |

The trailing-comment case is the sharp one, because the application's own loader
(`includes/config/env.php`) keeps everything after the first `=`, so the value
really is the string `production # live server` and `=== 'production'` misses it.
The guard now fails closed, strips comments and quotes, and also applies the
`DB_NAME` must end in `_test` rule that the gate already applied. All six unsafe
shapes now refuse; `ci.env.example` and a deliberate `OKV_ALLOW_DB_RESET=yes`
still run.

**2. The gate had the same blind spot (high).** `release_gate.sh` read `.env`
with `cut -d= -f2-`, so `APP_ENV=production # live` did not trigger its
production refusal either. Both guards now read `.env` through one shared
parser, the new `scripts/tests/lib/env_value.php`, so they cannot disagree about
what the file says. That is the point of having two guards.

**3. The gate never checked it was talking to MySQL 8 (high).** Section 1 is
titled "Fresh MySQL 8 migration from zero" and nothing proved the server was
MySQL 8. `CLAUDE.md` is explicit that MariaDB accepts `ADD COLUMN IF NOT EXISTS`
and MySQL 8 rejects it, so a chain that migrates cleanly on MariaDB can still
fail the production deploy on a syntax error. A gate that certifies a release
has to prove the thing it names. It now refuses a non-8 server, with
`OKV_ALLOW_ANY_DB_VERSION=yes` as a deliberate override.

**4. The gate linted every JavaScript file and no PHP (medium).** PHP is the
language this release ships. Added a `php -l` sweep beside the existing
`node --check` one: 311 files, clean.

**5. The orphan check could not see the orphans that matter (medium).**
`fixture_orphans.php` found leftover orders with
`FROM orders o JOIN users u ON u.id = o.user_id`. But `orders`, `payments`,
`kitchen_run_requests` and `issue_reports` all carry `ON DELETE SET NULL` on
`user_id`. When a suite deletes its fixture user in a `finally` block, the order
is not deleted with it, it survives with `user_id` NULL, and an inner join to
`users` matches nothing. The check reported "ORPHANS OK" in precisely the case
it exists to catch. Now left joins, plus the `ZZ` fixture number prefix, so a
detached row is still found; `payment_transactions` added as a ninth check.

**6. Two suites carried on against a dead port (medium).**
`role_leak_matrix_http_test.php` and `product_uploads_http_test.php` waited for
their spawned server and then continued whether or not it ever answered, so a
port already in use reads as "87 assertions failed" rather than "the server did
not start". Both now fail fast with the reason and the server log path. In
`role_leak_matrix` the server is also released first in the `finally`, so a
throwing `DELETE` cannot leave `php -S` holding port 8232 and wedge the rerun.

---

## 4. The gap the Owner waived

The release gate has never been executed, anywhere. The engineer raised this
herself, clearly and early, with three options and a recommendation. The Owner's
answer was to skip it: no Docker, no CI setup for this project. That decision is
recorded here so it is not re-litigated, and so the position stays honest:
**everything in M13 is built and reviewed, and rows 2 to 20 of the suite matrix
are still unproven by execution.** The M13 checkboxes in `PROGRESS.md` stay
unticked for that reason. Raising the blocker was the correct call and is not
held against the milestone.

---

## 5. What to carry into the next milestone

1. **A guard decides what to do when it cannot tell.** That is the whole design
   of a guard, and it is the question to answer first. `seed_visual_fixture.php`,
   `db_reset.php` and `fixture_orphans.php` all default `APP_ENV` to production;
   the new shared guard was the one place that did not. When you write a safety
   check, write the "I could not determine it" branch before the happy one.
2. **Test the guard, not only the feature.** Six lines of shell proved four ways
   past the old guard in under a minute. Anything whose job is to refuse needs a
   list of things it must refuse, and you should run that list.
3. **When a check joins tables, ask what the join hides.** The orphan bug was
   not a typo, it was an inner join quietly deciding that a row with no user is
   not a row. Read the foreign key's `ON DELETE` before you trust a join in a
   cleanup check.
4. **Name the thing you assert.** "Fresh MySQL 8 migration" in a heading and no
   version check underneath is the same class of gap as a suite in no runner.
5. **Copying a pattern forward is good; copy the best one.** You wrote the
   correct wait-for-port pattern in `smtp_delivery_http_test.php` and the weaker
   one in two other files the same week. When you improve a pattern, grep for
   its siblings.
6. **Two blanket deletes to look at when the gate first runs.** Both
   `role_leak_matrix_http_test.php` and `smtp_delivery_http_test.php` clear
   whole rate-limit buckets (`login:%`, `contact:%`) in teardown rather than
   their own rows, and `fixture_orphans.php` then checks that same table is
   empty. The check passes because the suites empty it, not because they cleaned
   up after themselves. Left as is for now, because changing cleanup scope is
   not a change to make blind; scope it when you can run the suites.

---

## 6. Verification I ran

Everything below was executed on this branch, with the changes in place:

- Unit suite: **3,374 / 3,374** assertions.
- Brand gate: **8 / 8** checks.
- `php -l`: **311 / 311** shipped files.
- `node --check`: all 5 `.mjs` files. `bash -n`: gate, runner, `verify.sh`, brand check.
- Gate preflight driven against 6 unsafe `.env` shapes, confirmed refusing, with
  the messages naming the remedy. Hardened guard driven against the same 6 plus
  the two that must still run.
- `git diff --check` clean. No em dash anywhere in the diff.

Not run, and not claimable: the database suites, the HTTP suites, the browser
pass and `verify.sh`. They need MySQL 8, Chromium and a running site, and this
environment has none of them. Section 4 covers why that stands.

---

## 7. Bottom line

The work is sound and the thinking behind it is better than the code in two or
three places, which is the right way round at this stage. Six fixes, five of
them in guards and checks rather than in the suites themselves, which says the
tests are good and the things watching the tests needed sharpening. Merged.

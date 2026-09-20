# Milestone 13, review and release evidence

Two parts, one milestone. Part II is the current state, written by PR7 on the
frozen SHA. Part I is the 19 September senior review of pull request 53, whose
text is kept word for word below (inter-section rules dropped, two bracketed
"Update, 20 September" notes added) because later claims reference it.

---

# Part II. PR7 release gate, final proof pass

**Frozen SHA:** `720625b47f5e10ace8289cbaff674bb1168630ce` (tip of `main` when
the PR7 branch was cut, 20 September 2026 15:17 UTC). Every number in this part
names that SHA. `main` moved three times while this pack was being built: to
`02645aa` (PR 63, PR1 audit follow-ups), `d8e3c65` (PR 64, catalogue graceful
degradation), and finally `766c0df` (PR #65, "Restore production deploys" plus
the Owner's letterhead decision). The first two changed nothing this pack
certifies. The third closed this part's blockers B1 and B2 as written, so a
post-PR audit round re-ran the whole battery on `766c0df` merged into this
branch, and on a second merge that brought `72f59b8` (PR #67, homepage hero)
sixteen minutes later; raw output is evidence file `15-audit-round-766c0df-72f59b8.log` and every
"now" statement below reflects it. Where a sentence describes the frozen SHA as
red, that remains true of `720625b47f`; it is no longer true of `main`.
**Date of run:** 20 September 2026; audit round the same day, after 16:19 UTC.
**Machine and honesty note:** the gate ran on a Linux box with no PHP, no MySQL
and no Chromium. PHP 8.3.33 (production's pinned minor) was installed from the
npm registry as `@php-wasm/node` behind a `php` shim; the ten extensions the
gate preflight demands were all present, `php -l` was the real parser, and every
suite in "executed" rows below really executed. No MySQL 8 server could be
obtained: apt mirrors, dev.mysql.com, cdn.mysql.com, conda, PyPI binaries and
Docker are unreachable from this sandbox, and the npm registry carries no Linux
MySQL 8 server (verified by registry search; `mysql-memory-server` downloads
from the blocked mirrors at runtime). Playwright's browser CDN is likewise
unreachable, and with no database no page of the app can boot, so the browser
pass could not run even had Chromium been available. Sections that did not run
are recorded as NOT RUN. Raw output: `docs/evidence/720625b47f/`.

## 1. Verdict

**At the frozen SHA the release gate was red on every candidate and production's
deploy path was broken. The audit round closed both code blockers upstream, but
the gate still has not completed anywhere, so PR7 still does not certify a
release.**

Three findings, in the order that mattered at freeze time, with their state
after the audit round:

**B1. Live deploys have been failing since 2026-09-20 09:33 UTC, and the cause
is a violated migration law.** The merge of PR 57 (PR1) edited
`migrations/003_reference_seed.sql`, a migration already applied on production
long ago, to seed Monday directly. The Migrator compares file checksums against
`schema_migrations` and refuses drift without an explicit force; `deploy.yml`
calls `public/migrate.php` without force. The live database therefore never
received 051, 052, 053 or 054: production runs 2026-09-20 code on a schema that
stops at 050. Nine consecutive failed deploy runs (`35502653512` on `7847cc9`
through `35520141104` on `d8e3c65`), each dying at
"Apply migrations on the server". M13 contract Section 11.2 lists "a failed or
partially applied migration" as a rollback-or-halt trigger. It is ACTIVE on
live, today. Full forensic chain, checksums and the three weighed remedies
(recommended: a corrective PR that restores 003's shipped bytes and lets 051,
which already carries the decision idempotently, do its job): evidence file
`11-migration-drift-forensics.log`.
**RESOLVED by PR #65 (`8ae07c5`, merged to `main` as `766c0df`, 16:19 UTC).**
The remedy merged is exactly the recommended R1: 003 is byte-for-byte back at
its applied state (re-hashed against the `f527ff4` era in the audit round,
evidence `15`, section 0), no new migration file, 051 carries the Monday
decision idempotently. Deploy run `35522368135` on `766c0df` is green through
every step, including "Apply migrations on the server", so production received
051 to 054 and the nine-run failure chain is closed at 16:19:51 UTC.

**B2. The unit gate fails on `main`, both frozen and current.** `DocumentTest`
asserts the printed documents carry the single-ink mono-green lockup (Brand
bible 3.7a, the brand PR3 delivery note, and the component's own comment). PR 62
(`720625b47f`) changed line 148 of `includes/components/documents/document.php`
from `lockup-mono-green.svg` back to the colour `lockup.svg` without touching
the assertion, so CI is red at `720625b47f`, at `02645aa` and at `d8e3c65`
(3,421/3,422, one failure). This is a one-line product fix. PR7 is evidence,
not features: it was NOT applied here, and until a candidate passes
`php scripts/tests/run.php` no SHA can be frozen green. Evidence `06` and
`06b`.
**RESOLVED by the Owner's call, not by the one-line fix proposed here.**
`48d669b` (inside PR #65) pins the full-colour lockup as the letterhead mark:
`DocumentTest` now asserts `lockup.svg`, and `brand-check.sh`'s note records the
"logo harmony" decision. That is the opposite direction from this review's
suggestion, and it is the Owner's to take; the brand bible comment in
`brand-check.sh` was amended in the same PR. Two stale doc-comments still named
the mono-green mark (`includes/components/documents/document.php:22`,
`public/documents/invoice.php:16`); the PR66 branch corrected those two comment
blocks, text only, no behaviour. Unit at the merged tree: 3,426/3,426 green at `766c0df`, 3,456/3,456 after
the second merge to `72f59b8` (evidence `15`, sections 4 and 13). CI on main is
green at both.

**B3. The prompt's premise "PR1 to PR6 plus PR8 PR9 have merged" is not the
repo's state.** Merged to `main`: PR0 54, PR2 55, PR3 56, PR1 57, PR4 59, PR6
60, PR8 61, plus 62 to 64 (not PR4 audit-complete or PR0-checklist-complete,
see the ledger in `docs/REMAINING_27_FIXES_8PR_PLAN.md`). NOT merged: **PR5
(infrastructure: fixes 8 to 12, 24, 25) and PR9 (fix 31)**. Consequences,
each checked in the tree rather than assumed:
`includes/bootstrap.php` has no maintenance state (fix 11 absent: no
503 `Retry-After` path exists to rehearse); GitHub `environments` is
`total_count: 0` (fix 9: a push to `main` is still sufficient authority);
`ci.yml` runs lint, build, brand and unit only (fix 24 not landed); no cron
evidence is possible while deploys fail (fix 12); PR7's live `verify.sh`
re-proof is unreachable until B1 is gone (fix 25). PR7's own acceptance items
therefore have a hard dependency the plan's merge order already states and the
repo has not honoured: "PR7 last, after all".
**Audit round, unchanged in substance:** PR #65 touched migrations, brand tests
and delivery code, not the gaps above. At `766c0df` there is still no
maintenance state in `includes/bootstrap.php`, `ci.yml` still runs the four
static jobs only, and `total_count: 0` environments. Two of the consequences
loosened: the deploy pipeline now reaches its own "Verify the deployed site"
step and passes it (run `35522368135`), which is the fix 25 live evidence
line, and fix 26's "deploy tested" precondition is met (the `72f59b8` deploy
re-ran `verify.sh` green the same afternoon). Fix 12's cron proof and
fix 11's rehearsal stay blocked behind PR5, and fix 24's CI-as-gate remains
unmerged, so the MySQL 8 gate sections have no host anywhere, not even CI.

## 2. Gate results, section by section, on 720625b47f

`release_gate.sh` is the gate, not `run_all.sh`. The full command could not
complete on this box (case 8 of evidence `01`: every static preflight passes,
then it refuses at "the database in .env is not reachable" and runs nothing).
The individually-executed truth:

| Gate section | Command / glob | Status at frozen SHA | Result |
|---|---|---|---|
| Preflight, 7 unsafe `.env` shapes | `bash scripts/tests/release_gate.sh` | EXECUTED | refuses every one, exit 2, remedy named; override tested scoped (never unlocks production). Evidence `01` |
| 1. Fresh MySQL 8 migration from zero, twice; `schema_migrations` count vs files; second run 0 pending | `php scripts/tests/db_reset.php` | NOT RUN | requires a MySQL 8 server. Attempt recorded verbatim (mysqlnd 2006), evidence `10`. This section is also what B1 proves production has NOT had since 09:33 UTC |
| 2a. Unit suite | `php scripts/tests/run.php` | EXECUTED, RED | CI-equivalent 3,421/3,422; after the `ci.env.example` fix both configurations show the same single failure (B2). The plan's 3,374 floor is met by size, failed by assertion |
| 2b. New unit floor (PR7 glue) | parse `N / M assertions passed` | EXECUTED | `04-gate-glue-selftest.log`: shrunken, emptied and crashed unit runs are caught; suite-count shrinkage can no longer pass silently |
| 2c. PHP syntax | gate's exact `find`, `php -l` | EXECUTED, GREEN | 321 of 321 shipped PHP files (the 311 in older docs is stale; 10 files were added by PR1 to PR8). Evidence `02` |
| 2d. JavaScript syntax | gate's `node --check` set | EXECUTED, GREEN | 39 files, 0 failed. Evidence `03` |
| 2e. Shell syntax (PR7 glue; contract Section 6 always required it) | `bash -n` over the gate's `find` | EXECUTED, GREEN | 9 files, 0 failed, `release_gate.sh` itself included |
| 3. Every `*_db_test.php` by glob | 36 files on disk | NOT RUN | each refuses without a `_test` database; with the valid gate shape they die at the absent server (evidence `10`). None passed, none skipped by hand; the gate simply does not reach them |
| 4. Every `*_http_test.php` by glob | 26 files on disk | NOT RUN | same. The suites spawn their own `php -S`, which the WASM runtime cannot bind; the gate's site service cannot start either |
| 5. Fixture cleanup (before) | `php scripts/tests/fixture_orphans.php` | NOT RUN | attempted, connection failure recorded, not a leak finding (evidence `10`) |
| 6. Brand guard | `bash scripts/brand-check.sh` | EXECUTED, GREEN | 8/8 (evidence `05`) |
| 7. Browser pass 390/1440: `seed_visual_fixture`, `visual_pass.mjs`, `homepage_visual_test.mjs`, `axe_suite.mjs`, `content_admin_visual_test.mjs`, `public_content_visual_fixture`, `role_journeys.mjs` | `node scripts/tests/*` | NOT RUN | no app server without MySQL; browser CDN unreachable. The three static browser-adjacent guards that need no server DID run green: motion coverage 52/52, lesser text 126/126, image contract 98/98 (evidence `09`) |
| 8. `verify.sh` against the local base | `bash scripts/verify.sh $BASE` | NOT RUN | needs the running site. Against LIVE `okveggies.com.ng`: unroutable from this box (000), and the deploy-side `verify` step has never been reached for any of the nine failed deploys since 09:33 UTC (evidence `12`) |
| 9. Teardown proof, orphans after | `OKV_FIXTURE_TEARDOWN=1` + orphans again | NOT RUN | as 5 |
| Suite-level guard, 8 `.env` shapes | `php scripts/tests/auth_db_test.php` with `OKV_ENV_PATH` | EXECUTED | all 7 unsafe shapes refuse (exit 2, shared `env_value.php` parser: comments, quotes, absent keys, staging-on-live-name); safe shape passes the guard then fails at the DB; override stays scoped (evidence `07`) |
| Fix 27 contrast | `run_all.sh` vs gate on the same box | EXECUTED | `run_all.sh` truthfully prints "4 suites passed, 1 failed, 5 skipped"; the gate refuses to start with the same missing services. A skipped suite can never be reported as a pass through `release_gate.sh` (evidence `09`) |

Executed and green on the frozen SHA: gate refusal matrix, guard matrix, PHP
syntax 321, JS 39, shell 9, brand 8/8, static guards 52 + 126 + 98.
Executed and red: the unit suite (one assertion, B2). NOT RUN and unclaimable:
fresh-migration twice, 36 DB suites, 26 HTTP suites, both fixture-orphan sweeps,
all seven browser passes, `verify.sh` local and live. "0 failed, 0 skipped" has
not been achieved for this SHA, so it is not claimed.

### 2b. Audit round: the same battery on the merged tree

The table above stays valid for `720625b47f`. PR #65 having closed B1 and B2,
the battery was re-run with `766c0df` merged into this branch, then again after
merging `72f59b8` (PR #67); raw output is evidence `15`. Result on the final
tree: unit suite 3,456/3,456 GREEN (the floor line reads 3,456 over 3,374; the
`766c0df` pass showed 3,426/3,426); `php -l` 321/321; shell syntax `bash -n`
over both edited runners clean; brand guard green under the Owner's full-colour
call; the three static node guards 52 + 128 + 116 (lesser text grew by two in
PR #65, image contract by eighteen hero assertions in PR #67); the wiring check
earned its keep immediately, flagging `homepage_hero_visual_test.mjs` as PR #67
landed it unwired; it was then wired into section 7 and the count returned to
zero; guard matrix, all 8 `.env` shapes, refuse-or-admit exactly as designed
(5 refusals at exit 2, 3 admissions); gate preflight refuses a production `.env`
by name ("APP_ENV=production ... DB_NAME is 'okveggies' ... Nothing was run and
nothing was written", exit 2) and an absent `.env` likewise; the unit floor's
empty-output case still refuses; and the fresh-MySQL probe still finds no
`mysqld` on this box, so sections 1, 3, 4, 5, 7, 8 and 9 remain NOT RUN, here
and everywhere else, unchanged by PR #65.

## 3. Performance and accessibility, before/after, on this SHA

Performance (contract Section 8, budgets FCP 3.0s, LCP 4.0s, CLS 0.1, initial
transfer 2MB, mid-range Android, 4x CPU, 1.6Mbps/150ms, cold):

| Column | Number | Source |
|---|---|---|
| Before (M12, no hero photo, 24 unoptimised JPEGs, no dimensions) | FCP 3,780ms, LCP/CLS unrecorded | `docs/PR6_PERF_TRACE.md`, known breach |
| Fix landed in code | WebP 400/800/1200 siblings, explicit width/height, lazy below fold, hero `fetchpriority` when published | PR6 on `main`, measured statically by `image_contract_test.mjs` 98/98 at the frozen SHA |
| After (throttled FCP/LCP/CLS/bytes on a live stack) | NOT MEASURED on this SHA | `homepage_visual_test.mjs` needs the served app and Chromium; the PR6 note on `main` records the same gap ("the throttled after column has not been printed") |
| Motion jank at 4x throttle (adjacent budget) | median 16.7ms, p95 37.6ms, CLS 0.0000, recorded by PR8 on `42ea449` at 390 and 1440, three green runs | plan ledger PR8 row; PR8 is merged, so the profile carries onto this SHA's `okv-motion.js`; it is not a substitute for the homepage perf run |

Accessibility: `axe_suite.mjs` exists and is wired into the gate; on this SHA
it is NOT RUN (no app server). PR8's motion suite reported axe 0 critical on
its own routes at `42ea449`; PR3's checkout visual reported axe 0 critical and
0 serious at its audit. Those are prior-SHA evidence, useful, not inheritable:
the contract requires the scan on every candidate. NVDA and VoiceOver checklist
sessions (contract 7.2): no recordings exist in `docs/`; `docs/NVDA_CHECKLIST.md`
and `docs/VOICEOVER_CHECKLIST.md` are the scripts awaiting an operator.

## 4. Fix-by-fix disposition (PR7's scope: 4, 5, 26, 27)

**Fix 5, full gate, 0 failed 0 skipped.** Built and partly executed: the gate
exists, runs everything by glob, treats skips as failures and refuses to start
on any input it cannot prove safe (20 rows of proof in this part and evidence
`01`/`07`/`09`). The PASS line belongs to a MySQL 8 host: see the recipe in
Section 8. Until B1 and B2 land, the honest expectation for that run is a red
unit section, so fix 5 closes only after both blockers are fixed.
**Audit round:** both blockers are fixed upstream, so the unit-section excuse is
gone; what keeps fix 5 open is the absent host, and it moved closer anyway.
`release_gate.sh` now also runs the three static node guards itself instead of
trusting `run_all.sh`, wires `checkout_visual_test.mjs` and
`motion_visual_test.mjs` into section 7, and carries a wiring check that fails
the gate if any `*_test.mjs` on disk belongs to no section (self-tested: 0
unaccounted on disk, fires on a deliberately orphaned file, evidence `15`
section 6). `run_all.sh`'s hand-maintained database and HTTP lists are gone;
both loop over the same globs the gate uses, refund's gateway-conditional
handled inside the loop logic. That is the "hand-maintained list" class fixed in
both runners at once. The wiring check's first real catch came within the hour:
PR #67 landed `homepage_hero_visual_test.mjs` unwired, the check flagged it, it
was wired into section 7 (evidence `15`, section 12). Sections 1, 3, 4, 5, 7, 8, 9 remain the open half, and
until one full green run exists on some host, fix 5 stays OPEN.

**Fix 27, skip tolerance removed.** Proven executed, two ways. Behaviourally:
`run_all.sh` on this box says "5 skipped", the gate on the same box says "REFUSING
TO START ... Nothing was run and nothing was written", so a release reported from
`release_gate.sh` cannot describe a partial run (evidence `09`). Structurally:
the gate has no skip path at all (`grep -in skip` returns prose and the fixed
"0 skipped" summary lines, evidence `01`), every section counts its globs so an
empty section fails, the unit floor cannot pass on 0 assertions, and both gate
and suites read `.env` through the shared `scripts/tests/lib/env_value.php`, the
one place the two guards could previously disagree. PR7 added the two gate checks
the acceptance text demanded and the file lacked: the 3,374-assertion unit floor
and the contract's shell-syntax sweep (evidence `04`), and marked
`run_all.sh`'s header as "THIS IS NOT THE RELEASE GATE".

**Fix 4, credential rotation from the 3 Sep demo.** Code side, proven on the
frozen SHA: no shipped SQL seeds a user row (0 `INSERT INTO users` in
`migrations/`), no password literal in shipped seeds, no demo admin emails in
tracked config or `.env.example`, `public/setup.php` is 404 without
`SETUP_TOKEN`, and all four rotation paths (`api/v1/users.php:168`,
`api/v1/auth.php:306/385/465`) stamp `password_changed_at = NOW()`, which
`Rbac` compares per request so an old session dies the moment the password
changes. The live half is the Owner's and is NOT attested yet: the five-step
checklist (V1 query, V2 old-password refusal, V3 active-account census, V4
setup endpoint 404, V5 the signed attestation wording) is in evidence `13`.
M13_REVIEW's sign-off table below carries that open line; PROGRESS boxes do not
move without it. **Audit round:** unchanged; the live half needs production
phpMyAdmin/SSH access this sandbox has no substitute for, and an attestation
cannot be manufactured by re-running the code-side proofs.

**Fix 26, repository privacy.** Audit half of contract Section 15, executed and
recorded (evidence `12`): repo public, 3 collaborators with permissions listed,
`total_count: 0` environments, secrets and branch protection UNVERIFIED with
this token (not proven absent), nine failed deploys showing Actions itself
reaches the host. The flip is correctly REFUSED: Section 15 requires an approved
RC and a tested deploy first, and both are false while B1 and B2 are open.
Owner steps after the blockers: name the reviewer(s), then flip in repo settings
(and only there), then re-run `deploy.yml` once against the private repo and
archive the run id alongside this file. That retest line is Section 9, row 7.
**Audit round:** the deploy-tested half of Section 15's precondition is now
met, and green on the exact current tip (run `35522368135` on `766c0df`);
`deploy.yml` reads its SFTP credentials from Actions secrets, so a flip is
behaviour-neutral for it provided secrets survive, which the retest proves.
What is still not met: no RC is approved (this document's rows 1 and 6 to 9 are
open), and no written Owner approval for the flip arrived with the audit
request. Standing rule from the audit prompt: without that approval in writing,
leave the repository PUBLIC and mark the gap. Gap marked; flip not performed;
everything the flip needs is now one settings click plus one re-run.

## 5. Backup and restore, evidence state

Contract Section 10 assigns the client the backup, the off-host copy and the
timed drill; engineering writes the runbook (now in `docs/DEPLOYMENT.md`,
sections 7 and 8) and witnesses. Status at this SHA: **runbook EXISTS, drill
NOT YET RUN**. What will count as the evidence when the owner's operator runs
it, and where each artefact lands (this pack, appended as file `14` once
real): cPanel "Download a MySQL Database Backup" plus a full `uploads/` copy,
both checksummed with `sha256sum` on arrival at the off-host destination; the
timed restore into the throwaway `okveggies_restore_test` database; the
read-back proofs (three named orders and one kitchen run with money totals, one
private issue photo read through `public/issue_photo.php` with an authorised
session, `schema_migrations` at the deployed highest number); elapsed minutes
against the deploy-window budget; the retention and deletion note for the
restored copy. Nothing in that list exists yet and no box in `PROGRESS.md`
may be ticked for it. The plan's PR7 acceptance text ("cPanel DB + uploads
manual before live deploy, off-host copy with checksums + runbook + timed
restore drill reading representative records and private photo read-back") is
therefore OPEN, with the procedure and the form prepared down to the SQL.

## 6. Rollback and maintenance state, evidence state

Contract Section 11. Rollback artefact policy: before the first gated deploy,
tar the previous verified release (the exact `_dist` the last SUCCESSFUL deploy
uploaded, i.e. from run `f527ff4` or its successor) plus the matching cPanel DB
dump, store off-host with a checksum in `docs/evidence/<sha>/rollback-artifact.txt`
style. The rehearsal ON LIVE with a maintenance window requires the
app-level toggle (`site_settings.maintenance_enabled`, anonymous 503 +
`Retry-After`, staff and `public/healthcheck.php` unaffected, fix 11) and
that code is NOT on `main`: PR5 has not merged (Section 1, B3). A rehearsal
against live today would mean an `.htaccess` edit, which the deploy re-uploads
and silently reverts; that is exactly the failure the contract pre-judged when
it rejected the server-file switch. Position: rehearsal BLOCKED behind PR5 and
B1; triggers documented verbatim in `docs/DEPLOYMENT.md` section 9; rollback
itself stays forward-only for the schema, corrective migration or restore
decision, never improvised SQL (contract 11.3).

## 7. Live smoke after an approved deploy (the post-B1 checklist)

Same file, section 10: HTTP 200 on `/`, `/shop`, `/combos`, `/product.php?slug=...`,
`/account.php` login redirect, `contact.php` 200 with POST-only API 303;
`verify.sh` complete against `https://okveggies.com.ng` including 403/404 on
`.env`, `includes/`, `migrations/`, `docs/`, `vendor/` (the fix 25 re-proof;
this sandbox cannot route to the host and the nine failed deploys never reached
the verify step); one low-value live Paystack charge, reconciled both ways
(Paystack dashboard row and `payments` + `payment_transactions` state, then a
refund or completion, ledger trail), recorded with the reference and no secret
keys; SPF, DKIM and DMARC DNS rows plus delivery into two independent provider
inboxes with the message headers and `notification_deliveries` row attached;
the cron line proven by a fresh success timestamp and a `CRON OK` manual call.
Every line is UNRUN today, and the earliest honest attempt is the first deploy
after B1, because nine consecutive deploys have not reached the end of their
own pipeline.
**Audit round:** B1 is gone, and the first post-B1 deploy (run `35522368135`)
already carried its own green "Verify the deployed site" step: `verify.sh`
executed on the production host against `https://okveggies.com.ng`, protected
paths included, and passed. That closes the deploy-internal half of the fix 25
live re-proof. This sandbox still cannot route to the host directly, so the
operator-side lines above (manual 200 spot-checks, reconciled Paystack charge,
two-inbox mail proof, cron timestamp) remain UNRUN and are the remaining
content of this checklist.

## 8. The recipe that turns rows 1 to 20 green (any MySQL 8 host, ~2 hours)

```
git checkout 720625b47f   # or the post-B1/B2 fix; re-freeze and re-run after any change
php -v                    # must be 8.3.x
mysql -e "CREATE DATABASE okveggies_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
           CREATE USER 'okv'@'127.0.0.1' IDENTIFIED BY 'okv_test_pw';
           GRANT ALL ON okveggies_test.* TO 'okv'@'127.0.0.1'"
cp scripts/tests/ci.env.example .env      # edit DB_* to match the server
npm install && npx playwright install chromium
bash scripts/tests/release_gate.sh 2>&1 | tee docs/evidence/<sha>/gate-run.log
```

The gate then prints section 1's `RELEASE MIGRATE OK` (fresh chain, second run
zero pending, `schema_migrations` 47 rows matching the 47 files at this SHA;
the recommended B1 remedy removes the drift without adding a file, so 47
unless the release owner instead ships a corrective 055, then 48), 62 suite
lines from the globs, `ORPHANS OK` twice, the browser pass at 390 and 1440,
and one of exactly two endings:
`RELEASE GATE PASSED with 0 failed and 0 skipped` or a refusal naming what is
missing. Paste the whole output into the evidence pack under the NEW frozen SHA
and re-name this document's Part II headers to it (a change after a freeze
creates a new candidate, contract Section 3). Expected red until B2 is fixed:
section 2a's floor line reads "unit (run.php) ... FAIL". **Audit round:** B2 is
fixed, so with this recipe's B2 clause deleted, the first run of this recipe on
a MySQL 8 host that includes `766c0df` should print the floor line green
(3,426 over 3,374) and everything that remains red is genuinely a service the
host is missing, not a known regression.

## 9. Sign-off (blank on purpose; no box ticks below it)

| # | Line | Owner | Status |
|---|---|---|---|
| 1 | Gate green, 0 failed 0 skipped, named SHA | engineering | BLOCKED on one thing only now: a MySQL 8 host run. B1/B2 closed upstream |
| 2 | B1 corrective PR (003 restored to shipped bytes, 051 carries the decision), deploy reaches `MIGRATE OK`, nine-run failure chain broken | release owner + operator | CLOSED by PR #65: `766c0df`, deploy run `35522368135` green through "Apply migrations" and "Verify" (audit evidence `15`) |
| 3 | B2 letterhead one-liner back to `lockup-mono-green.svg` | engineering | CLOSED differently: the Owner pinned the full-colour lockup (`48d669b`), tests followed; unit 3,426/3,426 green on `766c0df` |
| 4 | PR5 landed (maintenance, production environment, CI-as-gate, cron live proof, protected-path live re-proof) | owner + engineering | OPEN (never merged) |
| 5 | PR9 landed or consciously descoped (fix 31 is in the "everything ships" list) | owner | OPEN (never merged) |
| 6 | Backup + timed restore drill with record read-backs, off-host checksums | client-side operator, engineering witnesses | OPEN |
| 7 | Rollback artefact stored off-host and rehearsed with a maintenance window (needs line 4) | named operator | OPEN |
| 8 | Live smoke: 200s, one reconciled live Paystack charge, SPF/DKIM/DMARC into 2 inboxes, cron timestamp | operator | OPEN and now attemptable (line 2 closed; deploy-internal `verify.sh` already green) |
| 9 | Fix 4 attestation, V1 to V5 signed | Kumbish Emmanuel Putleh | OPEN |
| 10 | Repository flipped private, deploy retested private | Owner approval, then operator | OPEN, Section 15 order. Deploy-tested precondition met at `766c0df` (run `35522368135`); the Owner's WRITTEN approval is the only thing still missing, so the repo stays public and the gap is marked |
| 11 | Technical release owner and production operator NAMED (contract 5.2/19) | owner | OPEN, blocks lines 2, 7, 8, 10 |

No M13 `PROGRESS.md` box is ticked by this document, and none may be until
lines 1 to 11 carry their named evidence. Lines 2 and 3 now do, closed by
PR #65; that is not enough, because line 1 still fails and the discipline for
this milestone is that no box moves while the gate has a red or unexecuted
section. The plan's Section 7 ledger PR7 row says the same, in shorter words.

---

# Part I. Senior review of pull request 53 (19 September 2026)

# Milestone 13, senior review

**Pull request:** 53, `arena/01a0af39-okveggies` into `main`
**Reviewed:** 19 September 2026
**Scope as delivered:** 74 files, +3,097 / -14. Two documents, everything else under `scripts/tests/`.
**Reviewer changes:** 6 fixes on top, in the same branch.

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

## 4. The gap the Owner waived

The release gate has never been executed, anywhere. The engineer raised this
herself, clearly and early, with three options and a recommendation. The Owner's
answer was to skip it: no Docker, no CI setup for this project. That decision is
recorded here so it is not re-litigated, and so the position stays honest:
**everything in M13 is built and reviewed, and rows 2 to 20 of the suite matrix
are still unproven by execution.** The M13 checkboxes in `PROGRESS.md` stay
unticked for that reason. Raising the blocker was the correct call and is not
held against the milestone.

*Update, 20 September (Part II): the waiver has been partially retired. The
static sections have now been executed on a frozen SHA against a real PHP
8.3.33, the refusal and guard matrices have been executed end to end, and the
gate itself has been driven against every unsafe input it names. What remains
waived is the MySQL 8, HTTP and browser half, and Part II found why that half
matters most: the live deploy has been failing at the migration step since
PR 57, which is exactly what section 1 of the gate exists to catch.*

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

*Update, 20 September (Part II): the counts above are the PR 53-era ones; the
frozen-SHA numbers are 3,422 assertions, 321 PHP files and the full refusal and
guard matrices, in Part II Section 2.*

## 7. Bottom line

The work is sound and the thinking behind it is better than the code in two or
three places, which is the right way round at this stage. Six fixes, five of
them in guards and checks rather than in the suites themselves, which says the
tests are good and the things watching the tests needed sharpening. Merged.

*Bottom line as of Part II: the watching layer kept working exactly when it
mattered. It is what caught the edited shipped migration (B1) and it is what
made a red letterhead assertion impossible to merge silently into this review.
Both findings are open and both are named. That is what an evidence-only PR
looks like when the evidence says no.*

# PR7 release-gate evidence pack, frozen SHA 720625b47f

Every file here names one SHA: `720625b47f5e10ace8289cbaff674bb1168630ce`, the
tip of `main` when this branch was cut, 20 September 2026. One file is the
exception by design: `15-audit-round-766c0df.log` re-runs the whole battery on
origin/main `766c0df` after PR #65 landed, because that merge closed the two
red blockers (B1, B2) that the earlier files record as open. Where the two
trees disagree, the audit-round file wins. Nothing in this pack
is a substitute for a gate run that did not happen: sections that need MySQL 8,
Chromium or the live host are recorded as REFUSED or NOT RUN, with the refusal
output, and the recipe to run them for real lives in `docs/M13_REVIEW.md`.

The machine: no PHP, no MySQL, apt mirrors and the Playwright CDN unreachable.
PHP 8.3.33 (the version production runs, `composer.json` pin 8.3) was brought in
from the npm registry as `@php-wasm/node@3.1.54` behind a `php` shim, with all
ten extensions the gate preflight demands present (`curl pdo_mysql mbstring gd
xml zip fileinfo openssl`, plus tokenizer, session, json, dom). Every result
below came out of that real PHP 8.3.33, not a simulation: `php -l` is the real
parser, `run.php` and the suites are the real scripts, the gate is the real
`bash scripts/tests/release_gate.sh`.

| File | What it is | Result at the frozen SHA |
|---|---|---|
| `00-freeze.txt` | What was frozen, on what machine, with what unavailable | context |
| `01-gate-refusals.log` | `release_gate.sh` against 8 `.env` shapes (fix 5, fix 27) | refuses all 7 unsafe shapes, exit 2; the valid shape stops at the absent database, never mid-suite |
| `02-php-lint.log` | Gate's exact `find` + `php -l` sweep | 321 of 321 shipped PHP files parse clean, 0 failed |
| `03-node-bash-syntax.log` | Gate's `node --check` set plus `bash -n` over tracked shell | 39 JS/MJS files, 0 failed; 11 shell files, 0 failed (the 11 vs the gate's 9 is `git ls-files` counting two committed `vendor/` devcontainer scripts the gate's find excludes) |
| `04-gate-glue-selftest.log` | Self-test of the two gate checks PR7 added | floor catches shrunken, emptied and crashed unit runs; real log passes count-wise; unit still fails the gate on its own exit code |
| `05-brand-check.log` | Gate section 6 verbatim | brand guard 8/8 green |
| `06-unit-suite.log` | Gate section 2 before the ci.env glue fix | CI-equivalent 3,421/3,422, gate-env 3,420/3,422 |
| `06b-unit-suite-after-glue.log` | Same both ways after fixing `ci.env.example` | both reduce to the ONE real failure: the letterhead lockup assertion |
| `07-suite-guard-matrix.log` | `scratch_guard.php` through a hand-run `auth_db_test.php`, 8 shapes | refuses all unsafe shapes exit 2; passes the safe shape; override never unlocks production |
| `08-suite-inventory.log` | The gate's globs, counted on disk | 36 DB suites, 26 HTTP suites, 57 unit files, 11 MJS, 47 migration files, highest 054; both globs wired in `release_gate.sh` lines 301/309 |
| `08b-guard-coverage-note.log` | Guard coverage over fixture writers | 66 files require the shared guard; `db_reset.php` and `seed_visual_fixture.php` carry the identical rules inline; no writer lacks the rule |
| `09-runall-vs-gate.log` | Fix 27 contrast: developer runner vs release gate, same box | `run_all.sh` prints "4 suites passed, 1 failed, 5 skipped" and its static guards green (motion coverage 52/52, lesser text 126/126, image contract 98/98, brand 8/8); the gate refuses to start |
| `10-db-blocked-attempts.log` | Gate sections 1, 5, 7-prelude attempted without a DB server | real refusal text recorded (mysqlnd 2006); nothing here is dressed up as an executed suite |
| `11-migration-drift-forensics.log` | Why every production deploy since 09:33 UTC fails | PR #57 edited shipped migration `003`; the Migrator drift guard blocks `migrate.php`; nine failed deploy runs listed; three remedies weighed, R1 recommended |
| `12-github-state-snapshot.log` | Fix 26 audit, environment check, live-host probe | repo public; 3 collaborators; 0 environments (PR5 not landed); secrets and branch protection UNVERIFIED with this token; live host unroutable from this box |
| `13-fix4-credential-hygiene.log` | Fix 4 code-side proofs + the Owner's live checklist and attestation wording | 0 seeded user rows, 0 password literals in shipped SQL, no demo emails; all four rotation endpoints stamp `password_changed_at`; live proof is the Owner's to produce and tick |
| `15-audit-round-766c0df.log` | The post-PR audit round, battery re-run on the merged tree | origin/main `766c0df` (PR #65) merged in: migration 003 restored byte-for-byte and the Owner pinned the full-colour lockup, so the unit suite is 3,426/3,426 green and the production deploy is green end to end; the guard matrix, the preflight refusals, the new suite-wiring guard and the still-absent MySQL 8 are all re-proved here |

Reproduce any line: the harness lives outside the repo (PATH shim plus
`/home/user/.gate-tools/phpshim.mjs`); inside the repo the commands are exactly
the ones printed in each log, run from a checkout of the frozen SHA with
`cp scripts/tests/ci.env.example .env`.

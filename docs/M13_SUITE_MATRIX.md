# M13 release suite: requirement to suite

This is the living map between the twenty things the release-candidate suite has
to prove and the files that prove them. It is updated as suites are added, and
the status column says what has actually been executed, not what exists.

Status meanings:

- **built** - the suite exists and is wired into a runner.
- **executed** - it ran, with the result recorded below the table.
- **gated** - it is part of `scripts/tests/release_gate.sh`, which fails on a
  skip, so it cannot be reported as passing when it did not run.

| # | Requirement | Suites | Status |
|---|-------------|--------|--------|
| 1 | Unit suite | `scripts/tests/run.php` (`*Test.php`, 57 files at the frozen SHA) | built, executed in CI, executed on `720625b47f`: 3,421/3,422, RED on one letterhead assertion (M13_REVIEW Part II B2) |
| 2 | MySQL 8 integration | every `*_db_test.php` (36 files) | built, gated; not yet executed on any SHA |
| 3 | Fresh migration from zero, then a second run applying nothing | `scripts/tests/db_reset.php`, with the gate asserting the server is MySQL 8 first | built, gated; not executed here (no MySQL 8 obtainable), and B1 in `docs/M13_REVIEW.md` shows production has not run the chain since 20 Sep 09:33 UTC |
| 4 | HTTP suites | every `*_http_test.php` (26 files), including `manual_operations_http_test` | built, gated; not yet executed on any SHA |
| 5 | PHP and JavaScript syntax of what we ship | `scripts/tests/release_gate.sh` (`php -l` over all shipped PHP, `node --check` over `assets/js` and `scripts/tests/*.mjs`; PR7 added the contract's `bash -n` sweep and the 3,374-assertion unit floor) | executed on `720625b47f`: 321 PHP files clean, 39 JS/MJS files clean, 9 shell files clean, unit floor live |
| 6 | Browser pass at 390px and 1440px | `scripts/tests/visual_pass.mjs`, `homepage_visual_test.mjs`, `axe_suite.mjs`, `content_admin_visual_test.mjs`, `public_content_visual_fixture.php` | built, gated; not executed on the frozen SHA (needs the served app, so needs row 2's database); its static siblings did run green: motion coverage 52/52, lesser text 126/126, image contract 98/98 |
| 7 | Brand consistency | `scripts/brand-check.sh` | built, executed in CI, executed on `720625b47f`: 8/8 |
| 8 | Deployment smoke checks | `scripts/verify.sh` against the local server (which `public_content_router.php` makes possible away from Apache) and against the live base after a deploy | built, gated; the local run needs the site, the live run has not been reached since the deploy failures began (nine runs die before the verify step) |
| 9 | Guest journey | `scripts/tests/role_journeys.mjs` | built, gated, not executed |
| 10 | Household journey | `scripts/tests/role_journeys.mjs` | built, gated, not executed |
| 11 | Business and Pro journey | `scripts/tests/visual_pass.mjs` (six Pro screens), `pro_orders_db_test`, `pro_dashboard_db_test` | built, gated, not executed |
| 12 | Manager journey | `scripts/tests/role_journeys.mjs`, `auth_db_test` | built, gated, not executed |
| 13 | Owner journey | `scripts/tests/role_journeys.mjs` | built, gated, not executed |
| 14 | Restricted custom roles receive no forbidden data, in HTML, JSON or JavaScript | `scripts/tests/role_leak_matrix_http_test.php` (five one-permission roles), `content_admin_http_test` | built, gated, not executed |
| 15 | Paystack stand-in, no live gateway | `scripts/tests/fake/paystack.php` started by the gate, `refund_cancellation_db_test` | built, gated; the stand-in starts with the gate and the suites that need it are unexecuted |
| 16 | SMTP sink captures what is actually sent | `scripts/tests/fake/smtp_sink.php`, `scripts/tests/smtp_delivery_http_test.php` | built, gated, not executed |
| 17 | Upload validation and the no-execution rule | `scripts/tests/product_uploads_http_test.php`, `issue_photos_http_test` | built, gated, not executed |
| 18 | Empty, error and no-JavaScript states | `scripts/tests/role_journeys.mjs` (JavaScript disabled context), existing HTTP suites | built, gated, not executed |
| 19 | No suite silently skipped | `scripts/tests/release_gate.sh` (refuses to start unless everything it needs is present) | built, gated, and EXECUTED as a refusal battery on `720625b47f`: 7 unsafe `.env` shapes and the no-database case all refuse, exit 2, remedies named; `run_all.sh` contrast recorded side by side |
| 20 | Fixture rows do not outlive the run | `scripts/tests/fixture_orphans.php` (after the database and HTTP suites, and again after the browser pass) | built, gated; the two sweeps need the scratch database, so they ran only as a recorded connection refusal on this box |
| 21 | A suite cannot be run against production or a non-scratch database by hand | `scripts/tests/lib/scratch_guard.php` on all 66 fixture-writing files, sharing `lib/env_value.php` with the gate | built, gated, executed on `720625b47f`: all 8 `.env` shapes behave (refuse unsafe, pass safe, override scoped); `db_reset.php` and `seed_visual_fixture.php` carry the same rules inline by design |

Two guards support every row. `scripts/tests/lib/scratch_guard.php` is required
by every fixture-writing file (66 now, 63 when PR 53 merged; the count grows as
suites do, and `db_reset.php` plus `seed_visual_fixture.php` hold the identical
rules inline because they predate the shared file), and the gate repeats the
same check before it starts anything. Both refuse a run when `APP_ENV` is
production or when `DB_NAME` does not end in `_test`, and both fail closed: an
APP_ENV nobody set counts as production. They read `.env` through one shared
parser, `scripts/tests/lib/env_value.php`, so the two can never disagree about
what the file says.

## Execution record

| Date | Command | Result |
|------|---------|--------|
| 2026-09-17 | GitHub Actions `checks` job on `arena/01a0af39-okveggies` (35225551399, 35225941597) | green: PHP lint of every shipped file, CSS/JS build, brand check, unit suite |
| 2026-09-17 | `bash scripts/tests/release_gate.sh` | **not run yet**: the release job cannot be added to CI while the GitHub connection lacks the `workflows` permission, and this machine has no PHP or MySQL |
| 2026-09-19 | Senior review on pull request 53 | unit suite 3,374/3,374, brand gate 8/8, `php -l` 311/311, `bash -n` and `node --check` clean, gate preflight exercised against six unsafe `.env` shapes. The database, HTTP and browser sections still need MySQL 8 and Chromium and remain unexecuted |
| 2026-09-20 | PR7, frozen SHA `720625b47f`, PHP 8.3.33 via `@php-wasm/node` (real parser, all ten required extensions), raw logs in `docs/evidence/720625b47f/` | `php -l` 321/321, `node --check` 39/39, `bash -n` 9/9, brand 8/8, motion coverage 52/52, lesser text 126/126, image contract 98/98: green. Unit 3,421/3,422: RED, one failure, the letterhead lockup (B2). Gate refusal battery and the suite guard battery: green. 36 DB suites, 26 HTTP suites, both orphan sweeps, the migration proof and every browser pass: NOT RUN, this box has no MySQL 8 server and no path to one (see `00-freeze.txt` for what was tried) |

Rows 2, 4, 6 (the served-app half), 8 to 18 and 20 have still never been
executed on any SHA. The earliest honest date for the full green line is the
first completed `release_gate.sh` run on a MySQL 8 host after B1 and B2 from
`docs/M13_REVIEW.md` Part II are fixed; until that run exists, the M13
"Full role smoke suite green" box stays unticked, because a run that did not
happen is not a pass, and a run that ran and went red is not green either.

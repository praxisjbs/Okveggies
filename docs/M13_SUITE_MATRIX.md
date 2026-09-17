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
| 1 | Unit suite | `scripts/tests/run.php` (`*Test.php`, 56 files) | built, executed in CI |
| 2 | MySQL 8 integration | every `*_db_test.php` (35 files) | built, gated |
| 3 | Fresh migration from zero, then a second run applying nothing | `scripts/tests/db_reset.php` | built, gated |
| 4 | HTTP suites | every `*_http_test.php` (26 files), including `manual_operations_http_test` | built, gated |
| 5 | JavaScript syntax of what we ship | `scripts/tests/release_gate.sh` (`node --check` over `assets/js` and `scripts/tests/*.mjs`) | built, gated |
| 6 | Browser pass at 390px and 1440px | `scripts/tests/visual_pass.mjs`, `homepage_visual_test.mjs`, `content_admin_visual_test.mjs`, `public_content_visual_fixture.php` | built, gated |
| 7 | Brand consistency | `scripts/brand-check.sh` | built, executed in CI |
| 8 | Deployment smoke checks | `scripts/verify.sh` against the local server (which `public_content_router.php` makes possible away from Apache) | built, gated |
| 9 | Guest journey | `scripts/tests/role_journeys.mjs` | built, gated |
| 10 | Household journey | `scripts/tests/role_journeys.mjs` | built, gated |
| 11 | Business and Pro journey | `scripts/tests/visual_pass.mjs` (six Pro screens), `pro_orders_db_test`, `pro_dashboard_db_test` | built, gated |
| 12 | Manager journey | `scripts/tests/role_journeys.mjs`, `auth_db_test` | built, gated |
| 13 | Owner journey | `scripts/tests/role_journeys.mjs` | built, gated |
| 14 | Restricted custom roles receive no forbidden data, in HTML, JSON or JavaScript | `scripts/tests/role_leak_matrix_http_test.php` (five one-permission roles), `content_admin_http_test` | built, gated |
| 15 | Paystack stand-in, no live gateway | `scripts/tests/fake/paystack.php` started by the gate, `refund_cancellation_db_test` | built, gated |
| 16 | SMTP sink captures what is actually sent | `scripts/tests/fake/smtp_sink.php`, `scripts/tests/smtp_delivery_http_test.php` | built, gated |
| 17 | Upload validation and the no-execution rule | `scripts/tests/product_uploads_http_test.php`, `issue_photos_http_test` | built, gated |
| 18 | Empty, error and no-JavaScript states | `scripts/tests/role_journeys.mjs` (JavaScript disabled context), existing HTTP suites | built, gated |
| 19 | No suite silently skipped | `scripts/tests/release_gate.sh` (refuses to start unless everything it needs is present) | built, gated |
| 20 | Fixture rows do not outlive the run | `scripts/tests/fixture_orphans.php` (after the database and HTTP suites, and again after the browser pass) | built, gated |

Two guards support every row: `scripts/tests/lib/scratch_guard.php` is required by
all sixty-two fixture-writing suites and refuses to run when `APP_ENV=production`,
and the gate repeats that check before it starts anything.

## Execution record

| Date | Command | Result |
|------|---------|--------|
| 2026-09-17 | GitHub Actions `checks` job on `arena/01a0af39-okveggies` (35225551399, 35225941597) | green: PHP lint of every shipped file, CSS/JS build, brand check, unit suite |
| 2026-09-17 | `bash scripts/tests/release_gate.sh` | **not run yet**: the release job cannot be added to CI while the GitHub connection lacks the `workflows` permission, and this machine has no PHP or MySQL |

Nothing in row 2 to row 20 has been executed. The earliest honest date for a
result is the first green run of the release-gate job, and until that run exists
the M13 "Full role smoke suite green" box stays unticked, because a run that did
not happen is not a pass.

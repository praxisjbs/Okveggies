# Milestone 13 Release Contract

**Status:** Approved for execution, release candidate not yet established  
**Approved:** 17 September 2026  
**Scope:** Milestone 13, Hardening, QA and go-live  
**Working branch:** `M13-hardening-qa`

This document records the Owner's answers to the 22 Milestone 13 release
questions. It defines the evidence required before OK Veggies may be certified
or deployed as the Phase 1 release. It does not authorise production access,
credential changes, deployment, repository visibility changes, commits or
pushes.

## 1. Current baseline

The branch was clean when this contract was approved. Its starting commit is
`f215f9bc744ddd7d30b4880998f9515f0f87ee5b`, which is also the current `main`
commit.

That commit is not a release candidate. It contains M10 and M11 but not the
reviewed M12 implementation. The reviewed M12 work is on
`origin/m12-content-messages` at `c98e010` and includes migrations `049` and
`050`. Its merge base predates newer work on `main`, so it must be integrated
and the combined result verified before a release-candidate SHA is named.

M10 is complete on the current branch, subject to regression verification.
M12 is not complete for release because:

1. its implementation is not integrated into the M13 branch;
2. no approved Track 2 photograph of the real OK Veggies operation is present;
3. Terms, Privacy and Delivery Policy do not yet contain approved launch copy.

M13 must not close while any M10 or M12 acceptance criterion remains open.

## 2. Release outcome

M13 produces one frozen, evidence-backed release candidate that:

- contains all accepted Phase 1 milestones, including M10 and M12;
- passes the complete automated and manual release matrix;
- is proved on production-like staging before production promotion;
- has approved documentary photography and approved legal content;
- has tested backup, restore, migration and rollback procedures;
- has verified Paystack, email, scheduled work and monitoring;
- deploys only through an auditable manual production approval gate;
- enters a defined post-launch observation period; and
- leaves the repository private after deployment access and collaborator impact
  have been checked and the Owner has approved the change.

No local green suite, successful file upload or successful migration alone is
sufficient release evidence.

## 3. Release candidate contract

The release candidate must be created from `M13-hardening-qa` after:

1. integrating the reviewed `origin/m12-content-messages` work;
2. incorporating all newer accepted changes from `main`;
3. resolving conflicts without dropping either milestone's behaviour;
4. applying the complete migration chain to a fresh MySQL 8 database twice;
5. completing the dependency and carry-forward decisions in this contract;
6. passing the complete CI and staging gates; and
7. freezing one exact commit SHA for approval.

All test reports, screenshots, browser results, staging migration evidence and
deployment approvals must name the same frozen SHA. A change after the freeze
creates a new candidate and invalidates approval evidence affected by that
change.

## 4. Milestone dependency gates

| Dependency | Required release state |
| --- | --- |
| M0 to M9 | Previously accepted behaviour remains green in the complete regression suite |
| M10 | All Make It Right, privacy, money, upload, notification and role tests pass on the frozen candidate |
| M11 | Analytics, layered permissions, navigation commands and responsive accessibility regressions pass |
| M12 software | Content service, admin editor, public pages, FAQ, routes, metadata, footer and sitemap are integrated and pass |
| M12 photography | A rights-cleared, client-approved real-operation photograph is published with meaningful alt text and final performance evidence |
| M12 legal content | Client-approved Terms, Privacy and Delivery Policy are entered, attested and published; placeholder copy is never approved or exposed as final advice |
| M2 carry-forward | Each item is reviewed against the PRD; genuine Phase 1 gaps close before the RC freeze and accepted later work is documented explicitly |
| Cancellation policy | The Owner explicitly approves or corrects the after-cutoff pay-in-full and deposit treatment before launch |

## 5. Environment and deployment contract

### 5.1 Staging

A dedicated staging environment is mandatory. It must mirror the production
shape closely enough to prove:

- PHP 8.3;
- MySQL 8;
- Apache or cPanel rewrite and denial rules;
- production-like environment configuration without production secrets in the
  repository;
- the SFTP packaging and upload path;
- the web migration runner;
- the URL cron runner;
- HTTPS, headers, canonical redirects and protected paths; and
- Paystack test-mode and controlled SMTP behaviour.

Staging must not share live customer payment credentials. Any restored customer
data must be access controlled, handled only by authorised people and removed
after the rehearsal.

### 5.2 Production gate

Production deployment must use a protected GitHub `production` environment
with required manual approval. A push to `main` must not be sufficient authority
to upload to production.

The business Owner gives final release approval after a named technical release
owner signs the evidence checklist. The production operator must also be named.
One person may hold more than one role only when the Owner explicitly accepts
that arrangement.

The launch date is selected only after every gate passes. The launch uses a
scheduled 2-hour low-traffic maintenance and observation window.

No production change is authorised by this document alone. Credentials,
deployment and environment changes require the appropriate authorised person at
the relevant task.

## 6. Complete release test gate

The mandatory automated gate contains:

- PHP lint on every shipped PHP file;
- JavaScript syntax checks;
- shell syntax checks;
- production CSS and JavaScript builds;
- the brand guard;
- the complete unit suite;
- fresh MySQL 8 migrations and a second idempotency run;
- every MySQL integration suite;
- every HTTP suite;
- the Owner, Manager and restricted-role matrix;
- guest, household and business storefront coverage;
- browser automation at 390px and 1440px;
- deployment routing, redirect and protected-path checks; and
- `git diff --check`.

CI must provide MySQL 8 and controlled Paystack and SMTP test doubles so the
release gate completes with 0 failed and 0 skipped required suites. A test may
be classified as manual only when this contract explicitly makes it manual.

## 7. Browser and accessibility contract

### 7.1 Browser and device matrix

The accepted matrix covers the current and previous 2 supported versions of:

- Chrome;
- Safari;
- Firefox;
- Edge;
- Android Chrome; and
- iOS Safari.

Representative real devices or reliable simulators may be used. The report must
name the browser, version, operating system, viewport and whether the result is
real-device, emulator or hosted-browser evidence.

### 7.2 WCAG 2.1 AA evidence

Accessibility approval combines:

- an automated axe-style scan of representative public, customer, Pro and admin
  screens;
- keyboard-only operation;
- visible focus and logical focus order;
- zoom and reflow checks;
- reduced-motion behaviour;
- colour and contrast review;
- labels, names, errors, landmarks and heading structure;
- JavaScript-disabled operation where the product promises progressive
  enhancement;
- NVDA coverage on Windows; and
- VoiceOver coverage on macOS or iOS.

Critical manual journeys include browsing and checkout, account access, Kitchen
Runs, Order Trail, Make It Right, contact, content pages and FAQ, Pro ordering,
and the principal admin workspaces.

## 8. Performance contract

The final homepage, including the approved documentary image, must meet all of
these budgets on a mid-range Android profile over throttled 3G:

| Measure | Budget |
| --- | ---: |
| First Contentful Paint | 3.0 seconds or less |
| Largest Contentful Paint | 4.0 seconds or less |
| Cumulative Layout Shift | 0.1 or less |
| Initial transferred resources | 2MB or less |

The result must come from repeatable cold-load runs. Each run and the test
profile must be recorded. A warm repeat cannot erase a failed cold run without
diagnosis and a justified rerun.

The hero image is not lazy loaded. Non-hero imagery must be appropriately sized,
optimised and lazy loaded where it does not harm the experience.

## 9. Production-data migration contract

Before production migration:

1. obtain a current authorised production database and uploads backup;
2. restore the backup into the protected staging environment;
3. record pre-migration row counts and integrity checks for critical tables;
4. run the complete migration chain through the same runner production uses;
5. run it a second time and confirm nothing remains pending;
6. compare post-migration counts and relationships;
7. exercise representative historical customer, order, payment, refund,
   notification, Kitchen Run, issue and content records; and
8. record elapsed time and any maintenance impact.

A fresh empty database remains part of the gate, but it does not replace this
production-shaped rehearsal.

## 10. Backup and restore contract

A named operations owner must:

- capture both the database and customer-upload files before deployment;
- place a protected copy off the production host;
- record checksums, time, source and retention;
- restore both parts into an isolated environment;
- prove that the restored application can read representative records and
  private uploads; and
- record the restore duration and recovery steps.

The deployment must stop when the required backup fails. Continuing after a
backup failure is not permitted for the production release.

## 11. Rollback contract

Rollback or maintenance mode is triggered by any of the following:

- a failed or partially applied migration;
- exposure of `.env`, source, migrations, documentation or other protected
  paths;
- incorrect payment crediting, duplicate charging or material refund faults;
- a material permission or private-data leak;
- detected data corruption;
- failure of a critical post-deploy smoke journey; or
- another defect the Owner and technical release owner judge unsafe for live
  use.

The application rollback procedure restores the previous verified release
artifact. Database migrations remain forward-only. Database repair uses a new
corrective migration or a tested restore decision, never improvised destructive
SQL or an unproved down-migration.

When compatibility or data safety is uncertain, the application enters a clear
maintenance state until the previous artifact or corrective change is verified.
The rollback procedure must be rehearsed on staging before launch.

## 12. External service contract

### 12.1 Paystack

Staging must prove, in test mode:

- pay in full;
- deposit and remaining balance;
- callback and signed webhook handling;
- idempotent duplicate delivery;
- payment made after the customer closes the browser;
- scheduled reconciliation;
- full or partial refund and its final customer-visible state; and
- strict separation of test and live transaction modes.

Only an authorised Owner changes to live credentials. During the launch window,
the authorised team runs one controlled low-value live transaction and verifies
the application ledger and Paystack record before ordinary customer use.

### 12.2 Email and domain authentication

Production approval requires:

- working SMTP with the production sender;
- valid SPF;
- valid DKIM;
- an explicit DMARC policy and reporting destination;
- delivery tests to at least 2 independent mailbox providers; and
- successful activation, order, payment, support and staff-alert messages.

Delivery must be checked in the receiving mailbox, not inferred from a successful
SMTP hand-off.

### 12.3 Scheduled work

The cPanel job must be shown to exist and run every 5 minutes. Evidence must
include a recent successful timestamp, the expected payment-sweep and reminder
summary, and a named recipient for failure alerts. A successful manual call to
`public/cron.php` is necessary but not sufficient.

## 13. Security and discoverability gate

Before production approval:

- `.env`, `includes/`, `migrations/`, `scripts/`, `docs/`, `vendor/` and other
  server-only material return 403 or 404 on the real web server;
- deployment is proved to upload required dotfiles and server controls;
- no secret is printed, copied into evidence or committed;
- unpublished content and previews remain absent from public HTML, metadata,
  footer navigation and the sitemap;
- canonical and legacy redirects remain same-origin and cannot become open
  redirects;
- private uploads require their intended access checks and cannot execute PHP;
- restricted roles receive no forbidden HTML or JSON data; and
- security headers are confirmed on representative responses.

## 14. Monitoring and post-launch observation

The release has active observation for the first 48 hours and daily review for
7 days. Named owners monitor:

- application and PHP errors;
- response availability and performance;
- Paystack payments, webhooks, reconciliation and refunds;
- scheduled-job timestamps and failures;
- SMTP failures and mailbox delivery;
- database health and migration state;
- private and public uploads;
- contact and Make It Right queues; and
- unexpected authentication or permission failures.

The release record must state alert channels, escalation contacts and the time
of each review. Material incidents follow the rollback triggers in Section 11.

## 15. Repository privacy

The repository is made private only after:

1. the RC is integrated and approved;
2. deployment access has been tested;
3. collaborators, Actions, secrets, branch protection and external integrations
   have been audited;
4. the Owner has approved the impact; and
5. the production deployment no longer depends on anonymous repository access.

Changing repository visibility is a separately authorised operational action.
It is required before M13 closes but is not performed during contract writing.

## 16. Approval evidence

M13 approval requires a dated review document containing:

- the frozen branch and commit SHA;
- requirement-to-evidence mapping for every Phase 1 milestone;
- exact commands, suite counts, failures, skips and reruns;
- fresh and production-shaped migration results;
- role and permission results;
- browser, device, accessibility and performance results;
- staging deployment, protected-path and rollback evidence;
- backup and restore evidence;
- Paystack, SMTP and cron evidence;
- approved legal-copy and photography status;
- production approval names and time;
- production smoke results; and
- the 48-hour and 7-day observation outcome.

No checkbox may be marked complete while its required evidence is missing or a
required test is failing.

## 17. Ordered M13 task sequence

Implementation and verification proceed in this order:

1. **Integrate the release baseline.** Bring the reviewed M12 branch and current
   `main` together on `M13-hardening-qa`. Resolve conflicts and establish one
   complete migration list without editing shipped migrations.
2. **Close product dependencies.** Obtain and publish the approved Track 2
   photograph and approved legal copy. Review M2 carry-forward work and record
   the Owner's cancellation-policy decision.
3. **Make CI a complete release gate.** Add MySQL 8, controlled service doubles,
   full HTTP and RBAC coverage, browser checks and deployment-security tests.
4. **Build the staging promotion path.** Create a production-like staging
   environment and separate its credentials and customer data from production.
5. **Harden deployment packaging and approval.** Prove dotfiles and denial rules,
   introduce the protected production environment and stop automatic production
   deployment from an unapproved push.
6. **Build and prove backup and restore.** Add fail-closed pre-deploy backup,
   protected off-host retention and a timed database-plus-uploads restore drill.
7. **Define and rehearse rollback.** Produce a reproducible prior-release
   artifact, maintenance behaviour, trigger checklist and forward-only database
   recovery procedure.
8. **Run fresh and production-shaped migration rehearsals.** Record idempotency,
   data integrity, elapsed time and representative historical-record checks.
9. **Complete functional and role QA.** Run all suites and manual guest,
   household, business, Owner, Manager and restricted-role journeys.
10. **Complete accessibility and browser QA.** Run the agreed automated and
    manual WCAG matrix, screen readers and supported browser/device matrix.
11. **Complete performance QA.** Test the final approved media and critical
    pages against the agreed mobile budgets.
12. **Prove external services.** Complete Paystack test-mode journeys, SMTP and
    domain authentication, and cPanel cron execution evidence.
13. **Freeze the RC.** Name one exact SHA, rerun affected gates from a clean
    environment and assemble the final M13 review.
14. **Obtain launch approval.** The technical release owner signs the evidence;
    the business Owner approves the release and the maintenance window.
15. **Deploy through the manual gate.** Take the verified backup, perform the
    approved upload and migration, run production smoke checks and execute the
    controlled Paystack live transaction.
16. **Observe and close.** Complete the 48-hour watch and 7-day review, resolve
    material incidents, make the repository private with separate authority,
    then update M13 checkboxes only when every requirement is proved.

## 18. Explicit exclusions

M13 does not silently add Phase 2 product features. It does not invent legal
copy, fabricate documentary photography, assume credentials, modify production,
change repository visibility or bypass approval to meet a date. Paystack
settlement API expansion, surfaced disputes, automatic standing orders and PDF
generation remain outside M13 unless a confirmed release defect makes one of
them necessary for a current Phase 1 requirement.

# Milestone 13 Release Contract

**Status:** Approved for execution. Re-baselined and amended 17 September 2026. No M13 task is started by this document.
**Original contract:** commit `a0d22dc`, branch `M13-hardening-qa`, approved 17 September 2026.
**Amendment:** Section 0 records the Owner's answers to the 15 M13 release questions and the three baseline corrections they authorise.
**Scope:** Milestone 13, Hardening, QA and go-live.
**Working branch:** `arena/01a0af39-okveggies`.
**Release candidate:** not yet established.

This document defines the evidence required before OK Veggies may be certified or
deployed as the Phase 1 release. It does not authorise production access,
credential changes, deployment, repository visibility changes, commits or
pushes. Each of those remains a separately authorised action.

---

## 0. Amendment record, 17 September 2026

Fifteen release questions were put to the Owner and answered. Each answer is
recorded here with what it changes.

### 0.1 Baseline corrections

Three statements in the original Section 1 were wrong or stale when it was
written. The Owner authorised correcting them in place (answer 3A).

| Original statement | Correction |
| --- | --- |
| "Its starting commit is `f215f9bc…`, which is also the current `main` commit." | `f215f9b` was the starting commit of `M13-hardening-qa`, but `main` had already moved to `be1cb99` when the contract was written. |
| "The reviewed M12 work is on `origin/m12-content-messages` at `c98e010` and must be integrated." | M12 is already merged into `main` as `be1cb99` (pull request 51, merged 17 September 2026 at 11:35 UTC) and is already deployed to production. It must not be integrated a second time. |
| Section 17 task 1, "Bring the reviewed M12 branch and current `main` together on `M13-hardening-qa`." | Restated as: merge `origin/main` into the working branch, confirm no accepted milestone behaviour is lost, and confirm one complete migration list. |

Two further facts recorded at the same time, because they change what the first
task must protect:

- The M12 branch tip is `4e7f1ec`, not `c98e010`. Commit `c98e010` is an
  intermediate commit, "add faq, homepage and clean navigation", 17 September
  2026 at 08:33 WAT. Merging it would revert later M12 work.
- The senior review of M12 resolved four conflicts on merge. The one that
  mattered was `index.php`: the content-driven hero structure was kept, the seal
  trust-stamp block and the `id="home-heading"` accessibility anchor were folded
  in, and the client-approved hero line from pull request 50 was seeded into
  migration `049` and the public fallback. Any integration that loses those is a
  regression, not a merge.

### 0.2 Recorded answers

| # | Question | Answer | Effect |
| --- | --- | --- | --- |
| 1 | Home branch for M13 | A | `arena/01a0af39-okveggies` is the M13 working branch and the home of this contract. One branch carries the contract, the QA evidence and the release candidate. |
| 2 | Does the recorded contract govern | A | The `a0d22dc` contract governs as written. Anything it does not state is unanswered, not assumed. This amendment resolves the omissions. |
| 3 | May the stale baseline be corrected | A | Sections 1 and 17 task 1 are corrected in place, as above. |
| 4 | How integration lands | A | Merge `origin/main` (`be1cb99`) into the working branch. No rebase. No cherry-picking. The integration point stays visible for the frozen SHA. |
| 5 | Production status | A | Production is live to the public carrying live Paystack keys. Published legal copy is therefore the P0 item ahead of every QA task, and continued trading is an informed client decision. |
| 6 | Maintenance state | A | Build an application-level maintenance state. A server-file switch is rejected because the deploy re-uploads `.htaccess` and `.user.ini` on every push and would silently overwrite it. See Section 11. |
| 7 | Staging | A | An addon domain or subdomain on the same cPanel account: own database, own `.env`, test Paystack keys, same Apache rules. Engineering plans it and supplies the cPanel steps. No access is assumed. See Section 5.1. |
| 8 | CI as the release gate | A | `ci.yml` gains MySQL 8, an SMTP sink and the Paystack stand-in, and the database, HTTP and browser suites become required, with 0 failed and 0 skipped. See Section 6. |
| 9 | Accessibility tooling | A | axe-core through Playwright as a scripted gate over representative templates, plus recorded NVDA and VoiceOver sessions against a written checklist in `docs/`. See Section 7.2. |
| 10 | Performance profile and budget | A | One frozen, recorded profile. Contract budgets adopted. The 3,780ms cold first paint recorded in M12 is a known breach to close before freeze. See Section 8. |
| 11 | Migration rehearsal data | A | An authorised production backup is restored into staging, with recorded consent, named access and deletion after the rehearsal. See Section 9. |
| 12 | Backup and restore ownership | A | The client, who holds the cPanel account, owns the backup, the off-host copy and the timed restore drill. Engineering writes the runbook and witnesses the drill. See Section 10. |
| 13 | Rollback artifact | A | A versioned release artifact is stored off-host before the first gated deploy, so "restore the previous verified release" names something that exists. See Section 11. |
| 14 | Approval and the gate | A | One named technical release owner and one named production operator, with a protected `production` environment carrying required reviewers before the next production deploy. See Section 5.2. |
| 15 | Open pull request 52 | A | Close pull request 52 and re-land only `docs/CLIENT_MEETING_AUDIT_AND_IMPROVEMENTS.md` through a small branch off `main`, with its cron instructions corrected. See Section 12.3. |

### 0.3 Unanswered, and therefore open

Answer 2A makes these open items rather than assumptions:

- The technical release owner and the production operator are not yet named
  (Section 5.2).
- The client-side consent record, contact and retention rule for the
  production-data rehearsal are not yet named (Section 9).
- The client-side backup owner and the off-host destination are not yet named
  (Section 10).

None of these blocks planning. All three block the gates they belong to, and
each is listed again in Section 19.

---

## 1. Corrected baseline

Recorded on 17 September 2026.

| Fact | State |
| --- | --- |
| `main` | `be1cb99`, the merge of pull request 51 (M12 content pages), 17 September 2026 at 11:35 UTC |
| Working branch | `arena/01a0af39-okveggies`, starting commit `f215f9b`, which does not contain M12 |
| M12 branch | `origin/m12-content-messages`, tip `4e7f1ec`. Already merged. Not to be re-integrated |
| Production | `be1cb99` deployed successfully on 17 September 2026 (run 35216476355), including the two explicit dotfile uploads and the on-server verifier |
| Migrations | highest on `main` is `050`. On the working branch `050` is absent and `048` is highest. Unused numbers: `034` to `039`, `043`, `044`. Next free after integration: `051` |
| Repository visibility | public |
| GitHub environments | none configured (`total_count: 0`) |
| Branch protection on `main` | could not be read with the available token, so it is unverified rather than proven absent |
| Maintenance state in the application | does not exist |

The release candidate does not exist yet. `f215f9b` is not a candidate: it
contains M10 and M11 but not M12, and it is therefore not what production runs.
`be1cb99` is not a candidate either: it is deployed and unverified against the
gates in this contract.

M10 is complete, subject to regression verification on the frozen candidate.

M12 is not complete for release. Its software is merged and deployed, but two
client-owned requirements remain open:

1. no approved Track 2 photograph of the real OK Veggies operation is present
   and published, so the public hero shows the branded dependency state;
2. Terms, Privacy and Delivery Policy hold unapproved placeholder drafts and are
   deliberately unpublished, and are therefore absent from the footer and the
   sitemap.

M13 must not close while any M10 or M12 acceptance criterion remains open.

---

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

---

## 3. Release candidate contract

The release candidate is created from `arena/01a0af39-okveggies` after:

1. merging `origin/main` (`be1cb99`) into the working branch (answer 4A);
2. confirming the four M12 merge resolutions survive, in particular the
   `index.php` hero structure, the seal trust stamp, the `id="home-heading"`
   anchor and the seeded client-approved hero line;
3. confirming the migration list is complete in one series, with no shipped
   migration edited and no number reused;
4. applying the complete migration chain to a fresh MySQL 8 database twice;
5. closing the dependency and carry-forward decisions in Section 4;
6. passing the complete CI and staging gates in Sections 6 to 13; and
7. freezing one exact commit SHA for approval.

All test reports, screenshots, browser results, staging migration evidence and
deployment approvals must name the same frozen SHA. A change after the freeze
creates a new candidate and invalidates approval evidence affected by that
change.

---

## 4. Milestone dependency gates

| Dependency | Required release state |
| --- | --- |
| M0 to M9 | Previously accepted behaviour remains green in the complete regression suite |
| M10 | All Make It Right, privacy, money, upload, notification and role tests pass on the frozen candidate, and the production test-data policy for the rehearsal report is decided |
| M11 | Analytics, layered permissions, navigation commands and responsive accessibility regressions pass |
| M12 software | Already merged into `main` (`be1cb99`) and deployed. Re-proved on the frozen candidate, not re-integrated |
| M12 photography | A rights-cleared, client-approved real-operation photograph is published with meaningful alt text and final performance evidence |
| M12 legal content | Client-approved Terms, Privacy and Delivery Policy are entered, attested and published. Placeholder copy is never approved or exposed as final advice |
| M2 carry-forward | Each item is reviewed against the PRD. Genuine Phase 1 gaps close before the RC freeze, and accepted later work is documented explicitly |
| Cancellation policy | The Owner explicitly approves or corrects the after-cutoff pay-in-full and deposit treatment before launch |

Given answer 5A, the M12 legal content gate is promoted to the first priority
among these dependencies. A storefront that takes money in public without
published terms and a published privacy policy is the one risk a customer can
already reach today.

---

## 5. Environment and deployment contract

### 5.1 Staging

A dedicated staging environment is mandatory (answer 7A). It is an addon domain
or subdomain on the same cPanel account, so it reproduces the host's Apache
rules, which is where recent milestones found their worst defects. It must:

- run PHP 8.3 and MySQL 8;
- apply the same Apache or cPanel rewrite and denial rules;
- hold its own `.env`, with test Paystack keys and controlled SMTP, and never
  the production secrets;
- be reached over HTTPS with the same header and canonical-redirect behaviour;
- exercise the same SFTP packaging and upload path;
- exercise the token-guarded web migration runner and the URL cron runner; and
- be excluded from search engines and from the production sitemap.

Staging must not share live customer payment credentials. Any restored customer
data is access controlled, handled only by authorised people, and removed after
the rehearsal (answer 11A).

Engineering plans the environment and supplies the exact cPanel steps. No
staging access, cPanel access or credential is assumed by this document.

### 5.2 Production gate

Production deployment must use a protected GitHub `production` environment with
required reviewer approval (answers 14A and 13A). The repository currently has
no environment configured at all, so "a push to `main` is not sufficient
authority" is not yet true in practice. Creating that gate is a required task,
not an optional improvement, and it precedes the next production deploy.

The business Owner gives final release approval after a named technical release
owner signs the evidence checklist. The production operator is also named. One
person may hold more than one role only when the Owner explicitly accepts that
arrangement.

Three names are required and are not yet supplied: the technical release owner,
the production operator, and the client-side backup owner. Until they are named,
tasks 5, 10, 14 and 15 in Section 17 cannot close.

The launch date is selected only after every gate passes. The launch uses a
scheduled 2-hour low-traffic maintenance and observation window, avoiding
Tuesday and Friday, which are the B2B dispatch days, and Monday, Wednesday,
Thursday and Saturday, which are the household delivery days.

No production change is authorised by this document alone. Credentials,
deployment and environment changes require the appropriate authorised person at
the relevant task.

---

## 6. Complete release test gate

The mandatory automated gate contains (answer 8A):

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
release gate completes with 0 failed and 0 skipped required suites. `ci.yml`
today runs lint, build, the brand guard and the unit suite only, which is why a
green build has meant different things on different machines. That changes as
part of M13, and it is the change that makes every other number in this contract
reproducible.

A test may be classified as manual only when this contract explicitly makes it
manual.

---

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

Accessibility approval combines (answer 9A):

- an automated axe-core scan through Playwright, added as a scripted gate, over
  representative public, customer, Pro and admin screens;
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

The automated scan runs on every candidate. The screen-reader sessions are
recorded in a checklist kept in `docs/` and are named in the approval evidence.

---

## 8. Performance contract

One measurement profile is frozen before testing and recorded with the results
(answer 10A): a mid-range Android class device, CPU and network throttling, cold
cache, repeatable from a clean session.

The final homepage, including the approved documentary image, must meet all of
these budgets on that profile:

| Measure | Budget |
| --- | ---: |
| First Contentful Paint | 3.0 seconds or less |
| Largest Contentful Paint | 4.0 seconds or less |
| Cumulative Layout Shift | 0.1 or less |
| Initial transferred resources | 2MB or less |

The result must come from repeatable cold-load runs. Each run and the test
profile must be recorded. A warm repeat cannot erase a failed cold run without
diagnosis and a justified rerun.

M12 recorded a cold throttled first paint of 3,780ms against a 3.0 second
budget, and that measurement was taken **without** the approved hero photograph.
It is a known open breach, not a rounding matter, and it must be closed before
freeze. The likely contributors are already visible in the tree: the 24
catalogue photographs are JPEG only at 2.1MB in total with no WebP variants,
and most image elements carry no explicit dimensions.

The hero image is not lazy loaded. Non-hero imagery must be appropriately sized,
optimised and lazy loaded where it does not harm the experience.

---

## 9. Production-data migration contract

Before production migration (answer 11A):

1. obtain a current authorised production database and uploads backup, with the
   client's recorded consent;
2. restore the backup into the protected staging environment, accessible only to
   named people;
3. record pre-migration row counts and integrity checks for critical tables;
4. run the complete migration chain through the same runner production uses;
5. run it a second time and confirm nothing remains pending;
6. compare post-migration counts and relationships;
7. exercise representative historical customer, order, payment, refund,
   notification, Kitchen Run, issue and content records;
8. record elapsed time and any maintenance impact; and
9. delete the restored customer data from staging at the end of the rehearsal and
   record that it was deleted.

A fresh empty database remains part of the gate, but it does not replace this
production-shaped rehearsal.

---

## 10. Backup and restore contract

The client owns the cPanel account and therefore owns the backup and restore
procedure (answer 12A). Engineering writes the runbook and witnesses the drill.
A named operations owner must:

- capture both the database and customer-upload files before deployment;
- place a protected copy off the production host, in a destination the client
  controls;
- record checksums, time, source and retention;
- restore both parts into an isolated environment;
- prove that the restored application can read representative records and
  private uploads; and
- record the restore duration and recovery steps.

The deployment must stop when the required backup fails. Continuing after a
backup failure is not permitted for the production release.

Two facts govern the shape of this task. The host has no shell, so
`scripts/backup.sh` and `scripts/deploy.sh` cannot run there as written: they
need `mysqldump` and a PHP CLI. The host also never deletes remote files, so the
required evidence is a cPanel-based backup plus a timed restore that was opened
and read, not a script that exited zero.

---

## 11. Rollback contract

### 11.1 Maintenance state

The application gains a maintenance state (answer 6A). It is an Owner-editable
setting that returns `503` with `Retry-After` to anonymous storefront visitors,
while staff, admin, the token-guarded health check and the order data stay
reachable so the team can work and the release can be diagnosed.

A server-file switch is rejected. The deploy re-uploads `.htaccess` and
`.user.ini` on every push, so a maintenance rule kept in a dotfile would be
silently overwritten by the next deploy, which is the exact moment it is needed.

### 11.2 Triggers

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

### 11.3 Procedure

The application rollback restores the previous verified release artifact.
Database migrations remain forward-only. Database repair uses a new corrective
migration or a tested restore decision, never improvised destructive SQL and
never an unproved down-migration.

A versioned artifact must exist before the first gated deploy (answer 13A): the
previous verified production tree plus a database dump, stored off-host.
Reconstructing a tree from a git SHA under pressure is slower and more
error-prone than uploading a stored archive.

When compatibility or data safety is uncertain, the application enters the
maintenance state until the previous artifact or the corrective change is
verified. The rollback procedure must be rehearsed on staging before launch.

---

## 12. External service contract

### 12.1 Paystack

Staging must prove, in test mode (answer 7A):

- pay in full;
- deposit and remaining balance;
- callback and signed webhook handling;
- idempotent duplicate delivery;
- payment made after the customer closes the browser;
- scheduled reconciliation;
- full or partial refund and its final customer-visible state; and
- strict separation of test and live transaction modes.

Production already carries live credentials. Only an authorised Owner changes
them. During the launch window, the authorised team runs one controlled
low-value live transaction and verifies the application ledger and the Paystack
record before ordinary customer use.

`PAYSTACK_BASE_URL` stays unset in production, so a live key can never be
pointed at a stand-in gateway.

### 12.2 Email and domain authentication

Production approval requires:

- working SMTP with the production sender;
- valid SPF;
- valid DKIM;
- an explicit DMARC policy and reporting destination;
- delivery tests to at least 2 independent mailbox providers; and
- successful activation, order, payment, support and staff-alert messages.

Delivery must be checked in the receiving mailbox, not inferred from a
successful SMTP hand-off. A failed send must still be recorded in
`notification_deliveries` for retry.

### 12.3 Scheduled work

The cPanel job must be shown to exist and run every 5 minutes. Evidence must
include a recent successful timestamp, the expected payment-sweep and reminder
summary, and a named recipient for failure alerts. A successful manual call to
`public/cron.php` is necessary but not sufficient.

The working form on this host, which has no shell, is a cron entry that calls the
token-guarded URL:

```
*/5 * * * * curl -fsS -H "X-Migrate-Token: YOUR_TOKEN" https://okveggies.com.ng/public/cron.php > /dev/null
```

Two corrections apply to instructions circulating elsewhere (answer 15A). The
audit document in pull request 52 proposes
`php /home/ibbbnlso/public_html/scripts/cron.php --job=payment_sweep` and a
second `--job=daily` entry. That cannot work: the host has no shell, and
`scripts/cron.php` is CLI-only and takes a numeric limit, not a `--job` flag.
The single 5-minute URL job above runs both the payment sweep and the due
reminders, which is why `scripts/cron.php` and `public/cron.php` share one
`Cron::run()`. Scheduling both forms would mean two processes asking Paystack
the same question.

Until this job exists on the host, a customer who pays and closes the tab is
never reconciled and no payment reminder is ever sent.

---

## 13. Security and discoverability gate

Before production approval (answer 8A):

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

The protected-path denials are already evidenced once: the deploy of `be1cb99`
ran `scripts/verify.sh` against the live site and passed, which those checks
gate. That evidence is for the previous release, so it is re-run for the frozen
candidate rather than inherited. `public/setup.php` and the `SETUP_TOKEN` line
in the server `.env` must also be confirmed removed now that an Owner exists.

---

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

The existing tools cover most of this without new vendors: the
token-guarded `public/healthcheck.php`, the cron endpoint's non-zero exit on
failure, the failed rows in `notification_deliveries`, and the M11 dashboard
figures. The release record must state alert channels, escalation contacts and
the time of each review. Material incidents follow the rollback triggers in
Section 11.

---

## 15. Repository privacy

The repository is public as at 17 September 2026 and remains so until the Owner
authorises a change.

It is made private only after:

1. the RC is integrated and approved;
2. deployment access has been tested;
3. collaborators, Actions, secrets, branch protection and external integrations
   have been audited;
4. the Owner has approved the impact; and
5. the production deployment no longer depends on anonymous repository access.

Changing repository visibility is a separately authorised operational action.
It is required before M13 closes but is not performed during contract writing.

---

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

---

## 17. Ordered M13 task sequence

Task 1 is the only task with no unresolved dependency other than the Owner's
go-ahead. Every task below is unstarted. Tasks marked **gate** cannot close
without a name or an environment that does not exist yet.

| # | Task | Depends on | Owner | Required evidence |
| --- | --- | --- | --- | --- |
| 1 | **Integrate the release baseline.** Merge `origin/main` (`be1cb99`) into `arena/01a0af39-okveggies`. Confirm the four M12 merge resolutions survive. Confirm one complete migration series, highest `050`, next free `051`, no shipped migration edited, no number reused | Owner go-ahead | Engineering | Merge commit SHA, migration list, `php -l`, brand guard, unit suite, `git diff --check`, and a written check of the hero, seal, anchor and seeded copy |
| 2 | **Close product dependencies.** Publish the approved Track 2 photograph with alt text. Enter, attest and publish approved Terms, Privacy and Delivery Policy. Review M2 carry-forward. Record the cancellation-policy decision. Decide the M10 production test-data policy | Client supplies content | Client (content), Owner (decisions) | Published pages, published photograph, attestation record, written carry-forward and cancellation decisions. **P0: legal copy precedes every QA task** |
| 3 | **Make CI the complete release gate.** MySQL 8 service, SMTP sink, Paystack stand-in, database, HTTP, browser and deployment-security suites, 0 failed, 0 skipped | 1 | Engineering | Green CI run on the frozen SHA with counts |
| 4 | **Build the staging promotion path.** Addon domain or subdomain, own database and `.env`, test keys, same Apache rules, excluded from indexing | 1 | Engineering plans, Owner provisions | Staging URL, environment and protected-path evidence, recorded configuration without secrets |
| 5 | **Harden deployment packaging and approval, gate.** Protected `production` environment with required reviewers, so a push to `main` is no longer sufficient authority. Confirm dotfile upload and denial rules | 3, 4, named operator | Owner creates the environment; named technical release owner approves | Environment screenshot or API evidence, denied-path results, named approver and operator |
| 6 | **Backup and restore, gate.** cPanel-based database and uploads backup, off-host copy, runbook, timed restore drill | 4, named backup owner | Client owns, engineering witnesses | Checksums, off-host record, restore duration, representative records and private uploads read back |
| 7 | **Maintenance state and rollback.** Build the Owner-editable maintenance setting. Store the versioned prior-release artifact. Rehearse rollback on staging | 6 | Engineering | Maintenance behaviour on each route class, artifact location and checksum, rehearsal log |
| 8 | **Migration rehearsals.** Fresh empty database twice, then the production-shaped restore from Section 9 | 4, 6 | Engineering | Idempotency result, pre and post row counts, representative historical records, elapsed time, deletion record |
| 9 | **Functional and role QA.** Every suite plus manual guest, household, business, Owner, Manager and restricted-role journeys | 2, 3, 8 | Engineering | Suite counts, manual journey records |
| 10 | **Accessibility and browser QA.** axe-core gate plus NVDA and VoiceOver sessions across the matrix and the critical journeys | 9 | Engineering | Scan results, screen-reader checklist, browser and device matrix named as real, emulator or hosted |
| 11 | **Performance QA.** Frozen profile, cold-load runs against Section 8 budgets, including the closing of the 3,780ms breach | 2 (hero image), 9 | Engineering | Per-run measurements, profile definition, before and after numbers |
| 12 | **External services.** Paystack test-mode journeys, one controlled live transaction at launch, SMTP with SPF, DKIM and DMARC, cron evidence on the host | 4, 6 | Engineering, authorised Owner for credentials | Test-mode journey log, DNS records, inbox placement at 2 providers, cron timestamp and summary |
| 13 | **Freeze the RC.** Name one SHA, rerun affected gates from a clean environment, assemble the M13 review | 2 to 12 | Engineering | Frozen SHA and the assembled evidence document |
| 14 | **Obtain launch approval, gate.** Technical release owner signs the evidence, business Owner approves release and window | 13, named owners | Named technical release owner and Owner | Signed checklist, approval record with time |
| 15 | **Deploy through the manual gate.** Verified backup, approved upload and migration, production smoke, controlled live transaction | 14 | Named production operator | Backup record, deploy run, smoke results, live transaction reconciliation |
| 16 | **Observe and close.** 48-hour watch, 7-day review, incident resolution, repository privacy with separate authority, M13 checkboxes updated only when proved | 15 | Owner and engineering | Observation log, incident notes, visibility change record, updated `PROGRESS.md` |

### 17.1 Sequence rules

- Task 2 runs in parallel with tasks 1 and 3 onwards. It gates tasks 9, 11 and
  13, not the engineering tasks, so the photograph and legal copy are never the
  reason the hardening stops.
- Tasks 5 and 14 are the two gates that require a person who has not yet been
  named. Nothing else in the sequence waits on them.
- No task may tick an M13 box in `PROGRESS.md`. Boxes are updated in task 16,
  against evidence, and not before.

---

## 18. Explicit exclusions

M13 does not silently add Phase 2 product features. It does not invent legal
copy, fabricate documentary photography, assume credentials, modify production,
change repository visibility or bypass approval to meet a date.

Paystack settlement API expansion, surfaced disputes, automatic standing orders
and PDF generation remain outside M13 unless a confirmed release defect makes
one of them necessary for a current Phase 1 requirement.

---

## 19. Open, and not to be ticked

| Item | Where it lives | Closes when |
| --- | --- | --- |
| M12 documentary photograph unpublished | `PROGRESS.md` M12 box 1 | The client supplies a rights-cleared image and staff publish it |
| M12 Terms, Privacy and Delivery Policy unpublished | `PROGRESS.md` M12 box 2 | The client approves the copy, staff enter and attest it, and it is published |
| M2 carry-forward items | `PROGRESS.md` M2 | Each is reviewed against the PRD and either closed or explicitly deferred as Phase 2 |
| Cancellation asymmetry after cutoff | Audit document, owner question | The Owner approves or corrects the treatment |
| M10 production test-data policy | `docs/M10_HANDOVER.md` | The Owner decides retain or remove for the rehearsal report |
| Technical release owner and production operator | Section 5.2 | The Owner names them |
| Client backup owner, off-host destination and consent record | Sections 9 and 10 | The Owner names them |
| Repository privacy | Section 15 | Separately authorised after handover checks |
| Pull request 52 | Section 0.2 answer 15A | Closed, and the audit document re-landed alone with its cron section corrected |

An unchecked product requirement may not be reclassified as QA. In particular,
M12's two open requirements are product outcomes and are not satisfied by
passing tests.

---

## 20. Authority and limits of this document

This document records decisions and defines evidence. It grants nothing.

- It does not authorise production access, credentials or authority, and none is
  assumed.
- It does not authorise a deployment. No deployment is triggered by writing it.
- It does not authorise changes to production.
- It does not authorise a repository visibility change.
- It does not authorise a commit or a push.
- It does not authorise printing, copying or committing a secret.

Each action above requires the appropriate authorised person at the relevant
task in Section 17, and a separate, explicit instruction.

# OK Veggies, Deployment

The host is shared cPanel with **SFTP only, no SSH shell**. So we cannot run PHP
on the server from a terminal. Deployment therefore has two moving parts:

1. **Files** go up over SFTP (a GitHub Action, or FileZilla by hand).
2. **Migrations** run through a token-guarded web endpoint, `public/migrate.php`,
   which the workflow calls with a secret after the upload. No shell needed.

`vendor/` and the built CSS and JS are committed, so nothing is built on the
server.

---

## One-time setup

### 1. Create the database (cPanel, MySQL Databases)

Create the database and user that match your `.env`, and grant the user all
privileges on it. With the current `.env` that is database `ibbbnlso_okveggies`
and user `ibbbnlso_okveggies_admin`.

### 2. Find the docroot

Note the absolute path that `okveggies.com.ng` serves from. For a primary domain
it is usually `/home/ibbbnlso/public_html`. For an addon or subdomain it is that
domain's own folder. You will need this for `SFTP_REMOTE_PATH`.

### 3. Put the production `.env` on the server (once)

`.env` is gitignored and never uploaded, so the server keeps its own copy. Upload
it once with cPanel File Manager or FileZilla, into the docroot (or outside it and
set `OKV_ENV_PATH`). In that server `.env`:

- Keep the real DB, SMTP and Paystack values.
- Set `APP_DEBUG=false`.
- Add a strong migration secret: `MIGRATE_TOKEN=` the output of `openssl rand -hex 32`
  (or any long random string).

### 4. Add the GitHub repository secrets

Settings, then Secrets and variables, then Actions. Add:

| Secret | Value |
|---|---|
| `SFTP_HOST` | `51.79.17.60` |
| `SFTP_PORT` | `1624` |
| `SFTP_USER` | `ibbbnlso` |
| `SFTP_PASSWORD` | your SFTP password |
| `SFTP_REMOTE_PATH` | the docroot from step 2 |
| `APP_BASE_URL` | `https://okveggies.com.ng` |
| `MIGRATE_TOKEN` | the same value you put in the server `.env` |

The password and token live only here, encrypted. They are never in the repo.

---

## Deploying

After the setup above, every push to `main` deploys: the workflow uploads the
files over SFTP, then calls `public/migrate.php` with the token to apply any new
migrations. You can also trigger it by hand from the Actions tab
(Run workflow). See `.github/workflows/deploy.yml`.

### Manual first deploy (no CI, if you want to go live before wiring secrets)

1. In FileZilla connect to `sftp://51.79.17.60` port `1624` as `ibbbnlso`.
2. Upload the whole folder **except** `.git`, `node_modules`, `_to_delete` and
   `.env` into the docroot. (`vendor/` and `assets/` must go up.)
3. Make sure the server `.env` from step 3 is in place, and the database from
   step 1 exists.
4. Apply migrations one of two ways:
   - Visit `https://okveggies.com.ng/public/migrate.php?token=YOUR_TOKEN` in a
     browser. You should see each migration applied and `MIGRATE OK`.
   - Or import `migrations/000_...` through `006_...` in order via phpMyAdmin.

---

## Verifying a deploy

- Open `https://okveggies.com.ng/`. You should see the storefront home with the
  Stew Combo and the featured products.
- Open `https://okveggies.com.ng/shop.php` and one product page. Both must
  answer 200 with produce on them. The post-deploy smoke gate
  (`scripts/verify.sh`) checks these, and it fails the deploy when the
  catalogue cannot be read.
- Check migration state:
  `https://okveggies.com.ng/public/migrate.php?action=status&token=YOUR_TOKEN`
  Every migration should read `OK`.

---

## Cron jobs (cPanel), a short guide

The shop needs one scheduled job. Without it a payment made in a closed tab
waits for the customer to come back, and the pending payment reminder never
goes out.

There is no shell on this host, so the job is a `curl` call to a token-guarded
URL. It uses the same `MIGRATE_TOKEN` already in the server `.env`.

**Set it up once:**

1. cPanel, then **Cron Jobs**.
2. Under **Add New Cron Job**, set **Common Settings** to
   **Every Five Minutes** (`*/5 * * * *`).
3. Paste this as the command, with your own token in place of `YOUR_TOKEN`:

   ```
   curl -fsS -H "X-Migrate-Token: YOUR_TOKEN" https://okveggies.com.ng/public/cron.php > /dev/null
   ```

   The header keeps the token out of the server access logs. `-f` makes curl
   exit non-zero on a failure, so cPanel emails you only when something is
   actually wrong.
4. Save. Put your email in the **Cron Email** box at the top of the page if you
   want those failures to reach you.

**What that one job does, every five minutes:**

| Job | What it fixes |
|---|---|
| Payment reconciliation sweep | A customer paid, then closed the tab before Paystack reached us. The sweep asks Paystack directly and credits the order. |
| Due notifications | Sends the one reminder an unpaid pay in full or deposit order gets, 30 minutes after it was placed. Cancelled automatically if the payment lands first. |

**To check it by hand**, open this in a browser:

```
https://okveggies.com.ng/public/cron.php?token=YOUR_TOKEN
```

It prints one line per job and ends with `CRON OK`. A wrong or missing token
returns 404, the same as the migration and health check endpoints.

**Nothing else needs a cron job.** `scripts/payment_sweep.php` and
`scripts/cron.php` are the shell versions of the same work, for a host that has
a shell. Do not schedule both: one pass is enough, and two only means two
processes asking Paystack the same question.

The reminder delay is a setting, not a constant: `payment_reminder_minutes` in
`site_settings`, 30 by default.

---

## 7. Pre-deploy backup (manual, cPanel; required before every production push)

The host has no shell, so `scripts/backup.sh` cannot run there. This is the
manual equivalent, and per the M13 contract (Section 10) the client owns it,
engineering writes it and witnesses it. A deploy that cannot produce a fresh
backup of both halves does not proceed.

1. cPanel, **Backup Wizard**, **Download a Full Backup** or at minimum both
   parts separately:
   - **MySQL Databases**, or the Backup wizard's database row: download the
     dump for `ibbbnlso_okveggies`.
   - **File Manager**: compress `public_html/uploads/` (customer photos live
     there and exist in no other copy) and download the archive.
2. Copy both files **off the host** the same day, to a destination the client
   controls (not this server, not this GitHub repository: they contain customer
   data). Record where, in the deploy log for the SHA.
3. Checksum both files on arrival:
   `shasum -a 256 backup-YYYYMMDD.sql.gz uploads-YYYYMMDD.tar.gz`
   and write the two hashes, the local time (WAT) and the operator's name into
   the deploy log. A deploy is not covered until the hashes exist off-host,
   not merely a "backup succeeded" email.
4. Retention: keep the two most recent verified pairs, delete older pairs the
   day a newer pair checks; note the deletion in the log.

## 8. Timed restore drill (before launch; then with each release train)

A backup nobody has restored is a rumour. Run this quarterly and before the
first gated release; time it, because the deploy window budget is real.

1. On a throwaway machine (or the test database on this one, named so it ends
   in `_restore_test`): create the database, load the dump:
   `mysql okveggies_restore_test < backup-YYYYMMDD.sql`
   and start the minute timer at the `mysql` command, stopping at read-back.
2. `SELECT COUNT(*) FROM schema_migrations;` must equal the migration file
   count of the deploying release; if not, the dump predates the last deploy
   and the drill restarts from a fresh backup.
3. Representative record read-back, paste the output into the drill record:
   three named recent orders with money totals
   (`SELECT order_number, total_subunit, status FROM orders ORDER BY id DESC LIMIT 3;`),
   one kitchen run (`request_number, status`), one payment reference, and the
   Owner row's email with `password_changed_at` (see `docs/evidence/*/13` fix 4).
4. Private upload read-back: with a staff session on the restored app pointed
   at the restored database, open one issue photo through
   `public/issue_photo.php?photo=<id>` (the access-checked route, never the raw
   path); a 200 with the image bytes proves `uploads/` and its guard survived
   together. Also verify one catalogue product image serves from
   `uploads/products/`.
5. Record elapsed minutes, the two checksums that were restored, the read-back
   outputs, and a line "restored copy dropped <date>". Then drop the scratch
   database (`DROP DATABASE okveggies_restore_test;`). The drill's output is
   release evidence and belongs in the frozen SHA's evidence pack.

## 9. Rollback, maintenance state and triggers

Rollback target: **the previous verified release artifact, stored off-host**.
Before the first gated deploy, archive the exact tree the last SUCCESSFUL
deploy uploaded (the `_dist` set) plus its matching database dump, checksum it,
name it `okveggies-release-<sha7>.tar.gz`, and keep it where the operator can
reach it during an incident. Restoring means: maintenance on, upload the
stored tree over the docroot the same way deploy does (the two explicit dotfile
steps included), then decide the database action.

Migrations are **forward-only, always** (contract 11.3): a rollback of the
application does not run down-migrations. If the new schema is incompatible
with the old code, restore the matching database dump from the same archive
(the two halves of an artifact are a pair), or ship a corrective migration.
Never improvise destructive SQL at 11pm.

Rollback or maintenance is TRIGGERED by any of (contract 11.2):
a failed or partially applied migration; exposure of `.env`, source,
`migrations/`, `docs/` or other protected paths; incorrect payment crediting,
double charging or material refund faults; a material permission or
private-data leak; detected data corruption; failure of a critical post-deploy
smoke journey; or another defect the Owner and technical release owner judge
unsafe for live use. (As at 20 Sep 2026 trigger one is ACTIVE: the nine failed
migrate steps in `docs/M13_REVIEW.md` Part II B1.)

The intended switch is **application-level** and it is **not on `main` yet**:
`site_settings.maintenance_enabled` with `includes/bootstrap.php` answering
anonymous storefront requests with `503` and a `Retry-After` header while
staff, admin and `public/healthcheck.php` pass through. That code is PR5. The
server-file alternative was rejected because every deploy re-uploads
`.htaccess` and `.user.ini` and would silently flip the sign back to open
mid-window. Until PR5 lands, a window means either landing PR5 first or
deploying without a maintenance guarantee, with the store's trading hours
avoided; do not hand-edit `.htaccess` for maintenance, deploy will undo it.

## 10. Live smoke after an approved deploy (the same day, before "done")

From any machine that can route to the host (this repository's sandbox cannot,
see `docs/evidence/720625b47f/12`), in order:

1. `bash scripts/verify.sh https://okveggies.com.ng` - every line green, and it
   already asserts the protected paths: `.env`, `includes/config/db.php`,
   `migrations/001_core_schema.sql` and `docs/PRD.md` must read 403 or 404.
   Spot-check the rest of the deny list the same way: `/vendor/autoload.php`,
   `/scripts/migrate.php`. This is the fix 25 proof and the contract says it is
   re-run per candidate, never inherited.
2. `curl -s -o /dev/null -w '%{http_code} %{time_total}s\n'` on `/`, `/shop`,
   `/combos`, `/how-it-works`, `/contact.php`: expect five `200`s.
3. `/admin/login.php` 200; one signed-in click-through: dashboard loads, one
   order opens, the kitchen queue loads (this is the schema-half of the
   migration: with pending migrations, these screens are where a column that
   does not exist would 500).
4. One **low-value live Paystack transaction** placed on the live store, then
   reconciled BOTH ways: the Paystack dashboard row is `success`, the
   `payments` row and `payment_transactions` agree on reference and amount, the
   order advanced. Then refund or complete it per the run, and record the
   reference (never the keys). `scripts/payment_healthcheck.php`'s live cousin:
   re-run the healthcheck URL the host uses after the transaction.
5. Mail: one activation/order/support sequence delivered, and the DNS side
   proven once at handover with `dig TXT` for SPF, the DKIM selector, and
   `TXT _dmarc.okveggies.com.ng`; receipts pasted from **two independent
   provider inboxes** (Gmail and Outlook), showing `spf=pass dkim=pass
   dmarc=pass` headers, plus the matching `notification_deliveries` rows.
6. Cron: the `*/5` job exists in cPanel, and the endpoint answer from its own
   shell (`curl -fsS -H "X-Migrate-Token: ..."` ... ends `CRON OK`) plus the
   timestamp of a real five-minute pass within the last ten.

Append the output of every numbered line to the frozen SHA's evidence pack.
Until B1 (see `docs/M13_REVIEW.md` Part II) is merged and one deploy reaches
step 1, the pipeline has not earned the right to claim any of this.

## Notes and safety

- The migration endpoint fails closed: with no `MIGRATE_TOKEN` set, or a wrong
  token, it returns 404. Prefer the `X-Migrate-Token` header (the workflow uses
  it) over `?token=` so the secret stays out of access logs. Rotate the token by
  changing it in both the server `.env` and the GitHub secret.
- The deploy never deletes remote files, so your server `.env` and everything a
  customer has uploaded to `uploads/` are safe across deploys. The trade-off is
  that a file deleted from the repo is not removed from the server; delete those
  by hand if it ever matters.
- The main SFTP upload uses a wildcard, so the workflow uploads `.htaccess` and
  `.user.ini` again as explicit single files. Do not remove those 2 steps.
  Without them, the host can retain stale access rules while the visible app
  still deploys successfully.
- HTTPS is forced by `.htaccess`, and `.env`, `migrations/`, `includes/`,
  `scripts/`, `docs/` and `vendor/` are all denied over the web.
- The final workflow step runs `scripts/verify.sh` against the deployed URL.
  A deployment is not successful when a protected path is publicly readable.

## If something is wrong

- Home page shows a 500: the server `.env` DB values are wrong, the database was
  not created, or the host PHP is not 8.x. Check cPanel error logs.
- `/public/migrate.php` returns 404 with the right token: `MIGRATE_TOKEN` is not
  set in the server `.env`, or the two tokens do not match.
- Product images are blank: confirm the `assets/img/product_images` folder
  uploaded.

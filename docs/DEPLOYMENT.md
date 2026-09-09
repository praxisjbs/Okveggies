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

## Notes and safety

- The migration endpoint fails closed: with no `MIGRATE_TOKEN` set, or a wrong
  token, it returns 404. Prefer the `X-Migrate-Token` header (the workflow uses
  it) over `?token=` so the secret stays out of access logs. Rotate the token by
  changing it in both the server `.env` and the GitHub secret.
- The deploy never deletes remote files, so your server `.env` and everything a
  customer has uploaded to `uploads/` are safe across deploys. The trade-off is
  that a file deleted from the repo is not removed from the server; delete those
  by hand if it ever matters.
- HTTPS is forced by `.htaccess`, and `.env`, `migrations/`, `includes/`,
  `scripts/`, `docs/` and `vendor/` are all denied over the web.

## If something is wrong

- Home page shows a 500: the server `.env` DB values are wrong, the database was
  not created, or the host PHP is not 8.x. Check cPanel error logs.
- `/public/migrate.php` returns 404 with the right token: `MIGRATE_TOKEN` is not
  set in the server `.env`, or the two tokens do not match.
- Product images are blank: confirm the `assets/img/product_images` folder
  uploaded.

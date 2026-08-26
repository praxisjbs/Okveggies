# OK Veggies

Lagos's trusted fresh-provisions partner, online. This repository is the website and back office for OK Veggies: a public storefront for households and businesses, a B2B Pro Portal, and a staff admin panel that runs the whole operation.

**Sourced right. Priced right. Delivered right.**

> **Start here.** If you are a person or an AI assistant about to build on this project, read this file, then read `CLAUDE.md` (how we work, non-negotiable), then read `docs/PRD.md` (what we are building). Do not write code before you have read the PRD.

---

## What this is

OK Veggies sources fresh produce from verified farms in Ogun State and Jos and delivers it on the day the customer picks. The business already supplies restaurants, hotels and supermarkets over WhatsApp; this project opens it to households and turns the manual B2B service into software. See `docs/PRD.md` for the full picture.

Three surfaces, one brand:

| Surface | Path | For |
|---|---|---|
| Storefront | `/` | Everyone. Browse, combos, checkout |
| Pro Portal | `/pro` | Logged-in B2B buyers. Saved kitchen lists, credit, standing orders |
| Admin | `/admin` | Owner and Manager. Runs the business |

## Tech stack

- **PHP 8.x** (pin to the host's version, target 8.3), plain and modular. No framework.
- **MySQL / MariaDB** on cPanel shared hosting (phpMyAdmin).
- **Tailwind CSS**, self-hosted and built (no CDN). Vanilla JavaScript with Fetch. No jQuery.
- **Paystack** for payments. Money is stored in subunits (kobo) as integers, formatted by one `Money` helper.
- **SMTP email** via PHPMailer. **dompdf** for invoices and receipts. **PhpSpreadsheet** for CSV / Excel import and export. Composer dependencies are committed in `vendor/` because the server has no Composer.
- Architecture mirrors the reference project `bureau.lpc.cm`: an `index.php` front, `api/v1` controllers dispatched by action, `includes` for config / classes / functions / components, `modules` for feature pages, numbered SQL `migrations` run by `scripts/migrate.php`, RBAC, CSRF, a hardened `bootstrap.php`.

## Repository structure (intended)

```
okveggies/
  index.php                 public storefront home
  .htaccess  .user.ini      shared-hosting config + security headers
  .env  .env.example        secrets (never commit .env)
  composer.json  package.json  tailwind.config.js
  README.md  CLAUDE.md  PROGRESS.md
  public/                   token-link pages: order trail, receipts, PDF documents, auth
  includes/
    bootstrap.php           every entry point requires this first
    config/                 env.php, db.php, permissions.php, nav.php
    classes/                Database, Rbac, Csrf, RateLimiter, Money, Mail, Paystack, Otp,
                            OrderNumber, Uploads, PdfRenderer, ...
    functions/              helpers, assets, pricing, notify, ...
    components/             storefront header/footer/support, admin sidebar/topbar, pro nav
  api/v1/                   controllers: auth, catalog, cart, checkout, orders, payments,
                            paystack_webhook, kitchen_runs, combos, credit, customers,
                            pricing, delivery, contact, make_it_right, settings, rbac
  admin/                    admin panel pages (served at /admin/)
  pro/                      B2B Pro Portal pages (served at /pro/)
  shop.php product.php combos.php combo.php kitchen-runs.php cart.php
  checkout.php account.php page.php   storefront pages, at the web root
  assets/    css/ (tailwind build)  js/ (okv-*.js)  img/ (product_images)
  uploads/                 product images, kitchen-run uploads, proofs (PHP execution denied)
  migrations/              000_init ... numbered, idempotent SQL
  scripts/                 migrate.php, deploy.sh, verify.sh, backup.sh, tests/
  docs/                    PRD.md, discovery notes, decision rounds, QA checklists
  vendor/                  committed Composer packages
  .github/workflows/       deploy.yml (auto-deploy to cPanel on push to main)
```

## Getting started (local)

1. Copy `.env.example` to `.env` and fill in database, SMTP and Paystack values. Never commit `.env`.
2. Create the database, then run migrations: `php scripts/migrate.php`. Check state any time with `php scripts/migrate.php --status`.
3. Install dev tooling and build assets: `npm install` then `npm run build` (Tailwind + JS).
4. Install PHP dependencies once on a machine that has Composer: `composer install`, then commit `vendor/`.
5. Serve the folder with PHP: `php -S localhost:8000` (or point a local Apache at it).
6. Run the checks: `php scripts/migrate.php --status`, `bash scripts/verify.sh`, and the tests in `scripts/tests/`.

## Deploy

Auto-deploy on push to `main` via GitHub Actions to cPanel, same model as the reference project. `scripts/deploy.sh` runs migrations and smoke tests on the server. Back up before every deploy. The repository is public during the build and goes private at completion. Details in `docs/PRD.md` Section 21 and (once written) `docs/DEPLOYMENT.md`.

## Key documents

- `CLAUDE.md` how we build here. Read before coding.
- `docs/PRD.md` the product requirements. The source of truth.
- `PROGRESS.md` what is done, in progress, and next.
- The OK Veggies Brand Architecture bible v1.0 is the design authority.

## The short version of our standards

Read the PRD first. Ask at least five clarifying questions before building a feature. Follow the brand system exactly (forest / gold / tomato / foliage, the fonts, the spacing grid). Plain, relational language, no jargon, and no em dash anywhere, including code comments. Prepared statements, CSRF, escaped output, RBAC on every route. Write tests and run them. Nothing ships red. Full rules in `CLAUDE.md`.

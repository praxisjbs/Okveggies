# CLAUDE.md, how we build OK Veggies

This file is the operating contract for anyone building on OK Veggies, human or AI. It is short on purpose. Follow it exactly. If you are an AI assistant, this file governs your work in this repository.

The reference architecture is `bureau.lpc.cm`. When a convention is not written here, match how the reference project does it.

---

## The build loop (every task, in order)

1. **Read the PRD.** Open `docs/PRD.md` and read the section for the feature you are about to build. If the PRD does not cover it, the feature is not specified. Stop and ask.
2. **Ask at least five clarifying questions before writing code.** Not fewer. Real questions about behaviour, edge cases, data, copy and states. **Every question must offer three concrete answer options (A, B, C) and end with your recommendation.** Never open-ended, never one option, never "what do you want?". The three options must be genuinely different paths the build could take, not variations of the same one. Your recommendation names the option (A, B or C) and gives one sentence of reasoning. Wait for answers. Guessing is how we ship the wrong thing.
3. **Check `PROGRESS.md`.** Confirm the task, its acceptance criteria, and that nobody else is on it.
4. **Build it,** following the conventions and non-negotiables below.
5. **Write tests, then run them.** Unit tests for any money, pricing, deposit, credit, order-number, delivery-eligibility or webhook logic. Run them. They pass before you continue.
6. **Smoke test and lint.** `php -l` every file you touched. Run `bash scripts/brand-check.sh` (the brand guard: it also runs in CI and gates the deploy), then `bash scripts/verify.sh` and the relevant role smoke test. Load the page on a narrow (mobile) and wide (desktop) viewport.
7. **Update `PROGRESS.md`.** Tick the acceptance criteria, log what you did, note anything you discovered or deferred.
8. **Commit** with a clear message. Push to a branch, not straight to `main`, unless told otherwise.

If any step fails, the task is not done. Do not mark it done.

---

## Non-negotiables

### Security (violating one blocks the merge)
- No secrets in code. `.env` only.
- Prepared statements always. Never build SQL by string interpolation, not even for an integer.
- Never echo an exception message to the client. Log it, return a plain message.
- Escape output: `htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8')` in HTML, `json_encode()` in a JS context.
- CSRF token validated on every state change (POST / PUT / DELETE).
- RBAC on every admin and pro route and every API action. Re-check on the server every time. The frontend gate is UX only.
- Uploads: extension whitelist, MIME sniff, size cap, randomised filename, stored under `uploads/` where PHP execution is denied.

### Money
- Stored in subunits (kobo) as integers. Never a float, never `DECIMAL` for a running total that came from the gateway.
- All formatting and parsing goes through the one `Money` helper. Never hand-format naira in a template.

### Data
- Every entry point starts with `require_once .../includes/bootstrap.php;`.
- Every migration is numbered, idempotent (`IF NOT EXISTS`, `INSERT ... ON DUPLICATE KEY UPDATE`), wrapped in a transaction, and ends with verification queries. One migration, one file. Never edit a migration that has shipped; write a new one.
- Never delete an order, payment or credit record. Reverse it. History tables are append-only.
- snake_case for columns and PHP variables, PascalCase for classes, camelCase for JS.

### Brand and language (the house laws)
- **No em dash. Anywhere. Ever.** Not in copy, not in emails, not in a code comment. Use full stops, commas, colons, semicolons. A grep for the em dash character in the repo should return nothing.
- **No enterprise jargon in customer-facing copy.** Banned words: curated, artisanal, bespoke, leverage, utilise, endeavour, elevate, seamless, unlock, robust, one-stop solution, premium selection. Write from inside the kitchen. Specificity beats sophistication.
- **Nigerian English, British-leaning spelling** (colour, kilogramme, organise). Pidgin only in campaign lines and combo names, never in checkout, legal or error copy.
- **Numerals always** for prices, weights and dates (₦8,000, 2kg, Tuesday 26th), with the ₦ symbol and comma separators.
- **Units always stated** on every product reference (kg, bunch, head, tuber).
- **Design tokens only.** Colours and spacing come from `tailwind.config.js`. No arbitrary hex, no arbitrary 15px. Gold is never a button fill and never carries its own text.
- **The seal, the logo, the favicon.** The approved primary seal lives at `docs/brand/logo/ok-veggies-seal.jpg`. Every derived mark and icon is generated from it by `scripts/brand/generate_brand_assets.py` into `assets/img/brand/` (never hand-edit the outputs; regenerate). Use the full photographic seal only where it has room to read (120px or more): hero, footer, auth, print, the social card. In tight headers and nav use the horizontal lockup (`assets/img/brand/lockup.svg`, or `lockup-white.svg` on forest and dark grounds), never the seal shrunk small. The favicon and app icons are the flat monogram set plus `site.webmanifest`; the tab colour is Forest Green.
- **Fonts are self-hosted, three faces, no CDN at runtime.** Hanken Grotesk (body, UI, nav, buttons, prices), DM Serif Display (editorial headings, opt in with `font-editorial`), JetBrains Mono (figures, prices, order numbers). The woff2 files are in `assets/fonts/`; refetch with `scripts/brand/fetch_fonts.sh` then `npm run build:css`. Do not reintroduce Plus Jakarta or any other face.
- **Every page emits `okv_head_meta()`.** It is loaded by `bootstrap.php` and prints the favicon, manifest, theme colour, font preloads and social defaults. Any new page with a `<head>` calls it right before the stylesheet link. A page that skips it fails `scripts/brand-check.sh`.

### Experience (from PRD Section 2)
- Mobile behaves like a native app: bottom tab bar, slide-up sheets, skeletons, optimistic UI. Desktop admin behaves like desktop software. The desktop shop is dense.
- Deep links between related pages, both ways. A real back button and breadcrumbs everywhere. No dead ends.
- Accessibility is non-negotiable: 44px touch targets, a visible gold focus ring never suppressed, colour never the only signal, visible labels, alt text on every product photo, `prefers-reduced-motion` respected.
- Motion: Botanical 240ms for 90% of the interface, Market Bounce 320ms only for add-to-basket and success moments.

---

## Conventions (match the reference project)

- **Controllers** live in `api/v1/*.php`, dispatch on `$_POST['action']` or `$_GET['action']`, return JSON `{status, ...}`, and gate each action with RBAC.
- **Pages** live at the web root (storefront: `index.php`, `shop.php`, `product.php`, ...), in `admin/` (the admin panel) and `pro/` (the Pro Portal). Each opens with the bootstrap include and, for admin and pro, an RBAC permission check.
- **Shared logic** is a class in `includes/classes/` or a function file in `includes/functions/`, not copy-pasted. One concern per file. Split a page over 800 lines.
- **JavaScript** is vanilla with Fetch, one file per module in `assets/js/`, no inline scripts beyond a few lines of glue, and it renders user data with `textContent` or an escaping helper, never raw `innerHTML +=`.
- **Order numbers** come from the `OrderNumber` helper (`OKV` + two-digit year + padded sequence). Never build one by hand.
- **Tests** live in `scripts/tests/`. Smoke tests follow the shape of the reference `verify.sh`.
- **Brand assets** are generated, never hand-drawn: `scripts/brand/generate_brand_assets.py` builds the marks and icon set from the seal, `scripts/brand/fetch_fonts.sh` pulls the fonts. `scripts/brand-check.sh` is the static guard (em dash, banned jargon, gold fills, arbitrary hex, missing assets or head partial, stale stylesheet) and runs in CI and before every deploy.

---

## Never do these

- Never write code before reading the PRD section for it.
- Never skip the five clarifying questions to "save time".
- Never mark a task done with failing or missing tests.
- Never commit `.env`, a `.sql` dump, or an error log.
- Never disable a security gate to test something quickly.
- Never use an em dash or a banned jargon word in anything that ships.
- Never edit production by hand or run a destructive query against the live database.

---

## When you are unsure

Stop and ask. A short, specific question now is cheaper than a wrong feature later. Put the question in the conversation, mark the task blocked in `PROGRESS.md`, and move to something else that is unblocked.

# OK Veggies: client meeting audit, defect record and action backlog

**Project:** OK Veggies Fresh Provisions, e-commerce and operations platform (`praxisjbs/Okveggies`)
**Client:** Kumbish Emmanuel Putleh, OK Veggies (Lagos, Nigeria)
**Engineering:** Tom-Blake Asaah and JBS Praxis
**First written:** 17 September 2026
**Verified and corrected:** 19 September 2026, against the meeting transcripts and the codebase at `b2f1f43`
**Pre-handover audit:** 19 September 2026, at `a47a319`. See Section 6

---

## 0. What this document is, and how far you can trust it

This is the single source of truth for the final run to handover. It records
what the client asked for across three meetings, what was built in response,
what broke and was fixed, what a full technical audit found, and what is still
outstanding. It exists so that nobody has to re-listen to a recording or
re-read the codebase to find out whether something was agreed, delivered or
still owed.

**Everything left to do before handover is in Section 3, in priority order.**
Section 6 is the evidence behind the items the 19 September audit added.

### 0.1 How each claim here was checked

Every claim falls into one of three classes, and the class is stated wherever it
matters:

| Class | Meaning | How it was checked |
| --- | --- | --- |
| **Transcript-verified** | The client said it, in a meeting whose transcript is in hand | Read against the verbatim transcript, not the summary alone |
| **Code-verified** | The file, class, method, migration or table exists and does what is claimed | Opened in the repository at commit `b2f1f43` |
| **Reported** | Taken from a senior review document, not independently re-derived | Source named inline |

### 0.2 Confidence statement

**Sections 1B, 1C, 3 and 4 are transcript-verified and code-verified, and can be
worked from directly.** Section 1A is corroborated but not transcript-verified,
for the reason given in Section 1A. Section 2 is reported, with the file and
method references code-verified.

The 19 August 2026 discovery meeting transcript was **not available** to this
audit. Its content is corroborated by `docs/PRD.md`, which names
`docs/Client Discovery Meeting - 2026_08_19 08_58 WAT - Notes.docx` as a source
document, and by the Brand Architecture bible, which quotes the founder from
that session. That is corroboration, not verification. Section 1A is therefore
marked accordingly and should be confirmed against the recording before anything
commercially significant is decided from it alone.

### 0.3 Corrections made on 19 September 2026

The first draft of this document was audited claim by claim. The following were
wrong and have been corrected in place. They are listed rather than quietly
fixed, because the point of the document is that it can be trusted:

1. The 3 September 2026 project update meeting was **missing entirely**. It is now Section 1B, and it carries a decision that is still not implemented (Section 3, item 3).
2. Section 1C was attributed to the 9 September 2026 client meeting but described five defects that **are not in that transcript**. The real client asks from that meeting now form Section 1C; the five defects have moved to Section 2.7, where they belong, under their real source.
3. `scripts/tests/CronTest.php` and `scripts/tests/cron_db_test.php` were cited as evidence and marked "verified green". **Neither file exists.**
4. Six class and method references were wrong. All six are corrected in Section 2 and listed in Section 5.2.
5. The production protected-directory check was listed as an unverified launch blocker. It is **verified automatically on every deploy** and passed on 19 September 2026. See Section 3, item 2.
6. Delivery day coverage (Section 1A, D3) was stated as settled. The 3 September meeting changed it and the change was never seeded. See Section 3, item 3.
7. House style: em dashes and two banned jargon words were removed, and spelling was made consistently British-leaning, as `CLAUDE.md` requires.

### 0.4 Added on 19 September 2026: the pre-handover audit

A full technical audit was then run at `a47a319` across security, data,
performance, accessibility, interface, code quality, test coverage and
operational readiness. It added twelve items to Section 3 and closed two of
them in the same pass. Section 6 holds the evidence, including what came back
clean. Two findings are worth reading before anything else:

- **The client-side escape helper did not escape quotes**, which both broke the
  saved-address Edit button for every customer and opened an attribute-injection
  path. Proven in Chromium, fixed, and awaiting deploy (item 3).
- **There is no runbook, rollback procedure or backup and restore document.**
  That work existed on a branch that was never pushed and is gone (item 4). It
  is the one thing that should block handover rather than go-live.

---

## 1. The client meetings

### 1A. Discovery meeting, 19 August 2026

> **Corroborated, not transcript-verified.** The transcript was not available to
> this audit. Each row below is supported by `docs/PRD.md` and by the shipped
> implementation, both of which were written from that meeting. Treat the
> "what was agreed" column as reconstructed.

| # | Business context | What was agreed | Status in the code |
| --- | --- | --- | --- |
| **D1** | Nigerian e-commerce fraud: buyers refuse delivery or stop answering calls after goods are sourced | Full prepayment, or a 30% deposit, before dispatch | **Built.** `checkout.php` and `Checkout.php` enforce pay in full or a 30% deposit through Paystack |
| **D2** | Households buy staples in small units but also want quick meal kits | Separate browsing for single items and for combination bundles | **Built.** `shop.php` for the catalogue, `combos.php` and `combo.php` for bundles (`Combos.php`, `Catalogue.php`) |
| **D3** | Tuesdays and Fridays are the busy restaurant supply days | Households on Monday, Wednesday, Thursday, Saturday; businesses on Tuesday and Friday | **Built, then superseded.** Seeded in `003_reference_seed.sql`. The 3 September meeting opened Monday to businesses as well, and that change was never seeded. See Section 3, item 3 |
| **D4** | A delivery bus with dynamic dispatch; Lagos-wide automated pricing is premature and moves with fuel cost | Delivery fees stay off the basket and are settled on delivery | **Built.** `015_drop_fee_bearer_setting.sql` removed fee automation; checkout states that fees are quoted and paid on delivery |
| **D5** | Produce has distinct packaging: kg, bunch, head, tuber | Show the exact unit everywhere, and allow 0.5kg steps where the unit is kg | **Built.** `units_of_measurement` in `003_reference_seed.sql` carries `allows_decimal`; product seed `004` sets the unit per product |
| **D6** | Prices move weekly at Mile 12 and at the farms; the aggregator fixes a weekly price and absorbs small rises | Fast admin price updates with an audit trail | **Built.** `admin/pricing.php`, `Pricing.php`, and spreadsheet bulk upload (`PriceSheet.php`) writing `product_price_history` |
| **D7** | Regular hospitality buyers need 7 to 10 day terms | A credit application and limit system for approved business accounts | **Built.** M8 Pro Portal: `pro/credit.php`, `Credit.php`, `admin/credit.php`, with limits, a transaction ledger and on-account checkout |
| **D8** | Hospitality buyers send unstructured lists over WhatsApp | An on-platform flow: submit a list, staff quote it, customer approves, it converts to an order | **Built.** M7 and its follow-up: `kitchen-runs.php`, `admin/kitchen_runs.php`, `KitchenRuns.php`, `KitchenRunWorkflow.php` |
| **D9** | Anxious buyers need reassurance that an order is real | Transactional email on placement, payment and dispatch | **Built.** `Mail.php` and `Notifications.php`, with branded HTML templates |
| **D10** | The client works from the market from 06:00 on a phone, and from a laptop indoors | Full responsiveness at mobile and desktop widths | **Built.** Tailwind tokens, 44px touch targets. Proven at 390px and 1440px by the browser suites, which have not yet been executed in a release run |
| **D11** | Customers fear receiving poor or damaged perishable goods | A formal recourse flow: report with photos, staff review, refund or credit | **Built.** M10 Make It Right: `public/order.php`, `admin/make_it_right.php`, `IssueReports.php`, `IssueResolutions.php` |
| **D12** | ₦400,000 budget, a `.com.ng` domain, a dedicated project email for handover | Delivery, domain and hosting setup | **Done.** `docs/DEPLOYMENT.md` and the GitHub Actions pipeline |

---

### 1B. Project update, 3 September 2026

> **Transcript-verified.** Present: JBS Praxis, Tom-Blake Asaah, Kumbish
> Emmanuel Putleh. A walkthrough of the admin panel, the mobile storefront and
> the checkout, with the live payment gateway connected during the call.

This meeting was absent from the first draft of this document. It matters for
three reasons: it carries a recorded decision that is still not implemented, it
is the point at which live Paystack keys entered the system, and it produced a
client-owned security action that nobody has tracked since.

#### Recorded decision

| Decision | Status |
| --- | --- |
| **Monday is an available delivery day for businesses as well as households.** Recorded as aligned, in response to the client's suggestion during the delivery configuration walkthrough | **Not implemented in the seed.** See Section 3, item 3 |

#### What was demonstrated and confirmed working

| Area | What the client saw | Status in the code |
| --- | --- | --- |
| Admin dashboard and price management | Monitoring the storefront, updating prices, categories for herbs, spices, tubers and roots | **Built.** `admin/index.php`, `admin/pricing.php` |
| Combination bundles | Defining components, availability windows, cover images, withdrawing a bundle | **Built.** `admin/combos.php`, `Combos.php` |
| Staff and roles | Adding staff, setting manager or owner, changing passwords, switching access off | **Built.** `admin/users.php`, role and permission tables |
| Product catalogue and SKU | SKU explained as internal stock tracking, auto-numbered from the product count | **Built.** `004_product_seed.sql`, `Catalogue.php` |
| Price history and bulk import | Price history tracking, spreadsheet import for bulk updates | **Built.** `PriceSheet.php`, `product_price_history` |
| Payment gateway | **Live** secret key, **live** public key and the webhook URL entered during the call | **Live.** This is why published legal copy is a launch blocker and not a nicety. See Section 3, item 1 |
| Payments and credit | Recording manual payments, searching by client name, transaction status, credit for business clients | **Built.** `admin/payments.php`, `Credit.php` |
| Operational settings | Deposit percentage (30%), daily cut-off, cancellation policy | **Built.** `admin/settings.php`, `settings_fields.php` |
| Branding and WhatsApp | Business name, tagline, WhatsApp contact number | **Built.** Settings, `WHATSAPP_SUPPORT_NUMBER` |
| Checkout and payment proof | Full customer journey, then a real test transaction whose receipt and order status appeared on the admin dashboard | **Built and proven live on the call** |
| Manual order entry | Orders taken off the platform must be enterable by staff; the client called this the main bookkeeping benefit, replacing spreadsheets | **Built.** `admin/order_new.php` and `admin/kitchen_run_new.php`, covered by `manual_operations_http_test.php` |

#### Client-owned actions from this meeting

| Action | Owner | Status |
| --- | --- | --- |
| **Change the admin password and account email to secure the platform after launch** | Kumbish Emmanuel Putleh | **Open and untracked until now.** See Section 3, item 4 |
| Review the order and payment demo and send questions | Kumbish Emmanuel Putleh | Done. It produced the 9 September meeting |

---

### 1C. Update review, 9 September 2026

> **Transcript-verified.** This section replaces the first draft's Section B,
> which listed five engineering defects that do not appear anywhere in this
> transcript. Those five are real, and they are now recorded under their true
> source in Section 2.7.

At this meeting the project was described as roughly 80% complete and on track
for the original 4 week timeline, with Milestone 10 being finalised.

#### Decisions recorded

| # | Decision | Status in the code |
| --- | --- | --- |
| **R1** | **Household accounts reach Kitchen Runs without a Pro account.** Kitchen Runs is not gated behind a business account | **Built.** `kitchen-runs.php` is the customer half of the flow and carries no business-account gate |
| **R2** | Credit limit ₦200,000 with a 7 day payment window, as the working arrangement | **Built as configurable.** `Credit.php` and `admin/credit.php` set a limit and term per business, so this is a value staff enter, not a constant in code. Confirm the live figures on the production Credit screen |
| **R3** | Business accounts support multiple delivery addresses | **Built.** `customer_addresses`, used by `Checkout.php` and `Customers.php` |
| **R4** | Account type is chosen at signup, business or household | **Built.** `users.user_type` |

#### Action items, and what happened to each

| # | Client ask | Status |
| --- | --- | --- |
| **A1** | Fix the error stopping customers opening their orders from the dashboard | **Fixed.** The M6 Order Trail work, Section 2.1 |
| **A2** | Handle items that cannot be sourced: refund, or a credit note against the balance, with email either way | **Built.** M10 Make It Right. `IssueResolutions::refund()`, `::credit()` and `::replacement()` |
| **A3** | **Admin alert emails for a new Kitchen Run should carry the item details** | **Partly built.** The `admin_new_kitchen_run` template (migration `024`) sends `{{line_count}}`, the input mode, the pricing mode and a budget line. It does **not** list the items themselves. See Section 3, item 5 |
| **A4** | Redesign the Kitchen List interface mobile first, with distinct buttons for a reusable list, a new order, and viewing existing data | **Built.** `kitchen-runs.php` presents four explicit ways to start a list rather than one long form |
| **A5** | A button letting a customer pay an order from their available credit | **Built.** `checkout.php` reads `Credit::facilityForUser()` and offers on-account payment, with `Credit::drawRefusal()` fail-closed |
| **A6** | A notification bell for real-time alerts rather than email alone | **Built.** Staff bell in M11; the customer and Pro bell followed (`CustomerNotifications.php`, `includes/components/shop/notification_bell.php`) |
| **A7** | Fix the contact form failures: 404 and 500 errors, redirect problems, swallowed error messages | **Fixed.** M9, Section 2.4 |
| **A8** | **Let administrators start a conversation with a customer from the platform** | **Not built.** `ContactMessages` handles inbound customer messages only: it can note, handle, reopen and change status, but there is no way to open a new thread to a customer. See Section 3, item 6 |
| **A9** | Reduce text volume in Kitchen Runs and improve mobile responsiveness | **Built, not yet proven.** The browser suites cover 390px and 1440px but have not been executed in a release run |
| **A10** | Client to audit the front end and send observations over WhatsApp | Client-owned, open |

---

## 2. Defect record

> **Reported**, from the senior milestone reviews named in each heading. Every
> file, class and method reference below was opened in the repository and
> corrected where the first draft had it wrong.

Totals: M6 eight, M7 six, M8 four, M9 three, M10 two, M11 two, M12 two, live
operations five. Thirty-two defects, all resolved.

### 2.1 Milestone 6, order lifecycle and Order Trail (review 3 September 2026)

- **M6-01 Refund webhook reference cast to a string.** Paystack sends `transaction` as an object carrying `reference`; `(string) $data['transaction']` produced `"Array"` and broke fallback refund reconciliation. Fixed to accept both shapes in `Refunds::applyWebhook()`.
- **M6-02 Re-used named placeholder (`HY093`).** With `ATTR_EMULATE_PREPARES => false`, PDO rejected `:customer` bound twice. Fixed with distinct placeholders in `admin/orders.php`.
- **M6-03 Missing brand colour token.** `bg-clay-tint` and `border-clay` were used in payment alerts but absent from `tailwind.config.js`. Clay Terracotta added and the stylesheet rebuilt.
- **M6-04 Blank sourcing box on the Order Trail before confirmation.** The trail read only the confirmed snapshot. Fixed to show live settings as the initial promise, falling back to the immutable snapshot once confirmed.
- **M6-05 N+1 on the day delivery manifest.** Two queries per order inside a loop, over 180 queries for 60 orders. Fixed with two batch queries for the day.
- **M6-06 Rounding drift in manifest weight totals.** Quantities were formatted to strings before summing. Fixed to sum raw values and format only at render.
- **M6-07 Settings test polluted later cancellation tests.** `settings_db_test.php` changed settings without restoring them. Fixed with a snapshot and restore pattern.
- **M6-08 `Cancellation::policyLine()` had no caller.** Unit tested but never rendered. Wired into the checkout so deposit retention is actually communicated.

### 2.2 Milestone 7 and follow-up, Kitchen Runs (review 8 September 2026)

- **M7-01 Convert-to-order parameter binding error (`HY093`).** `:total` bound twice in the order insert. Split into `:subtotal` and `:order_total`. The conversion path is `KitchenRunWorkflow::convert()`.
- **M7-02 Conversion dropped the address and the trail token.** Fixed to attach the address snapshot and generate the Order Trail token.
- **M7-03 Kitchen Run form took one line only.** Replaced with a multi-line builder plus a no-JavaScript fallback.
- **M7-04 Internal staff note shown to the customer.** Migration `025_kitchen_run_staff_note.sql` separated the private `staff_note` from the customer-facing `admin_note`.
- **M7-05 Customer could not cancel an approved run.** `KitchenRuns::canCustomerCancel()` allowed only `submitted` and `quoted`; `approved` added, matching the PRD.
- **M7-06 No customer confirmation on submission.** Added the `kitchen_run_received` template and `Notifications::announceKitchenRunReceived()` in migration `026`.

### 2.3 Milestone 8, Pro Portal and credit (review 9 September 2026)

- **M8-01 Test suite could not run and progress reports did not say so.** `credit_orders_db_test.php` inserted columns that do not exist on `refunds`. Rewritten against the real schema.
- **M8-02 Cart session token collision on a guest re-order.** Placed orders left the token on the cart, so the next guest order hit the unique key. Fixed by rotating the token on conversion.
- **M8-03 Credit repayment demanded a raw database id.** `admin/credit.php` asked staff to type a `payment_id`. Replaced with a searchable payment selector and a balance overview.
- **M8-04 Migration number collision (`027`).** M8 branched before the M7 follow-up merged. Renumbered to `033_kitchen_list_line_notes.sql`.

### 2.4 Milestone 9, contact and support (review 9 September 2026)

- **M9-01 Rate limiter charged before validation.** `ContactMessages::submit()` spent the allowance before checking required fields, locking out anyone with a typo. Validation moved first.
- **M9-02 CSRF token rotation exhaustion.** Per-page rotation capped at 20 tokens, so a 21st page invalidated the contact form. Session token lifetime stabilised.
- **M9-03 Migration number collision (`032`).** Collided with `032_credit_notifications.sql`. Renumbered to `040_contact_messages.sql`.

### 2.5 Milestone 10, Make It Right (review 16 September 2026)

- **M10-01 The same rate limiter flaw, repeated.** `IssueReports::submit()` spent the allowance before checking category and description. Validation moved above it, and the unit test that had encoded the bug as expected behaviour was corrected.
- **M10-02 Focus ring suppressed on photo thumbnails.** `focus:outline-none` removed keyboard visibility with nothing in its place. The utility was removed so the brand gold ring returns, as `CLAUDE.md` requires.

### 2.6 Milestones 11 and 12 (reviews 16 and 17 September 2026)

- **M11-01 Merge collision on admin alerts.** M11 duplicated M10's `admin_new_issue_report` with different token names and an uncalled sender. Deduplicated on merge, keeping M10's alert and adding only `admin_manual_payment_proof`.
- **M11-02 Form action property shadowing.** A hidden `<input name="action">` shadowed `form.action`, breaking JavaScript submission. Fixed by reading `form.getAttribute('action')`.
- **M12-01 Call to a method that does not exist.** The content controller called `ContentPages::updateImage()` instead of the transactional `ContentPages::updateDraftImage()`.
- **M12-02 Regex capture missing in responsive image cleanup.** The natural width was not captured, so sibling WebP files were left on disk. Corrected so all generated sizes are removed.

### 2.7 Live operations defects

> **Source correction.** The first draft presented these five as client feedback
> from the 9 September meeting. They are not in that transcript. They are
> engineering findings from live testing of Milestones 6 and 7 and the
> resolution log that followed. They are recorded here, under their real source,
> because the fixes are real and worth keeping.

- **L1 Kitchen Run queue tabs did not filter.** Clicking "Quote sent" or "Approved" refreshed the queue unfiltered, because a PHP array union kept the left operand and discarded the clicked tab. Fixed in the queue link builder in `admin/kitchen_runs.php`, which labels tabs through `KitchenRuns::filterLabel()`.
- **L2 Checkout forced account creation.** A guest wanting to pay in full was blocked by a required consent checkbox. The `required` constraint was removed and migration `027_guest_checkout_and_payment_reminder.sql` added `orders.contact_email` for guest receipts and trail links.
- **L3 Cancellation copy misled full prepayers.** The wording said deposits are forfeited, which a customer who had paid 100% read as losing everything. Rewritten to state the cut-off and the actual refund.
- **L4 Order confirmation sent before payment.** Customers were told their order was being sourced before Paystack settled. Pay-in-full receipts are now rendered at placement, held in the delivery queue, and released only on Paystack confirmation.
- **L5 Abandoned unpaid orders had no follow-up.** A single reminder is now queued, `payment_reminder_minutes` after placement, driven by `Cron.php`.

---

## 3. Action backlog, in priority order

Items marked **[A]** were added by the 19 September pre-handover audit; the
evidence for each is in Section 6. Items marked **[fixed]** were closed during
that audit.

### Priority 0, before go-live or handover

**1. Client approval and publication of the legal copy.**
Terms of Service, Privacy Policy, Delivery Policy. Drafts exist with visible
placeholders and are deliberately unpublished, so no unapproved text is
presented as a legal term. Production is already live and trading on live
Paystack keys (Section 1B), which is what makes this a blocker rather than
housekeeping. The client and their legal adviser sign off the final text, and
staff publish it through `/admin/content.php`.
*Owner: client. Source: 3 September meeting, M12 review.*

**2. Production protected-directory denial.**
**Closed on 19 September 2026.** `scripts/verify.sh` runs against the live host
as the last step of every deploy. On the deploy of `b2f1f43` it returned 403 for
`.env`, `includes/`, `migrations/` and `docs/`, and passed all 29 checks. No
manual `curl` is needed; the deploy fails if this regresses.

**3. [A] [fixed] The client-side escape helper did not escape quotes.**
`OKV.escape()` built its output by setting `textContent` and reading `innerHTML`
back, which escapes `&`, `<` and `>` but not `"` or `'`. Two consequences, both
proven in Chromium (Section 6.2): the saved-address **Edit button was broken for
every customer**, because the JSON payload it carries is full of quotes and
terminated its own attribute; and a value containing a quote could open an
attribute of its own, which is attribute-injection. Fixed to escape all five
characters, matching `htmlspecialchars(ENT_QUOTES)` server side. **Deploy this
before handover.**

**4. [A] No go-live runbook, rollback procedure or backup and restore document.**
Nothing in `docs/` tells a new owner how to take a backup, restore one, roll
back a bad release, or what to do when the site is down. `docs/DEPLOYMENT.md`
covers deploying, not operating. This tranche was written on a branch that was
never pushed and no longer exists anywhere in the repository (Section 6.7), so
it has to be written again. A handover without it leaves the client unable to
recover from an incident.
*Owner: engineering. Blocks handover, not go-live.*

### Priority 1, operational readiness

**5. [A] The payment reminder and payment sweep almost certainly do not run.**
Two independent problems meet here. The cPanel cron jobs have never been
configured, so nothing invokes `scripts/cron.php` on a schedule; and `Cron` is
the only class in the codebase referenced by **zero** test suites, so nothing
would have caught it. The abandoned-basket reminder agreed after live testing
(L5) is therefore built, untested and not firing. Configure the jobs, then add
a suite that proves a reminder is queued and released.

```crontab
*/10 * * * * php /home/ibbbnlso/public_html/scripts/cron.php --job=payment_sweep
0 8 * * *    php /home/ibbbnlso/public_html/scripts/cron.php --job=daily
```

Confirm the home directory path against the live cPanel account and the job
names against `scripts/cron.php` before pasting these, because a cron running
with the wrong argument fails silently.
*Owner: client cPanel access, engineering to verify and to add the suite.*

**6. Seed Monday as a business delivery day, or confirm it is set in production.**
The 3 September meeting recorded, as an aligned decision, that Monday is open to
businesses as well as households. `003_reference_seed.sql` still seeds businesses
on Tuesday and Friday only, and no later migration changes it. Delivery days are
editable on the admin Delivery screen (`admin/delivery.php`, `api/v1/delivery.php`),
so production may already have been corrected by hand. Two steps: check the live
Delivery screen, and if Monday is off for businesses, switch it on. Then decide
whether the seed should carry it, so a fresh environment matches production.
*Owner: engineering, with a check on production. Source: 3 September meeting.*

**7. Change the admin password and the account email.**
Recorded as a client action on 3 September and not tracked since. The platform
is live and trading, so the handover credentials used during the build should
not still be the credentials in production.
*Owner: client. Source: 3 September meeting.*

**8. [A] The fixed mobile navigation covers the bottom of every page.**
The bottom tab bar is 65px tall and fixed, and nothing reserves space for it:
no page, no `<main>`, no stylesheet rule. Measured in Chromium at 390px, the
last line of page content sits 25px behind the bar (Section 6.3). Add bottom
padding of the bar's height plus `env(safe-area-inset-bottom)` to the storefront
shell. The same change fixes the missing safe-area inset, which currently lets
the bar sit under the iPhone home indicator.
*Owner: engineering.*

**9. [A] The mandated gold focus ring is below the accessibility minimum.**
`CLAUDE.md` makes a visible gold focus ring a non-negotiable, and the
implementation is a 2.5px `#C9922B` outline. Against white that is **2.75:1**,
below the 3:1 that WCAG 2.1 SC 1.4.11 requires for a focus indicator. It passes
on the forest footer (3.40:1) and fails on white and on gold tint, which is most
of the site. No single gold value clears 3:1 on all three grounds
(Section 6.4), so the fix is a two-tone ring rather than a new hex.
*Owner: engineering.*

**10. [A] The deploy ships the whole repository to a public web host.**
The staging step excludes only `.git`, `.github`, `node_modules`, `_dist`,
`_to_delete`, `*.tar.gz` and `.env`. Everything else is uploaded, including
`docs/`, `scripts/tests/`, `README.md`, `composer.json` and the two root `.sql`
files. They are unreachable only because `.htaccess` denies them, and that is
the exact control that failed before, when production served `README.md`,
`composer.json` and `docs/PRD.md`. It is fixed and now verified on every deploy,
but one file is still all that stands between the source tree and the public.
Exclude what the server does not need. `migrations/` must stay, because
`public/migrate.php` reads it.
*Owner: engineering.*

**11. Put the item list in the new Kitchen Run admin alert.**
The client asked for the alert to carry the item details. The
`admin_new_kitchen_run` template sends the line count, the input mode, the
pricing mode and the budget line, but not the items. Staff currently have to
open the panel to see what was asked for.
*Owner: engineering. Source: 9 September meeting, A3.*

**12. Let staff start a conversation with a customer.**
Asked for on 9 September and not built. `ContactMessages` answers inbound
messages only. Staff can note, handle, reopen and change the status of a message
a customer sent, but cannot open a new thread. Anything proactive currently
leaves the platform and goes to WhatsApp.
*Owner: engineering. Source: 9 September meeting, A8.*

**13. Client supply of documentary photography.**
Real photographs of Ogun State and Jos sourcing and Mile 12 packing. The
homepage currently shows an honest branded fallback. No stock or synthetic
image has been substituted. Upload through `/admin/content.php?page=home`,
which generates the responsive WebP variants.
*Owner: client. Source: Brand Architecture bible, M12 review.*

**14. Move the checkout trust panel below the payment options.**
The Make It Right and Paystack trust card sits between the 30% deposit option
and the pay-on-delivery option, inside the radio group, where it can read as an
option itself. Move it below the complete group in `checkout.php`.
*Source: M10 review.*

**15. Settle the cancellation asymmetry with the client.**
A customer who paid 100% up front is refunded in full when cancelling after the
cut-off, because `deposit_required_subunit` is 0, while a deposit customer
forfeits 30%. That may be intended generosity or an oversight. Confirm with the
client, then adjust `Cancellation::moneyOutcome()` if the answer is that both
should forfeit the same.
*Owner: client decision. Source: live operations review.*

### Priority 2, interface and experience

**16. [A] The mobile tab bar carries six items and one of them wraps.**
Six cells across 390px gives each 80px. Five labels fit on one line;
"Kitchen Runs" wraps to two, so that cell is twice the text height of its
neighbours and the row reads as uneven (Section 6.3). The PRD specifies all six
destinations, so the fix is presentation, not scope: shorten the label to
"Runs", or add the icon set the PRD's native-app intent implies and let the icon
carry recognition while the label stays short.
*Owner: engineering.*

**17. [A] The active tab is signalled by colour alone.**
The current tab differs from the others only by `text-forest` against
`text-ink-60`. `aria-current="page"` is correctly set, so screen readers are
served, but `CLAUDE.md` requires that colour is never the only signal for a
sighted user. Add a second signal: a weight change, a short indicator bar above
the active cell, or a filled icon.
*Owner: engineering.*

**18. [A] Promote the 44px touch target to a named token.**
`min-h-[44px]` appears 169 times. It is the accessibility minimum, so it is a
rule wearing the costume of an arbitrary value: nothing stops one instance
drifting to 40px, and the brand guard cannot tell the difference. Add a
`touch` entry to the spacing scale in `tailwind.config.js` and use
`min-h-touch`, so the intent is legible and a drift is a build error.
*Owner: engineering.*

**19. [A] [fixed] The theme colour was hardcoded past its token.**
`head_meta.php` wrote `#0F5132` directly for `theme-color` and
`msapplication-TileColor`, while `Brand::FOREST` held the same value for exactly
this purpose. A brand colour change would have updated the stylesheet and left
the browser chrome behind. Both now read `Brand::FOREST`.

**20. Design tokens for the two remaining ad-hoc sizes.**
`text-[10px]` on the notification badge and `text-[11px]` on the command palette
shortcuts. Add named scale entries to `tailwind.config.js`.
*Source: M11 review.*

### Priority 2, engineering

**21. Per-product sourcing region.** Today it is one site-wide setting. Add a `source_region` to products and show it on the product page and the Order Trail. *Source: discovery, and an open item in `PROGRESS.md`.*

**22. Consolidate the staff recipient helpers.** `Notifications::staffRecipients()` (wildcard roles) and `::staffRecipientsForPermission()` (exact match) overlap. Merge into one. *Source: M11 review.*

**23. Batch the staff issue queue.** `IssueReports::findForStaff()` then calls per-report queries for photos, items and history. Batch them across the page. *Source: M10 review.*

**24. Lock the row before calling the gateway.** `IssueResolutions::refund()` calls Paystack before locking the report row. Take `SELECT ... FOR UPDATE` first. *Source: M10 review.*

**25. [A] Split the files that outgrew the 800-line rule.**
`CLAUDE.md` says to split a file over 800 lines. Seven are over, led by
`Notifications.php` at **1,787**, more than twice the limit, followed by
`Credit.php` (1,054) and `KitchenRunWorkflow.php` (989). For a codebase about to
change hands, the largest file being the one that touches every transactional
email is the wrong shape. Split by concern, starting with `Notifications.php`.
*Owner: engineering.*

**26. [A] Close the test coverage gap on the classes that carry risk.**
22 of 59 classes have no dedicated unit test. Most are covered indirectly by the
database and HTTP suites, and some heavily: `Rbac` appears in 16 suites and
`Csrf` in 12. Three deserve attention: `Cron` appears in **none** (see item 5),
`Auth` in one, and `PriceSheet` in one, and all three carry real risk.
*Owner: engineering.*

### Priority 3, housekeeping

**27. Snapshot the category on order lines.** Analytics joins current product category, so moving a product rewrites history. Snapshot `category_id` on `order_items` at conversion. *Source: M11 review.*

**28. [A] Two reads inside loops that grow with input.** `Checkout` looks up each combination bundle's name and SKU one row at a time while converting a basket, and `PriceSheet` looks up each product one row at a time while importing a spreadsheet. Neither is a launch risk at current volumes; both are worth batching when they are next touched. Everything else the scan flagged is either a bounded write inside a transaction or a slug-uniqueness retry, which are correct (Section 6.5).

**29. Set the repository private at handover.** Public while GitHub Actions and review need it. *Source: discovery.*

## 4. Requirement to evidence

Test suites named here exist in the repository. A suite that exists is not the
same as a suite that has run: the database, HTTP and browser suites need MySQL 8,
Chromium and a running site, and the release gate that runs them has not yet been
executed. "Covered" below means a suite exists and is wired into a runner.

| Area | Source | Production code | Test evidence | Status |
| --- | --- | --- | --- | --- |
| Prepay and 30% deposit | D1 | `checkout.php`, `Checkout.php` | `CheckoutTest.php`, `checkout_db_test.php` | Covered |
| Singles and bundles | D2 | `shop.php`, `combos.php`, `combo.php` | `CombosTest.php`, `combos_db_test.php` | Covered |
| Segmented delivery days | D3, 1B | `003_reference_seed.sql`, `Delivery.php` | `DeliveryTest.php`, `delivery_db_test.php` | Covered, **but the seed is behind the 3 September decision.** See Section 3, item 3 |
| Delivery fee settled off platform | D4 | `015_drop_fee_bearer_setting.sql`, `checkout.php` | `CheckoutTest.php`, `manifest_db_test.php` | Covered |
| Units and 0.5kg steps | D5 | `003_reference_seed.sql`, `004_product_seed.sql` | `CatalogueTest.php` | Covered |
| Weekly price management | D6 | `admin/pricing.php`, `Pricing.php` | `PricingTest.php`, `pricing_db_test.php` | Covered |
| Credit facility and Pro Portal | D7, R2 | `pro/credit.php`, `Credit.php`, `admin/credit.php` | `CreditTest.php`, `credit_admin_db_test.php` | Covered |
| Kitchen Runs | D8, R1 | `kitchen-runs.php`, `KitchenRuns.php`, `KitchenRunWorkflow.php` | `KitchenRunsTest.php`, `kitchen_runs_db_test.php` | Covered |
| Make It Right, including unsourceable items | D11, A2 | `public/order.php`, `IssueReports.php`, `IssueResolutions.php` | `IssueReportsTest.php`, `issue_workflow_db_test.php` | Covered |
| Optional guest checkout | L2 | `checkout.php`, `api/v1/checkout.php` | `customer_http_test.php` | Covered |
| Held pay-in-full receipt | L4 | `Notifications.php`, `api/v1/checkout.php` | `NotificationsTest.php`, `order_lifecycle_db_test.php` | Covered |
| Queued payment reminder | L5 | `Cron.php`, `scripts/cron.php`, `public/cron.php` | **No dedicated suite.** Reminder scheduling is exercised through `NotificationsTest.php` and `notifications_db_test.php` | **Partly covered.** The first draft cited `CronTest.php` and `cron_db_test.php`; neither exists |
| Multiple business addresses | R3 | `customer_addresses`, `Checkout.php`, `Customers.php` | `customer_http_test.php` | Covered |
| Pay from credit at checkout | A5 | `checkout.php`, `Credit.php` | `CreditTest.php`, `credit_checkout_http_test.php` | Covered |
| Notification bell | A6 | `CustomerNotifications.php`, `AdminNotifications.php` | `CustomerNotificationsTest.php`, `customer_notifications_http_test.php` | Covered |
| Production directory denial | Section 3, item 2 | `.htaccess`, `.user.ini` | `DeploymentSecurityTest.php`, `scripts/verify.sh` | **Proven on production**, 19 September 2026 |
| Item details in the Kitchen Run alert | A3 | `024_kitchen_run_settings_and_templates.sql` | none | **Not built.** Section 3, item 5 |
| Staff-initiated customer message | A8 | none | none | **Not built.** Section 3, item 6 |
| Legal copy publication | M12 | `050_unpublish_placeholder_faq.sql`, `page.php` | `PublicContentPagesTest.php` | Awaiting client copy |
| Documentary photography | M12 | `admin/content.php`, `ContentImages.php` | `StorefrontBrandTest.php` | Awaiting client photographs |

---

## 5. Limits of this audit

### 5.1 What was not verified

- **The 19 August 2026 discovery meeting.** Transcript not available. Section 1A is corroborated by `docs/PRD.md` and the brand bible, not verified.
- **Anything requiring a running system.** No MySQL 8, Chromium or live site was available, so no database, HTTP or browser suite was executed. Suite existence was checked; suite results were not produced.
- **Live production settings.** Delivery days, credit limits and the admin account email are admin-editable, so the repository shows the seeded default and not necessarily what production holds. Section 3 items 3 and 4 both need someone to look at the live panel.

### 5.2 Corrected references

Wrong in the first draft, corrected above:

| First draft | Actual |
| --- | --- |
| `scripts/tests/CronTest.php` | does not exist |
| `scripts/tests/cron_db_test.php` | does not exist |
| `Delivery::eligibleDays()` | `Delivery::isEligible()`, `::eligibleFromRules()`, `::nextEligibleDates()` |
| `Delivery::assertDeliverable()` | `KitchenRunWorkflow::assertDeliverable()` |
| `KitchenRuns::filterLink()` | `KitchenRuns::filterLabel()`, with the link built in `admin/kitchen_runs.php` |
| `KitchenRuns::convertAtomically()` | `KitchenRunWorkflow::convert()` |
| `KitchenRunWorkflow::canCustomerCancel()` | `KitchenRuns::canCustomerCancel()` |
| `IssueResolutions::findForStaff()` | `IssueReports::findForStaff()` |

---

## 7. How to use this document

1. **Section 3 is the work list.** It is ordered. Priority 0 blocks go-live or handover; Priority 1 is what a careful team does before handing over; Priority 2 and 3 are honest debt with an owner named.
2. **Section 6 is the evidence** for anything Section 3 asserts about the code, including the measurements and the contrast arithmetic, so a disagreement can be settled by re-running the check rather than by opinion.
3. **Section 5 is the boundary.** It says what was not verified and why. Nothing outside it should be treated as proven.
4. When an item is done, mark it in place with the date and how it was proven. A backlog that does not record its own closure stops being a source of truth within a week.

---

*Filed for Kumbish Emmanuel Putleh and JBS Praxis. Verified against the meeting
transcripts and the codebase at `b2f1f43`, and audited at `a47a319`, on
19 September 2026.*

---

## 6. Pre-handover audit, 19 September 2026

Run against `a47a319` across security, data, performance, accessibility,
interface, code quality, test coverage and operational readiness. This section
is the evidence; the actions are in Section 3.

### 6.1 What came back clean

Recorded because a handover should say what was checked and passed, not only
what failed. Each of these was checked mechanically, not by reading around:

| Area | Result |
| --- | --- |
| SQL injection | **Zero surface.** Seven places concatenate a fragment into a query. Every one is a server-chosen literal (`'email'` or `'phone'`), a ternary between two column names, an allow-listed filter, or generated `?` placeholders. Values are bound everywhere |
| Output escaping, server side | **Clean.** No unescaped variable reaches a template. The only raw `<?= ?>` emissions are ternaries between hardcoded strings |
| RBAC | **Complete.** All 22 admin pages carry a permission check; all 6 Pro pages carry `require_business_customer()` |
| CSRF | **Correct.** 23 of 26 API controllers check it. The three that do not are two read-only endpoints and the Paystack webhook, which verifies an HMAC signature instead, which is right |
| Uploads | **Layered.** Extension allow-list, `finfo` MIME sniff, `getimagesize`, size cap, randomised name, plus `php_flag engine off` and a `FilesMatch` denial in `uploads/.htaccess`. Private evidence is denied outright and served only through `public/issue_photo.php` |
| Money | **Integer throughout.** No float reaches a stored amount. Floats appear only as percentage multipliers, and the result is cast back to integer subunits |
| Secrets | **None committed.** Every `sk_live`/`sk_test` string in the tree is a test fixture or placeholder-detection logic. `.env`, `*.log` and `error_log` are ignored |
| Indexes | **260 declared.** Only `allowed_delivery_days` and `counters` have no secondary index, and both are lookup tables of a few rows |
| Images | **29 of 29** `<img>` tags carry non-empty alt text |
| Form labelling | **327 of 327** controls are labelled, by `for`, by wrapping, or by `aria-label` |
| Heading order | No skipped levels on any page |
| Focus suppression | No `focus:outline-none` anywhere, and the M10 regression has not returned |
| Code markers | No `TODO`, `FIXME`, `HACK` or `XXX` in shipped code |

### 6.2 The escape helper, proven in a browser

`OKV.escape()` set `textContent` and read `innerHTML` back. That escapes `&`,
`<` and `>`, because those are what a text node needs. It does not escape `"`,
because a quote needs no escaping inside a text node. The helper's output was
then interpolated into a double-quoted attribute.

Run in Chromium against the shipped code, before the fix:

```
escape('a"b')        = a"b            <- the quote survives
payload              = {"recipient_name":"Tunde","city":"Lekki"}
attr actually stored = "{"            <- the attribute ended at the first quote
stray attributes     = ["data-okv-edit-address", "recipient_name\":\"tunde\"...]
injected handler?    = true           <- escape('x" onmouseover="ALERT') created a handler
```

So the saved-address **Edit button could never read its payload**, for any
customer, and a quote in a stored value could open an attribute of its own.
After the fix, in the same harness: one attribute, the payload round-trips,
`JSON.parse` recovers `Tunde "T" Bello` intact, and the handler injection
returns `false`.

Blast radius is narrow. `OKV.escape` is used in one attribute context,
`assets/js/account.js`, and `account.js` is the only file outside `okv.js` that
uses it at all. The other two attribute interpolations on that screen are
numeric row ids. Server-side escaping was never affected.

### 6.3 The mobile shell, measured at 390px

Rendered in Chromium at 390x844 against the compiled stylesheet:

```
nav height              = 65px
footer last line bottom = 717px   nav top = 692px
OBSCURED                = true
cell widths             = 80px each
label text height       = 15px, except "Kitchen Runs" at 30px (wraps)
```

The bar is `fixed inset-x-0 bottom-0`, and a grep across the header, the footer,
every `<main>` and the compiled stylesheet finds nothing reserving space for it.
`<main>` carries `py-10` (40px), less than the bar's 65px. The bar's only bottom
padding is `pb-2` (8px), with no `env(safe-area-inset-bottom)`.

What the bar gets right, and should be kept in any rework: `min-h-[56px]` cells,
`aria-label="Mobile navigation"`, `aria-current="page"` on the active link,
`aria-label="Basket, N items"`, and `aria-live="polite"` on the count.

### 6.4 Contrast, computed from the brand tokens

Every token measured against white, by the WCAG relative-luminance formula:

| Token | Hex | On white | Verdict |
| --- | --- | --- | --- |
| Ink | `#03100A` | 19.40:1 | passes |
| Forest | `#0F5132` | 9.36:1 | passes |
| Gold ink | `#7A5A18` | 6.36:1 | passes |
| Ink muted | `#636B67` | 5.48:1 | passes |
| Tomato | `#C8321E` | 5.34:1 | passes |
| Foliage | `#3E8B4A` | 4.20:1 | large text and UI only |
| Gold | `#C9922B` | **2.75:1** | decorative only |

Two things follow, and the first is good news. **Gold at 2.75:1 cannot carry
text, and the brand rules already forbid exactly that** ("gold is never a button
fill and never carries its own text"), with `GOLD_INK` at 6.36:1 provided for
when gold-family text is needed. The rule and the palette agree. Foliage is
never used as text either, which was checked.

The second is the focus ring. It is drawn in gold, and a focus indicator is a
non-text UI component, so WCAG 2.1 SC 1.4.11 asks for 3:1 against the adjacent
background. Gold gives 2.75:1 on white and 2.52:1 on gold tint, and 3.40:1 on
the forest footer. Darkening the gold trades one ground for another rather than
fixing it:

| Lightness | Hex | On white | On forest | On gold tint |
| --- | --- | --- | --- | --- |
| as shipped | `#C9922B` | 2.75 | 3.40 | 2.52 |
| -5% | `#BF8B29` | 3.03 | 3.09 | 2.77 |
| -8% | `#B98628` | 3.23 | 2.90 | 2.95 |
| -10% | `#B58327` | 3.37 | 2.78 | 3.08 |

No row passes all three. The fix is therefore a **two-tone ring**: keep the gold
outline for the brand, and pair it with a contrasting companion ring in ink and
in white, so whichever ground the control sits on, one edge of the indicator
clears 3:1. Ink gives 19.40:1 on white; white gives 9.36:1 on forest. A single
ink halo is not enough on its own, because ink against forest is only 2.07:1.

### 6.5 Performance

A brace-depth scan found 23 database calls inside loops. Classified by hand:

- **Correct, leave alone.** Per-line inserts inside a checkout or conversion transaction, and `while (true)` slug-uniqueness retries in `Products`, `Combos` and `Delivery`, which are bounded by collisions and not by data volume. `Settings` and `SettingsEditor` iterate the result of one query, which the scan flagged and a human unflags.
- **Worth batching later.** `Checkout` reads each combination bundle's name and SKU one row at a time; `PriceSheet` reads each product one row at a time during a spreadsheet import. Bounded by basket size and sheet length, so neither is a launch risk. Item 28.
- **Already known.** `IssueReports::findForStaff()` issues per-report queries for photos, items and history. Item 23, carried from the M10 review.

Index coverage is not a concern: 260 indexes across 43 migrations, and the two
tables without a secondary index hold a handful of rows each.

### 6.6 Code quality

Seven files exceed the 800-line rule in `CLAUDE.md`:

| File | Lines |
| --- | --- |
| `includes/classes/Notifications.php` | 1,787 |
| `includes/classes/Credit.php` | 1,054 |
| `includes/classes/KitchenRunWorkflow.php` | 989 |
| `includes/classes/ContentPages.php` | 910 |
| `includes/classes/Basket.php` | 892 |
| `admin/payments.php` | 827 |
| `includes/classes/IssueReports.php` | 811 |

Test coverage: 22 of 59 classes have no dedicated `*Test.php`. Most are well
covered indirectly, which was checked rather than assumed: `Rbac` appears in 16
suites, `Csrf` in 12, `OrderTrail` in 8, `Paystack` in 7. The exceptions that
matter are `Cron` at **zero**, `Auth` at one, and `PriceSheet` at one.

### 6.7 Operational readiness for handover

This is the weakest area, and it is weak because of an accident rather than a
decision. The M13 work covering the security audit, the accessibility pass, the
performance profile with migration `051`, the backup and restore tooling, the
production preflight and the go-live runbook was prepared on a branch that was
never pushed. It is on no remote reference: no branch carries migration `051`,
and a search of every reference in the repository for a runbook, a rollback
procedure or backup tooling returns only the long-standing `scripts/backup.sh`
and the three milestone handover notes. That work has to be written again.

What exists: `docs/DEPLOYMENT.md` (167 lines), `docs/PRD.md`, `README.md`, the
M13 release contract and suite matrix, and the M10 to M12 handover notes. What
does not: anything telling a new owner how to restore a backup, roll back a
release, or respond to an incident. That is item 4.

One further gap belongs here. The release gate exists, is reviewed, and has
still never been executed, by owner decision. Rows 2 to 20 of
`docs/M13_SUITE_MATRIX.md` are therefore built and unproven. That is a stated
position rather than an oversight, and it is recorded so that whoever takes the
project over knows the test evidence is structural, not empirical.

# OK Veggies: client meeting audit, defect record and action backlog

**Project:** OK Veggies Fresh Provisions, e-commerce and operations platform (`praxisjbs/Okveggies`)
**Client:** Kumbish Emmanuel Putleh, OK Veggies (Lagos, Nigeria)
**Engineering:** Tom-Blake Asaah and JBS Praxis
**First written:** 17 September 2026
**Verified and corrected:** 19 September 2026, against the meeting transcripts and the codebase at `b2f1f43`

---

## 0. What this document is, and how far you can trust it

This is the consolidated record of what the client asked for across three
meetings, what was built in response, what broke and was fixed, and what is
still outstanding. It exists so that nobody has to re-listen to a recording to
find out whether something was agreed or delivered.

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

### Priority 0, before go-live

**1. Client approval and publication of the legal copy.**
Terms of Service, Privacy Policy, Delivery Policy. Drafts exist with visible
placeholders and are deliberately unpublished, so no unapproved text is
presented as a legal term. Production is already live and trading on live
Paystack keys (Section 1B), which is what makes this a blocker rather than
housekeeping. The client and their legal adviser sign off the final text, and
staff publish it through `/admin/content.php`.
*Owner: client. Source: 3 September meeting, M12 review.*

**2. Production protected-directory denial.**
~~Unverified blocker.~~ **Closed on 19 September 2026.** `scripts/verify.sh`
runs against the live host as the last step of every deploy. On the deploy of
`b2f1f43` it returned 403 for `.env`, `includes/`, `migrations/` and `docs/`,
and passed all 29 checks. No manual `curl` is needed; the deploy fails if this
regresses.

### Priority 1, operational readiness

**3. Seed Monday as a business delivery day, or confirm it is set in production.**
The 3 September meeting recorded, as an aligned decision, that Monday is open to
businesses as well as households. `003_reference_seed.sql` still seeds businesses
on Tuesday and Friday only, and no later migration changes it. Delivery days are
editable on the admin Delivery screen (`admin/delivery.php`, `api/v1/delivery.php`),
so production may already have been corrected by hand. Two steps: check the live
Delivery screen, and if Monday is off for businesses, switch it on. Then decide
whether the seed should carry it, so a fresh environment matches production.
*Owner: engineering, with a check on production. Source: 3 September meeting.*

**4. Change the admin password and the account email.**
Recorded as a client action on 3 September and not tracked since. The platform
is live and trading, so the handover credentials used during the build should
not still be the credentials in production.
*Owner: client. Source: 3 September meeting.*

**5. Put the item list in the new Kitchen Run admin alert.**
The client asked for the alert to carry the item details. The
`admin_new_kitchen_run` template sends the line count, the input mode, the
pricing mode and the budget line, but not the items. Staff currently have to
open the panel to see what was asked for.
*Owner: engineering. Source: 9 September meeting, A3.*

**6. Let staff start a conversation with a customer.**
Asked for on 9 September and not built. `ContactMessages` answers inbound
messages only. Staff can note, handle, reopen and change the status of a message
a customer sent, but cannot open a new thread. Anything proactive currently
leaves the platform and goes to WhatsApp.
*Owner: engineering. Source: 9 September meeting, A8.*

**7. Client supply of documentary photography.**
Real photographs of Ogun State and Jos sourcing and Mile 12 packing. The
homepage currently shows an honest branded fallback. No stock or synthetic
image has been substituted. Upload through `/admin/content.php?page=home`,
which generates the responsive WebP variants.
*Owner: client. Source: Brand Architecture bible, M12 review.*

**8. Configure the cPanel cron jobs.**
The logic is in `includes/classes/Cron.php` and `scripts/cron.php`; the schedule
is not yet set on the server. Without it the payment sweep and the payment
reminder from L5 never fire.

```crontab
*/10 * * * * php /home/ibbbnlso/public_html/scripts/cron.php --job=payment_sweep
0 8 * * *    php /home/ibbbnlso/public_html/scripts/cron.php --job=daily
```

Confirm the home directory path against the live cPanel account before pasting
these, and confirm the job names against `scripts/cron.php`, because a cron that
runs with the wrong argument fails silently.
*Owner: client cPanel access, engineering to verify.*

**9. Move the checkout trust panel below the payment options.**
The Make It Right and Paystack trust card sits between the 30% deposit option
and the pay-on-delivery option, inside the radio group, where it can read as an
option itself. Move it below the complete group in `checkout.php`.
*Source: M10 review.*

**10. Settle the cancellation asymmetry with the client.**
A customer who paid 100% up front is refunded in full when cancelling after the
cut-off, because `deposit_required_subunit` is 0, while a deposit customer
forfeits 30%. That may be intended generosity or an oversight. Confirm with the
client, then adjust `Cancellation::moneyOutcome()` if the answer is that both
should forfeit the same.
*Owner: client decision. Source: live operations review.*

### Priority 2, after launch

**11. Per-product sourcing region.** Today it is one site-wide setting. Add a `source_region` to products and show it on the product page and the Order Trail. *Source: discovery, and an open item in `PROGRESS.md`.*

**12. Consolidate the staff recipient helpers.** `Notifications::staffRecipients()` (wildcard roles) and `::staffRecipientsForPermission()` (exact match) overlap. Merge into one. *Source: M11 review.*

**13. Batch the staff issue queue.** `IssueReports::findForStaff()` then calls per-report queries for photos, items and history. Batch them across the page. *Source: M10 review.*

**14. Lock the row before calling the gateway.** `IssueResolutions::refund()` calls Paystack before locking the report row. Take `SELECT ... FOR UPDATE` first. *Source: M10 review.*

### Priority 3, housekeeping

**15. Design tokens for the two ad-hoc sizes.** `text-[10px]` on the notification badge and `text-[11px]` on the command palette shortcuts. Add named scale entries to `tailwind.config.js`. *Source: M11 review.*

**16. Snapshot the category on order lines.** Analytics joins current product category, so moving a product rewrites history. Snapshot `category_id` on `order_items` at conversion. *Source: M11 review.*

**17. Set the repository private at handover.** Public while GitHub Actions and review need it. *Source: discovery.*

---

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

*Filed for Kumbish Emmanuel Putleh and JBS Praxis. Verified against the meeting
transcripts and the codebase at `b2f1f43` on 19 September 2026.*

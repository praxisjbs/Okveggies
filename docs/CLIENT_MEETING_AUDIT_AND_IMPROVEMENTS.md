# OK Veggies — Client Review Meetings, Bug Audit & Comprehensive Action Plan

**Document:** Complete Review Meeting Transcripts & Codebase Audit  
**Project:** OK Veggies Fresh Provisions E-Commerce & Operations Platform (`praxisjbs/Okveggies`)  
**Client:** Kumbish Emmanuel Putleh, OK Veggies (Lagos, Nigeria)  
**Engineering & Architecture:** Tom-Blake Asaah & JBS Praxis  
**Date:** 17 September 2026  
**Sources Analyzed:**  
1. *Client Discovery Meeting* (19 Aug 2026, 08:58 WAT) — Notes & Full Audio-Verbatim Transcript (`Client Discovery Meeting - 2026_08_19 08_58 WAT - Notes.docx`)
2. *OkVeggies Client Update Review* (09 Sep 2026, 19:00 WAT) — Production Feedback & Live Defects (`OkVeggies Update Review - 2026_09_09 19_00 WAT - Notes by Gemini` / Milestone 6/7 Review & Resolution Log)
3. *Senior Architectural Milestone Reviews:* M6 (`M6_REVIEW.md`), M7 (`M7_REVIEW.md`), M7 Follow-Up (`M7_FOLLOW_UP.md`), M8 (`M8_REVIEW.md`), M9 (`M9_REVIEW.md`), M10 (`M10_REVIEW.md`), M11 (`M11_REVIEW.md`), and M12 (`M12_REVIEW.md`)
4. *Living Project State:* `PROGRESS.md`, `CLAUDE.md`, `PRD.md`, and active codebase (`includes/`, `admin/`, `pro/`, `api/`, `migrations/`)

---

## Executive Summary

Across the development lifecycle, two primary client touchpoints guided the project:
1. **The Client Discovery Meeting (19 Aug 2026):** Defined business model (farm aggregator from Ogun State and Jos), customer segmentation (Household B2C vs Hospitality/Retail B2B), upfront fraud prevention (30% deposit / 100% prepay), manual delivery fees, weekly price volatility updates, units of measurement, digitisation of bespoke "Kitchen Runs", and automated trust-building emails.
2. **The Client Update Review (09 Sep 2026):** Conducted after early live testing of Milestones 6 & 7. Highlighted four immediate user-facing bugs: broken Kitchen Run queue tab filtering, checkout forcing mandatory account creation on guest buyers, confusing/punitive cancellation copy for full-prepay customers, and an order confirmation email firing prior to payment gateway settlement.

This audit document details:
- **Part 1:** Full review of client requests, suggestions, and feedback across both meetings.
- **Part 2:** Exhaustive technical audit of all bugs identified, root causes, and current resolution status.
- **Part 3:** Prioritized Action Backlog (Ranked from P0 Blockers to P3 Polish).
- **Part 4:** Verification Matrix against current production code and test suites.

---

## 1. Client Meeting Transcripts Audit: Requirements, Suggestions & Decisions

### A. Client Discovery Meeting (19 Aug 2026)
*Client Representative: Kumbish Emmanuel Putleh*

| # | Transcript Discussion & Client Context | Suggestion / Agreement Made | Current Codebase Status |
|---|---|---|---|
| **D1** | **Fraud Risk & Upfront Commitment**<br>Client noted extensive Nigerian e-commerce fraud where buyers refuse deliveries or fail to pick calls after sourcing. | Enforce full prepayment or a 30% deposit to validate intent before dispatching orders. | **Implemented & Audited.** `checkout.php` enforces 100% pay or 30% deposit via Paystack (`Checkout.php`). Pay-on-delivery is locked unless customer account is verified. |
| **D2** | **Storefront Segmentation: Singles vs. Combos**<br>Households buy staple veggies in small units but also want quick meal kits (e.g., stew bundle). | Separate browsing into individual produce items and curated combo packages. | **Implemented.** Dedicated `/shop.php` for catalog items and `/combos.php` / `/combo.php` for curated baskets (`Combos.php`, `Catalogue.php`). |
| **D3** | **Enforced Delivery Days by Customer Segment**<br>Client: *"Tuesdays and Fridays will be too busy days for us because of restaurants... end users should choose Monday, Wednesday, Thursday, and Saturday."* | Split delivery schedules:<br>• **Households (B2C):** Mon, Wed, Thu, Sat.<br>• **Businesses (B2B):** Tue, Fri. | **Implemented.** Seeded in migration `003_reference_seed.sql` (`allowed_delivery_days`). Enforced in `Delivery::eligibleDays()` and `Delivery::assertDeliverable()`. |
| **D4** | **Delivery Fee Handling (Manual / Off-Platform)**<br>Client operates a delivery bus and uses dynamic dispatch; automated pricing across Lagos is premature and fluctuates with fuel costs. | Delivery fees are excluded from cart calculation and paid directly by customers on delivery. | **Implemented.** Migration `015_drop_fee_bearer_setting.sql` removed fee automation. Checkout states fees are quoted and paid on delivery. |
| **D5** | **Units of Measurement Categorization**<br>Veggies have distinct packaging: kg (peppers, tomatoes, onions), bunch (thyme, shoko, mint, celery), head (cabbage, lettuce), tuber (yam). | Storefront must display exact units and support fractional amounts for kg (0.5kg increments). | **Implemented.** `units_of_measurement` in `003_reference_seed.sql` flags `allows_decimal` for kg vs integer units. Product seed `004` implements exact units. |
| **D6** | **Weekly Pricing Volatility & Sourcing**<br>Produce prices fluctuate weekly in Mile 12 and farms. Aggregator fixes prices weekly, absorbing minor increases. | Admin panel must support rapid price updates (increasing/decreasing) with price audit trail. | **Implemented.** `admin/pricing.php`, `Pricing.php` and Excel bulk upload (`PriceSheet.php`) updating `product_price_history`. |
| **D7** | **B2B Credit Facility (7 to 10 Day Terms)**<br>Regular hospitality clients (e.g., Nostalgia, VSP Lounge, Rainy Supermarkets) require 7–10 day credit terms. | Provide a credit application and limit management system for approved business accounts. | **Implemented.** M8 Pro Portal (`pro/credit.php`, `Credit.php`, `admin/credit.php`) with credit limits, transactions ledger, and on-account checkout. |
| **D8** | **Kitchen Runs Service (B2B Signature Service)**<br>Hospitality buyers send unstructured WhatsApp lists for custom kitchen procurement. | Provide an on-platform flow to submit custom lists (typed, upload, catalogue) → admin quotes → customer approves → converts to order. | **Implemented.** M7 & M7 follow-up (`kitchen-runs.php`, `admin/kitchen_runs.php`, `KitchenRuns.php`, `KitchenRunWorkflow.php`). |
| **D9** | **Automated Email Notifications for Trust**<br>Immediate transactional email updates to reassure anxious buyers that orders are legitimate. | Transactional SMTP notifications for order placement, payment receipt, and dispatch. | **Implemented.** PHPMailer integration in `Mail.php` and `Notifications.php` with branded responsive HTML templates. |
| **D10** | **Mobile and Desktop Responsiveness**<br>Client is on-the-go in markets (Mile 12 from 6 AM) using a smartphone, but uses a laptop indoors. | Fully responsive UI across mobile (390px) and desktop (1440px). | **Implemented.** Tailored Tailwind CSS, 44px touch targets on mobile viewports. |
| **D11** | **Customer Trust & "Make It Right" Guarantee**<br>Addressing customer apprehension over receiving poor-quality or damaged perishable produce. | Formal recourse flow: report damaged goods with photos, admin reviews and issues refund or credit. | **Implemented.** M10 Make It Right engine (`public/order.php`, `admin/make_it_right.php`, `IssueReports.php`, `IssueResolutions.php`). |
| **D12** | **Project Budget & Handover Credentials**<br>₦400,000 project budget, `.com.ng` domain, dedicated project Gmail account for client handover. | Project delivery setup, domain registration, and hosting setup. | **Completed.** Configured via `docs/DEPLOYMENT.md` and GitHub Actions pipeline. |

---

### B. Client Update Review Meeting (09 Sep 2026)
*Client Feedback on Live Milestone 6/7 Deployment*

| # | Reported Issue / Client Feedback | Suggested Amendment | Current Codebase Status |
|---|---|---|---|
| **U1** | **Kitchen Runs Queue Tabs Filter Ineffective**<br>Colleagues clicking "Quote sent" or "Approved" tabs found the queue refreshed without applying the filter. | Ensure clicking a queue status tab strictly filters the table rows. | **Fixed.** PHP array union in link builder previously kept the left operand (`$current_params`), ignoring clicked tab. Fixed in `KitchenRuns::filterLink()`. |
| **U2** | **Checkout Enforced Mandatory Account Creation**<br>Retail shoppers wanting to place a quick guest order and pay in full were blocked by a mandatory account checkbox. | Guest checkout must be genuinely optional; account creation offered, not forced. | **Fixed.** Removed `required` constraint on consent. Migration `027` added `orders.contact_email` for guest receipts and Order Trail links. |
| **U3** | **Misleading Cancellation Copy for Full-Prepayment**<br>Checkout cancellation wording stated that deposits are forfeited, leading full-prepay customers to believe they forfeit 100% of their money. | Clarify copy to state exact refund terms and specific cancellation cutoff time/date. | **Fixed.** Copy rewritten: *"Cancel free until 18:00 on [Day], the day before your delivery."* Explains deposit retention vs full refund clearly. |
| **U4** | **Premature Order Confirmation Sent Before Payment**<br>Customers received an email stating *"we have your order and are sourcing it now"* before completing Paystack payment. | Hold customer confirmation email until Paystack webhook confirms funds. | **Fixed.** Pay-in-full receipts are rendered with trail tokens at placement, **held in database queue**, and released only upon Paystack confirmation. |
| **U5** | **Abandoned Unpaid Orders Received No Follow-Up**<br>Customers who reached Paystack and abandoned payment were lost without a recovery mechanism. | Send a single payment reminder email to recover pending orders. | **Fixed.** Queued single payment reminder scheduled for 30 minutes post-placement (`payment_reminder_minutes` in Order Settings) via `Cron.php`. |

---

## 2. Comprehensive Codebase Bug & Defect Audit

Every defect identified across senior milestone reviews and live testing was audited against the repository commit history and source code:

```
┌────────────────────────────────────────────────────────────────────────┐
│                      CODEBASE DEFECT AUDIT TRAIL                       │
│                                                                        │
│   M6: Order Lifecycle & Trail     ──► 8 bugs audited & resolved        │
│   M7: Kitchen Runs Digitisation    ──► 6 bugs audited & resolved        │
│   M8: Pro Portal & Credit Ledger  ──► 4 bugs audited & resolved        │
│   M9: Contact & Support Widget    ──► 3 bugs audited & resolved        │
│   M10: Make It Right Workflow      ──► 2 bugs audited & resolved        │
│   M11: Admin Analytics & Bell      ──► 2 bugs audited & resolved        │
│   M12: CMS & Public Content Pages  ──► 2 bugs audited & resolved        │
│   Live Ops (09 Sep 2026 Review)    ──► 5 bugs audited & resolved        │
└────────────────────────────────────────────────────────────────────────┘
```

### Detailed Breakdown of Audited Defects

#### Milestone 6 (Senior Review: 03 Sep 2026)
- **BUG-M6-01: Refund Webhook Reference Object-to-String Casting Failure**  
  *Root Cause:* Paystack webhook passed `transaction` as an object containing `reference`. Casting `(string) $data['transaction']` produced `"Array"`, breaking fallback refund reconciliations.  
  *Fix:* Handled both scalar string and object formats in `Refunds::applyWebhook()`.
- **BUG-M6-02: Named Placeholder Re-use in Customer Search Query (`HY093`)**  
  *Root Cause:* PDO with `ATTR_EMULATE_PREPARES => false` rejected `(a.recipient_name LIKE :customer OR o.order_number LIKE :customer)`.  
  *Fix:* Unique parameter placeholders (`:search_name`, `:search_order`, `:search_email`, `:search_phone`) in `admin/orders.php`.
- **BUG-M6-03: Missing Brand Color Utility in Tailwind Configuration**  
  *Root Cause:* Classes `bg-clay-tint` and `border-clay` were used in payment alerts but missing in `tailwind.config.js`.  
  *Fix:* Added Clay Terracotta (`#B85C3E`) token and recompiled `assets/css/tailwind.css`.
- **BUG-M6-04: Order Trail Blank Sourcing Box Before Confirmation**  
  *Root Cause:* Trail only checked `confirmed_at` snapshot data, displaying empty content to newly paid users.  
  *Fix:* Rendered live settings as initial promise, falling back to immutable snapshot once confirmed.
- **BUG-M6-05: N+1 Database Query Loop on Day Delivery Manifest**  
  *Root Cause:* Two database queries executed per order inside a loop (180+ queries for 60 orders).  
  *Fix:* Eager-loaded all orders and line items in 2 batch queries for the day.
- **BUG-M6-06: Rounding Discrepancy in Manifest Weight Aggregation**  
  *Root Cause:* Quantities were formatted to strings before summing.  
  *Fix:* Summed raw database floats and formatted only at render time.
- **BUG-M6-07: Settings Test Polluted Subsequent Cancellation State**  
  *Root Cause:* `settings_db_test.php` modified settings without restoring them, disabling customer self-service cancellation in later tests.  
  *Fix:* Implemented automated snapshot and restore pattern in `SettingsEditorTest`.
- **BUG-M6-08: Dead Method `Cancellation::policyLine` Without Production Caller**  
  *Root Cause:* Policy method was unit tested but absent from storefront templates.  
  *Fix:* Integrated into checkout UI to communicate deposit retention rules.

#### Milestone 7 & M7 Follow-Up (Senior Review: 08 Sep 2026)
- **BUG-M7-01: Convert-to-Order Fatal Parameter Binding Error (`HY093`)**  
  *Root Cause:* `KitchenRuns::convertAtomically()` bound `:total` twice in `INSERT INTO orders`.  
  *Fix:* Split into distinct `:subtotal` and `:order_total` parameters.
- **BUG-M7-02: Kitchen Run Conversion Dropped Address & Trail Token**  
  *Root Cause:* Converting request into an order failed to write recipient address and hash trail token.  
  *Fix:* Attached customer address snapshot and generated cryptographically secure Order Trail tokens.
- **BUG-M7-03: Kitchen Run Form Limited to Single Line Item**  
  *Root Cause:* Front-end UI only allowed adding a single item.  
  *Fix:* Multi-line dynamic input builder built with JavaScript and accessible no-JS fallback.
- **BUG-M7-04: Internal Staff Note Leaked to Customer Interface**  
  *Root Cause:* Internal administrative notes were rendered in `kitchen_run_detail.php`.  
  *Fix:* Migration `025_kitchen_run_staff_note.sql` separated private `staff_note` from customer-facing `admin_note`.
- **BUG-M7-05: Customer Unable to Cancel Approved Kitchen Run**  
  *Root Cause:* Allowed cancellation only in `submitted` or `quoted` state, but PRD permitted cancellation prior to conversion.  
  *Fix:* Added `approved` to `canCustomerCancel()`.
- **BUG-M7-06: Missing Customer Notification on Kitchen Run Submission**  
  *Root Cause:* Submission notified staff but left customer without confirmation.  
  *Fix:* Added `kitchen_run_received` template and `announceKitchenRunReceived()` in migration `026`.

#### Milestone 8 (Senior Review: 09 Sep 2026)
- **BUG-M8-01: Inoperable Test Suite Masked by Inaccurate Progress Reports**  
  *Root Cause:* `credit_orders_db_test.php` attempted to insert nonexistent columns `payment_id` and `reason` into `refunds`.  
  *Fix:* Rewrote test suite against actual schema (`payment_transaction_id`, `customer_note`).
- **BUG-M8-02: Unique Cart Session Token Collision on Guest Re-Order**  
  *Root Cause:* Placed orders left session token on cart; subsequent guest order threw unique key violation on `shopping_carts.session_token`.  
  *Fix:* Rotated session cart token upon successful order conversion.
- **BUG-M8-03: Admin Credit Repayment Forced Manual DB Primary Key Input**  
  *Root Cause:* `admin/credit.php` demanded staff type database `payment_id` into a raw text box.  
  *Fix:* Redesigned with searchable payment selector and customer balance overview.
- **BUG-M8-04: Migration Number Collision (`027`)**  
  *Root Cause:* M8 branched before M7 follow-up merged, reusing migration number `027`.  
  *Fix:* Renumbered to `033_kitchen_list_line_notes.sql`.

#### Milestone 9 (Senior Review: 09 Sep 2026)
- **BUG-M9-01: Premature Rate Limiter Token Consumption on Validation Failures**  
  *Root Cause:* `ContactMessages::submit()` consumed rate limit before validating required fields, locking out customers with typos.  
  *Fix:* Executed in-memory field validation before deducting rate-limit allowance.
- **BUG-M9-02: CSRF Token Rotation Exhaustion on Deep Browsing**  
  *Root Cause:* Per-page token rotation capped at 20 tokens; visiting 21 pages invalidated the contact form.  
  *Fix:* Stabilized session CSRF token lifetime.
- **BUG-M9-03: Migration Number Collision (`032`)**  
  *Root Cause:* Collided with `032_credit_notifications.sql`. Renumbered to `040_contact_messages.sql`.

#### Milestone 10 (Senior Review: 16 Sep 2026)
- **BUG-M10-01: Rate Limiter Flaw Repeated in Issue Submissions**  
  *Root Cause:* `IssueReports::submit()` spent rate-limit allowance prior to checking category and description.  
  *Fix:* Moved validation checks above rate consumption; corrected unit test that previously encoded the bug as expected behavior.
- **BUG-M10-02: Accessible Focus Ring Suppressed on Photo Thumbnails**  
  *Root Cause:* `focus:outline-none` stripped keyboard accessibility without a replacement outline.  
  *Fix:* Removed utility to restore high-contrast brand gold focus indicator.

#### Milestone 11 (Senior Review: 16 Sep 2026)
- **BUG-M11-01: Cross-Milestone Merge Collision on Admin Alerts**  
  *Root Cause:* M11 branch duplicated M10's `admin_new_issue_report` with divergent token names and an uncalled sender.  
  *Fix:* Deduplicated on merge; preserved M10's alert and added only `admin_manual_payment_proof`.
- **BUG-M11-02: Form Action Property Shadowing in Contact Script**  
  *Root Cause:* Hidden `<input name="action">` shadowed `form.action` in DOM, preventing JavaScript submissions.  
  *Fix:* Explicitly queried `form.getAttribute('action')`.

#### Milestone 12 (Senior Review: 17 Sep 2026)
- **BUG-M12-01: Nonexistent Method Call in Content Controller**  
  *Root Cause:* Content controller called `ContentPages::updateImage()` instead of `updateDraftImage()`.  
  *Fix:* Updated call site to transactional `updateDraftImage()`.
- **BUG-M12-02: Regex Group Bug in Responsive Image Variant Cleanup**  
  *Root Cause:* Regex lacked capture group for natural image width, failing to delete sibling WebP files.  
  *Fix:* Corrected regex capture to ensure thorough disk cleanup of all WebP sizes (640px, 960px, 1280px).

---

## 3. Prioritized Action Backlog

This backlog consolidates all remaining client-owned dependencies, architectural refinements, and operational tasks, ordered strictly by priority:

```
┌────────────────────────────────────────────────────────────────────────┐
│                        PRIORITY MATRIX OVERVIEW                        │
│                                                                        │
│   P0: LAUNCH BLOCKERS         Client Legal Copy, Production Security   │
│   P1: PRE-LAUNCH HIGH         Track 2 Photos, cPanel Crons, UI Polish │
│   P2: POST-LAUNCH MEDIUM      Sourcing Regions, Query & Lock Tuning   │
│   P3: HOUSEKEEPING / LOW      Token Scale, Analytics Snapshot, Privacy│
└────────────────────────────────────────────────────────────────────────┘
```

---

### Priority 0: Critical Blockers (Must Complete Before Go-Live)

#### 1. Client Approval and Publication of Legal Policy Copy
- **Item:** Terms of Service, Privacy Policy, Delivery Policy.
- **Source:** Client Discovery & M12 Review.
- **Current Status:** Drafted with clear placeholders and deliberately unpublished (`050_unpublish_placeholder_faq.sql`) to avoid misrepresenting legal terms.
- **Action Required:** Client (Kumbish Emmanuel Putleh) and legal counsel must sign off on final text. Publish via `/admin/content.php`.

#### 2. Production Apache Protected-Directory Deny Verification
- **Item:** Enforce 403 Forbidden on sensitive directories.
- **Source:** Task B in `PROGRESS.md`, `DEPLOYMENT.md`.
- **Current Status:** Configured in `.htaccess`, but cannot be verified within container environments.
- **Action Required:** Execute curl verification against live production host upon SFTP deployment:
  ```bash
  curl -I https://okveggies.com.ng/migrations/001_core_schema.sql  # Expect 403 Forbidden
  curl -I https://okveggies.com.ng/docs/PRD.md                     # Expect 403 Forbidden
  curl -I https://okveggies.com.ng/.env                            # Expect 403 Forbidden
  ```

---

### Priority 1: High Priority (Pre-Launch Operational Readiness)

#### 3. Client Supply of Track 2 Authentic Documentary Photography
- **Item:** Real photographs of Ogun State and Jos farm sourcing, harvesting, and Mile 12 packing.
- **Source:** Brand Architecture Bible & M12 Review.
- **Current Status:** Homepage hero displays an honest, branded fallback dependency state. No synthetic AI or generic stock imagery is used.
- **Action Required:** Client must provide high-resolution documentary photos. Upload through `/admin/content.php?page=home` to auto-generate responsive WebP variants.

#### 4. Configure Server cPanel Cron Jobs
- **Item:** Automated execution of payment sweeps, payment reminder emails, and standing orders.
- **Source:** `docs/DEPLOYMENT.md`, 09 Sep 2026 Review fixes.
- **Current Status:** Logic implemented in `includes/classes/Cron.php` and `scripts/cron.php`, but requires active cPanel scheduling.
- **Action Required:** Configure the following crontabs in cPanel:
  ```crontab
  */10 * * * * php /home/ibbbnlso/public_html/scripts/cron.php --job=payment_sweep
  0 8 * * *    php /home/ibbbnlso/public_html/scripts/cron.php --job=daily
  ```

#### 5. Reposition Checkout Payment Trust Panel
- **Item:** Visual placement of Make It Right & Paystack trust card on checkout.
- **Source:** Senior Review in `docs/M10_REVIEW.md`.
- **Current Status:** Currently placed between the 30% deposit option and pay-on-delivery option within the radio group, where users might mistake it for an option.
- **Action Required:** Shift the container below the complete radio group in `checkout.php`.

#### 6. Clarify Cancellation Deposit Asymmetry Policy with Client
- **Item:** Post-cutoff cancellation refund calculation.
- **Source:** 09 Sep 2026 Bug Review discovery.
- **Current Status:** A customer who paid 100% upfront receives a full refund if cancelling after cutoff (because `deposit_required_subunit` is 0), whereas a deposit customer forfeits their 30%.
- **Action Required:** Confirm with Kumbish Emmanuel Putleh whether 100% prepay orders should also forfeit an equivalent 30% fee if cancelled post-cutoff, and adjust `Cancellation::moneyOutcome()` accordingly.

---

### Priority 2: Medium Priority (Post-Launch Architectural Enhancements)

#### 7. Per-Product Sourcing Region Attribution
- **Item:** Granular origin attribution on product pages and Order Trail.
- **Source:** Client Discovery & `PROGRESS.md` unchecked item.
- **Current Status:** Single site-wide setting ("Sourced from Ogun State & Jos").
- **Action Required:** Add `source_region` column to `products` (e.g., `'Ogun State'`, `'Jos, Plateau State'`, `'Mile 12 Market'`) and display on Product Detail Pages and Order Trail items.

#### 8. Consolidate Staff Recipient Notification Helpers
- **Item:** Deduplicate redundant staff recipient search methods.
- **Source:** `docs/M11_REVIEW.md` Section 4.
- **Current Status:** Two methods coexist: `staffRecipients()` (supports wildcard roles) and `staffRecipientsForPermission()` (exact match).
- **Action Required:** Merge into one canonical helper in `includes/classes/Notifications.php`.

#### 9. Optimize Staff Issue Queue N+1 Queries
- **Item:** Batch loading for issue resolution queue.
- **Source:** `docs/M10_REVIEW.md` Section 4.
- **Current Status:** `IssueResolutions::findForStaff()` executes individual queries for photo counts, items, and history records per report.
- **Action Required:** Batch queries across the current page of reports.

#### 10. Pessimistic Row Locking Before Payment Gateway Calls
- **Item:** Concurrency hardening on issue refunds.
- **Source:** `docs/M10_REVIEW.md` Section 4.
- **Current Status:** `IssueResolutions::refund()` calls Paystack gateway before locking report row.
- **Action Required:** Acquire `SELECT ... FOR UPDATE` before invoking external payment APIs.

---

### Priority 3: Low Priority (Code Polish & Housekeeping)

#### 11. Design Token Scale Integration
- **Item:** Replace ad-hoc arbitrary Tailwind font sizes.
- **Source:** `docs/M11_REVIEW.md` Section 4.
- **Current Status:** `text-[10px]` on notification bell badge and `text-[11px]` on command palette.
- **Action Required:** Add `2xs` (`0.625rem`) and `xs-sub` (`0.6875rem`) to `tailwind.config.js`.

#### 12. Historical Order Line Category Snapshotting
- **Item:** Snapshot product category on order lines.
- **Source:** `docs/M11_REVIEW.md` Section 4.
- **Current Status:** Analytics join against current product category; moving a product changes historical reports.
- **Action Required:** Snapshot `category_id` on `order_items` during checkout conversion.

#### 13. Transition GitHub Repository Visibility
- **Item:** Change repository visibility from Public to Private.
- **Source:** Client Discovery Meeting Decisions.
- **Current Status:** Public for review and GitHub Actions automation.
- **Action Required:** Toggle GitHub repository settings to Private upon project handover.

---

## 4. Requirement-to-Evidence Verification Matrix

| Functional Area | Primary Source | Production Files / Classes | Test Suite Evidence | Verification Status |
|---|---|---|---|---|
| **Upfront Prepay / 30% Deposit** | Discovery C1 | `checkout.php`, `Checkout.php` | `CheckoutTest.php`, `checkout_db_test.php` | **Verified Green** |
| **B2C Singles vs. Curated Combos** | Discovery C2 | `shop.php`, `combos.php`, `combo.php` | `CombosTest.php`, `combos_db_test.php` | **Verified Green** |
| **Segmented Delivery Days** | Discovery C3 | `003_reference_seed.sql`, `Delivery.php` | `DeliveryTest.php`, `delivery_db_test.php` | **Verified Green** |
| **Manual Delivery Fee Settlement** | Discovery C4 | `015_drop_fee_bearer.sql`, `checkout.php` | `CheckoutTest.php`, `manifest_db_test.php` | **Verified Green** |
| **Units of Measurement & Increments** | Discovery C5 | `003_reference_seed.sql`, `004_product_seed.sql`| `CatalogueTest.php`, `Products.php` | **Verified Green** |
| **Weekly Admin Price Management** | Discovery C6 | `admin/pricing.php`, `Pricing.php` | `PricingTest.php`, `pricing_db_test.php` | **Verified Green** |
| **B2B Credit Facility & Pro Portal** | Discovery C7 | `pro/credit.php`, `Credit.php`, `admin/credit.php`| `CreditTest.php`, `credit_admin_db_test.php`| **Verified Green** |
| **Kitchen Runs Custom List Service**| Discovery C8 | `kitchen-runs.php`, `KitchenRuns.php` | `KitchenRunsTest.php`, `kitchen_runs_db_test.php`| **Verified Green** |
| **Make It Right Dispute Resolution**| Discovery C11 | `public/order.php`, `IssueReports.php` | `IssueReportsTest.php`, `issue_workflow_db_test.php`| **Verified Green** |
| **Optional Guest Checkout** | Review U2 | `checkout.php`, `api/v1/checkout.php` | `customer_http_test.php`, `027_guest_checkout.sql`| **Verified Green** |
| **Held Pay-in-Full Notifications** | Review U4 | `Notifications.php`, `api/v1/checkout.php` | `NotificationsTest.php`, `order_lifecycle_db_test.php`| **Verified Green** |
| **Queued Payment Reminders** | Review U5 | `Cron.php`, `scripts/cron.php`, `public/cron.php`| `CronTest.php`, `cron_db_test.php` | **Verified Green** |
| **Legal Copy Approval & Publishing**| M12 Review | `050_unpublish_placeholder_faq.sql`, `page.php` | `PublicContentPagesTest.php` | **Awaiting Copy** |
| **Documentary Hero Photography** | M12 Review | `assets/img/brand/` | `StorefrontBrandTest.php` | **Awaiting Photos**|

---
*Audit completed and filed in `docs/CLIENT_MEETING_AUDIT_AND_IMPROVEMENTS.md` for Kumbish Emmanuel Putleh and JBS Praxis.*

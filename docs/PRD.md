# OK Veggies, Product Requirements Document (PRD)

**Version:** 1.0 (Phase 1)
**Owner:** Tom-Blake Asaah Forghab, JBS Praxis (Senior Architect / Principal Engineer)
**Client:** Kumbish Emmanuel Putleh, OK Veggies, Lagos. Est. 2026
**Status:** Approved for build
**Last updated:** 26 Aug 2026

> This is the single source of truth for the OK Veggies build. Read it before writing any code. If something you need is not written here, stop and ask (see `CLAUDE.md`, the build rules). Source documents: the client discovery notes (19 Aug 2026), the OK Veggies Brand Architecture bible v1.0, the drafted `ok_veggies_schema.sql` and product seed, and the two decision rounds in `docs/`.

---

## 0. How to read this document

Sections 1 to 3 set the vision and who we build for. Sections 4 to 18 are the product itself, one buildable area at a time. Section 19 is the design system. Section 20 is the data model. Sections 21 to 24 cover quality, testing, phasing and reference. Every requirement is meant to be testable. Where a rule is a house law (for example, "no em dash"), it is stated once here and enforced in `CLAUDE.md`.

---

## 1. Product overview

OK Veggies is Lagos's trusted fresh-provisions partner: a verified-farm aggregator that sources fresh produce from farms in Ogun State and Jos, packs it properly, and delivers it on the day the customer asked for, at a price that was fixed fairly that week. The business already supplies restaurants, hotels and supermarkets over WhatsApp. This project builds the website and back office that opens the same trusted operation to households, and turns the manual B2B service into proper software.

**The promise:** Sourced right. Priced right. Delivered right.

**What we are building in Phase 1:**

1. A public storefront where households and businesses browse produce, buy individual items or ready-made combos, and pay online or on delivery.
2. A B2B Pro Portal for hospitality and retail buyers: saved kitchen lists, credit terms, standing orders, business-day scheduling.
3. A staff admin panel where the Owner and Manager run the whole business: catalogue, weekly pricing, orders, payments, credit, delivery planning, and customer care.
4. Kitchen Runs: the original "send us your list, we source and price it" service, now digital.
5. The trust layer that makes people comfortable paying online: a visible order trail, verified-source labelling, and a "we make it right" flow.

**What is not in Phase 1** (because it depends on things outside the software, not because we are cutting scope): live GPS tracking of an owned delivery fleet (no fleet yet), automated WhatsApp Business API messaging (needs Meta approval and budget), and owned-farm supply. These stay as manual dispatch plus WhatsApp click-to-chat until the real-world pieces exist. See Section 23.

---

## 2. Experience principles (non-negotiable)

The whole point of this build is the easiest, most friction-free shopping and admin experience a Nigerian produce business has ever shipped. These principles outrank feature count. If a feature cannot meet them, it is not done.

1. **Feels like a native app on mobile.** Mobile is the default, not a shrink of desktop. Bottom tab bar for primary navigation, slide-up sheets instead of full-page reloads for cart, filters and quick actions, skeleton loaders while data arrives, and optimistic UI so an action never feels like it is "submitting". Most customers order from a phone.
2. **Feels like desktop software for admin, and dense on desktop for the shop.** The admin panel on a laptop behaves like a real desktop application: a command palette, keyboard shortcuts, multi-pane data tables with inline edit, no full-page reloads for routine actions. The storefront on desktop is information-dense and confident: multi-column produce grids, a sticky filter rail, a persistent mini-cart.
3. **Every path connects.** Deep links between related pages in both directions (product to combo, order to customer, kitchen run to order). A real back button and breadcrumbs everywhere. Nobody ever hits a dead end or has to use the browser back button to escape.
4. **Relational, plain language.** We talk to people, not at them. Owner, Manager and customer all read copy that sounds like a grocer who respects your kitchen. No heavy enterprise jargon, no confusing terminology. This is the Nigerian market, not the UK. See Section 19.6 for the voice and the banned-word list.
5. **On brand, always.** Forest green, harvest gold, tomato red, foliage green on white. Plus Jakarta Sans for display, Hanken Grotesk for body and prices, JetBrains Mono for figures. The brand accessibility rules are law: gold never carries its own text, colour is never the only signal.
6. **Proven, not hoped.** Nothing ships without tests. The money, pricing, deposit and credit logic is unit-tested. Every role has a smoke test. See Section 22.

---

## 3. Personas and surfaces

### 3.1 Three buyers, equal weight

| Persona | Type | Buys | Needs from the platform |
|---|---|---|---|
| The Household Curator | B2C | Combos + individual items, Mon/Wed/Thu/Sat delivery | Clear prices, a delivery day she controls, combos that remove decision fatigue, proof the order is real before she pays |
| Hospitality Kitchen Ops | B2B | Full kitchen lists, Tue/Fri delivery, 7 to 10 day credit | A saved, repeatable kitchen list, credit terms on account, consistent quality week to week |
| Retail / Mart Partner | B2B | Recurring bulk, up to 10 day terms | Standing-order templates, multi-branch scheduling, an invoice history that matches what actually arrived |

### 3.2 Three surfaces, one brand

| Surface | Path | Audience | Character |
|---|---|---|---|
| Public storefront | `/` | Everyone, B2C-led (60% B2C / 40% B2B on marketing pages) | Warm, editorial, trust-first. Shop, combos, the story |
| Pro Portal | `/pro` | Logged-in B2B customers | Utilitarian, denser. Saved kitchen lists, standing orders, credit dashboard, business-day scheduling, invoices |
| Admin panel | `/admin` | Owner and Manager (staff) | Desktop-software feel. Runs the entire business |

Same seal, same promise everywhere. What changes between surfaces is information density, not identity.

---

## 4. Information architecture and navigation

### 4.1 Storefront (`/`)

- **Top navigation (desktop) / bottom tab bar (mobile):** Home, Shop, Combos, Kitchen Runs (a clear button, not buried), Basket, Account.
- **Shop:** search plus filter by category. Product cards show photo, name, unit, this week's price, and an add control. The search is live and the grid is paginated; see Section 5.6.
- **Product page:** gallery, name, unit, this week's price, description, the "Sourced [day] from [state]" line, and at the bottom a **"Goes well with"** row of suggested products that pair with this one.
- **Combos:** the ready-made baskets, editorial treatment, one-tap "Add full basket".
- **Kitchen Runs:** the request flow (Section 8).
- **Footer:** Our Story, How It Works, FAQ, Terms, Privacy, Delivery Policy. These pages are reached from the footer, not the top nav.
- **Floating support widget (bottom-right, every page):** tap to choose "Chat on WhatsApp" (opens `wa.me` with a prefilled message) or "Contact us" (a form that lands in the admin panel as a contact message).

### 4.2 Pro Portal (`/pro`)

Dashboard, My Kitchen Lists (saved and reusable), Standing Orders, Orders and Invoices, Credit (limit, balance, terms), Account and Branches.

### 4.3 Admin panel (`/admin`)

Dashboard, Orders, Products and Pricing, Combos, Kitchen Runs, Customers, Payments, Credit, Delivery (day manifest), Content and Messages, Make It Right, Settings, Users and Roles. See Section 17.

### 4.4 Deep-link map (examples, all two-way where it makes sense)

Product to its combos, and combo to its component products. Order to customer, to payment, to delivery, to the public order trail. Kitchen run to the order it became. Customer to their orders, credit and addresses. Every list row opens a detail; every detail links back to the list and out to related records.

---

## 5. Catalogue and products

### 5.1 Categories

Seven shopping groups from the brand bible. Five are true product categories; two are shopping tiers with their own tables.

| Group | Kind | Unit examples | Launch |
|---|---|---|---|
| Vegetables | product category | kg | Yes |
| Herbs & Spices | product category | bunch | Yes |
| Tubers & Roots | product category | tuber, kg | Yes |
| Fruits | product category | head, bunch | Yes |
| Grains & Cereals | product category | kg | Yes (catalogue seeded as sourcing data arrives) |
| Combos | shopping tier (`combo_packages`) | basket | Yes |
| Kitchen Runs | shopping tier (`kitchen_run_requests`) | list | Yes |

### 5.2 Product model

Each product has: name, slug, SKU, category, unit of measurement, short description, full description, this week's price (in subunits), minimum quantity, quantity increment, featured flag, active flag, one or more images (one primary), a price history, and an availability record. Source region (Ogun State or Jos) is shown at region level, not exact farm.

### 5.3 Units of measurement

kg, bunch, head, tuber. `allows_decimal` is true only for kg. Garlic in the current seed is set to "Bag"; this is corrected to kg in the reference seed so it can join combos sensibly.

### 5.4 Availability

A simple status the admin sets: available, out of stock, or restocking (with an optional restock date). No stock-quantity decrement at checkout in Phase 1. OK Veggies sources to order, so a hard count would create false "out of stock" blocks.

### 5.5 "Goes well with"

Admin-curated pairings per product (a `product_pairings` table), with a same-category fallback when none are set. Shown at the bottom of the product page and used to nudge basket size.

### 5.6 Browsing a long catalogue (search and pagination)

The shop grid (`shop.php`) and the admin catalogue (`admin/products.php`) are the same behaviour on two surfaces. Both have to stay quick to use when the catalogue runs well past one screen.

- **Search is live.** Typing filters the list, debounced at 300ms. There is no Search button to press. On the admin catalogue the category and on-shop dropdowns apply the moment they change.
- **Results are rendered on the server, always.** A live filter asks `api/v1/catalog.php` or `api/v1/products.php` (action `browse`) for one page and swaps the results region with what comes back, so what someone sees as they type is the same markup a plain load of the same URL renders. The two cannot drift.
- **25 rows to a page**, on both listings. `Catalogue::PER_PAGE` and `Products::PER_PAGE` hold that number and nothing else may repeat it.
- **The page switcher carries the filters.** Previous and Next, plus numbered pages with the first and the last always within reach. Changing a filter drops back to page 1. Nothing renders at all when the results fit on one page.
- **A count sits over every listing:** "24 items" while everything fits on one page, "Showing 26 to 50 of 87 items" once it does not.
- **The address bar keeps up.** Search, category and page all live in the URL, so a set of results can be shared and the browser back button steps back through the filters.
- **A deep link beats the pagination.** `/admin/products.php?product=12` from the Pricing screen opens that product's panel on whichever page holds it. When the filters in play do not list the product at all, the link opens page 1 rather than a page the product is nowhere on.
- **It all works with JavaScript off.** The GET forms submit, the server filters, and the page links are plain anchors. Live filtering makes the same job faster; it is never the only way to do it.

---

## 6. Pricing operations

Weekly repricing is the core recurring task of the business. Prices are fixed weekly per item using trusted suppliers who hold the price. When cost rises slightly, OK Veggies absorbs it rather than pass it on mid-week. When cost drops, the customer's price drops with it immediately.

- **Pricing table (admin):** every product in one screen, price editable inline, with a "apply to whole category" bulk action and effective-now change. Every change writes a `product_price_history` row automatically (old price, new price, reason, who, when).
- **CSV / Excel import and export:** the Manager keeps prices in a spreadsheet already. Import updates prices in bulk; export produces the current price list. Built on PhpSpreadsheet.
- **No price is ever silently lost:** history is append-only.

---

## 7. Combos

Combos are ready-made baskets for a specific cooking occasion. They are called **Combos** in the product and **"The Stew Combo"**, **"The Weekend Basket"**, etc. for individual ones. The word "Kit" is not used anywhere.

### 7.1 Combo builder (admin)

A product picker: the admin ticks products and quantities, the system shows the **live component total** (the sum of the current prices of the chosen products at their quantities), and the admin then sets the **combo sell price** independently. The combo price is fixed and does not auto-recompute when component prices move, but the builder always shows the current component total for reference at repricing time. Combos can be drafted, edited and published (active flag), with optional available-from and available-until dates.

### 7.2 The Stew Combo (seeded at launch)

A blended pepper-tomato base for a Lagos pot of stew. All six items are already in the product seed.

| Item | Quantity | Unit price (seed) | Line |
|---|---|---|---|
| Fresh Tomatoes | 2 kg | ₦2,700/kg | ₦5,400 |
| Tatashe (red bell) | 1 kg | ₦4,500/kg | ₦4,500 |
| Rodo (scotch bonnet) | 0.5 kg | ₦4,000/kg | ₦2,000 |
| Shombo | 0.5 kg | ₦4,500/kg | ₦2,250 |
| Onion | 1 kg | ₦1,400/kg | ₦1,400 |
| Ginger | 0.25 kg | ₦8,000/kg | ₦2,000 |
| **Component total** | | | **₦17,550** |

Seed sell price: **₦16,900** (a visible saving against buying the items separately). The admin can adjust this any time in the combo builder.

---

## 8. Kitchen Runs

The original, highest-trust OK Veggies service, digitised: a customer submits a list, we price it, they approve, it becomes an order. Many items on a kitchen list are not in our catalogue (for example pomo / cowskin, meat, oil), so the flow does not require catalogue products.

### 8.1 How a request starts (four input modes)

1. **Pick from the catalogue.** The customer builds a list from our products.
2. **Type a custom list.** Free-text item names, not required to be catalogue products. Two fill logics:
   - **Priced by us:** the customer gives quantity plus unit and leaves price blank (for example "6 kg tomatoes, 10 kg pomo"). We fill the prices and confirm.
   - **Priced by the customer:** the customer gives a target price and leaves quantity or unit blank (for example "tomatoes ₦35,000, pomo ₦40,000"). We fill in the unit and quantity to match and confirm.
3. **Upload a list.** An image (JPEG / PNG) or PDF of a written list. We transcribe and price it.
4. **Already priced.** The customer's list already carries prices. We only confirm and proceed.

### 8.2 Open-budget trust mode (decision Q16b = A)

Some customers say "no matter the cost, just source it, I will pay". For these:

- The admin sets a **deposit amount per request**, using judgement based on the list and the relationship.
- An **optional spend cap** the customer agrees to protects both sides. We source up to the cap.
- The balance is reconciled after delivery (pay on delivery, or on account for credit customers).

### 8.3 Flow and states

Submitted, then Quoted (admin fills the blanks, live totals), then Approved by the customer, then Converted to an order (with deposit or credit), then normal fulfilment. A request can also be Declined or Cancelled. Saved lists in the Pro Portal let B2B customers reuse a list next week.

### 8.4 Data

New tables: `kitchen_run_requests` (mode, status, budget ceiling, estimated total, deposit amount, cap, uploaded-list attachment, notes, converted order id) and `kitchen_run_items` (free-text item name, optional product link, quantity, unit, unit price, line total, price source). See Section 20.

---

## 9. Cart and checkout

### 9.1 Basket

Guest baskets are supported (session token) and merge into the account on login. A basket holds both individual products and combos. Quantities respect each product's minimum and increment. The mini-cart is always reachable.

### 9.2 Checkout, B2C

Steps kept to the minimum: basket, then delivery details and day, then payment choice, then confirm. Guest checkout is allowed for B2C (name, phone, email, address captured), with a light account created so the order can be tracked and a delivery day chosen.

### 9.3 Payment choice at checkout

- **Pay in full** now (Paystack), or
- **Pay a deposit** now (the deposit percentage is configurable in Order Settings, defaulting to 30%), balance on delivery, or
- **On account** (credit), for approved B2B customers only.

Anyone choosing to pay on delivery must have an account and must activate it with an OTP first (Section 10.2). The delivery fee is never charged on the platform; a clear note explains it is arranged and settled on delivery, and the customer's area is captured so the team can confirm the fee before dispatch (Section 13).

### 9.4 Delivery day picker

The picker only offers the days allowed for that customer type (Section 13), respects the cutoff time and minimum lead days, and greys out full or excepted dates with a plain explanation.

---

## 10. Accounts and authentication

### 10.1 Login

Identifier is the **phone number or the email address** (both unique), plus a password. Passwords follow a shared policy (minimum length, not a common password), hashed with bcrypt. Sessions use the same hardened cookie handling as the reference architecture.

### 10.2 OTP activation

A new account can be activated by a one-time code (sent by email, and by SMS later when a provider is funded). Activation is **required before a customer can place a pay-on-delivery order**, because that is the flow most exposed to abuse. New table: `otp_verifications` (Section 20).

### 10.3 Account types and roles

- **Customers** are `household` or `business` account types, not staff roles.
- **Business customers** have a business profile, can apply for credit, and get the Pro Portal.
- **Staff** are the Owner and the Manager (Section 17.1). RBAC is the same engine as the reference app.

---

## 11. Payments

Paystack is the gateway. Money is stored in **subunits (kobo)** everywhere, as integers, and formatted for display by a single `Money` helper. Never a float.

- **Channels:** all Paystack channels enabled (card, bank transfer, USSD, bank).
- **Online payments** are initialised and verified through Paystack; the signed **webhook** lands in an idempotent inbox (`payment_webhook_events`) and drives status changes. Reconciliation against Paystack settlements is supported.
- **Deposit balance and pay-on-delivery** amounts are recorded by the admin (cash or transfer) with an optional proof, reviewed in the admin panel (`manual_payment_proofs`).
- **Refunds, settlements and disputes** are modelled and tracked (append-only status history on payments and refunds).
- **Deposit percentage** is a setting, not a constant.

---

## 12. B2B credit (two-way)

Credit is extended to trusted restaurants, hotels and marts, 7 to 10 day terms.

- **Self-serve application:** a business applies from the Pro Portal (requested days, requested limit, reason). The admin reviews and approves a limit and term.
- **Manual grant:** the admin can also turn any business account into credit mode directly and set a limit, without an application.
- **Credit orders** draw against the limit. Charges, repayments and adjustments are recorded in `credit_transactions`, each with a due date.
- **Outstanding view:** the admin sees who owes what and when it is due (a simple aging list). Automated reminders and dunning are Phase 2.

---

## 13. Delivery and fulfilment

Delivery fees stay off the platform, arranged and settled on delivery. The software plans and schedules; it does not price delivery.

- **Allowed days by customer type:** households get Monday, Wednesday, Thursday, Saturday. Tuesday and Friday are reserved for restaurant and mart supply, so the two queues do not slow each other down. Driven by `allowed_delivery_days`, editable by the admin.
- **Cutoff and lead time:** an order-by cutoff (default 18:00 the day before) and a minimum lead in days, both configurable. Full or excepted dates come from `delivery_date_exceptions`.
- **Zones:** delivery zones are admin-managed data (`delivery_zones`), seeded with editable Lagos placeholders. The customer's zone is captured at checkout and shown on the manifest, and it is what the team uses to confirm the off-platform fee (Section 9.3).
- **Delivery-day manifest / packing list (admin):** for any chosen day, a printable list of every order to pack and deliver, grouped by zone, with items, quantities and customer contact. This is a day-one operational need.

---

## 14. Order lifecycle and the Order Trail

### 14.1 Statuses

pending, confirmed, packed, dispatched, delivered, cancelled. Every transition is written to `order_status_history` (old, new, source, who, note). An order is never deleted; cancelling records a cancellation and reverses money per policy.

### 14.2 The Order Trail (signature trust pattern)

Every order, B2C or B2B, gets a visible trail the customer can open from a link in the confirmation email, no login required: **Placed, Sourced (with the farm state), Packed, Dispatched, Delivered**, each with a timestamp as it happens. The "Sourced [day] from [state]" line appears on the product card, the order trail, and the confirmation email, so the promise is never made only once. This is the direct answer to the pay-online fear the client named twice on the discovery call.

---

## 15. Notifications

- **Email (SMTP, via a `Mail` class on PHPMailer):** order placed, payment confirmed, deposit received, order dispatched, order delivered, and the order-trail link. Transactional, plain, always with a next step. Templates live in `notification_templates`; deliveries are tracked in `notification_deliveries`.
- **WhatsApp:** the floating support widget opens `wa.me` with a prefilled message. WhatsApp remains the human, trust-building channel. Automated WhatsApp Business API messaging is Phase 2.
- **Contact form:** the "Contact us" option lands a `contact_messages` row in the admin panel and notifies staff.
- **Admin alerts:** new order, new kitchen run, new manual payment proof to review, new contact message, new make-it-right report.

---

## 16. Trust and "Make It Right"

Trust is the brand's core value and the biggest lever on whether people pay online.

- **Make It Right flow:** if what arrived is not what was described, the customer reports it against the order (category, note, optional photos). The admin resolves it (refund, credit, or replacement) and the customer sees the outcome. No ticket numbers, no three-day wait. New table: `issue_reports`.
- **Trust signals on the storefront:** verified-source labelling ("Sourced [day] from [state]"), real produce photography, secure-payment marks at checkout, and the visible order trail.

---

## 17. Admin panel

### 17.1 Roles

Two staff roles at launch, both operating the business day to day.

| Role | Scope |
|---|---|
| Owner | Everything, including users and roles, settings, credit approvals, and financial views |
| Manager | Sales, operations and delivery: catalogue, pricing, combos, orders, kitchen runs, customers, payments recording, delivery manifest, make-it-right. Not user management, role editing, or destructive settings |

A full permission catalogue is seeded (dot-notation keys, same engine as the reference app) so finer roles can be added at runtime later.

### 17.2 Modules

Dashboard (today's orders, revenue, payments due, credit outstanding, plus simple charts: sales over time, top products, order-share by category using the fixed category colours), Orders, Products and Pricing (with CSV import/export), Combos (the builder), Kitchen Runs (the quote workflow), Customers (households and businesses, addresses, credit), Payments (Paystack transactions, manual proofs, refunds), Credit (applications, limits, outstanding), Delivery (day manifest, allowed days, zones, exceptions), Content and Messages (page copy, FAQ, contact messages), Make It Right (reports and resolutions), Settings (Order Settings including deposit percentage and cutoff, site settings, notification templates), Users and Roles (Owner only).

---

## 18. Content and marketing pages

Home (Track 2 documentary hero, the promise, featured combos, categories), Shop, Product, Combos, Kitchen Runs, Our Story / About, How It Works, FAQ, Contact (via the floating widget and a form), Terms, Privacy, Delivery Policy. About, How It Works, FAQ and legal are reached from the footer. Page copy is editable in the admin Content module rather than hardcoded.

---

## 19. Design system

The OK Veggies Brand Architecture bible v1.0 is the authority. This section encodes the parts the build must not get wrong. Tokens go into `tailwind.config.js`; nothing uses arbitrary colours or spacing.

### 19.1 Colour

| Token | Hex | Role |
|---|---|---|
| Forest Green | `#0F5132` | Primary anchor |
| Harvest Gold | `#C9922B` | Secondary. Never a button fill, never carries its own text |
| Tomato Red | `#C8321E` | Accent, alerts |
| Foliage Green | `#3E8B4A` | Leaf motif, success |
| White | `#FFFFFF` | Primary canvas |
| Ink | `#03100A` | Body text |
| Gold Ink | `#7A5A18` | Text on gold tint |
| Mist | `#EAE8E8` | Disabled UI only, never a brand surface |

Contrast is computed, not eyeballed. Gold fails AA against white, so Ink carries all body text. Foliage carries white only above 24px (or bold 18.66px), Ink below that.

### 19.2 Type

Display: **Plus Jakarta Sans** (700, 800, 600 italic). Body, UI, prices: **Hanken Grotesk** (400 to 800). Figures, SKUs, order numbers: **JetBrains Mono**. All self-hosted from Google Fonts, no CDN. Scale is a 1.25 ratio on a 16px base, line-heights per the bible. Headings above h4 use `text-wrap: balance`.

### 19.3 Grid and spacing

8px grid, ten steps only: 4, 8, 12, 16, 24, 32, 48, 64, 96, 128. No arbitrary 15px or 22px anywhere. Layout is 12 columns, 24px gutter, max-width 1280px. Breakpoints: mobile under 640px (4 columns, product grid 2 up), tablet 640 to 1023px (8 columns, 3 up), desktop 1024px and above (12 columns, 4 up).

### 19.4 Components

- **Buttons:** 44px min height, three variants (Primary forest, Secondary forest outline, Text), five states each. Gold is only a focus ring, border accent, or progress fill, never a button background.
- **Inputs:** 48px height, radius 6px, labels always visible above the field, six states, error and success carry an icon plus text, never colour alone.
- **Product card:** 16px padding, 12px radius, hero-on-white photo.
- **Icons:** 24px grid, 2px stroke, rounded caps and joins, line only, never filled.
- **Pagination:** Previous, a window of numbered pages, Next, under any listing that runs past one page (Section 5.6). 44px minimum touch target on every control, the current page carrying `aria-current="page"` so it is never marked by colour alone, and the gold focus ring left visible.

### 19.5 Motion

Two curves. Botanical, 240ms `cubic-bezier(0.4, 0, 0.2, 1)`, carries 90% of the interface (card hover lifts of 4px, page transitions, reveals; 150ms on button hover and focus). Market Bounce, 320ms `cubic-bezier(0.34, 1.56, 0.64, 1)`, for the small celebration moments only (add to basket, basket count increment, kitchen-run submitted). `prefers-reduced-motion` collapses all motion to near-instant.

### 19.6 Voice and the house laws

Voice is the ingredient-literate grocer: calm, specific, warm, never selling harder than the produce needs. Five registers (product copy, marketing, trust and support, system and error, Pro Portal) change formality and pace, not personality. System and error copy is plain, zero jargon, and always offers the next step ("Tuesday's delivery slots are full. Wednesday and Thursday still have room. Pick another day to continue.").

House laws, enforced across copy, emails and code comments:

1. **No em dash. Anywhere. Ever.** Full stops, commas, colons and semicolons carry every rhythm it used to.
2. **Write from inside the kitchen.** Banned words in customer-facing copy: curated, artisanal, bespoke, leverage, utilise, endeavour, elevate, seamless, unlock, robust, one-stop solution, premium selection. Specificity beats sophistication.
3. **Nigerian English, British-leaning spelling** (colour, kilogramme, organise, flavour). Pidgin only in campaign lines and combo names, never in checkout, legal or error copy.
4. **Numbers are numerals, always** (₦8,000, 2kg, Tuesday 26th). Currency carries the ₦ symbol and comma thousands separator.
5. **Units are never implied.** kg, bunch, head or tuber is stated on every product reference. "1kg tomatoes", not "2 tomatoes".

### 19.7 Accessibility (non-negotiable)

44px minimum touch targets, a visible 2.5px gold focus ring at 2px offset never suppressed, colour never the only signal, visible labels, alt text on every product photo in the pattern "[Product], [unit], sourced from [state]", and `prefers-reduced-motion` respected site-wide. Target WCAG 2.1 AA.

### 19.8 Photography

Track 1 hero-on-white for product grids, product pages and combo contents. Track 2 market-documentary for the homepage hero, About and order-trail story cards. No stock photography, no studio lifestyle, no heavy filters.

---

## 20. Data model

The drafted `ok_veggies_schema.sql` is strong and stays the backbone: subunit money, an idempotent Paystack webhook inbox, settlements, refunds and disputes, price history, guest carts, off-platform delivery fees. Money is `BIGINT` subunits throughout. Every business table keeps created/updated/updated-by columns and the append-only history pattern; orders, payments and journal-style records are never deleted, only reversed.

### 20.1 Existing tables (kept)

users, roles, user_roles, business_customers, customer_addresses, product_categories, units_of_measurement, products, product_images, product_price_history, product_availability, combo_packages, combo_package_items, combo_price_history, shopping_carts, cart_items, allowed_delivery_days, delivery_date_exceptions, orders, order_addresses, order_items, order_item_components, order_status_history, order_cancellations, delivery_schedules, payments, payment_transactions, manual_payment_proofs, payment_webhook_events, payment_status_history, refunds, refund_status_history, settlements, settlement_transactions, payment_disputes, dispute_evidence, credit_applications, credit_transactions, notification_templates, notifications, notification_deliveries, audit_logs, site_settings.

### 20.2 Additions for Phase 1

| Table | Why |
|---|---|
| `kitchen_run_requests` | A kitchen-run request: mode, status, budget ceiling, estimated total, deposit amount, spend cap, uploaded-list attachment, notes, converted order id |
| `kitchen_run_items` | Request lines: free-text item name, optional product link, quantity, unit, unit price, line total, price source (customer or admin) |
| `kitchen_run_templates` (+ items) | Saved, reusable B2B lists for the Pro Portal (may reuse the two tables above with an `is_template` flag) |
| `product_pairings` | "Goes well with" suggestions per product |
| `delivery_zones` | Admin-managed Lagos zones, captured on the order and shown on the manifest |
| `otp_verifications` | One-time codes for account activation (email now, SMS later), required before pay-on-delivery |
| `contact_messages` | Storefront contact-form submissions surfaced in admin |
| `issue_reports` | The Make It Right flow: report against an order, resolution and outcome |
| `content_pages` | Editable copy for About, How It Works, FAQ, legal |

### 20.3 Seeds and settings

Reference seed: 5 product categories (Vegetables, Herbs & Spices, Tubers & Roots, Fruits, Grains & Cereals), units (kg, bunch, head, tuber), allowed delivery days (households Mon/Wed/Thu/Sat), placeholder delivery zones, a default RBAC set (Owner, Manager) with the permission catalogue. Product seed: the 24 produce items already drafted, with the Garlic unit corrected to kg. Combo seed: The Stew Combo. Settings seed in `site_settings`: `deposit_percentage_default = 30`, `delivery_cutoff_time = 18:00`, `delivery_min_lead_days = 1`, `currency = NGN`, business identity, WhatsApp support number.

### 20.4 Order numbers

Format `OKV` + two-digit year + zero-padded sequence, for example `OKV26001`. Sequence resets per year. Generated in one place (`OrderNumber` helper), never hand-built.

### 20.5 Migrations

The monolithic schema is split into numbered, idempotent migrations run by `scripts/migrate.php`, exactly like the reference app:

```
000_init_schema_migrations.sql   tracking table
001_core_schema.sql              the full drafted schema
002_rbac_seed.sql                permissions catalogue + Owner/Manager roles
003_reference_seed.sql           categories, units, delivery days, zones, settings
004_product_seed.sql             the 24 products + images + price history + availability
005_combo_seed.sql               The Stew Combo
006_kitchen_runs.sql             kitchen_run_requests + items + templates
007_storefront_extras.sql        product_pairings, delivery_zones, otp_verifications, contact_messages, issue_reports, content_pages
```

Every migration is idempotent (`IF NOT EXISTS`, `INSERT ... ON DUPLICATE KEY UPDATE`), wraps writes in a transaction, and ends with a short block of verification queries.

---

## 21. Non-functional requirements

- **Hosting:** cPanel shared hosting. No Composer on the server, so `vendor/` is committed. PHP 8.x (pin to the host's version, target 8.3 to match the reference app).
- **Performance:** product images optimised (WebP where supported) and lazy-loaded; Tailwind built and minified, not CDN; the storefront usable on a mid-range Android phone over 3G. First shop paint under 3 seconds on such a connection.
- **Security:** prepared statements only, CSRF on every state change, output escaping, no secrets in code (`.env` only), RBAC on every admin and pro route and API action, uploads validated (extension, MIME, size, randomised name, denied execution). Same non-negotiables list as the reference app.
- **SEO:** clean slugs, per-page titles and meta, Open Graph tags, a sitemap, and `wa.me` click-to-chat.
- **Browsers:** current Chrome, Safari, Firefox, Edge, and Android Chrome and iOS Safari two versions back.
- **Backups:** database and uploads backed up before every deploy.

---

## 22. Testing and QA

Nothing ships red. Testing is part of "done", not a later phase.

- **Unit tests** for the logic that must not be wrong: the `Money` helper, deposit and balance maths, order-number generation, weekly price changes and history, combo component totals, credit limit and balance, delivery-day eligibility (cutoff and lead), and Paystack webhook signature verification and idempotency.
- **Smoke tests per role** (a script in the style of the reference app's `verify.sh`): guest, household, business, Manager, Owner. Each checks the pages that role can reach and the gates that should stop it.
- **`php -l`** on every file touched, before every push.
- **Manual QA checklist per feature**, kept in `docs/` and ticked before a feature is marked done in `PROGRESS.md`.
- **Acceptance criteria** live with each feature in the PRD and in `PROGRESS.md`. A feature is done when its acceptance criteria pass, its tests pass, and it meets the Section 2 experience principles on both a phone and a laptop.

---

## 23. Phasing

**Phase 1 (this build): the whole software.** Storefront (individual items and combos), Pro Portal, admin panel, Kitchen Runs (all four input modes plus open-budget trust), B2B accounts and two-way credit, all three payment modes with Paystack and OTP-gated pay-on-delivery, the Order Trail, email notifications, WhatsApp click-to-chat, the Make It Right flow, delivery planning and the day manifest, CSV import/export, and the admin dashboard with simple charts.

**Phase 2 (blocked on the outside world or genuinely later):** live GPS tracking of an owned fleet, automated WhatsApp Business API messaging, owned-farm supply, product reviews and ratings, standing-order automation, combo customisation by the customer, phone-OTP by SMS, and automated credit reminders and dunning.

---

## 24. Appendices

### 24.1 Glossary (plain terms we use, jargon we avoid)

| We say | Not |
|---|---|
| Basket | Cart / shopping cart |
| Combo | Bundle / SKU pack |
| This week's price | Current unit rate |
| Pay a little now, the rest on delivery | Remit 30% deposit tranche |
| Kitchen Run | B2B procurement request |
| We will bring it on the day you pick | Fulfilment scheduling window |
| Sourced Tuesday from Ogun State | Supply-chain provenance metadata |

### 24.2 Environment variables (shipped in `.env.example`)

APP_URL, APP_ENV, APP_DEBUG (false in production), DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_CHARSET, SESSION and security keys (session cookie, bcrypt cost, CSRF length, APP_ENCRYPTION_KEY), SMTP_HOST, SMTP_PORT, SMTP_ENCRYPTION, SMTP_USER, SMTP_PASS, SMTP_FROM_EMAIL, SMTP_FROM_NAME, PAYSTACK_PUBLIC_KEY, PAYSTACK_SECRET_KEY, PAYSTACK_WEBHOOK_SECRET, WHATSAPP_SUPPORT_NUMBER, UPLOAD limits and path, error-log path. Real secrets stay in `.env` (never committed); `.env.example` carries the full key list with safe placeholders.

### 24.3 Source documents

`docs/Client Discovery Meeting - 2026_08_19 08_58 WAT - Notes.docx`, the OK Veggies Brand Architecture bible v1.0, `docs/OK_Veggies_Architecture_Decisions_Round_2.md`, `ok_veggies_schema.sql`, `product seed.sql`.

---

*End of PRD v1.0. Changes to this document are themselves a decision: note them in `PROGRESS.md` and bump the version.*

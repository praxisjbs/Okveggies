# NVDA checklist (Windows)

**Fix 7 / PR6.** WCAG 2.1 AA screen-reader evidence for OK Veggies.

This is the written checklist the M13 contract (Section 7.2) requires. The automated gate is `scripts/tests/axe_suite.mjs`. Live NVDA sessions are recorded against these rows on the frozen candidate. Do not tick a row without sitting through it.

**Profile:** NVDA current release, Firefox or Chrome current, Windows, speech rate comfortable, browse mode then focus mode as NVDA switches.

**How to record:** For each row, note Pass / Fail, the browser, NVDA version, viewport (390 or 1440), and a one-line note. Attach the session log or a short recording to the M13 review.

## Shared chrome

| # | Check | Pass |
|---|-------|------|
| 1 | Skip to content is the first Tab stop and lands in `main#okv-main` | |
| 2 | Header landmark, main landmark, footer landmark, one of each | |
| 3 | Heading order is 1 then 2 then 3, never a skipped level | |
| 4 | Gold focus ring is visible on every control reached by Tab | |
| 5 | Support trigger is reachable by keyboard, opens a dialog, Escape returns focus | |
| 6 | Mobile tab bar names each destination; current page is `aria-current` | |

## Journeys

| # | Journey | What NVDA must say | Pass |
|---|---------|--------------------|------|
| 7 | Home | Hero heading, promise as three steps, Learn opens a dialog, catalogue cards named with price and Add | |
| 8 | Shop | Search field labelled, filters named, empty state heading plus two actions, product cards as articles | |
| 9 | Product | Name as h1, price, unit, availability in words, Read sheet for the long description, Add to basket | |
| 10 | Combo | Basket name as h1, contents as a list of links, Add full basket | |
| 11 | FAQ | Each question is a heading level 2, Expand / Close is announced, Expand all / Collapse all work | |
| 12 | How It Works | Three steps, Learn sheet with the published body, Make It Right one line plus Learn | |
| 13 | Delivery Policy | Table with Day and Who we deliver to, caption present, Read sheet holds the legal body | |
| 14 | Contact | Fields labelled, sent empty state has a heading and two actions | |
| 15 | Basket | Empty state or line names with quantity and Remove, Continue to checkout | |
| 16 | Checkout | Four named steps, payment cards as radios, trust panel after the group, sheets close on Escape | |
| 17 | Account | Sign in fields labelled, orders list, report an issue from an eligible order | |
| 18 | Kitchen Runs | Guest intro three steps plus account buttons; signed-in 3-tap flow, help sheet | |
| 19 | Order Trail | Public token view names the order without exposing Make It Right | |
| 20 | Make It Right | Window in days, photo limit, outcome stays on the signed-in order | |
| 21 | Pro | Six screens, current nav item announced, combobox filters | |
| 22 | Admin | Dashboard, content editor, Make It Right queue, focus never lost in a sheet | |

## Empty, error, no JavaScript

| # | Check | Pass |
|---|-------|------|
| 23 | Empty shop, empty basket, unpublished FAQ and 404 all have a heading, one line and two actions | |
| 24 | Forms still submit with NVDA browse mode and with JavaScript off | |
| 25 | Images have meaningful alt, or empty alt when the name is already beside them | |

**Operator:** ________________  **Date:** ________________  **SHA:** ________________

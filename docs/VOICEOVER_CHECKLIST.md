# VoiceOver checklist (macOS or iOS)

**Fix 7 / PR6.** WCAG 2.1 AA screen-reader evidence for OK Veggies.

Pair this with `docs/NVDA_CHECKLIST.md` and the axe-core Playwright gate. Live VoiceOver sessions are recorded against these rows on the frozen candidate. Do not tick a row without sitting through it.

**Profile:** VoiceOver on macOS Safari, or iOS Safari on a real iPhone. Rotor set to Headings, Links, Form Controls and Landmarks.

**How to record:** For each row, note Pass / Fail, device (Mac / iPhone), Safari version, viewport, and a one-line note. Attach a screen recording to the M13 review where possible.

## Shared chrome

| # | Check | Pass |
|---|-------|------|
| 1 | Rotor Landmarks lists Header, Main, Footer once each | |
| 2 | Skip to content is reachable and VoiceOver lands in `main#okv-main` | |
| 3 | Rotor Headings walks 1, then 2, then 3, never a skipped level | |
| 4 | Focus ring is gold and visible after each swipe or Tab | |
| 5 | Every control is at least 44 by 44 points on iPhone | |
| 6 | Sheets announce as modal dialogs; Close, swipe down and Escape dismiss them and return focus | |

## Journeys

| # | Journey | What VoiceOver must say | Pass |
|---|---------|-------------------------|------|
| 7 | Home | Hero heading, three promise cards, Learn dialog, Add on each card | |
| 8 | Shop | Search labelled, category chips, empty state with two buttons | |
| 9 | Product | h1 name, price and unit, Read sheet, quantity stepper, Add to basket | |
| 10 | Combo | Basket name, contents as links, Add full basket | |
| 11 | FAQ | Questions as headings, Expand / Close, Expand all | |
| 12 | How It Works | Three steps with headings, Learn sheet, Make It Right Learn | |
| 13 | Delivery Policy | Data table, caption, Read sheet | |
| 14 | Contact | Labels, sent empty state | |
| 15 | Basket | Lines or empty state, Continue to checkout | |
| 16 | Checkout | Progress list, payment radios as cards, pay bar above the tab bar on iPhone | |
| 17 | Account | Sign in, orders, Make It Right from an eligible order | |
| 18 | Kitchen Runs | Guest three steps; signed-in stepper and sheets | |
| 19 | Order Trail | Order named, no Make It Right on the public token view | |
| 20 | Pro | Bottom tabs named, current page announced | |
| 21 | Admin | Content editor and Make It Right queue stay in VoiceOver focus | |

## Zoom, reflow, motion

| # | Check | Pass |
|---|-------|------|
| 22 | 200 percent zoom at 390px does not clip or overflow horizontally | |
| 23 | Full motion still runs when Reduce Motion is on, per the 20 Sep decision | |
| 24 | Contrast of forest on white and white on forest remains readable | |

**Operator:** ________________  **Date:** ________________  **SHA:** ________________

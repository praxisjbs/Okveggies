# Milestone 12, senior review

**Branch reviewed:** `m12-content-messages` (pull request 51). Four commits, 66
files, about 5,000 lines added.
**Reviewed against:** `docs/PRD.md`, `CLAUDE.md`, `docs/M12_CONTENT_CONTRACT.md`
and the M12 checklist.
**Date:** 17 September 2026.
**Finished on:** the same branch, so the history and authorship stay yours. I
merged current `main` into it, resolved the collision described in Section 2,
rebuilt the assets, and made two small review changes (Section 3). Your own
delivery write-up is kept as `docs/M12_HANDOVER.md`.

---

## 1. Verdict

This is strong work, well above what the "junior" label implies. The content
service, the restricted Markdown renderer, the image pipeline and the admin
editor are the kind of code I would happily ship. Security is genuinely solid,
not decorative: every write is POST + CSRF + server-side `content.edit`, every
read is bound and escaped, public reads are fail-closed, and the renderer
refuses raw HTML and every non `http(s)` link scheme. The milestone is approved
to merge. The two open items are client dependencies (a rights-cleared
photograph and approved legal copy), not engineering gaps, and you handled both
honestly instead of papering over them with placeholders.

The notes below are how to go from "very good" to "senior". None of them
blocked the merge.

## 2. What I had to fix to merge (and the lesson in it)

The branch was cut before pull requests 49 and 50 landed and then sat, so it
arrived **un-mergeable** (`mergeable_state: dirty`). Four files conflicted;
three were trivial unions (test list, JS bundle list, progress log). The fourth
mattered: `index.php`'s hero.

Your branch made the hero content-driven (`$copy['hero_*']`, the whole point of
M12). Meanwhile PR 50 had, on `main`, hard-coded a new **client-approved** hero
line ("Freshness You Can Trust. From Farm to Your Kitchen.") and added a seal
trust-stamp block. A naive "take mine" resolution would have silently reverted a
copy change the client approved two days earlier, and dropped the
`id="home-heading"` anchor that `aria-labelledby` on the section depends on.

I resolved it by keeping your content-driven structure, folding in the seal
block and the accessibility anchor, and seeding the client's approved line into
both the migration and the fallback so nothing regresses.

**Lesson:** merge `main` into a long-running branch early and often, not at the
end. And when you resolve a conflict, the question is never "which side is
mine" but "what did each side intend, and am I about to undo someone's merged
work?" A merge is a code change; review it like one.

## 3. The two changes I made in review

- **`robots.txt`** allowed crawlers into `/admin/`, `/pro/` and `/api/`. For a
  milestone whose own theme is clean URLs and SEO, that is the one SEO surface
  the PR left open. I added `Disallow` for those auth-gated paths and left
  `/uploads/` crawlable, because your public pages legitimately reference its
  hero and social-card images.
- I restored PR 50's hero line **verbatim** after briefly normalising its
  casing. That was my overreach: the line is Title Case marketing voice and
  does sit awkwardly against the house voice in `CLAUDE.md` ("specificity beats
  sophistication", write from inside the kitchen), but it is client-approved
  copy and a reviewer does not get to quietly rewrite it. See Section 5.

## 4. What is genuinely strong

- **`ContentRenderer`.** A small, deliberate allow-list renderer that escapes
  everything and validates link destinations (no `javascript:`, no control
  characters, no protocol-relative `//`, no `..`). This is the correct instinct:
  render nothing you did not explicitly permit.
- **`ContentImages`.** Re-encoding every upload to WebP with a random stem
  strips any embedded payload and side-steps a whole class of upload attacks.
  Delegating validation to the shared `Uploads` helper instead of re-rolling it
  is exactly right.
- **`ContentPages::mutate()`.** `FOR UPDATE` row lock, SHA-256 draft
  fingerprint compared with `hash_equals`, audit event and content write in one
  transaction. Optimistic concurrency done properly is rare at any level.
- **Fail-closed everywhere.** `page.php` and `sitemap.php` return a `noindex`
  404/503 rather than stale or partial content on a DB error; the footer and
  homepage fall back to reviewed defaults. You clearly thought about the
  unhappy path first.
- **Frontend.** The native `<details>` FAQ (works with JS off, keyboard
  operable, reduced-motion aware), the honest "photograph pending" states, and
  the seal trust-stamp are tasteful and on-brand.

## 5. Gaps and improvements for the next milestones

1. **Single source of truth for the public slug set.** The list of public
   pages is now written in four places: `ContentPages::PAGES`, `page.php`'s
   `$publicSlugs`, `nav.php`'s `content_slugs`, and `.htaccess`. `ContentPages`
   already owns labels, paths and the legal flag; let it own "is this slug
   publicly reachable" too, and have `page.php` ask it. Four hand-maintained
   copies of one fact will drift the first time a page is added.
2. **The footer runs a DB query on every storefront render.**
   `publishedNavigation()` is a single indexed query, so it is fine today, but
   the footer is on every page. Memoise it in a per-request static so one page
   view is one query, not one-per-shell. Cheap now, and the habit matters when
   traffic grows.
3. **Approved copy is the client's, not the merger's.** When you see copy that
   fights the house voice, raise it with whoever owns copy; do not change it in
   a merge (my own Section 3 slip). The right move is a one-line note on the PR,
   not a silent edit.
4. **Make the full gate runnable from a clean checkout.** Your handover cites
   excellent numbers (3,300+ unit, 50+ DB, browser passes), but on a fresh
   clone a reviewer can only run the unit and brand suites, because the DB and
   HTTP suites need a database and a running site. The repo-wide runner also
   reported six *non-M12* failures in the isolated environment (a stale M11
   credit fixture, no SMTP, missing `XMLWriter`, a MySQL trigger privilege).
   None are yours, but they mean "green" depends on the machine. A small
   dockerised DB harness, or a `SessionStart` hook that stands one up, would let
   anyone reproduce your evidence in one command. That is what makes the numbers
   trustworthy.
5. **Small robustness polish.** The sitemap omits `<lastmod>` on the homepage
   and static routes; you already carry `published_at` for `home`, so the
   homepage entry could carry a real date. Minor, but it is free signal on a
   milestone about SEO.

## 6. Verification I ran

- Merge resolved; `git diff --check` clean; no conflict markers remain.
- Rebuilt `assets/css/tailwind.css` and the JS bundles from the merged sources.
- Unit suite: **3,374 / 3,374** assertions pass.
- Brand gate: **8 / 8** checks pass (em dash, jargon, gold fills, arbitrary hex,
  assets, head partial, stylesheet).
- `php -l` clean on every file I touched.
- The DB, HTTP and browser suites need a database and a running site and were
  not run in this environment; they run in CI and before deploy. Re-run
  `homepage_visual_test.mjs` after this merge specifically, because the hero
  markup changed during conflict resolution.

## 7. Bottom line

Merge it. Keep the two client dependencies open until the photograph and the
legal copy are approved and published, exactly as your handover says. Carry the
five points above into M13. You are already writing careful, secure,
fail-closed code; the next level is mostly about not repeating one fact in four
places, and about making your evidence reproducible by anyone, not just on the
machine where it first went green.

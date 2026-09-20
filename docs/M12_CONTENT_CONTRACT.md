# Milestone 12 Content Pages Contract

**Status:** Approved for implementation
**Approved:** 16 September 2026
**Scope:** Milestone 12 only

This document fixes the functional and technical contract for Milestone 12 before implementation. It records the Owner's answers to the 16 M12 decision questions. It does not implement the milestone.

## 1. Outcome

M12 delivers:

- a homepage with a real Track 2 documentary hero, the promise, featured combos and categories;
- Our Story, How It Works, FAQ, Terms, Privacy and Delivery Policy;
- a safe Content workspace inside the existing Content and Messages admin module;
- separate draft and published copies, with authenticated staff preview;
- fixed clean public URLs, canonical metadata, Open Graph metadata and sitemap coverage;
- publication-aware footer navigation;
- accessible mobile and desktop presentation;
- full audit snapshots for content changes;
- strict separation between Content and Messages data and permissions.

The existing Messages workflow remains intact. M12 must not change message submission, filtering, handling, history, notifications or counts except where the shared route and navigation need to admit a content-only staff member safely.

## 2. Fixed page inventory and URLs

The page inventory is fixed. Staff cannot create, delete or rename pages, and cannot edit slugs.

| Page | Stored slug | Canonical public URL |
|---|---|---|
| Homepage | `home` | `/` |
| Our Story | `about` | `/our-story` |
| How It Works | `how-it-works` | `/how-it-works` |
| FAQ | `faq` | `/faq` |
| Terms | `terms` | `/terms` |
| Privacy | `privacy` | `/privacy` |
| Delivery Policy | `delivery-policy` | `/delivery-policy` |

Apache rewrites the 6 content-page paths internally to the content-page controller. Requests for the old `/page.php?slug=...` forms receive permanent redirects to the matching clean URL. The redirect must inspect the original request so the internal rewrite cannot loop. Unknown slugs do not create arbitrary public routes.

Every published page emits one canonical URL and a matching `og:url`. Preview, unpublished and not-found responses are `noindex` and are never canonical public alternatives.

## 3. Content formats

### 3.1 General pages

General body copy is stored as restricted Markdown. Raw administrator-authored HTML is never accepted or rendered.

Allowed constructs are:

- paragraphs;
- level-2 and level-3 headings;
- ordered and unordered lists;
- emphasis and strong emphasis;
- block quotes;
- links using `https`, `http` or a site-relative path.

The renderer escapes source text before producing an allowlisted set of elements. It rejects or neutralises raw HTML, scripts, event attributes, `javascript:` URLs, embedded media, iframes, forms, styles and level-1 headings. The page template owns the single `h1`. External links receive safe relationship attributes. Public and admin output remains escaped at its final context.

### 3.2 FAQ

FAQ uses the same restricted Markdown storage. Each level-2 heading is one question; all content until the next level-2 heading is its answer. A FAQ draft with text before the first question, an empty question or an empty answer cannot be published.

The public FAQ renders native disclosure sections with a visible question in each summary. It works without JavaScript, is keyboard operable, preserves heading order for assistive technology and does not use colour as the only state signal.

### 3.3 Homepage fields

Homepage marketing copy is edited in Content, but catalogue truth remains in its existing modules. The fixed homepage fields are:

- hero eyebrow;
- hero heading;
- hero introduction;
- primary call-to-action label and local destination;
- secondary call-to-action label and local destination;
- promise heading and restricted-Markdown body;
- featured-combos eyebrow and heading;
- categories eyebrow and heading;
- featured-products eyebrow and heading.

Call-to-action destinations must be safe site-relative paths from an allowlist of existing storefront destinations. They cannot contain a scheme, host, control character or protocol-relative prefix.

Featured combo, category and featured-product records continue to come from `Catalogue` and their existing admin modules. Content editors cannot override their availability, ordering, pricing or publication state from the Content screen.

## 4. Draft, preview and publication lifecycle

Each page has a working draft and a last published snapshot.

- Saving changes updates only the draft.
- Saving a draft never changes public output.
- Publishing validates the complete draft, then copies it to the published snapshot atomically.
- Unpublishing makes the public URL unavailable but retains both the draft and the last published snapshot.
- Republish uses the current validated draft, not a hidden older version.
- Editing a published page does not unpublish or alter its current public snapshot.
- A save that makes no change writes no content or audit row.
- Concurrent editing is protected by comparing the submitted draft fingerprint with the locked current draft. A stale save returns a conflict and does not overwrite newer work.

Public reads select only the published snapshot and require `is_published = 1`. Missing and unpublished pages return the same branded 404 response with `noindex`. They are excluded from footer navigation and the sitemap.

Preview is available only to a signed-in staff session with `content.view`. It rechecks the permission on every request, reads the current draft, emits `noindex`, and has no unauthenticated or permanent token form. A copied preview URL therefore stops working outside the authorised session.

Publishing and unpublishing require an explicit confirmation. Legal-page publication additionally requires the editor to attest that the client-approved wording has been supplied. The attestation, actor and time are recorded in the publish audit event. The permission remains capability-based, not hardcoded to a role name.

## 5. Legal content

The existing Terms, Privacy and Delivery Policy seed bodies are placeholders, not approved legal copy. They must not be represented as final advice or commitments.

- Terms remains unpublished until client-approved wording is entered and explicitly published.
- Privacy remains unpublished until client-approved wording is entered and explicitly published.
- Delivery Policy may be published only after the client confirms its operational delivery and Make It Right wording.
- Until that confirmation, the existing checkout Make It Right link must lead to an operational, non-legal section in How It Works rather than to an unpublished Delivery Policy.
- The first M12 data migration unpublishes all 3 placeholder legal rows. It does not alter migration `006`.

Legal approval is an operational attestation recorded with the publish audit event. M12 does not invent legal text, approval dates or client commitments.

## 6. Photography contract

The homepage and Our Story use client-supplied, rights-cleared photographs of the real OK Veggies operation: farms, markets, packing, the team or produce in context. Stock photography and synthetic documentary scenes are prohibited.

Until approved photographs arrive, the layout may show a clearly labelled branded placeholder. That placeholder does not satisfy the final Track 2 photography acceptance criterion.

Content image handling follows the existing upload security rules: allowlisted extensions, MIME inspection, size limit, randomised stored name and no PHP execution. Each public image requires meaningful editor-supplied alt text. Decorative images use empty alt text deliberately. Draft image changes do not replace the currently published image until publish succeeds.

## 7. SEO, Open Graph and sitemap

Each page has optional draft and published SEO title and description fields.

- SEO title is a page-specific title fragment with a defined length limit; the renderer applies the consistent OK Veggies suffix.
- An empty SEO title falls back to the visible page title.
- Meta description has a defined length limit and falls back to a plain-text excerpt of the published body.
- Open Graph and Twitter titles and descriptions reuse the final SEO values.
- The published documentary image is the Open Graph image when available; otherwise the global OK Veggies social image is used.
- `og:url` exactly matches the canonical URL.
- Preview, unpublished, 404 and 503 responses are not indexed.

`/sitemap.xml` is generated from public state. It includes the homepage, the existing canonical storefront destinations, active products, currently buyable combos and published content pages. It excludes admin, account, basket, checkout, previews, unpublished pages and query-string legacy content URLs. A sitemap database failure returns an error response rather than a partial or misleading sitemap.

## 8. Navigation

Informational and legal content remains footer-only. It is not added to desktop top navigation or the mobile bottom tab bar.

The footer uses one canonical navigation definition rather than duplicating page links in a component. A content-page link renders only when that page is published. Contact and shop links remain independent of content-page publication.

## 9. Admin Content and Messages workspace

`admin/content.php` remains the shared workspace.

- A staff member with either `content.view` or `messages.view` may enter the route.
- Only permitted tabs are rendered.
- A direct request for a forbidden tab returns 403 before its query runs.
- A messages-only staff member receives no page-copy rows, SEO values, draft text, images, history or preview data.
- A content-only staff member receives no message rows, counts, sender details or handling history.
- The default tab is Messages when `messages.view` is present; otherwise it is Page copy.
- The sidebar entry is visible when either view permission is present. The unanswered count is queried and shown only with `messages.view`.

Content permissions are enforced as follows:

- `content.view`: list and read page drafts, publication state, audit history and authenticated previews;
- `content.edit`: save drafts, upload or replace draft photography, publish and unpublish;
- `messages.view`: existing message list and detail reads;
- `messages.handle`: existing message notes, handling and reopening.

Read responses must not contain data from a module the caller cannot view. Write controllers recheck their exact edit or handle permission on the server. Hiding a tab or button is not a security boundary.

## 10. Write and audit contract

Every content mutation requires POST, a valid CSRF token, `content.edit`, prepared statements and server-side validation. No exception message reaches the client.

Draft save, image change, publish and unpublish run in transactions. The corresponding audit entry is written inside the same transaction so content and history cannot diverge.

Audit actions are distinct and append-only:

- `content_pages.draft.update`;
- `content_pages.image.update`;
- `content_pages.publish`;
- `content_pages.unpublish`.

Audit records contain the page ID, actor, request metadata and full before-and-after snapshots of every changed field. Publish records also contain the legal-approval attestation when applicable. Unchanged saves produce no event. History is read-only in M12; there is no one-click restore and no dedicated revision table.

## 11. Empty, missing and error states

- Missing and unpublished public pages return the same branded 404 with a useful next route.
- An empty required draft cannot be published.
- An empty FAQ cannot be published.
- The admin list has a clear empty state if the fixed rows have not yet been seeded, with no create-page control.
- A content database failure is logged and returns a branded 503 with `noindex`; the application never falls back to seed or hardcoded legal copy.
- Content and Messages fail independently. A Content-tab failure does not query or expose Messages, and a Messages-tab failure does not query or expose Content.
- Public error copy reveals no exception, SQL detail, draft existence or approval state.

## 12. Accessibility and responsive behaviour

All M12 public and admin screens must meet the existing house rules:

- one semantic `h1` and ordered headings;
- 44px minimum touch targets on mobile;
- visible labels and the shared gold keyboard-focus ring;
- keyboard-operable FAQ disclosures and admin controls;
- useful alt text on real documentary images;
- colour never used as the only state signal;
- reduced-motion support;
- no horizontal overflow at 390px or 1440px;
- footer and support controls remain clear of the mobile bottom navigation;
- content remains readable with JavaScript disabled.

## 13. Minimum required migration

The approved staged-publishing and SEO requirements cannot be implemented with the current `content_pages` columns alone. A new numbered, idempotent MySQL 8 migration is genuinely required. Migration `001` and migration `006` remain unchanged.

The existing `title` and `body` columns remain the published snapshot so the
currently shipped public reader has a safe deployment bridge. The additive
migration adds exactly these columns:

| Column | Type | Purpose |
|---|---|---|
| `draft_title` | `VARCHAR(200) NULL` | Working visible title |
| `draft_body` | `MEDIUMTEXT NULL` | Working restricted-Markdown body |
| `draft_meta_title` | `VARCHAR(120) NULL` | Working SEO title fragment |
| `draft_meta_description` | `VARCHAR(320) NULL` | Working meta description |
| `meta_title` | `VARCHAR(120) NULL` | Published SEO title fragment |
| `meta_description` | `VARCHAR(320) NULL` | Published meta description |
| `draft_content_data` | `JSON NULL` | Working fixed homepage fields |
| `content_data` | `JSON NULL` | Published fixed homepage fields |
| `draft_image_url` | `VARCHAR(500) NULL` | Working documentary image path |
| `draft_image_alt` | `VARCHAR(255) NULL` | Working documentary image alt text |
| `image_url` | `VARCHAR(500) NULL` | Published documentary image path |
| `image_alt` | `VARCHAR(255) NULL` | Published documentary image alt text |
| `published_at` | `DATETIME NULL` | Time of the latest successful publish |
| `published_by` | `BIGINT UNSIGNED NULL` | Staff user who last published |

`published_by` has an idempotently added foreign key to `users.id` with
`ON DELETE SET NULL` and `ON UPDATE CASCADE`. Existing `updated_by` and
`updated_at` continue to identify the latest draft mutation.

For the `home` row, `draft_content_data` and `content_data` use an object with
only the following keys: `hero_eyebrow`, `hero_heading`, `hero_intro`,
`primary_cta_label`, `primary_cta_path`, `secondary_cta_label`,
`secondary_cta_path`, `promise_heading`, `promise_body`,
`combos_eyebrow`, `combos_heading`, `categories_eyebrow`,
`categories_heading`, `products_eyebrow` and `products_heading`. Unknown keys
are discarded on input and never copied into the published snapshot.

The migration also:

- inserts the fixed `home` content row if absent;
- backfills draft title and body from the existing published values;
- preserves existing non-legal published pages;
- unpublishes the 3 placeholder legal rows;
- uses `information_schema` guards and prepared `ALTER` statements where MySQL 8 requires them;
- ends with verification queries.

No FAQ table, content revision table, arbitrary page table, public preview-token table or editable-slug schema is required. Revision evidence uses `audit_logs`. FAQ uses restricted Markdown. Preview uses the authenticated session.

No other `content_pages` columns are authorised by this contract.

## 14. Implementation task map

Implementation proceeds as separate M12 tasks after this contract is accepted:

1. **Migration and fixtures:** add the minimum draft, published, SEO, image and homepage-field storage; seed `home`; unpublish placeholder legal rows; add migration tests.
2. **Content domain service:** fixed registry, prepared reads, validation, restricted-Markdown rendering, FAQ parsing, draft fingerprints, transactional save, publish, unpublish and audit snapshots.
3. **Content controller:** POST, CSRF and `content.edit` write gates; `content.view` reads and preview; safe JSON or redirect responses; no exception leakage.
4. **Shared admin route permissions:** admit either view permission, render only authorised tabs, preserve every Messages path, and make the sidebar and new-message count permission-safe.
5. **Page-copy admin interface:** fixed page list, draft editor, SEO fields, image and alt controls, publication state, legal attestation, preview, history and accessible empty or error states.
6. **Public page routing:** clean rewrites, legacy redirects, canonical registry, published-only reads, branded 404 and 503 responses, and delivery-policy anchor compatibility after approval.
7. **Homepage:** connect approved editable marketing fields while retaining catalogue-driven combos, categories and featured products; add the Track 2 image region and honest placeholder state.
8. **Public page templates:** Our Story, How It Works, accessible FAQ, and legal layouts using restricted Markdown and the shared storefront chrome.
9. **Navigation and sitemap:** one publication-aware footer source and dynamic sitemap coverage for current canonical public records.
10. **SEO:** titles, descriptions, canonical URLs, Open Graph and Twitter fields, image fallback and noindex rules.
11. **Legal transition:** keep Terms, Privacy and Delivery Policy unpublished until approval; point interim Make It Right guidance to How It Works.
12. **Verification:** unit, migration, database, HTTP permission and role tests; Messages regression tests; canonical, redirect, sitemap, preview and unpublished-page tests; PHP lint, asset builds, brand check, deploy verifier, and browser checks at 390px and 1440px.

## 15. Acceptance boundaries

M12 is not complete until client-approved Track 2 photographs are present and the required legal pages intended for launch have approved copy. The software may support their draft, preview and publication workflow before those external assets arrive, but placeholders cannot be counted as final documentary photography or approved legal content.

M12 does not add arbitrary pages, unrestricted HTML, public preview links, FAQ records, revision restore, content scheduling, multi-language content, blog posts or page-builder functionality.

# Milestone 12 delivery handover

**Author:** milestone engineer (LoveDatax).  
**Reviewed against:** `CLAUDE.md`, `docs/PRD.md`, `docs/M12_CONTENT_CONTRACT.md` and the M12 checklist.  
**Date:** 17 September 2026.  
**Branch:** `m12-content-messages`.

_This is the delivery write-up for M12. The senior review of this milestone is
kept alongside it as `docs/M12_REVIEW.md`._

## Outcome

The M12 software contract is implemented and the focused evidence is green. The milestone is not ready to close because 2 client-owned content dependencies remain:

1. No approved Track 2 documentary photograph of the real OK Veggies operation is present or published.
2. Terms, Privacy and Delivery Policy still hold explicit placeholder drafts, not client-approved legal copy, and therefore remain unpublished.

The homepage shows an honest branded photography dependency state. Unapproved legal drafts do not appear publicly, in the footer, or in the sitemap.

## Requirement-to-evidence checklist

| Requirement | Status | Evidence |
|---|---|---|
| Documentary homepage hero | Dependency | Upload, alt-text, responsive WebP, draft and publication paths exist; no approved photograph exists, so no stock or synthetic substitute is shown. |
| Homepage promise | Pass | Published `home` copy drives the promise; restricted Markdown is safely rendered. |
| Featured combos | Pass | `Catalogue::featuredCombos()` supplies live featured data; empty and database-error states retain routes onward. |
| 5 category links | Pass | Catalogue categories and counts remain database-driven; every active category links to its shop filter, including zero-count “Being sourced”. |
| Our Story | Pass | Clean published route, safe editorial renderer, metadata, breadcrumbs, shop/support routes and optional approved image. |
| How It Works | Pass | Clean published route, restricted content, operational Make It Right guidance and routes onward. |
| FAQ | Pass | Ordered `## Question` source, duplicate/incomplete/token validation, native disclosures, keyboard operation, no-JavaScript access and Contact/WhatsApp routes. |
| Terms | Awaiting copy | Editor and legal approval gate pass; placeholder remains an unpublished draft. |
| Privacy | Awaiting copy | Editor and legal approval gate pass; placeholder remains an unpublished draft. |
| Delivery Policy | Awaiting copy | Editor and legal approval gate pass; placeholder remains an unpublished draft. |
| Admin content editing | Pass | Fixed 7-page registry, shareable selection, SEO, draft save, publish/unpublish, image fields, author/timestamp, validation, audit history and optimistic conflicts. |
| Publishing and preview | Pass | Public reads use only published snapshots; preview is session-bound to `content.view`, saved-draft only and noindex. |
| Content/Messages permissions | Pass | `messages.view`, `content.view` and `content.edit` are independent. Seeded Owner and Manager plus restricted custom roles pass from real HTTP. |
| Navigation and clean URLs | Pass | One registry owns canonical labels and paths; published pages alone enter footer navigation; legacy query URLs redirect locally. |
| Metadata and sitemap | Pass | Page titles, descriptions, canonical and Open Graph URLs are escaped; sitemap is published-only and excludes previews, drafts and legacy URLs. |
| Accessibility and responsive behaviour | Pass | 390px/1440px browser checks cover headings, keyboard disclosures, JavaScript-off FAQ, 44px controls, no overflow, reduced motion and shared support. |

## Defects fixed in this review

- The content controller called nonexistent `ContentPages::updateImage()`. It now calls the implemented transactional `updateDraftImage()` method for upload and removal.
- Responsive image cleanup referenced an uncaptured regex group. The stored natural width is now captured, so all generated siblings are removed.
- The editor claimed HTML would be displayed as text while validation correctly rejects it. The help copy now states that HTML is not accepted.
- The PHP development-server router did not emulate Apache’s trailing-slash or sitemap rewrites. It now does, so the HTTP tests exercise the deployment contract.
- Role/security coverage now explicitly checks seeded Owner and Manager access, message-versus-content data absence, forbidden JSON, rejected script reflection and preview isolation.

No new schema change was required. Migrations `049` and `050` remain the only M12 migrations; migrations `001` and `006` were not edited.

## Security and permissions

- All writes require POST, CSRF and server-side `content.edit`.
- `content.view` permits Page Copy and saved-draft preview without granting edits.
- `messages.view` permits Messages without exposing page drafts.
- A role with neither viewing permission receives no route or navigation access.
- Optimistic draft fingerprints return a conflict instead of overwriting newer work.
- SQL values are bound, public/admin values are escaped, restricted Markdown never executes stored HTML, and controller exceptions are logged without being returned.
- Legal publication requires a positive client-approval attestation. The repository’s placeholder legal wording is not approved legal advice.

## Verification

- MySQL 8.0.46: clean migration run succeeded; second run reported nothing pending.
- Unit: **3,326/3,326** assertions.
- ContentPages database: **51/51**.
- Content admin HTTP/RBAC: **53/53**.
- Messages database/HTTP: **27/27** and **42/42**.
- Public content HTTP: **52/52**.
- Homepage HTTP: **20/20**.
- Sitemap HTTP: **18/18**.
- Focused M12 storefront access: **8/8** for guest, household and business customers; wider customer HTTP regression: **132/132**.
- Browser: homepage **29/29**, Content admin **42/42**, public content **40/40** at 390px and 1440px. A first cold throttled homepage run was **28/29** at 3,780ms FCP; the immediate repeat was **29/29**.
- Syntax: **45** touched PHP files passed `php -l`; **9** touched JavaScript files passed `node --check`; touched shell scripts passed `bash -n`.
- Brand: **8/8** checks.
- Real Apache deploy verifier: **29/29**, including protected-directory denial.
- `git diff --check`: passed.

The repository-wide runner finished with **48 suites passed, 6 failed and 2 skipped** in the isolated review environment. All M12 and Messages suites passed. Non-M12 failures were caused by a pre-seeded M11 credit fixture, unavailable SMTP, missing `XMLWriter` for a pricing spreadsheet test, and a Docker MySQL account without the privilege needed to create 2 temporary test triggers while binary logging is enabled. The refund gateway and aggregate browser runner were skipped. These are recorded here and are not treated as evidence that the 2 M12 dependencies are complete.

## Handover

To close M12, the client must supply a rights-cleared real-operation photograph with meaningful alt text and approve final Terms, Privacy and Delivery Policy copy. Staff then save and publish those assets through Page Copy. Re-run the focused HTTP, browser, sitemap and Apache verifier suites; only then change both M12 headline checkboxes to complete.

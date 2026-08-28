# ERP-KOL-M1.3-CONTENT-001

Status: FINAL GATE PASS / OWNER UAT PASS
Baseline: `main@68c4021fb84fe1fed1a2474328bc7d060813d325`
Branch: `feature/erp-kol-m1-3-content-001`
Owner UAT: PASS on 2026-08-29

## Scope
- Add canonical KOL Content records as child facts of KOL, optionally linked to one formal Collaboration.
- Own platform, content type, two-state publication status, Content post URL and planned/actual publish dates.
- Add private publication asset screenshots/attachments and evidence classification: `none`, `url`, `screenshot`, `url_and_screenshot`.
- Enforce frozen rule: `已合作 != 已发布`; `published` requires URL and/or attachment evidence.
- Add server-side `Has Published Content` and `Publication Evidence` filters to KOL Library.
- Project Content/evidence into KOL Detail without duplicating Collaboration/Fulfillment truth.

## Hard boundaries
- `kol_collaborations.post_url` remains the M1.2A primary/representative cooperation URL; `kol_contents.post_url` is the URL of one concrete Content record.
- One Collaboration may have multiple Content records; M1.3 does not auto-convert legacy Collaboration URLs into Content truth.
- Evidence files use private storage and are served only through authenticated, Owner-scoped ERP routes.
- M1.3 does not own Order, Inventory, Warehouse, Shipment, Tracking, Affiliate, commission or settlement truth.
- No continuous scraping, social OAuth ingestion, automated performance metrics, Audience Analytics or AI scoring in this package. The only remote fetch is an explicit Owner-triggered one-shot post-image sync restricted to allowlisted social-platform/CDN hosts, with manual paste/upload fallback.

## Content Editor UI V2 frozen contract
- Editor is a right-side Drawer with sticky top actions: Cancel / Save / Close.
- Existing Content KOL is read-only; new Content uses server-side KOL search.
- Platform defaults to Instagram; content type defaults to image/text post.
- Publication status is only `published` / `unpublished`, rendered as a two-button switch.
- All M1.3 publish fields are date-only; canonical migration uses SQL `DATE`.
- Content notes are removed from the M1.3 schema and UI.
- Collaboration primary URL is reference-only and copies to Content URL only after an explicit click.
- Publication asset screenshot supports click upload, drag/drop and Ctrl+V / Cmd+V paste.
- Existing private assets render as thumbnail cards with View/Delete actions.
- `Sync post image` is best-effort, Owner-triggered, replaces only the previous synced image, stores privately, audits the mutation, and rejects IP/private/arbitrary-host fetches.

## Advanced-mode architecture corrections
- Replaced public evidence storage with private storage plus permission-controlled delivery.
- Replaced bulk KOL option payload with server-side option search.
- Replaced fixed Content list limit with server-side pagination.
- KOL identity on a Content record is immutable after creation; linked Collaboration must belong to the same KOL.
- Combined published/evidence filtering must match the same Content row to avoid cross-content false positives.
- KOL Library uses lightweight SQL existence projection rather than eager-loading Content/attachments.

## Technical gate evidence
- M1.3 focused backend: 11 tests / 50 assertions / 0 failures.
- Full KOL backend: 210 assertions / 0 failures.
- Frontend Vitest: 28/28 PASS.
- TypeScript: PASS.
- Production build: PASS.
- SQLite migration fresh -> rollback latest -> reapply: PASS.
- Targeted MySQL M1.3 migration apply -> rollback -> reapply: PASS.
- Full-repository MySQL fresh is blocked by a pre-existing unrelated Inventory migration whose generated index name exceeds MySQL's identifier limit; M1.3 migration itself passed targeted MySQL rehearsal.
- `git diff --check`: PASS.
- Audit regressions cover Content create, Evidence add/delete and post-image sync mutations; sync security regression rejects non-platform/IP URLs before any HTTP request.
- Owner UAT target: isolated `http://127.0.0.1:18111`.

Delivery PR may be created after Final Gate. Merge remains separately gated by explicit Owner authorization.

## Isolated Owner UAT readiness
- UAT URL: `http://127.0.0.1:18111`.
- UAT DB: isolated worktree SQLite copied from the completed M1.2B dataset, then migrated with M1.3 only.
- Prepared Content states: unpublished/no evidence, published/URL evidence, published/private screenshot evidence; all dates are date-only.
- Authenticated smoke: `/kol/content` 200; `/kol/library/1` 200; private evidence delivery 200 for authorized Admin.
- Server-side KOL option search 200 and returns only scoped KOL/collaboration data.
- KOL Library `has_published_content=yes` and `publication_evidence=screenshot` filters return the expected KOL.
- Content page `published + url` returns only URL-backed Content; `published + screenshot` returns only screenshot-backed Content.
- No `.env`, SQLite DB, private evidence, build output, vendor or node_modules artifacts appear in Git status.

- Owner UAT: PASS on 2026-08-29.
- Final post-UAT SQLite migration targeted fresh -> rollback M1.3 -> reapply: PASS (2 -> 0 -> 2).

- Rebased cleanly onto latest remote main `ead8b2d3fde7e9ddd72a5c3c031d310b2c1b0828` after concurrent MVP1 stabilization updates; no conflicts.

- Rebased Final Gate on latest main: TypeScript PASS; frontend 28/28 PASS; focused backend 50 assertions / 0 failures; full KOL 210 assertions / 0 failures; production build PASS; targeted SQLite migration 2 -> 0 -> 2 PASS; diff check PASS.

- Rebased cleanly onto `main@68c4021fb84fe1fed1a2474328bc7d060813d325` after PR #115 Page Header Action Zone; KOL Index/Show overlap produced no conflicts and the full KOL gate remained green.

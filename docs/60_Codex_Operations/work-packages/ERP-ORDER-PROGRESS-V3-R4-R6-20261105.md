# ERP Order Status V3 — R4/R5/R6 release gate (2026-11-05)

## Approval / source of truth
Owner approved Plan B; R0–R3 isolated UAT **PASS**, and then explicitly said `pass 继续` to continue R4–R6. Actual Production deployment still requires a separate controlled release approval; do not infer deployment simply from a development pass.

Built on R0–R3 Draft PR #612 (commit `e6cf80845e191c1bd97998b51b9c6cb5bfa68a00`) and merged the later `main` baseline `efc8b60070e89143b0c787d6383d11b1d60f246a`. Main's Processing/KOL allocation eligibility, operational archive, tracking auto-sync, overdue inventory and sync UI changes must remain intact. Runtime/GitHub `main` wins over outdated documentation.

## R4 — Split-root roll-up / auxiliary flags
- Derived fulfillment children retain their own authoritative Shipment, review, Tracking and actual progress.
- Parent's displayed progress is a read-only aggregate: allocated → partially_shipped → shipping/completed, and escalates to progress_conflict if any child has a direct conflict.
- Root phase queries exclude roots with conflicting children from partially_shipped.
- Existing customer follow-up annotations (Cost / Renew / Claims / Missing Info / Returned follow-up) are auxiliary markers `workflow_aux_*`, not executable fulfillment stages; no data migration/backfill.
- Legacy `workflow_status` auxiliary values remain readable until explicitly changed/removed. Changing the dropdown never silently clears older notes.
- Authenticated `orders.edit` actors can add or remove auxiliary markers through a revision-gated/audited route. An actual dispatch, shipment, return, stockout, completed, and archive remain controlled by their own authoritative services.
- Keep current compact status pill; no new dashboard/page. Archive/read-only gate from newer main remains intact.
- Customer pending/review/sending pages and provider daily/export eligibility use shared progress query. KOL's prior stage semantics remain untouched in this release.

## R5 Evidence
- Dev-only R4 isolated SQLite, created from LocalUatSeeder and synthetic V3 data; never uses Production database, Woo credentials, shipping API or external writes.
- Local PHP targeted suite: 591 tests, 7462 assertions PASS (before final split conflict fix); 8 V3 tests / 90 assertions PASS after conflict filter fix. Frontend: 19/19 tests, TypeScript PASS, Vite build PASS.
- Baseline-only broad suite revealed 25 pre-existing failures: 24 in legacy LogisticsOsV1ClosureTest and 1 in CarrierMasterV2Test. Exact same count reproduced on `origin/main@efc8b600`; do not misrepresent broad suite as full PASS and do not weaken the currently enforced domestic review gate.
- Isolated UI UAT path: `http://100.94.52.109:18734` (Tailnet only, US Mac mini), login HTTP 200, unauthenticated protected review returns 302. 5 synthetic scenarios projected: pending_allocation, pending_review, sending, out_of_stock, progress_conflict. Server-side authenticated Inertia list view returned 200 in review, stockout, pending, today-4px and exceptions. Owner R0–R3 separate UAT already PASS.

## R6 rollout gate / rollback
1. Current main must be re-fetched and compared; PR CI for exact combined HEAD must PASS (especially MySQL, SQLite, PHP, TypeScript).
2. Confirm no unapproved changes to actual orders, stock, shipments, refunds or WooCommerce in the release diff. Database migration is **not needed** for R4: uses existing `order_operational_markers`.
3. **Separate Owner Production `pass` required after review.** No forced GitHub merge, Production symlink switch, PHP-FPM reload, or live order mutation before approval.
4. Back up database using existing encrypted backup engine; verify checksum, previous release path, production rollback compatibility and current enabled external hooks.
5. Deploy by verified commit and read-only smoke: login, Kaven 3-way review, Vita allocation, Dede pending/review, Liang pending, parent/child progress, auxiliary marker visibility, historical exceptions, archive protection, tracking/sync isolation. Do not use real customers for writes without separate canary authorization.
6. Keep legacy 8 anomalous 4PX orders out of any automatic backfill. Repair requires evidence-backed separate authorization. On smoke failure: atomic code symlink rollback; preserve audit, logs, and backup.

## Strict exclusions
No UI redesign, no Shipping/OMS rewrite, no automatic historical remapping, no KOL flow redesign, no external payment/sync writes. All old unresolved issues tracked separately.

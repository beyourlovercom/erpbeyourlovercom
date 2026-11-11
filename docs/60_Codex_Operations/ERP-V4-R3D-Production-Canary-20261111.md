# ERP V4 R3-D · Controlled Production admin Canary deployment

**Executed 2026-11-11 03:30 UTC.** The Owner explicitly approved the five-operator R3-D resource budget and controlled admin Canary rollout.

## Source and exact scope
- Source: [PR #702](https://github.com/beyourlovercom/erpbeyourlovercom/pull/702), merged `eedf5594891bfcea8db999206600fd3531ac6d39`.
- Production base at staging and pointer switch: `global-grid-r4-pending-pr700-1eeb37e7-20261111`. The R3-D release did **not** deploy all of GitHub `main` over concurrent ERP work.
- Candidate live release: `/Users/a/ai/production/erp.beyourlover.com/releases/erp-v4-r3d-admin-canary-pr702-eedf559-20261111`.
- Per-release source and rollback manifest: `r3d-release-manifest.json`. Previous intact release: `/Users/a/ai/production/erp.beyourlover.com/releases/global-grid-r4-pending-pr700-1eeb37e7-20261111`.
- Overlaid **four R3-D runtime frontend files**: WorkspaceTabs.vue, AuthenticatedLayout.vue, workspaceTabs.ts and new workspaceHotCache.ts. Two nonruntime pre-existing UI test fixture typing corrections from PR #702 were also overlaid so staged Vue typecheck could pass. All other Production source files and existing ERP business logic preserved.
- Pre-switch byte-by-byte fingerprint check: **888 app PHP files, 2 route PHP files, 36 config PHP files and 167 database migration PHP files unchanged**. Composer lock, scheduler routes and ShipOut config unchanged.
- Vite build controlled with `VITE_ERP_WORKSPACE_TABS=1` and `VITE_ERP_WORKSPACE_CANARY_IDS=1,4`, verified against current enabled administrator role IDs. Admin must opt in using `/dashboard?erp_v4_tabs=1`; opt out using `/dashboard?erp_v4_tabs=0`. No automatic staff-wide enablement.
- Shared `.env` symlink remained untouched. No migrations, real customer writes, dispatch POST, cache clearing, or worker/device/Chrome restart.

## Dev verification before Production cutover
- Five authenticated synthetic users in isolated US Mac mini SQLite Dev, each mounting existing Domestic Index and AG Grid with 50 synthetic orders: **PASS**. Concurrent group ~838ms on localhost PHP built-in server; not a Production P95 capacity claim.
- Returning to warmed page snapshots: ~60–68ms in two Chromium trials for page visibility, before authoritative server GET completed; main operations locked until latest-data validation.
- 1/10/20/30/31 sequential real Grid route visits: forced-GC JS Heap 3.90/12.83/13.33/13.97/13.97 MiB, max 30 bookmarks, max three hot response snapshots, three pins intact; login was only observed POST during stress test.
- KOL unsaved form: cancel/accept navigation PASS. Intentional revalidation GET failure kept page actions locked, showed Retry, and recovered after a successful GET; expected single network error noted. Opt-out clears hot data.
- 13 relevant frontend tests PASS, TypeScript PASS, Vite PASS, 59 Laravel feature tests / 698 assertions PASS.
- Full Vitest still has **five pre-existing Warehouse UI expectation failures in two files**, previously reproduced before R3-D; do not represent all-suite PASS.
- The hot snapshot cache is *not* a mounted Vue KeepAlive cache. Future true page instance retention/scroll-editor state restoration requires separate safe persistent layout migration.

## Cutover and post-check
- Current Release pointer was checked immediately before the atomic symlink replacement; candidate hashes, backend parity, shared environment, runtime roles and build assets were checked.
- Production /login and unauthenticated /dashboard redirect flow returned HTTP 200; new `/build/assets/app-CAQZo6sg.js` returned HTTP 200. Atomic cutover completed without rollback.
- Read-only check at 2026-11-11 **03:31 UTC** confirmed Release pointer and manifest status `DEPLOYED_CANARY`. All three Production LaunchAgents (Scheduler, default Queue, dedicated ShipOut) were running; queue lengths sampled at 0/0. Scheduler error log empty; existing queue and ShipOut error logs unchanged since prior timestamps.
- Isolated Dev port 56988 was stopped after process-parent/CWD ownership verification; temporary synthetic login credentials and browser harness files were deleted. Other UAT and Chrome processes untouched.

## Acceptance remaining
- Real Production administrator visual review of opt-in/out, actual order grid filters, navigation, pin/unpin and operations is **pending**; only public HTTP and synthetic authenticated Dev probes have been run.
- The 5-user Dev test does not validate sustained Production throughput or actual staff network latency.
- This Canary is deliberately opt-in. Do **not** enable for all staff until owner accepts the interface, multi-hour memory baseline, backend P95, and draft/refresh behavior.
- Do not change the R3-D Canary release if a parallel branch has taken over the `current` symlink. Follow the active release, then do a fresh diff/staging plan.

## Rollback
Before rollback, check that Production `current` still points to `erp-v4-r3d-admin-canary-pr702-eedf559-20261111`. If so, atomically redirect `current` back to `global-grid-r4-pending-pr700-1eeb37e7-20261111`. If another branch now owns `current`, **stop and do not overwrite it**. Never replay ShipOut jobs, restart workers globally, delete UATs or clear Production caches as part of this UI rollback.


## D4 owner-operated Production UX checklist — 2026-11-11

The Owner reported **all six manual inspection items as "已检查" (checked)** in the ERP project conversation. This is an important progress milestone, **not a declaration of six PASS results**: the message did not state whether any item exhibited a defect, unmet performance target, or acceptance/rejection. Do not close the D4 quality gate or expand the Canary solely on this checklist.

| Item | Owner inspection | Outcome |
|---|---|---|
| 1. Page/Tab switching responsiveness | Checked | PASS/FAIL and observations not yet reported |
| 2. Pin three tabs / reject fourth | Checked | PASS/FAIL not yet reported |
| 3. Sleep other tabs and restore | Checked | PASS/FAIL not yet reported |
| 4. Cached data revalidation / operation interlock | Checked | PASS/FAIL not yet reported |
| 5. Unsaved KOL/form draft confirmation | Checked | PASS/FAIL not yet reported |
| 6. Longer use: scrolling/filtering/perceived responsiveness | Checked | PASS/FAIL and duration not yet reported |

**Read-only Production verification at 2026-11-11 04:06 UTC:** `current` still points to `erp-v4-r3d-admin-canary-pr702-eedf559-20261111`; unauthenticated Dashboard redirect chain returned HTTP 200 in ~430ms; Scheduler, default queue, dedicated ShipOut batch LaunchAgents all present with PIDs. This is availability evidence, not authenticated frontend acceptance.

### D4 remaining release gates / requested owner feedback
1. Obtain one overall UX verdict (**all six PASS** / **some issues**) and shortest reproduction details for anything abnormal. In particular, page components still remount from hot *data snapshots*, which is an intentional limitation versus true KeepAlive.
2. Test 30th and 31st distinct navigation in the authenticated Canary (max 30, three pins survive eviction); opt out via `erp_v4_tabs=0` and verify ordinary UI resumes. No real shipping/stock writes.
3. Verify cached route state (search/filter/pagination/horizontal scroll) in real Domestic and Orders pages; record whether preserved or reset. This feature was not fully implemented in R3-D, so discrepancies should become D5 acceptance tasks, **not silently marked PASS**.
4. Safe failure handling: on normal user operation, revalidation must unlock once latest data arrives; if network fails, disable actions and show Retry. A synthetic failure was tested in Dev but not intentionally induced in Production.
5. Observability gate before widening: sustained logged-in resource readings (browser full footprint, host memory pressure / swap churn, server PHP-FPM wait & P95, background polling count), plus 5-user peak traffic; the prior 5-user result is an isolated Dev synthetic test.
6. Existing full Vue suite had five unrelated pre-existing Warehouse UI expectation failures; resolve or explicitly document separate R6 exception before claiming global CI clean.

Status: **D4 checklist 6/6 inspected, result unconfirmed; Production Canary remains administrator opt-in only. Not approved for staff-wide release.** No backend services, production data, user permissions, or browser sessions changed by this documentation update.

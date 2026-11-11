# ERP V4 D5 — Production administrator Canary release (2026-11-11)

## Scope
Owner instructed to continue from completed D5 Dev and D6 bounded five-user synthetic tests to **D5 controlled administrator-only Canary**. Staff-wide rollout remains prohibited pending real use and multi-hour monitoring.

- Source [PR #704](https://github.com/beyourlovercom/erpbeyourlovercom/pull/704), merged GitHub main SHA `1f25c7eeaaed6c29adf6c336bb6439f7aac73963`.
- Live Production base before switch: `/Users/a/ai/production/erp.beyourlover.com/releases/erp-v4-r3d-admin-canary-pr702-eedf559-20261111`.
- New Production release: `/Users/a/ai/production/erp.beyourlover.com/releases/erp-v4-d5-admin-canary-pr704-1f25c7e-20261111`.
- Previous release preserved for guarded rollback. Full source SHA hashes and release metadata in `d5-release-manifest.json` within new release.
- Minimal overlay: `resources/js/Components/AppShell/WorkspaceTabs.vue`, `resources/js/Components/DataGrid/OmsGrid.vue`, new `resources/js/lib/workspaceViewState.ts` (3 frontend runtime files), plus one D5 unit test file. Existing AG Grid behavior and Laravel backend untouched.
- Build time flags `VITE_ERP_WORKSPACE_TABS=1`, `VITE_ERP_WORKSPACE_CANARY_IDS=1,4`; administrators must explicitly opt in per browser via `/dashboard?erp_v4_tabs=1`. Opt out via `=0`.
- No database migration, order/inventory/warehouse write, ShipOut replay, worker restart, device restart or Chrome restart.

## Release gates and evidence
- 2026-11-11 04:57–05:00 UTC: staged as copy-on-write clone of live R3-D Canary with only D5 files overlaid from exact merged commit.
- Staged TypeScript `npm run typecheck` **PASS**; controlled Vite build **PASS**. New asset `/build/assets/app-Bg5It_7d.js` generated and HTTP 200.
- PHP parity against immediately prior Production release: **888** app, **2** route, **36** config and **167** migration PHP files all identical by SHA-256; `composer.json`, `composer.lock`, bootstrap files identical. Shared Production `.env` symlink unchanged.
- Source hash assertions for each patched file and compiled asset presence passed. Three Production LaunchAgents (scheduler, default queue, ShipOut batch) were running before cutover.
- Atomic `current` symlink switch at **2026-11-11 04:59:52 UTC**, with automatic rollback if the post-cutover HTTP /login, /dashboard or new application JS asset did not return HTTP 200. All three **PASS**; cutover marked `DEPLOYED_ADMIN_CANARY`.
- Read-only post-check at **05:00:13 UTC**: Production pointer still D5 release; scheduler PID 46979, default queue PID 47320, ShipOut PID 61595, all running. Redis default and shipout-production-batch queues each **0** at the instant sampled. Existing error logs show no new cutover-time modified timestamps; not a guarantee of future absence of errors.
- Current US Mac Chrome was **not restarted**, and existing browser windows were preserved. A new tab was opened with Canary URL; browser redirected to `https://erp.beyourlover.com/login`, title "BYL ERP · 登录". **Production authenticated owner UX has not yet been tested**; Owner must log in.

## Prior Dev evidence
- Real Domestic Index + actual AG Grid, isolated SQLite/synthetic 50 rows: searched `needle`, page 3/5, horizontal scroll 300px were preserved across tab-return and F5; ~75ms and ~104ms for scroll restoration once grid was ready. No JS error.
- Five authenticated synthetic sessions, 51 rounds, 255 browser navigations over ~151 seconds; median 240ms, P95 405ms, max 677ms, 0 page errors and 0 HTTP 5xx.
- Five-session GC JS Heap approximately 70.01MiB at 30-tab threshold and 71.49MiB at final sample; not equivalent to browser process RSS. Host swapout counter did not increase in this bounded test.
- Laravel regression 59 tests / 698 assertions PASS. Targeted Vue suites PASS; **five pre-existing warehouse old-UI assertions in two Vitest files remain red**. Do not claim whole suite green.
- Audit document: `docs/60_Codex_Operations/ERP-V4-D5-D6-Dev-Acceptance-20261111.md`.

## Remaining gates before expanding beyond admin
1. Administrator authenticates and verifies on actual Production orders and Domestic GlobalGrid: search / filter / pagination / horizontal scroll preserved after switching to another ERP Tab and back, then F5; no unintended changes to preferences or draft.
2. Multi-hour measurement of real Chrome full memory/RSS, backend production P95, DB requests, polling and swap activity, while ordinary staff workload continues.
3. Five real staff concurrent workday acceptance with no workflow regressions, queue backlog, permission issues or undesirable stale data.
4. Owner approves any staff-wide enablement as a separate controlled release. **Do not remove administrator canary gate without explicit approval.**

## Safe rollback
Check `current` still resolves to `erp-v4-d5-admin-canary-pr704-1f25c7e-20261111`, and that the old release is intact. If both pass and owner requests rollback, atomically point `current` back to `erp-v4-r3d-admin-canary-pr702-eedf559-20261111`. If another parallel release has since taken `current`, **stop and do not overwrite it**. No queue replay, DB changes, cache clearing or global worker restarts.


## Owner D5 Production acceptance — 2026-11-11 (final)

Owner explicitly reported **"效果pass"** after the D5 administrator Canary inspection requested in this project conversation. Record the overall D5 administrator UX verdict as **PASS** (not just "checked"). The signoff covers the D5 owner-observed experience; no additional performance measurements can be inferred from this message.

**D5 manual UX gate: CLOSED / PASS.** Staff-wide rollout remains **NOT AUTHORIZED** until D6 long-running browser/server measurements, five actual employees' normal workflow concurrency acceptance, and separate Owner approval.

### Parallel Production release check, 2026-11-11 05:41 UTC
A separate branch replaced the ERP Production `current` pointer after the D5 Canary release. Live release is now:
`erp-r4-3f-pr703-55846334-over-d5-20261111`.

Read-only comparison against original D5 Release `erp-v4-d5-admin-canary-pr704-1f25c7e-20261111`:
- D5 `WorkspaceTabs.vue`: **byte-for-byte identical**.
- D5 `OmsGrid.vue`: **byte-for-byte identical**.
- D5 `workspaceViewState.ts`: **byte-for-byte identical**.
- Current compiled Vite build contains both D5 grid-layout-ready and R3-D canary feature markers.
- `/login` and unauthenticated `/dashboard` redirects returned HTTP 200.
- Production scheduler, default queue and ShipOut-batch LaunchAgents were all `running` (read-only check).
- Host macOS memory pressure reported 45% free and swap used 13,169.75 MiB at this instant. A single reading does **not** establish a leak or measured D6 duration.

**Operational instruction:** Do not revert to the prior D5 standalone Release or redeploy PR #704 over the newer R4-3F Production code. If future D6 needs release modification, rebase minimally on whatever `current` points to then. No Production deployment was performed for this signoff.

### D6 remains
1. Collect sustained full Chrome process footprint / DOM / CPU / memory pressure and swap-in/out *rate*, together with ERP request latency and worker/queue status. D6 151-second Dev synthetic test alone is insufficient for a multi-hour Production PASS.
2. Obtain normal-workflow acceptance from **five real staff** at the same time (no destructive test orders or external shipping POST in a performance test). Record date, page types, actual timings, failures and resource peaks.
3. Keep Canary administrator-only until D6 real-world criteria pass and Owner explicitly authorizes employee-wide enablement.

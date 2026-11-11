# BYL ERP V4 · R2/R3 Dev acceptance — 2026-11-11

## Scope
- **R2**: Reuse existing Inertia and domestic logistics components. PR #694 and #696 already merged to `main`. Production not deployed from this work.
- **R3-A**: Lightweight navigation workspace, not a second Vue Router, not 30 mounted pages, and not an AG Grid rewrite. Opt-in with `VITE_ERP_WORKSPACE_TABS=1` at Vite build time. Default off.
- **Isolation**: US Mac mini under `/Users/a/ai/uat/erp-v4-r2-r3-20261111`, new SQLite file, synthetic login, local-only `127.0.0.1:56986`. Only existing tests used Production artifacts as cached dependencies; no production database, orders, Chrome user profile, or credentials.
- Temporary acceptance-only page routes, account and browser harness exist in this UAT checkout **only** and are not part of this PR.

## R2 browser acceptance
Authentic Inertia GET `/logistics/china?view=review` in isolated Dev browser: HTTP 200, component `Logistics/Domestic/Index`, full view-count map has nine entries. Browser-authenticated partial requests:
- `rows`: response props `errors, rows`, no `viewCounts` or `notificationCenter`; ~34.4ms and 18.7ms observed.
- `rows,viewCounts`: all nine counts preserved; ~38.1ms observed.
- No Chromium page errors.
Earlier same-host SQLite microbenchmarks: R2-A four→two SQL queries (notification two→zero) on an auth-only partial request; R2-B domestic rows-only 15→3 SQL queries. These timings are **synthetic** and cannot be projected to Production P95.

## R3-A browser acceptance
Using isolated Chromium login / 31 synthetic Dev pages with the existing AuthenticatedLayout:
- One→10→30 internal work tabs: PASS. Page navigation uses Inertia; only one UAT page is mounted at once.
- At 30 entries, next unseen page keeps tab count bounded at 30 (LRU **bookmark** eviction); old components are not cached.
- F5 reload restores 30 tabs in the same user/session; scoped sessionStorage prevents other user ID from reading this state.
- Close and activate tab: PASS; first active tab click navigated to expected safe path.
- Opt-in `erp:workspace-dirty` confirmation: cancel keeps current page on tab click and separate Inertia Link; clear flag allows normal navigation. Native beforeunload listener is installed when dirty.
- 390px mobile tab bar visible; no page JS exceptions.
- Chromium CDP sum for ONE internal workspace: ~5.28 MiB JS heap at one synthetic tab, ~6.94 MiB at 10, ~10.1 MiB at 30 (sample only).
- Separate headless Chromium test: 1/10/30 **independent Chrome tabs** all loaded, aggregate per-page JS heap ~3.14/31.44/94.32 MiB. Headless `visibilityState` was not reliable for proving background page throttling. Do not claim this is full browser RSS or real AG Grid load.
- Build + TS check + Vitest 5/5 + PHP 31 tests/465 assertions PASS.

## Explicit limitations
- Navigation bookmarks are light; opening one again fetches that route and re-renders that page. No fake promise of 30 live cached AG Grids or instant restored unsaved form DOM.
- Draft guard is opt-in by dispatching `erp:workspace-dirty`; other existing business forms are **not yet integrated** and should not be described as protected.
- Real production orders / 30 heavy grids / simultaneous user throughput need separate non-destructive load UAT before any Production enablement.
- This code is **off by default**, no Production deployment or worker restart.

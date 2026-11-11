# ERP V4 R3-D · Five-user bounded workspace pilot (2026-11-11)

## Contract
- Target: five simultaneous ERP operators, 16GB-class client computers.
- **30** navigation bookmarks/session, **3** explicitly pinned (cannot be automatically evicted or closed without unpinning), **3** in-memory hot page *response snapshots* maximum.
- Hot snapshots are **not** three mounted Vue/AG Grid component instances. The existing Inertia page is recreated on client-side navigation; cached page props are shown immediately, then authoritative backend state is checked. This is an intentionally low-risk intermediary compatible with the current page-owned `AuthenticatedLayout`; persistent component instances would require a separate shell/layout migration.
- Up to 1.5MB UTF-8 JSON per hot entry, maximum 60-second lifetime; no response snapshot saved to browser storage. The 30 lightweight bookmarks (including pin flags) use existing user-scoped sessionStorage. Closing the Canary opt-in clears hot data.
- User scoping, asset-version, route, filter URL, and allowed component allowlist are checked before any client-side push. GET hot navigation never posts provider shipments. Every operational page hit revalidates via Inertia `router.reload({showProgress:false})`; Dashboard can skip if under five seconds old.
- While a potentially stale operational page is displayed, `.shell-content` is non-interactive with `aria-busy` until the server confirms. Network failure leaves actions locked and displays an explicit Retry control. It is not acceptable to claim a warehouse action succeeded before its server response.
- Pinning does not force any component or API polling to stay alive. “Sleep other tabs” frees all other snapshots without removing bookmarks/pins.
- The existing 2.5s Warehouse and 5s ShipOut visibility-aware read-only polling remains unchanged. No new pollers or timers.

## Host baseline (read-only)
- US Mac mini, 8 logical CPUs, 16GB RAM, Production PHP-FPM maximum 8 children, two active sampled, memory_pressure about 50% free, swap about 12.45GiB used. This is not proof of current swapping rate or capacity.
- Host had ~53 development artisan serve launchers and ~57 PHP built-in HTTP processes at the sample. No existing services were killed, and Chrome/READY devices were not restarted.
- ERP Production can be updated by parallel branches; any future Canary release must be a minimal overlay onto the **live** release at deployment time and must guard its current pointer before switching.

## Isolated Dev evidence
All Dev tests are on a separate US Mac mini directory and isolated SQLite database, with five generated synthetic local accounts and an opt-in Canary build. Route `/__uat/r3-grid/{n}` returns the actual Domestic Index/AG Grid components with 50 synthetic rows; no external provider credentials, shipping actions or real customer data.

- TypeScript PASS, Vite build PASS, targeted Vue 13/13 tests PASS (workspace pinning, hot LRU, user/version/age/byte cap, Warehouse and MorePlus hidden-poll tests).
- Laravel regression: 59 tests / 698 assertions PASS (Inertia notification + domestic, app shell, warehouse restock, ShipOut batch).
- Full Vue suite: 106 test files PASS, two pre-existing Warehouse UI expectation files FAIL (five old assertion mismatches); same failures were documented in R3-C. No green full-suite claim.
- **Five authenticated browser contexts**: all five real 50-row domestic grids load. Concurrent Dev group completed in 838ms wall time, each individual route ~722–838ms including render wait. This is a *single-threaded localhost Dev server* and is **not** a production 5-user throughput/P95 claim.
- Returning to an in-memory snapshot was observed at ~60ms and ~68ms in two Chrome runs before authoritative GET completed. The old page was visually available immediately, its content locked while validating, exactly one GET revalidation followed; no warehouse POST occurred.
- 31 consecutive real AG Grid route visits, 3 pinned tabs: maximum 30 bookmarks and 3 hot snapshots; the pins remain visible. After GC: JS Heap ~3.90MiB (1) / 12.83MiB (10) / 13.33MiB (20) / 13.97MiB (30) / 13.97MiB (31); nodes ~3276 at 31. Chrome complete RSS is **not** measured by these JS heap figures.
- KOL unsaved draft protection on a hot tab: cancel maintains the form; accept permits navigation. Simulated network interruption: action zone remains locked, retry control displayed, recovery after network restores idle. One intentionally aborted request emitted an expected pageerror, not an unexplained production error. Canary opt-out frees the three-entry in-memory cache.

## Release gates
- No direct Production writes, schema changes, queue retries, or worker restarts are part of this R3-D code.
- Canary must be explicit per admin session (`erp_v4_tabs=1`), and regular employees must not see the UI.
- Check page source/asset parity with the live release; don't deploy `main` wholesale across another branch's changes.
- Record rollback target and all modified source/asset hashes. If live release pointer moves, abort.
- Still outstanding for full desktop-like experience: true keep-alive of three Vue component instances, lifecycle deactivation, per-tab restoration of unsaved rich editor/AG Grid scroll, and sustained multi-hour monitoring. **Do not claim these are implemented by response snapshots.**

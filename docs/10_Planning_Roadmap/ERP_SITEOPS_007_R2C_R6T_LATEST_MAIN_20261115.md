# ERP SiteOps R2-C → R6-T Dev UAT, updated current-main baseline — 2026-11-15

## Source and isolation
- Draft PR #766 is the only intended code branch; no main merge or ERP Production release authorized.
- Latest original ERP main examined: `b07885e36c491a997f88ae3c7a6ef0b14d17ad31` (30 commits newer than earlier `0508a126`).
- Fresh isolated Mac mini Laravel worktree: `/Users/a/ai/uat/erp-siteops-r2c-current-b07885e` and **copied-only** SQLite fixture `/Users/a/ai/uat/erp-siteops-r2c-main-b07885e.sqlite`.
- New SQLite copy contains 126 generated UAT orders, 4 fabricated @local.invalid Laravel users and 11 Dev warehouses; two original-main migrations applied ONLY to that isolated copy: `2026_11_14_220000_create_warehouse_stock_last_read_tables` and `2026_11_15_080000_add_order_routing_warehouse`. No original source/workflow/production write.
- Current-main Laravel Dev listens on private tailnet `http://100.94.52.109:18811` (ephemeral artisan serve, no autostart), 007windows localhost-only Vben at `http://127.0.0.1:18786/`.
- For Blade/Inertia HTML serialization, the new PHP Dev worktree temporarily reuses previous Dev's Vite manifest/build artifacts. **Do not claim native Mac latest-frontend visual parity from this manifest.** 007 Vben has its own freshly built assets.
- The 007 UAT proxy uses actual Laravel CSRF login and per-role session cookies kept server-side; only allowlisted GET orders/customer orders, strips customer PII and any original note content, no business write endpoints.

## R2-C latest-main regression
- 007 direct Laravel session + UAT gateway: Owner 123 records / 7 menu groups, operations staff 123 / 4, KOL specialist 20 / 3, KOL manager 41 / 3. Warehouse shortcuts are returned dynamically from Laravel for Owner. Archived/deleted records excluded.
- GET-only 007 API suite 401 anonymous, POST 405, bad role 400, source-version guard, all four authorization scopes, PII scrubbing and customer grid 20/page: PASS.
- 007 genuine Chrome (`D:/Program Files/Chrome/Chrome/chrome.exe`) login→Laravel permission-scoped first menu→auto-order read, 30-tab stress, KOL role/tab prune, latest-role race control, 390px no viewport overflow: PASS on current main.
- **Not yet done:** actual production staff sessions/Vita/Liang/Dede/Emma/Guts/Hiko role-by-role login parity. The four synthetic accounts are not claimed as real staff. Existing UAT is only a route and query contract benchmark; formal R2-C staff gate remains OPEN.

## R6-T UI and cache evidence
- Vben tabbar `maxCount=30`. Dev-only tab hook enforces up to 3 pinned tabs including pinned Dashboard and implements LRU choice of least-recently used unpinned bookmark. Pin policy pure unit tests 3 PASS; original pinned tabs not auto-evicted.
- Chrome actual tab context menu: Dashboard already pinned; pin Orders and Domestic to reach 3; fourth attempted pin on Warehouse is denied, pinned count stays at 3: PASS.
- Chrome 36 distinct route visits → exactly 30 visible tabs; KOL session switch prunes old forbidden tabs. Direct Vben browser test PASS.
- Read-only Orders response hot-cache is bounded to **3 pages**, **60-second TTL**, **1.5 MB UTF-8 per entry**, in-memory only. Source SHA, role, path scope, query and paging encoded in key. No localStorage/sessionStorage cache of response data; logout clears it; cache is not three mounted Vue instances.
- Cached orders appear immediately on reread but grid marked aria-busy and pointer-disabled until a fresh Laravel GET validates. Chrome deliberately slowed GET: one revalidation request, stale grid locked, later latest rows unlocked PASS. Chrome deliberately aborted GET: cached rows and totals cleared, menu hidden, error shown; explicit retry recovered PASS.
- `erp-hot-snapshots.test.ts` 4 PASS plus pin LRU policy 3 PASS and existing order adapter 5 PASS = 12/12 targeted unit tests PASS, TypeScript and Vite build PASS.
- Hot response snapshot is implemented only for this Vben Orders module. **It is NOT full ERP per-page hot-cache/complex form-draft/AG Grid filter-scroll parity**. 'Sleep other page cache' clears other in-memory Orders snapshots without closing bookmarks.

## Remaining launch gates
1. Actual staff-account/session permission and Dev-only role matrix acceptance: R2-C OPEN.
2. Source baseline/PR rebased to current `main` and Vben clean-machine full base reproduction: OPEN (vendor ZIP SHA lock currently verifies input but does not recreate full baseline).
3. 3-hot-cache parity across future migrated business modules, unsaved forms, scroll/filter restoration and longer 5-user load: OPEN; do not equate the Orders-only cache with old ERP feature set.
4. Owner visual signoff on 007 frontmost Chrome, PR review and explicit approval for main merge/Production release: OPEN.

Safety: no Production SQL/data/API/logistics/stock/Shop actions, no Mac/Windows/Chrome READY process restarts. The 007 Dev localhost gateway alone was restarted to retarget the new isolated PHP Dev port.

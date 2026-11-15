# ERP V2 SiteOps R2-C → R6-T — 007windows Dev evidence (2026-11-15)

## Source of truth / environment
- Draft PR #766 in `beyourlovercom/erpbeyourlovercom`; **no main merge, no Dev/Production release**.
- Versioned Vben overlay: `apps/erp-siteops-vben/overrides`; isolated 007windows working tree `D:\ai\erp-siteops\frontend`, localhost `127.0.0.1:18786`.
- Original Laravel authority at Mac mini exact source `0508a1263e51cfeae8b2f3036bc4f3d83df52386`, `APP_ENV=local`, isolated SQLite UAT fixtures.
- No WooCommerce/OMS/warehouse business write; the gateway still only allows signed/read-only `GET` orders. This PR does not bypass Laravel RBAC.

## R2-C — real staff session and real Dev business data (NOT YET PASS)
- Original Laravel login, session, authorization and original `GET /orders`/`GET /customers/orders` **have been validated on generated isolated test accounts**, not on the actual employee session.
- Four fixture role outcomes rechecked via `r2b-current-smoke.cjs`: Owner 123, staff 123, KOL specialist 20, KOL manager 41; 401/405/400 deny cases PASS; PII projection and archived/deleted exclusion PASS.
- 007 headless Edge `r2b-current-vben-uat.cjs` PASS: authorized sidebar, owner 123, KOL 20, manager 41, search 1→123, customers 82 / page 20, no synthetic data leak, desktop/mobile no body overflow.
- **R2-C HOLD:** actual Dev employee sign-in (not UAT role selector), current real Dev orders and staff-role matrix, long session/logout/relogin, and differential read-only comparison against the original Dev UI are not evidenced. Never use Production credentials or copy Production data into the UAT SQLite to claim this gate.

## R6-T — 30 navigation tabs / 3 pins / 3 hot responses
- The original ERP source `resources/js/lib/workspaceTabs.ts` fixes `WORKSPACE_TAB_LIMIT=30` and `WORKSPACE_PIN_LIMIT=3`; `workspaceHotCache.ts` caps **3 serialized responses**, TTL 60s, 1.5 MB each. Full AG Grid components must not be retained merely to keep tabs.
- Vben Dev policy limits 3 pinned tabs including the default pinned Overview, blocks a fourth pin, and selects LRU non-pinned bookmarks for 30-tab overflow.
- All child business routes now use `keepAlive:false`. Sidebar keeps lightweight bookmarks; **no 30 mounted grids**. Only Overview remains affixed.
- Orders Dev-only cache retains at most 3 role/scope/search/page-specific sanitized API snapshots in memory, TTL 60s / per-entry 1.5 MB, LRU. Each cache preview triggers a fresh authorized GET. While refreshing the grid is non-interactive, and a failed GET clears the UI and affected snapshot. Role switch and auth loss clear snapshots; other snapshots can be slept manually. No persistent cache or artificial order source.
- Test results on isolated 007windows: R6-T policy/snapshot Vitest **7/7 PASS**, Vue TypeScript `vue-tsc --noEmit` PASS, `build.cmd` (typecheck + Vite build) PASS. Prior R2-B browser regression PASS on rebuilt artifacts.
- **R6-T browser-specific acceptance pending:** exhaustively verify 30 tab overflow, 3 pin UX, three hot entries and refresh failure handling in 007 Chrome/Edge before Owner visual PASS. Exact pinned Vben vendor baseline reproducibility remains a release gate.

## Follow-up gates (not release approvals)
1. R2-C: obtain a legitimate current Dev staff session without using Production cookies or invented credentials; compare original and Vben route behavior read-only under multiple genuine Dev roles; capture role matrix and regression logs.
2. R6-T: dedicated 007 Chrome desktop/mobile UAT for 30/3/3, reload/logout/permission change, hot snapshot stale/timeout/budget and memory; no hidden AG Grids or cross-role data.
3. Rebase on exact current main, re-run CI and Owner visual UAT; request **separate** authorization before any PR merge, Dev exposure or Production cutover.

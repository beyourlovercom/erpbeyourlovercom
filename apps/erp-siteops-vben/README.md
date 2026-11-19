# BYL ERP SiteOps / Vben — 007windows Dev overlay

This is a **Dev-only, opt-in read-only** migration experiment tracked inside the existing BYL ERP repository. Laravel remains the only order/business authority.

## 2026-11-19 — three logistics pages: Owner visual PASS / Dev code closure

- Three independent Vben routes: 待分仓 (`logistics-wait-stock`), 国内发货 (`logistics-china`), 海外仓发货 (`logistics-oversea`).
- Versioned source: `overrides/src/views/erp/logistics-*.vue`, `overrides/src/api/erp-logistics.ts`, `erp-logistics.test.ts`, `router/erp-view-resolver.ts`, and the exact navigation/login wiring.
- Canonical backend is **separate Draft PR #803** (GET-only Laravel projections); this Draft PR #766 contains only the Vben overlay and local Dev gateway. Neither PR may be merged or deployed to Production by this checkpoint.
- Build with `VITE_ERP_IDENTITY_MODE=laravel`; the isolated fake JWT is never an alternative authority for logistics data.
- The isolated gateway `runtime/erp2-logistics-dev-gateway.cjs` requires explicit `ERP2_LOGISTICS_DEV_UPSTREAM=http://<tailnet-ip>:<isolated-dev-port>` and an **absolute** `ERP2_LOGISTICS_VBEN_DIST`. It binds **only** to `127.0.0.1:18795` by default, refuses Production/public upstream hosts, external redirects, and all non-whitelisted business writes.
- Owner UAT preview is **local to 007windows**: `http://127.0.0.1:18795/#/erp/logistics/logistics-wait-stock`; this URL is not reachable from other machines.
- Verified against **synthetic isolated SQLite only**: allocation 3 rows, domestic 2, overseas 4 (including A/B/C); frontend Vitest, typecheck and build PASS, genuine Laravel session Owner login and 007 Chrome page walkthrough PASS.
- Do not commit personal Chrome profiles, local credentials, fixture databases, compiled dist, or host-specific service config. No Woo/OMS/Production business data access or write.

## 2026-11-16 — canonical employee ACL Dev opt-in (P0 OPEN)

- The original ERP Production persisted user IDs/roles/permission counts for Vita, Liang, Dede, Emma, Guts and Hiko are recorded in `docs/10_Planning_Roadmap/ERP2_IDENTITY_PERMISSION_PARITY_OWNER_FREEZE_20261116.md`. **All six old 007 Dev fixtures have different IDs/rights**, and Emma is actually a KOL manager in Production.
- Laravel now provides **Dev-only GET `/_erp2/identity`** using its existing authenticated web session, dynamic role/custom effective permissions and authorized shell navigation. No password/token fields or client-selected role/user. It rejects local admin bypass.
- A same-origin isolated Dev reverse proxy can opt into **`VITE_ERP_IDENTITY_MODE=laravel`** at frontend build time. In this mode, Vben ignores its isolated fake JWT for ERP authorization, hides the role selector/synthetic data, in the P0-A..D isolated identity-only preview, caches verified sidebar routes and revalidates Laravel every 60 seconds or on window focus; the older fixture gateway still uses its separate Dev-only order connector. **Do not enable on the current 007 fixture gateway** until a real Laravel Dev session and same-origin routing are proven.
- In-memory SQLite PHPUnit contract regression: **4 tests / 24 assertions PASS**. Node 26 TypeScript adapter parse/fail-closed smoke PASS. PHP syntax checks PASS.
- **P0 remains HOLD** until 6/6 real employee sessions and effective rights, direct URLs, data scope and revocation pass. PR #766 stays Draft; no Production endpoint/deployment and no resumed P1 migration.

## Updated 2026-11-15 — current main + reproducible Dev dependencies

- Reference for isolated latest Laravel UAT: `docs/10_Planning_Roadmap/ERP_SITEOPS_007_R2C_R6T_LATEST_MAIN_20261115.md`. R2-C actual employee sessions are still OPEN; only isolated test accounts/data are in scope.
- R6-T 30 tabs / 3 pins / 3 in-memory Orders snapshots passed Chrome and targeted 12/12 tests. Other ERP modules are not yet migrated into functional Vben pages.
- A cold extract of the SHA-256-pinned Vben ZIP initially failed TypeScript because the vendor source omits `ag-grid-community` and `ag-grid-vue3` (each `36.3.0`). The versioned `scripts/patch-pinned-ag-grid.cjs` adds exactly those dependencies and frozen-lock transitive metadata, preserving original Vite lock values.
- The normal `scripts/bootstrap.cjs --apply-overlay` now applies this pin to the isolated 007 Dev frontend, and refuses unknown or partially-patched lock state. A dry run never mutates.
- For independent offline verification: extract the ZIP named in `vendor-lock.json` into a fresh `D:/ai/erp-siteops/<isolated-name>/vue-vben-admin-main`; copy `overrides/` into its `apps/web-antd/`; run `node apps/erp-siteops-vben/scripts/patch-pinned-ag-grid.cjs <isolated-vben-root>`; then run `pnpm install --offline --frozen-lockfile`, `pnpm --filter @vben/web-antd typecheck`, targeted Vitest, and `pnpm --filter @vben/web-antd build`. This sequence passed on 007windows. It is not yet a vendor artifact distributable to any clean machine.
- Proof and remaining launch gates: `docs/10_Planning_Roadmap/ERP_SITEOPS_007_R6T_REPRODUCIBLE_BASE_20261115.md`.

## Status on 2026-11-14
- 007windows site: `http://127.0.0.1:18786/#/erp/orders` (loopback only).
- 007windows browser tests PASS using 10 actual **Mac mini local Dev SQLite** records, not Production orders.
- Legacy SiteOps remains untouched: 007windows 18777 and 18778.
- 007windows Laravel full local runtime is **not ready**; the R2 bridge temporarily reads the Mac mini's existing local `erp.b` Dev using its original GET `/orders`.
- The Mac mini's Dev instance is an **older worktree** with a local SQLite test database. Do not claim latest Production parity.
- All business writes are forbidden; Mac bridge accepts only GET, strips emails, addresses, customer information and notes. Its order representation is limited to order number, status, warehouse, SKU, tracking and date.
- The source Mac Dev app may use local UAT Owner auto-auth. **Individual ERP role/session parity is not validated**, and this bridge must never be treated as Production authentication.

## Git and source
- Repository: `beyourlovercom/erpbeyourlovercom`
- Branch: `feat/erp-siteops-007-r0-orders-readonly-20261114`, PR #766 (Draft).
- `overrides/`: Vben navigation, Orders AG Grid, adapter, local login copy.
- `runtime/gateway.cjs`: localhost-only 007 owner login, validated read-only proxy, no business POST.
- `runtime/r2-smoke.cjs`, `runtime/r2-browser.cjs`: API and Edge UAT evidence.
- `devbridge/mac-r2-readonly.cjs`: tailnet-only GET transport to `http://erp.b/orders`; no arbitrary URL fetch, remote writes or credentials stored in source.
- The Vben **full vendor base is not versioned** by this overlay. Source is the approved SiteOps Vben V9.1 on 007windows; a pinned complete base is a later prerequisite for reproducibility.

## Strict local-only configuration
Secrets are never committed. Existing private files on 007windows:
- `D:\ai\erp-siteops\private\local-admin-key.txt` — local owner UI login.
- `D:\ai\erp-siteops\private\dev-bridge-token.txt` — random Mac transport token.
- `D:\ai\erp-siteops\private\dev-bridge-address.txt` — tailnet-only local Dev bridge URL.
- `D:\ai\erp-siteops\frontend\apps\web-antd\.env.production.local`: `VITE_ERP_DEV_BRIDGE_ENABLED=1` in **007 UAT only**.

Private Mac mini files adjacent to bridge script:
- `bridge.token` — same random secret, mode 0600.
- `bridge.config.json` — approved tailnet IP and local UAT SQLite path.
- Do NOT commit these files, dump the SQLite database, expose Dev bridge to public Internet, or connect to production URLs.

## How to rebuild / run (Dev only)
1. Keep old SiteOps Vben V9.1 untouched and copy its approved Vben workspace into `D:\ai\erp-siteops\frontend` (excluding node_modules/dist/secrets).
2. In the ERP git branch, run `node apps/erp-siteops-vben/scripts/bootstrap.cjs` for dry run; then `--apply-overlay` only with the isolated target.
3. On 007windows install/build using `install-offline.cmd` and `build.cmd` copied into the isolated runtime.
4. Start `node D:\ai\erp-siteops\gateway.cjs` (localhost:18786); start the Mac Dev bridge with its *local private config* only.
5. Execute `node D:\ai\erp-siteops\r2-smoke.cjs` and `node D:\ai\erp-siteops\r2-browser.cjs`.
6. The bridge is a temporary Dev transport. On 007 loss of Mac connection, fail closed with an error and **never silently restore synthetic sample rows**.

## Mandatory R2 hold points
- Connect a current ERP Laravel **Dev**, with per-user authenticated session and `can:orders.view` permissions, before considering read API parity complete.
- Independently validate archive/deletion, workflows, KOL row scope, global server search/sort, 20/50/100 pages and workspace tabs.
- No merge to `main` or Production deployment from this UAT checkpoint without Owner visual acceptance and separate release approval.

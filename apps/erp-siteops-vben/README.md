# BYL ERP SiteOps / Vben — 007windows Dev overlay

This is a **Dev-only, opt-in read-only** migration experiment tracked inside the existing BYL ERP repository. Laravel remains the only order/business authority.

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

# ERP SiteOps R2-B Login, Navigation and Git Gates — 2026-11-15

## Completed on isolated 007windows
- Draft PR #766; not merged or deployed to ERP Production.
- 007windows genuine Chrome executable used in a separate headless Playwright process, without restarting the existing browser.
- Before showing any sidebar, Vue route guard obtains current-main Laravel Dev-authorized navigation; Owner UAT sees 7 authorized top-level modules and real 11 warehouse shortcuts. It does not initially flash all 13 static modules.
- Opening order master automatically renders 123 isolated SQLite Dev fixture rows, without synthetic placeholders; KOL specialist returns 20 and hides Inventory/Accounting; after 36-route tab saturation its prior forbidden cached tabs are pruned (12 KOL-allowed tabs remain); back to Owner gives 123; rapid manager→Owner switch retains latest role only.
- Previous Edge tests passed KOL manager 41, server search 1→123, customers 82 and 20/page, desktop and 390px mobile no global horizontal overflow.
- API tests passed 401 anonymous, 405 business writes, 400 invalid selectors, server-permission scopes and PII stripping.
- TypeScript and Vite build PASS; tolerated AG Grid BigInt transform warnings.

## 30-tab / pinned / snapshots gap
- 007 Chrome tested 36 distinct route visits: visible tab count stayed at 30 (TAB_30_CAP_PASS). New Vben shell sets tabbar.maxCount=30 (old Vben default was unlimited), but this is NOT equivalent to the legacy ERP 30 lightweight bookmarks, max 3 manually pinned bookmarks and max 3 hot data snapshots. Exact parity remains an R3 acceptance gate.
- A local approved upstream Vben ZIP (6,945,397 bytes) is now lockable by SHA256 in vendor-lock.json; overlay bootstrap must reject absent or altered ZIP. Full base source is NOT yet fully committed or reproducibly reconstructed on clean machines.

## Boundaries
- Dev-only test role switcher represents four fabricated @local.invalid users. It is NOT Production employee login/RBAC.
- Current-main Laravel business controller, permission policies, WooCommerce, OMS, Production DB and Production runtime are unchanged.
- Dev gateway maintains Laravel cookies server-side; browser bundle receives only a stripped, read-only order projection.
- Initial user navigation fails closed on Dev error; retry possible on Overview.
- Never silently merge Draft PR #766, publish Production, or move real customer data to this isolated test fixture.

## R3 gate
- Rebase PR against current main; pin complete Vben base; 30 tabs and 3 pins/3 hot snapshots parity; real employee session/RBAC Dev UAT; 007 Chrome Owner visual approval; new explicit merge and Production release permission.

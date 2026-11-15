# ERP SiteOps Vben R6-T — pinned vendor clean-source verification (007windows)

Date: 2026-11-15. Scope: isolated BYL ERP Vben Dev only. No changes to original Jutdo SiteOps worktree, READY devices/Chrome, ERP Production, operational data, Woo or OMS. PR #766 remains Draft.

## Exact sources
- ERP delivery branch: `feat/erp-siteops-007-r0-orders-readonly-20261114`, previous baseline head `e873f41e94ca2c7935fb1af4cf69a3ca090d5b6c`. Local Git worktree was clean at that SHA before the versioned dependency patch.
- Original approved Vben ZIP: `D:/ai/siteops-vben/vben-upstream-main.zip`, 6,945,397 bytes, SHA-256 `DF1E977A85EB0D55E9A75D19E270180BFF68E8E7FCD15759D69919223E44141B`, 2,704 entries.
- Independent temporary reproduction path: `D:/ai/erp-siteops/r2c-vben-clean-20261115/vue-vben-admin-main`. The clean ZIP was expanded here, then 17 tracked ERP overrides from the exact local PR branch were copied into the isolated copy.

## Observed failure, root cause
1. Original ZIP `pnpm install --offline --frozen-lockfile`: PASS, 1,740 packages.
2. TypeScript: **FAIL** TS2307 at `src/views/erp/workspace.vue`: missing `ag-grid-community` and `ag-grid-vue3`. The ZIP `apps/web-antd/package.json` lacks both, while the already-working 007 Dev copy includes `36.3.0` for each. This was an unversioned local-base dependency, not a Laravel/API regression.
3. A one-off `pnpm add --offline` made the TypeScript/build PASS, but also drifted unrelated Vite/rolldown lock entries. Therefore that automatic resolver output is **not** promoted to the canonical lock.

## Versioned minimal correction
- `apps/erp-siteops-vben/scripts/patch-pinned-ag-grid.cjs`: validates a confined `D:/ai/erp-siteops/` target, injects exactly two pinned direct dependencies and their `ag-charts-types@14.3.0`/`ag-stack@36.3.0` lock resolutions; fails closed for unknown/partial baselines. It does not download packages, copy credentials or modify the shared SiteOps base.
- `apps/erp-siteops-vben/scripts/bootstrap.cjs`: runs this patch on the approved isolated Dev destination when `--apply-overlay` is explicitly used. Dry run does not patch.
- Direct fixture test from pristine ZIP entries: `package.json` hash **identical** to the working isolated test; lock only differs from the disposable `pnpm add` output by the two unrelated Vite/rolldown drifts. Exact pinned vendor versions remain `vite 8.3.0` and `rolldown 1.2.9`.

## Fresh pinned-lock regression
Using the deterministic patched package/lock inside the independent ZIP-derived Dev tree:
- `pnpm install --offline --frozen-lockfile`: **PASS**, exit 0.
- `pnpm --filter @vben/web-antd typecheck`: **PASS**, exit 0.
- Targeted Vitest Orders adapter + hot snapshots + tab policy: **12/12 PASS**, exit 0.
- `pnpm --filter @vben/web-antd build`: **PASS**, exit 0. AG Grid BigInt transform emits nonfatal warnings inherited from the configured browser target.

## Gates remaining open
- This proves a ZIP-derived rebuild **on the existing 007windows host with its local pnpm store**; it does **not** prove a brand-new machine can obtain the approved vendor ZIP, run without the cached pnpm store, or authenticate actual ERP staff.
- R2-C actual Vita/Liang/Dede/Emma/Guts/Hiko Dev session acceptance remains OPEN. Synthetic roles and 126 fixture orders do not substitute for employee UAT.
- PR #766 must still reconcile against updated ERP main, pass Owner visual acceptance and release-specific gates. **No merge and no Production deployment in this work.**

## Acceptance
**R6-T 007 clean-source dependency/build regression: PASS.**
**Full clean-machine portable-vendor artifact: OPEN.**
**R2-C real staff: OPEN.**

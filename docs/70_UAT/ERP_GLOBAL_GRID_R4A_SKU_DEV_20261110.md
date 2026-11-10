# R4-A Global Grid Dev pilot — SKU Registry (2026-11-10)

Frozen R4 selection: route #07 /products/skus (out of 51; 4 pre-existing Grid pilots, 47 further migration candidates).

**Scope / status:** one Dev-only dual-render pilot; automatic checks PASS, Owner real-browser UAT PENDING, GitHub main and Production deployment NOT authorized by this milestone alone.

## Existing data/permission contract
- Authenticated view requires the current products.view guard. No new write or permission grants.
- Legacy /products/skus route and 50-row default stay unchanged.
- In Global Grid opt-in mode (?global_grid=1), paginate exactly 20 / 50 / 100 on the canonical ProductService::list query, invalid limit falls back to 50; backend search uses the existing SKU/name/alias match.
- Columns preserve SKU, product name, status, source, alias count and canonical product edit detail link; server query remains source of truth.
- SKU alias count optimized to one Eloquent loadCount across the current page, avoiding per-SKU queries; no change in stored values.
- One built-in grid search, outside keyword box hidden in pilot mode; original legacy table, search and pager remain when pilot is off.
- Pilot gate reuses existing GlobalGridPilot (local/testing/dev, or Production ID1 explicit flag); no company-wide rollout and no new Production deployment.
- No new schema, order state, fulfillment, import/export, financial write, permission or warehouse API behavior.

## Exact automated checks
- npm run typecheck — PASS
- npm run typecheck:e2e — PASS
- npm run build — PASS
- ProductManagementTest + SkuAliasTest — **59/59 PASS (240 assertions)**; local test .env generated from example to avoid missing-file warnings.
- BYL_E2E_BROWSER=chrome BYL_E2E_SUITE=global-grid npm run test:e2e — **4/4 PASS**, including the three unchanged R3 pilot pages, original mobile procurement cards, and new SKU page search/clear/paging/reversible toggle.
- E2E disposable SQLite and temporary external files cleanup — PASS.
- No real Production data writes or live OMS calls.

## Owner UAT gate (pending)
- Real authenticated US Mac Dev browser: inspect /products/skus?global_grid=1 and /products/skus.
- Check six columns, link and compact action spacing, built-in full-data search, page size 20/50/100 and old-table toggle. Return explicit PASS / changes.
- Do NOT claim Owner UAT PASS, close R4-A or change Production defaults before Owner confirmation.

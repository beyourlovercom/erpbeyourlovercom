# OMS Grid P3 Import · R5 Authenticated Browser UAT · 2026-11-09

## Scope and guardrails

Owner-approved R2–R5 import correction on GitHub PR #646: select import type (website / KOL / external procurement), preserve XLSX original order number, reuse existing detail/list type and number views, keep the original OMS permission and duplicate-import protections.

This is **isolated Dev browser UAT on US Mac mini**, NOT a production browser session and NOT evidence of deployment to an externally hosted Dev site. Real Chromium via the locally installed Chrome channel opened the actual Laravel/Inertia/Vue/AG Grid pages and exercised the real multipart XLSX preview + confirm endpoints.

- Repro command: `BYL_E2E_BROWSER=chrome BYL_E2E_SUITE=oms-import npm run test:e2e`. The new browser cases are deliberately excluded from the general all-suite shared seed run, and included only under `oms-import`.
- Worktree: `/Users/a/AI/uat/erp-oms-import-source-20261109`
- Runtime: loopback `127.0.0.1:<ephemeral port>`; `APP_ENV=testing`; dedicated disposable on-disk SQLite; generated synthetic credentials; test-run bootstrap cache isolation; authenticated Fortify session.
- External browser traffic blocked; user browser sessions and other Laravel servers untouched.
- E2E fixture: `E2E-SKU-0001`; one XLSX per import type, all one item with quantity 2; unique original order number per type; account with existing Super Admin role, plus unauthorized account.
- On exit, the runner removes its temporary database, browser artifacts, generated XLSX and credentials (printed `E2E_CLEANUP=PASS`). No Production records, MySQL schema, WooCommerce, inventory, warehouse, logistics or real orders were written.

## Results (actual Playwright HTTP + UI clicks)

| Browser scenario | Evidence | Result |
| --- | --- | --- |
| Login, open OMS Grid `/orders?oms_grid=1`, modal and template | Real auth session, accessible modal and XLSX download link | PASS |
| Mandatory import type | Preview disabled until type selected | PASS |
| Type change after valid preview | Confirmation disabled, preview rows invalidated | PASS |
| Website | XLSX `E2E-WEB-0091`; `POST preview` then `POST confirm`; created id 5; customer detail displays `原始订单号` and `网站订单` | PASS |
| KOL | XLSX `E2E-KOL-0092`; created id 6; KOL order detail displays original and `网红订单` | PASS |
| External procurement | XLSX `E2E-PO-0093`; created id 7; customer detail displays original and `外采订单` | PASS |
| Original vs internal ERP identity | Confirm payload returns independent `erp_order_id`; detail displays unmodified source number | PASS |
| Repeat XLSX | New preflight rejects as duplicate; confirm remains disabled | PASS |
| Unauthorized identity | Template GET and preview POST return 403 with proper session CSRF | PASS |
| Browser guard | Console, page errors, failed requests, external requests and unhandled rejections all zero | PASS |

**Playwright result:** 2/2 browser cases PASS (~6 seconds after visual source-label fix). All three imported types show Chinese source in the existing grid column and detail source field; their original XLSX order numbers are unchanged. Artifact cleanup PASS.

**Additional check:** `npm run typecheck:e2e` PASS; `npm run test:e2e:contracts` 16/16 PASS.

**Previously completed at PR #646 original implementation head:** PHP 23/23, 380 assertions; scoped Vue 102/102; Vite build, TypeScript, Pint and four GitHub workflows PASS.

## Release boundaries

Code-only branch/PR stays **Draft, not merged, not Production-deployed**. No external Dev staging deployment verified. Production is unchanged. Do not infer an Owner production approval or permission to import live orders.

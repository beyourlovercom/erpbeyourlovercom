# ERP XLWMS Warehouse Sync Button S1–S4 — approved Work Package
EXECUTION_PROVIDER: US_CODEX_CLI
WORK_PACKAGE_ID: ERP-XLWMS-WAREHOUSE-SYNC-S1S4-20261028
GIT_BRANCH: feature/xlwms-warehouse-sync-s1s4-20261028
GIT_BASE: origin/main @ 9d128115e1b11bda5c56b0d52839c68daa29a644
OWNER_DESIGN_PASS: 2026-10-28, owner accepted preview and explicitly approved S1–S4.
OWNER_PRODUCTION_PASS: NOT GRANTED. OWNER_POST_DEVELOPMENT_UAT_PASS: PENDING.
EXECUTION_HOST: US Mac mini ONLY. One implementation provider per worktree.
## Frozen requirement
In the existing Logistics > Oversea > US-3SM Dede grid, add top-right 同步仓库. Show per-row OMS reference/status/last sync and compact result summary/errors in Chinese. No new technical page, no changes to other warehouses; retain carrier, review, pending, manual fallback and all existing table features.
Click handles bounded eligible approved, pending shipments assigned US-SM. The server must check provider OMS by both exact deterministic thirdOrderNo and Woo platformOrderNo before any create; existing order reads status/tracking, never duplicate creates. If no matching provider order, and only after canonical preflight, approved verified SKU alias, complete fresh applied V2 warehouse stock, actual logistics channel/warehouse binding and explicit safe gates, API create can happen when separately enabled. Persist unique attempts & results and replay protection, handle concurrent clicks and uncertain result fail-closed, never auto-retry ambiguous create. Old historical provider mapping PR#508 stays excluded.
Provider status 3 is confirmed shipped; 4 is canceled, other unknown states remain unresolved. Only exact OMS-verified Tracking can update ERP shipped; preserve Vita/Woo sync authority; no early Woo customer notification. Failures per order in Chinese, never leak PII/secrets. No simulated provider status labeled real.
Batch creation gate is OFF by default, requires logistics operator + approved warehouse binding, no broad automatic background/cron activation. Existing single-order allowlist remains for debug path as needed but the approved batch action must be a distinct properly gated route. Dry-run read-only path for Dev acceptance; real OMS write forbidden without independent Owner Production approval.
## Source and integration
Latest main contains Product OS unified verified alias (#515/#522), US-SM inventory-only provider sync (#518/#519/#521/#524). Older Draft outbound chain in origin/feature/xlwms-workbench-inline-20261026 (stack #501/#504/#510/#511/#512/#513/#517) must be safely transplanted without clobbering current main inventory/product/warehouse UI. Read historical docs/60_Codex_Operations/XLWMS_DEDE_INLINE_WORKBENCH_2026-10-26.md via Git refs. Do not merge retired mapping #508 or copy secrets.
## Scope and evidence
S1 integration; S2 original-list UI and bounded safe sync trigger; S3 exact OMS reconcile/conditional create and verified tracking update; S4 no-write Dev test plus migration/rollback, scoped PHPUnit, Vue, TS, Vite, manually prepped local UAT. No Production, real OMS orders, live Woo writes, or secrets. Commit locally after testing. Git push/PR allowed after GPT review; no main merge before Owner post-development UAT PASS, no Production deploy without separate Owner PASS. Note any blockers, do not claim green unrun tests.

## Current engineering evidence (2026-10-28 US Mac)
- Isolated branch from main 9d128115. Original US-SM list has top-right sync button, per-order result/status, protected batch sync route, max 25 approved pending shipments/click.
- Exact OMS search/reconciliation path is independently usable with new-create gate OFF; creating a brand-new OMS order remains gated by two explicit OFF-by-default dispatch flags, approved warehouse/identities, fresh V2 applied inventory snapshot, local preflight, and OMS native catalog check.
- Durable attempt/unique-claim and uncertain-response barriers preserve duplicate protection; provider status 3 + verified identity/tracking can mark local ERP shipped and queue canonical manual Woo sync, provider status 4/unknown remain blocked.
- US Mac scoped PHP feature tests: 69 warning-bearing cases, 579 assertions, zero failures. Vue Show.test.ts 14/14 PASS, vue-tsc PASS and Vite build PASS. PHP warnings are from test-time temp-file environment and need independent CI review.
- No live OMS calls, real orders, production migrations, Woo writes, browser UAT, or external secret access.
- Remaining release conditions: exact provider SKU quantity identity contract/real OMS lookup/readback evidence; independent CI, isolated Dev browser Owner UAT; Production launch plus real order sends require a separate Owner PASS. Production switches remain OFF.

## S4 Safety hardening follow-up (2026-10-28, US Mac; no live writes)
- Verified official provider `outboundOrder/detail` API schema: each `productList` row carries `sku` and integer `quantity`. Source: `https://apidoc-oms.xlwms.com/reference/getoutboundorderusingpost.md` (read-only documentation).
- Before reusing an existing OMS order to mark ERP shipped, require the same ERP thirdOrderNo, Woo platformOrderNo, bound OMS warehouse, AND complete aggregated `(sku, quantity)` map. Missing quantity or mismatched quantities fail closed; multiple exact OMS matches fail closed.
- Previously claimed attempts are read-only reconcilable when OMS matches but NEVER eligible for another automatic create even if dispatch flags change from OFF to ON. After ambiguous original create, later read-only lookup errors keep `batch_uncertain`, blocking automatic re-submit. Human resolution needs separate audited workflow.
- CI/local regression uses mocked OMS HTTP only; no live OMS customer orders were queried/created during this patch. A real, sanitized read-only OMS response review and Owner browser acceptance are still pending.

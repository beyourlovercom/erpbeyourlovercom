# ERP Monthly Product Cost Import V1 — Owner-Approved Design Brief
Date: 2026-11-19. Visual proposal PASS: private interactive GPT Site `BYL ERP 产品成本导入交互实验室`.
Scope authority: Owner said `效果pass 推进执行`; implement in ERP Dev only. Production deploy/apply requires separate Owner authorization.
Repository baseline: GitHub main 078de3ecdebc1b9dbbc26f29717e0f4833af55bc at branch creation.
Branch: feat/product-cost-import-20261119. Worktree: /Users/a/ai/007/erp-product-cost-import-20261119.
EXECUTION_PROVIDER: GPT_WEB via Owner-requested BYL self-hosted DC Remote (US Mac). VERIFICATION_TIER: HIGH_RISK (financial costs + potential DB schema + UI).
Architecture: reuse Products ConfirmedProductCostSourceImportService/Repository, product_cost_sources, AccountingFxSnapshotBridgeService, CostMaterializationService and Order Profit existing projection. No second canonical product-cost authority.
Business objective 1: ERP's product-cost import page accepts recurring 007 monthly purchase/inventory/sales XLSX and CSV, including months beyond October, extracts all base SKUs and raw CNY costs.
Input examples: `VA617A（国内新版）` -> base SKU `VA617A`; `VA617A...包装更新款` -> `VA617A`; `BEYOURLOVER 35毫升软管润滑油` source long code `1BY489A5C0100` -> `BY489A`.
Workbook observed: 2月 tab name `2月进销存`: name C, cost E; 3月 tab `3月进销存`: name A, 原成本单价 G; 4–10月: name A, long code B, 原成本单价 H. Tabs have trailing whitespace. Skip System Outbound and ending-stock transaction records.
Source sample (2026 Feb–Oct) previously processed to 942 valid cost lines, 94 base SKUs, 71 exact match with US local snapshot, 58 unique ready, 15 same-month price conflicts, 23 unmatched. These counts describe offline snapshot, not live ERP truth.
Every parsed row preserves month, source sheet, source row, product long code, product name, original CNY unit cost and exclusion/warning reason.
Normalize deterministically; do not strip meaningful ERP suffixes blindly. Match exact base SKU first; show suggestions; require user selection for ambiguous/composite/variant SKU; verify actual ERP product ID + SKU server-side.
Business objective 2: costs remain canonical CNY product_cost_sources with confirmed provenance; show USD derived cost read-model using existing accounting FX resolution/snapshot with rate/date/source. FX unavailable => blocked, never use a hardcoded demonstration 7.20 as authoritative.
Business objective 3: subsequent eligible order profit uses existing ProductCostEvidenceReader and CostMaterializationService keyed to order date, with immutable posting FX. Do not backfill historical, reconciled or fulfilled orders silently.
Interaction: original approved prototype flow — import file -> stats/list -> conflict/unmatched filters -> row confirm drawer with ERP SKU search -> select source version or manual actual CNY cost -> effective month, source/evidence, required reason -> dry-run summary -> guarded confirm.
Manual actual CNY cost allowed. Must preserve imported original rows and audit before/after, actor, reason and source reference. Never silently edit previously confirmed costs.
Existing confirmed product/month mismatch, missing SKU, multiple prices, missing FX, ambiguous version, invalid file => clear blocker. Re-import idempotent/noop when same source fingerprint; no duplicate cost record.
Apply must require existing authorization / CSRF / file validation and server-side fresh recheck, default to dry-run; no Production apply paths created in this Work Package.
Place page actions in canonical upper-right Page Header Action Zone; Global Data Grid VI V1.1 applies to primary table. Keep approved menu baseline unchanged unless separately approved.
Create route in the existing Products/Finance UX, linked from existing product-cost area or product index header action. Do not remove/rename Level-1/Level-2 menu entries.
Form follows docs/10_Planning_Roadmap/18_BYL_ERP_FORM_DESIGN_STANDARD_V1.md; use existing Inertia Vue and app design tokens, not the standalone mock's incompatible CSS.
US Mac local http://erp.b is the primary Dev/UAT; no China device, no Production database, no real OMS test order.
Before tracked feature code, create a complete formal Work Package with all mandatory footer modules from AI/LARGE_PROJECT_EXECUTION_STANDARD.md and sources/docs/10_Planning_Roadmap/13_PROGRAM_ROADMAP_AND_HOURS.md.
Then implement scoped feature and tests in this one dedicated worktree; maintain ONE_WORK_PACKAGE_ONE_BRANCH_ONE_PR and no parallel writer.
Stop for Owner on real Dev UAT inspection. Do not merge before the post-development Owner UAT PASS; do not deploy Production.

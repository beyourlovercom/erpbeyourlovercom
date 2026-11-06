# BYL ERP OMS Grid P3-A — Preview-only gate and real-schema verification

Date: 2026-11-06. Scope: P3 batch order import, NO actual order creation, NO Production enablement.

## Current Production facts (read-only)
- Active Production release when checked: oms-export-pr636-2cae68e8-20261106.
- P1 Grid: ON for administrator user ID 1 only.
- P2 Export: ON for user ID 1 only.
- P3 Import: OFF for all users.
- Product SKU matches (read-only): MF414A unique; A1 unique.
- New OMS import batch/claim tables: 0 rows each.

## Production no-write test
A private PHP verifier used the exact Production Laravel model, writer, XLSX reader, and OrderOmsBatchImport::inspect() method. The verifier **never called commit()** and temporarily generated files on disk only; it did not modify application or order data.

Verified:
1. Template is 15 exact columns, with sheets 批量订单导入, 使用说明, 示例-勿上传.
2. Demo grouping: same external_order_number appears across 2 SKU item lines; a second number starts a different Order.
3. Valid synthetic MF414A x2 + A1 x1 and second Order A1 x3: 2 unique Orders, 3 line rows, valid signed preflight token, correct 3-unit order total.
4. Conflicting follow-on SKU row city: row-level error; token null; 1 of 2 orders valid.
5. Nonexistent SKU: missing-product error; token null.
6. Existing Production external_order_number: duplicate error; token null.
7. Real current Production template/preview/confirm controller endpoints all return 404 while import flag OFF.
8. orders, order_items, import batches, and import claims table counts unchanged before/after tests.

Machine-local evidence script: /Users/a/ai/007/runtime/erp-p3-readonly-preflight-20261106.php. Do not copy real buyer data or plaintext secrets into GitHub.

## Safety gap found and solution

Current application uses the SAME `production_import_enabled` switch for template download, preflight and real commit. Enabling it for UI validation would unintentionally enable real Order creation. This is unacceptable for the approved independent-gate rollout.

This branch splits P3 into:
- **P3-A Preview only** `ERP_OMS_GRID_PRODUCTION_IMPORT_PREVIEW_ENABLED=false` default. Separately and explicitly allowlisted admin may download template and run `/orders/oms/import/preview` only. Server may return signed preview token but stores no orders/claims/batches.
- **P3-B Commit** `ERP_OMS_GRID_PRODUCTION_IMPORT_ENABLED=false` default. This separate gate is required for `/orders/oms/import/confirm`, real Order creation and associated audit. It is never implicitly enabled by P3-A.
- UI in preview-only mode provides template, file upload, error list, SKU/order counts and preview table, explicitly says "预检模式：创建订单未开放" and does not render the confirm-create button.
- Backend maintains existing orders.create permission + exact P1 allowlist checks for both modes.
- P1 and P2 flags/permissions unchanged.

## Regression
- Simulated Production tests verify that valid 2-SKU preflight is permitted under the P3-A flag while a request with the valid token to confirm is rejected with 404, also unauthorized accounts receive 404.
- PHPUnit Orders + UserUiPreference: 115/115 PASS, 1,618 assertions.
- Five scoped Vitest suites: 26/26 PASS.
- TypeScript and Vite build PASS.

## Rollout stages, still independently gated
P3-R0: production no-write validation DONE.
P3-R1: review this exact GitHub PR and CI results before separate Owner merge/deploy approval. Deploy dormant flags all new P3 toggles OFF.
P3-A: Owner separately authorizes enabling preview-only for administrator ID 1; explicitly keep confirm OFF. Owner checks actual XLSX download, sample import, preview errors and no-write behavior.
P3-B: after P3-A Owner PASS, seek distinct exact scope/quantity and explicit authorization for a small real manual Order creation canary. Never infer this from R1 or P3-A.
R4 Inventory grid migration is on hold until R3/P3 closes.

## Rollback
Set P3-A `ERP_OMS_GRID_PRODUCTION_IMPORT_PREVIEW_ENABLED=false` and rebuild Laravel config cache; no Orders created at any point. Preserve P1/P2 as-is. A separate code release can also revert the branch without touching business data.

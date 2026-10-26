# XLWMS T2 REAL DRAFT — LOCAL-only one-shot readiness

**Status:** Prepared / not executed. Not a production release.

## Host and database
- China Mac isolated Git worktree: /Users/a/AI/007/worktrees/erp-xlwms-t2-cn-20261026
- Local ERP Dev URL: http://127.0.0.1:8912/inventory/us-3sm?tab=config
- Isolated SQLite DB at database/xlwms-t2-local-uat.sqlite. Never point Dev at production.
- Locally seeded admin access, independent APP_KEY, manual_probe_enabled=true.
- XLWMS_LIVE_DISPATCH_ENABLED=false, live_order_id=0, T2 enabled only by explicit local env flag.
- UAT Order ID 8: TEST-XLWMS-001, manual_other, VP676A x1, synthetic no-real-recipient address, zero value, US-SM. No Woo linkage.

## Credential gate
- OMS existing account holds App Key and App Secret in logged-in China Mac Chrome, OMS profile > API信息.
- Attempted automated copy/paste is blocked by security tooling. DO NOT bypass or extract the secret by shell or clipboard process.
- Owner must use existing China Mac Chrome OMS COPY actions and paste into Dev US-SM config > OpenAPI > 验证并加密保存. Secret never goes in chat or Git.
- Then read OMS authorized warehouses and explicitly bind real 世迈001 (Yangfan) code after checking name and country.

## One-shot T2 runner
- LocalTestDraftExercise executes ONLY in local/testing and with XLWMS_T2_TEST_DRAFT_ENABLED=true; normal live dispatch flag MUST remain false.
- Rejects any ERP order except exact TEST-XLWMS-001, exactly one valid SKU and correct US-SM warehouse, verified API credentials and bound OMS warehouse.
- TestDraftPlan produces a local isSubmit=0 payload; distinct gateway createTestDraftSingle enforces strict TEST reference and synthetic DO NOT SHIP recipient.
- Before remote POST, signed *read-only* OMS exact duplicate search, channel and SKU catalog checks must all pass.
- Persist a unique single attempt BEFORE actual create. Success is draft_created_pending_cancel; timeout is draft_create_uncertain. NEVER retry blindly.
- No new web route, no Woo, no production deploy, no auto-submission or auto-fulfillment enabled.
- Execution after owner key entry: locally inspect attempt count and confirm channel, then set one-shot flag, invoke the service and capture *redacted* result.

## Cancellation gate
- The OMS interactive 一件代发出库 page supports 草稿 and 取消; no independently verified public cancel API contract yet. After creation, immediately search exact TEST reference, inspect state (草稿 only), select it, cancel it, and read it back as 已取消. If unexpected 仓库处理中, do not repeat or claim safe; request warehouse urgent cancellation and verify stop.
- Test count: 76 PHP tests / 598 assertions PASS, TypeScript check PASS. This tests simulated responses, NOT real API create/cancel.

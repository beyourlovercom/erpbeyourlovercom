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

## 2026-10-26 REAL OMS T2 RESULT — PASS AND CLOSED
- Owner saved genuine App Key/App Secret ONLY inside encrypted local ERP Dev Settings, without exposing secrets in chat, scripts, docs or Git.
- China Mac PHP 8.3 stale OpenSSL CA path caused cURL 77. Scoped local XLWMS CA bundle /opt/homebrew/etc/ca-certificates/cert.pem repaired HTTPS, keeping certificate verification enabled. Dummy signature probe got HTTP 200, OMS error 11008 as expected.
- Authenticated warehouse/options returned precisely Yangfan / 世迈001 / US and mapping was saved. Vendor catalog confirmed VP676A approved with approveStatus 2, channel Upload_Shipping_Label / USPS上传物流面单 returned whCodes empty (global). Adapted safety preflight to support this provider contract.
- Read-only outboundOrder/pageList rejected pageSize=100, vendor code 10002 "查询超过最大条数限制". Revised to pageSize=10, max 50 pages/500 results, fail closed if incomplete. Initial OMS had 24 orders, 3 pages; complete duplicate lookup confirmed BYL-TEST-8 and TEST-XLWMS-001 absent.
- ONE real outboundOrder/create call used isSubmit=0, TEST-XLWMS-001 VP676A x1, Upload_Shipping_Label, Yangfan, synthetic DO NOT SHIP recipient. OMS returned OBS0272610260RS, Draft 1 / To Submit 1, Processing 0, with no tracking/pickup.
- In authenticated OMS Draft / 待提交 selected ONLY OBS0272610260RS (one checked row). Clicked bulk 取消; modal explicitly said "总共选择了1个单据". Confirmed; after reload 草稿 (0) and 仓库处理中 (0). In 已取消 view verified the exact OMS order and TEST reference. Created 2026-10-26 15:27:13, cancelled 2026-10-26 15:31:01.
- Independently signed read-only OMS API pageList verified 25 orders, OBS0272610260RS status=4 (same as UI 已取消) and no tracking.
- Single local ERP attempt marked draft_cancelled_confirmed; ERP Order id 8 pending_shipment, Shipment pending; no Woo writes. Live dispatch OFF, no production deployment, no second attempt.
- T2 real draft/create/readback/cancel/reconcile PASS. This is NOT permission for T3 warehouse processing or general release.

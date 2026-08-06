# BYL ERP MVP1 本地人工 UAT 缺陷登记

```text
PROJECT: BYL ERP
ENVIRONMENT: LOCAL UAT
SESSION: MVP1 LOCAL HUMAN UAT
REPORTED_AT: 2026-08-05
REPORTED_BY: Owner / local human UAT
HUMAN_UAT_STATUS: IN PROGRESS
UAT_GATE: CHANGES REQUIRED
OPEN_DEFECTS: 5
LOGIN_GATE: PASS
OWNER_DECISION: Continue defect discovery and remediation
AUTOMATED_ACCEPTANCE: PASS does not equal Human UAT PASS
REMOTE_DEV_ACCESS: NO
PRODUCTION_ACCESS: NO
SSH: NO
REAL_EXTERNAL_API: NO
```

## MVP1-UAT-DEFECT-001

```text
DEFECT_ID: MVP1-UAT-DEFECT-001
UAT_SCENARIO: UAT-02 / UAT-04; open Orders and collapse the level-2 menu
TITLE: 二级菜单折叠后页面布局错乱
MODULE: App Shell / Navigation / Orders
SEVERITY: HIGH
STATUS: REPORTED
REPORTED_AT: 2026-08-05
REPORTED_BY: Owner / local human UAT
PRECONDITION: Authenticated Owner/Admin is on an Orders page with the level-2 menu expanded
REPRODUCTION_STEPS: |
  1. Open an Orders list, create, or detail page.
  2. Collapse the level-2 menu.
  3. Inspect the main content, breadcrumb, heading, toolbar, filters, and table.
EXPECTED_RESULT: |
  The level-2 menu is hidden or reduced to the approved width. The main content
  expands automatically. Breadcrumbs, headings, descriptions, toolbar, filters,
  and tables retain normal layout in both expanded and collapsed states.
ACTUAL_RESULT: |
  After collapsing the level-2 menu, the Orders main content is compressed into
  a very narrow area. Breadcrumbs, headings, descriptions, buttons, and filters
  wrap character-by-character or line-by-line, while a large invalid blank area
  remains on the right.
EVIDENCE: Owner-observed local browser UAT result; screenshot/browser trace pending Owner attachment
CONTRACT_REFERENCE: docs/60_Codex_Operations/work-packages/MVP1-ORDER-CORE-001.md (Routes and UI); menu/README.md; menu/APPROVED_MENU.json
ROOT_CAUSE: UNKNOWN; inspect App Shell collapsed-width, content-grid, and responsive layout contract during remediation
AFFECTED_FILES: resources/js/Layouts/**; resources/js/Components/AppShell/**; resources/js/Pages/Orders/**; related shell/order styles
FIX_BRANCH: PENDING
FIX_PR: PENDING
AUTOMATED_TESTS: Existing automated acceptance does not cover this human layout failure; regression test pending remediation
OWNER_RETEST: PENDING after fix PR
CLOSURE_STATUS: OPEN / awaiting root-cause analysis and Owner retest
```

## MVP1-UAT-DEFECT-002

```text
DEFECT_ID: MVP1-UAT-DEFECT-002
UAT_SCENARIO: UAT-02; submit an Order create form with only the order number populated
TITLE: 订单创建业务必填字段缺失服务端校验
MODULE: Orders
SEVERITY: CRITICAL
STATUS: REPORTED
REPORTED_AT: 2026-08-05
REPORTED_BY: Owner / local human UAT
PRECONDITION: Authenticated operator has Orders create permission and is on the create form
REPRODUCTION_STEPS: |
  1. Open the Orders create page.
  2. Fill only the currently required order number field.
  3. Leave the remaining order business data blank.
  4. Submit the form.
EXPECTED_RESULT: |
  The frontend and server use the same approved required-field rules. An incomplete
  order is rejected with explicit field-level errors and no partially saved order
  is created.
ACTUAL_RESULT: |
  Only the order number is currently required. The remaining order business data
  can be left blank and the form can still be submitted.
EVIDENCE: Owner-observed local browser UAT result; request/response and persisted-row evidence pending controlled reproduction
CONTRACT_REFERENCE: docs/60_Codex_Operations/work-packages/MVP1-ORDER-CORE-001.md (Frozen Business Contract ADDRESS and ITEMS; Authorization and Audit VALIDATION; Acceptance Criteria invalid/missing fields rejected)
ROOT_CAUSE: Server-side create validation does not enforce the full frozen Order Core field contract; exact validation gap pending remediation audit
AFFECTED_FILES: app/Modules/Orders/**; resources/js/Pages/Orders/**; tests/Feature/Mvp1/Mvp1WorkflowTest.php
FIX_BRANCH: PENDING
FIX_PR: PENDING
AUTOMATED_TESTS: Existing automated acceptance does not prevent this human UAT path; negative server-validation regression test pending remediation
OWNER_RETEST: PENDING after required-field contract is verified and fix PR is deployed locally
CLOSURE_STATUS: OPEN / required-field contract is frozen in Order Core; no Owner decision currently required
```

## MVP1-UAT-DEFECT-003

```text
DEFECT_ID: MVP1-UAT-DEFECT-003
UAT_SCENARIO: UAT-02 / UAT-04 / UAT-05 / UAT-07; create an order and compare its identifier across Orders, Fulfillment, CSV, Manual Sync, and Audit
TITLE: 用户填写的订单号被系统生成或修改
MODULE: Orders / Identity
SEVERITY: CRITICAL
STATUS: REPORTED
REPORTED_AT: 2026-08-05
REPORTED_BY: Owner / local human UAT
PRECONDITION: Authenticated operator can create an Order and inspect downstream MVP1 views
REPRODUCTION_STEPS: |
  1. Enter a user-chosen order number in the create form.
  2. Save the Order.
  3. Compare the displayed identifier with the original input.
  4. Compare the identifier in UI/API, list, detail, CSV, Fulfillment, Manual Sync, and Audit.
  5. Attempt to change the identifier after creation through UI and API.
EXPECTED_RESULT: |
  order_no is required and unique, is saved and displayed exactly as entered, and
  cannot be changed after creation through either UI or API. Every MVP1 surface
  uses the same order number. No second visible system-generated order number exists.
ACTUAL_RESULT: |
  The user-entered order number is generated or modified by the system instead of
  remaining the user-provided business name and unique identifier.
EVIDENCE: Owner-observed local browser UAT result; exact before/after identifier values and API evidence pending controlled reproduction
CONTRACT_REFERENCE: Owner contract supplied for MVP1-UAT-DEFECT-003; current Order Core record also states IDENTIFIER: unique internal_order_number generated server-side, which conflicts with the Owner contract and must not be silently resolved by implementation
ROOT_CAUSE: Contract/implementation mismatch: current Order Core identifier contract permits server generation, while the Owner contract requires immutable user-entered order_no; implementation root cause pending Owner contract reconciliation
AFFECTED_FILES: app/Modules/Orders/**; app/Modules/Identity/**; resources/js/Pages/Orders/**; CSV/Fulfillment/Manual Sync/Audit identifier projections
FIX_BRANCH: PENDING
FIX_PR: PENDING
AUTOMATED_TESTS: Existing automated acceptance covers generated unique internal numbers, not the Owner-required immutable user-entered order_no contract; contract regression test pending Owner resolution
OWNER_RETEST: PENDING after Owner contract is reconciled and fix PR is deployed locally
CLOSURE_STATUS: OPEN / CONTRACT_CONFLICT_REQUIRES_OWNER_DECISION
```

## MVP1-UAT-DEFECT-004

```text
DEFECT_ID: MVP1-UAT-DEFECT-004
UAT_SCENARIO: UAT-05 / UAT-06 / UAT-07 / UAT-12; use Fulfillment copy actions and verify admin authorization
TITLE: 发货复制功能无效且 Admin 权限合同可能不完整
MODULE: Fulfillment / Authorization / Clipboard
SEVERITY: HIGH
STATUS: REPORTED
REPORTED_AT: 2026-08-05
REPORTED_BY: Owner / local human UAT
PRECONDITION: Authenticated local UAT admin account is viewing the Fulfillment pending queue
REPRODUCTION_STEPS: |
  1. Open the Fulfillment pending-shipment queue as admin or uat_owner_admin.
  2. Use recipient, full shipment, and carrier/tracking copy actions.
  3. Observe clipboard contents and success/failure feedback.
  4. Verify the same account's access to Orders, Fulfillment, Manual Sync, Finance, and Audit.
  5. If copy failure and authorization failure have different causes, split this record into 004-A and 004-B before remediation.
EXPECTED_RESULT: |
  Copy actions produce the approved shipment field format and show explicit success
  feedback. Copy failures show an error instead of remaining silent. admin and
  uat_owner_admin have all MVP1 permissions for Orders, Fulfillment, Manual Sync,
  Finance, and Audit.
ACTUAL_RESULT: |
  The copy function in the Fulfillment queue is ineffective. The completeness of
  the admin MVP1 permission contract is not established by the current UAT result.
EVIDENCE: Owner-observed local browser UAT result; browser console, Clipboard API permission, network response, and permission matrix evidence pending controlled reproduction
CONTRACT_REFERENCE: docs/60_Codex_Operations/work-packages/MVP1-FULFILLMENT-001.md (COPY_PAYLOADS and PERMISSIONS); docs/60_Codex_Operations/work-packages/MVP1-SYNC-MANUAL-001.md (PAYLOAD and PERMISSIONS); docs/60_Codex_Operations/work-packages/MVP1-FINANCE-ORDER-ANALYSIS-001.md; docs/70_UAT/MVP1_LOCAL_UAT_CHECKLIST.md UAT-05/UAT-12
ROOT_CAUSE: UNKNOWN; distinguish Clipboard frontend/browser permission, button event, payload generation, and backend authorization causes; split into 004-A and 004-B if independent roots are confirmed
AFFECTED_FILES: app/Modules/Logistics/**; app/Modules/Sync/**; app/Modules/Finance/**; app/Modules/Identity/**; resources/js/Pages/Logistics/**; permission definitions and authorization tests
FIX_BRANCH: PENDING
FIX_PR: PENDING
AUTOMATED_TESTS: Existing automated acceptance does not establish browser Clipboard feedback or the full admin/uat_owner_admin cross-module permission matrix; focused regression tests pending root-cause classification
OWNER_RETEST: PENDING after root-cause classification and fix PR is deployed locally
CLOSURE_STATUS: OPEN / root-cause classification pending; split required if two independent defects are confirmed
```

## Session Governance

```text
HUMAN_UAT_STATUS: IN PROGRESS
UAT_GATE: CHANGES REQUIRED
OPEN_DEFECTS: 5
LOGIN_GATE: PASS
OWNER_DECISION: Continue defect discovery and remediation
AUTOMATED_ACCEPTANCE: PASS does not equal Human UAT PASS
FIX_SCOPE_THIS_SESSION: Documentation only; no defect fixes
BUSINESS_CODE_CHANGED: NO
MENU_IMPACT: NO
APPROVED_MENU_CHECK: PASS
```

## Validation and Secret Boundary

```text
MARKDOWN_FENCES: PAIR_CHECK_REQUIRED
GIT_DIFF_CHECK: REQUIRED
SECRET_SCAN: REQUIRED
FORBIDDEN_ARTIFACTS: passwords; cookies; tokens; sessions; .env files; local database files
REMOTE_OR_PRODUCTION_EVIDENCE: NONE
```

## MVP1-UAT-FINDING-005

```text
FINDING_ID: MVP1-UAT-FINDING-005
STATUS: NEEDS_OWNER_DECISION
UAT_SCENARIO: UAT-04 / UAT-05 / UAT-06 / UAT-07 / UAT-08 / UAT-09
SEVERITY: HIGH
TITLE: 订单详情页未实现原订单履约工作台的信息架构
REPORTED_AT: 2026-08-06
REPORTED_BY: Owner / local human UAT
ACTUAL_RESULT: |
  The current page shows order number/source/date/status, recipient address snapshot,
  simple product info, simple fulfillment/sync status, address correction, and raw
  audit events. The approved design workspaces are absent: pending queue,
  pending/shipped/exception switching, prev/next order, complete customer/site order
  info, product amount totals, warehouse/carrier/tracking/ship time, save draft/
  confirm shipped/mark pickup/defer shipment, logistics parsing/copy, manual website
  sync, business notes, and readable full timeline. The left context says 当前模块：客户
  while the body is order detail and says customer module later. Raw audit events
  order.updated/order.created/actor 4 are not human-readable names/actions.
EXPECTED_RESULT: |
  Preserve Order/Fulfillment/Manual Sync backend boundaries while presenting a unified
  order fulfillment workspace with queue/quick switching, customer/order info,
  products/amounts, shipping, copy/address parsing, manual site sync, business notes,
  and readable activity log; no second order source of truth.
OWNER_DECISION: |
  A restore full combined workspace;
  B keep backend split and restore unified frontend workspace;
  C accept basic detail page and formally retire original design baseline.
RECOMMENDATION: B
EVIDENCE: |
  Two supplied screenshot references are placeholders; evidence is not supplied and
  is pending Owner-provided redacted static screenshots. No repository-relative paths
  are recorded. Owner confirms screenshots contain synthetic data only and must contain
  no real PII or credentials.
CONTRACT_REFERENCE: Owner-supplied finding for UAT-04 through UAT-09; original order fulfillment workspace design baseline
ROOT_CAUSE: PENDING Owner decision and remediation review
AFFECTED_FILES: PENDING
FIX_BRANCH: PENDING
FIX_PR: PENDING
AUTOMATED_TESTS: PENDING; documentation-only recording in this session
OWNER_RETEST: PENDING
CLOSURE_STATUS: OPEN / NEEDS_OWNER_DECISION
```

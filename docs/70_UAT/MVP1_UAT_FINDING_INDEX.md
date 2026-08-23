# MVP1 UAT Finding Index

## Authority and Migration Scope

```text
INDEX_STATUS: FORMAL_MIGRATION_RECORD
SOURCE_PR: #73
SOURCE_PR_ROLE: LEGACY_HUMAN_UAT_FINDING_CONTAINER
SOURCE_BRANCH: codex/mvp1-local-human-uat-session
SOURCE_HEAD: db5d515c6474a1681585c6c8497a7a244184cf8e
SOURCE_DOCUMENT: docs/70_UAT/MVP1_LOCAL_UAT_DEFECT_REGISTER.md
SOURCE_DOCUMENT_STATUS: LEGACY / READ_ONLY
LEGACY_FINDINGS_DISCOVERED: 5
LEGACY_FINDINGS_MIGRATED: 5
FINDINGS_MISSING: 0
```

This index preserves each finding from the final content of PR #73 without
renumbering, merging, closing, or changing historical facts. Fields that have
no approved value are recorded as `TBD`.

## Finding Records

### MVP1-UAT-DEFECT-001

```text
FINDING_ID: MVP1-UAT-DEFECT-001
SOURCE: PR #73 final defect register
SOURCE_PR: #73
SOURCE_BRANCH: codex/mvp1-local-human-uat-session
SOURCE_HEAD: db5d515c6474a1681585c6c8497a7a244184cf8e
PAGE: CROSS_PAGE
PAGE_ROUTE_OR_CANONICAL_PAGE_ID: CROSS_PAGE
PAGE_SCOPE: Orders list/create/detail
FOUNDATION_CANDIDATE: APP_SHELL
PAGE_CLASSIFICATION_STATUS: READ_ONLY_AUDIT_COMPLETE / FOUNDATION_REMEDIATION_REQUIRED
TITLE: 二级菜单折叠后页面布局错乱
SEVERITY: HIGH
ORIGINAL_STATUS: REPORTED
CURRENT_STATUS: MERGED_CLOSED
TARGET_WORK_PACKAGE: ERP-APP-SHELL-NAVIGATION-REMEDIATION-001
REMEDIATION_BRANCH: feature/erp-app-shell-navigation-remediation-001
REMEDIATION_PR: #87 / MERGED
VALIDATED_HEAD: 795d3a5ac565c68a5c8b9cc7bd173d2272d45b5c
OWNER_UAT: PASS / 2026-08-23
MERGE_SHA: 31bd84100ecb14cc1db809733e4a981a8a312c95
CLOSURE_STATUS: CLOSED
NOTES: UAT-02 / UAT-04 App Shell remediation completed in PR #87. Exact product revision 795d3a5ac565c68a5c8b9cc7bd173d2272d45b5c passed US full verification, China exact-revision verification including 19/19 Browser E2E and http://erp.b HTTP 200, and Owner second visual UAT PASS on 2026-08-23. PR #87 was explicitly Owner-authorized and squash-merged to main at 31bd84100ecb14cc1db809733e4a981a8a312c95; this finding is formally CLOSED.
```

### MVP1-UAT-DEFECT-002

```text
FINDING_ID: MVP1-UAT-DEFECT-002
SOURCE: PR #73 final defect register
SOURCE_PR: #73
SOURCE_BRANCH: codex/mvp1-local-human-uat-session
SOURCE_HEAD: db5d515c6474a1681585c6c8497a7a244184cf8e
PAGE: Orders / Create
PAGE_ROUTE: /customers/orders/create
PAGE_ROUTE_OR_CANONICAL_PAGE_ID: Orders/Create
PAGE_CLASSIFICATION_STATUS: SOURCE_SUPPORTED
TITLE: 订单创建业务必填字段缺失服务端校验
SEVERITY: CRITICAL
ORIGINAL_STATUS: REPORTED
CURRENT_STATUS: PLANNING
TARGET_WORK_PACKAGE: TBD
REMEDIATION_BRANCH: TBD
REMEDIATION_PR: TBD
OWNER_UAT: TBD
MERGE_SHA: TBD
CLOSURE_STATUS: OPEN
NOTES: UAT scenario is UAT-02. Source route truth is GET /customers/orders/create with form submission to POST /customers/orders. PR #73 records that the required-field contract is frozen in Order Core and that no Owner decision is currently required.
```

### MVP1-UAT-DEFECT-003

```text
FINDING_ID: MVP1-UAT-DEFECT-003
SOURCE: PR #73 final defect register
SOURCE_PR: #73
SOURCE_BRANCH: codex/mvp1-local-human-uat-session
SOURCE_HEAD: db5d515c6474a1681585c6c8497a7a244184cf8e
PAGE: CROSS_PAGE
PAGE_ROUTE_OR_CANONICAL_PAGE_ID: CROSS_PAGE
PAGE_CLASSIFICATION_STATUS: REQUIRES_OWNER_DECISION
TITLE: 用户填写的订单号被系统生成或修改
SEVERITY: CRITICAL
ORIGINAL_STATUS: REPORTED
CURRENT_STATUS: PLANNING
TARGET_WORK_PACKAGE: TBD
REMEDIATION_BRANCH: TBD
REMEDIATION_PR: TBD
OWNER_UAT: TBD
MERGE_SHA: TBD
CLOSURE_STATUS: OPEN / CONTRACT_CONFLICT_REQUIRES_OWNER_DECISION
NOTES: PR #73 scopes UAT-02 / UAT-04 / UAT-05 / UAT-07 across Orders, Fulfillment, CSV, Manual Sync, and Audit. The Owner contract and current Order Core identifier contract conflict; no page classification or remediation may resolve that conflict without Owner decision.
```

### MVP1-UAT-DEFECT-004

```text
FINDING_ID: MVP1-UAT-DEFECT-004
SOURCE: PR #73 final defect register
SOURCE_PR: #73
SOURCE_BRANCH: codex/mvp1-local-human-uat-session
SOURCE_HEAD: db5d515c6474a1681585c6c8497a7a244184cf8e
PAGE: CROSS_PAGE
PAGE_ROUTE_OR_CANONICAL_PAGE_ID: CROSS_PAGE
PAGE_CLASSIFICATION_STATUS: REQUIRES_READ_ONLY_AUDIT
TITLE: 发货复制功能无效且 Admin 权限合同可能不完整
SEVERITY: HIGH
ORIGINAL_STATUS: REPORTED
CURRENT_STATUS: PLANNING
TARGET_WORK_PACKAGE: TBD
REMEDIATION_BRANCH: TBD
REMEDIATION_PR: TBD
OWNER_UAT: TBD
MERGE_SHA: TBD
CLOSURE_STATUS: OPEN / ROOT_CAUSE_CLASSIFICATION_PENDING
NOTES: PR #73 scopes UAT-05 / UAT-06 / UAT-07 / UAT-12 across Fulfillment, Authorization, and Clipboard. PR #73 requires split into 004-A and 004-B only if controlled remediation evidence confirms independent roots.
```

### MVP1-UAT-FINDING-005

```text
FINDING_ID: MVP1-UAT-FINDING-005
SOURCE: PR #73 final defect register
SOURCE_PR: #73
SOURCE_BRANCH: codex/mvp1-local-human-uat-session
SOURCE_HEAD: db5d515c6474a1681585c6c8497a7a244184cf8e
PAGE: UNRESOLVED
PAGE_ROUTE_OR_CANONICAL_PAGE_ID: UNRESOLVED
PAGE_CLASSIFICATION_STATUS: REQUIRES_OWNER_DESIGN_DECISION
TITLE: 订单详情页未实现原订单履约工作台的信息架构
SEVERITY: HIGH
ORIGINAL_STATUS: NEEDS_OWNER_DECISION
CURRENT_STATUS: PLANNING
TARGET_WORK_PACKAGE: TBD
REMEDIATION_BRANCH: TBD
REMEDIATION_PR: TBD
OWNER_UAT: TBD
MERGE_SHA: TBD
CLOSURE_STATUS: OPEN / NEEDS_OWNER_DECISION
NOTES: PR #73 records UAT-04 through UAT-09 and Owner options A, B, or C. Recommendation B is not an Owner decision. Page remains UNRESOLVED until the Owner chooses a design direction.
```

## Completed Auth/Home Remediation Record

```text
PAGE: Auth / Home
WORK_PACKAGE: MVP1_UAT_REMEDIATION_AUTH_HOME_001
PR: #78
OWNER_UAT: PASS
MERGE_STATUS: MERGED
MERGE_SHA: ed77cbdc8c52f57aa72a512a0733167670027f0f
STATUS: MERGED_CLOSED
NOTES: This is a completed remediation record. PR #73 contains no explicit evidence mapping any legacy finding to Auth / Home, so no legacy finding is closed by this record.
```

## Status Vocabulary

`OPEN`, `PLANNING`, `IN_REMEDIATION`, `OWNER_UAT_READY`, `OWNER_UAT_PASS`,
`READY_FOR_PR`, `MERGED_CLOSED`, `DEFERRED`, and `SUPERSEDED` are the permitted
index statuses. Historical source statuses remain preserved in
`ORIGINAL_STATUS`; `CURRENT_STATUS` is the governance workflow state.
---
type: current-context
project: BYL ERP
updated: 2026-08-25
status: release-1-operations-inventory-foundation-planned
---

# Current Context

## Authority and operating model

```text
OWNER: final product, UAT, merge and release authority
DESIGN_DIAGNOSIS_AUTHORITY: GPT Web + Owner
IMPLEMENTATION_PROVIDER: US Mac / Claude Code / implementation only
TEST_PROVIDER: China Mac / Codex CLI / test, verification and evidence only
SOURCE_OF_TRUTH: GitHub / beyourlovercom/erpbeyourlovercom
US_LOCAL_RUNTIME: http://erp.b
CHINA_LOCAL_RUNTIME: http://erp.b
PRIMARY_FULL_VERIFICATION_HOST: US Mac
DAILY_CHINA_VERIFICATION: EXACT_SHA_SMOKE_BY_DEFAULT
REMOTE_MILESTONE_TARGET: https://erp.beyourlover.com
AICC_IN_ERP_EXECUTION_CHAIN: NO
```

Claude and Codex do not design, diagnose, select architecture, decide root cause, or decide the next Work Package. They may report raw implementation/test facts and errors only.

## Canonical baseline

```text
PR_82_LOCAL_BASELINE_AUDIT: MERGED
BASELINE_MERGE_SHA: faa45f9620211f3c398dde7f30c1a886313ced14
PR_83_ROADMAP_REVISION: MERGED
PR_84_EXECUTION_ROLE_ALIGNMENT: MERGED
PR_85_INQUIRY_M1_CONTRACT: MERGED / OWNER APPROVED
CURRENT_MAIN_SHA: d4bd3a6ae9eba16e8b3e5e35a957fa4632c8669d
ARCHITECTURE_FOUNDATION: COMPLETE / PASS
GLOBAL_ARCHITECTURE_GATE: PASS
LOCAL_RUNTIME: http://erp.b
LOCAL_BASELINE_RECONCILIATION: COMPLETE
GITHUB_UNIQUE_SYNC_SOURCE: YES
REMOTE_DEPLOYMENT: NOT AUTHORIZED UNTIL MILESTONE STABLE
```

Existing implemented foundations include Identity/Auth/Permissions, App Shell, Dashboard foundation, Runtime health, immutable Audit, Sync Outbox/manual sync, Orders, Order Items, Addresses, Shipments/Logistics, Finance/Order Analysis, Redis/Queue/Scheduler validation, PHPUnit/Vitest/Browser E2E and local MySQL UAT backup/restore evidence.

## Existing MVP1 status

```text
MVP1_FOUNDATION: COMPLETE / PASS / CLOSED
MVP1_ORDER_CORE: COMPLETE / PASS / CLOSED
MVP1_FULFILLMENT: COMPLETE / PASS / CLOSED
MVP1_MANUAL_SYNC: COMPLETE / PASS / CLOSED
MVP1_FINANCE_ORDER_ANALYSIS: COMPLETE / PASS / CLOSED
MVP1_AUTOMATED_UAT_READINESS: PASS
MVP1_HUMAN_UAT: CHANGES REQUIRED
LEGACY_HUMAN_UAT_FINDINGS_OPEN: 4
```

Five legacy Human UAT findings were migrated. `MVP1-UAT-DEFECT-001` is now formally CLOSED after PR #87 merge and Owner UAT PASS; four legacy findings remain open. They may proceed as separate bounded remediation packages when prioritized and when no undeclared shared-foundation dependency conflicts with the current Inquiry milestone.

## Current milestone

```text
CURRENT_PROGRAM_MILESTONE: Release 1 Internal Operations / Tencent Docs Replacement
ORDER_FULFILLMENT_STREAM: ACTIVE / PR #90 NOT MERGED
PRODUCT_MASTER_STREAM: ACTIVE PARALLEL STREAM
SHIPPING_CARRIER_STREAM: ACTIVE / US OWNER UAT REMEDIATION
NEXT_FOUNDATION_DEPENDENCY: ERP-INVENTORY-FOUNDATION-M1-001
INVENTORY_FOUNDATION: OWNER APPROVED PLAN / IMPLEMENTATION NOT STARTED
ERP_INQUIRY_M1: PAUSED B2B TRACK / OWNER REACTIVATION REQUIRED
```

Inventory Foundation planned sequence:

```text
Warehouse / Inventory schema
→ /inventory dashboard/add/list
→ /inventory/products cross-warehouse table
→ six warehouse detail pages + Markdown billing tables
→ Shipping warehouse candidate/recommendation integration
```

## Business objective

Replace Tencent Docs for daily order and fulfillment work. Release 1 must support efficient copy/paste workflows and real warehouse-aware shipping. Inventory is the canonical source for warehouse identity and SKU availability; Shipping consumes that truth instead of guessing. B2B Inquiry is a separate paused track.

## Environment gates

```text
NORMAL DEVELOPMENT DEPLOYMENT: LOCAL ONLY
LOCAL URL: http://erp.b
LOCAL CODE SYNC: GitHub exact revision
REMOTE URL: https://erp.beyourlover.com
REMOTE USE: STABLE MILESTONE RELEASE ONLY
PRODUCTION/REMOTE DIRECT EDIT BY CLAUDE: FORBIDDEN
PRODUCTION/REMOTE DIRECT FIX BY CODEX: FORBIDDEN
```

## Immediate sequence

```text
1. Finish current Shipping UAT remediation except the warehouse-recommendation gap.
2. Prepare ERP-INVENTORY-FOUNDATION-M1-001 dependency/menu/schema preflight on a dedicated branch/worktree.
3. Implement Warehouse Master + SKU × Warehouse inventory truth.
4. Implement inventory dashboard, warehouse list, product inventory table and six warehouse detail pages.
5. Integrate Shipping warehouse candidates/recommendation with manual override, save and Audit.
6. Finish Tencent Docs single/batch copy, batch paste and batch fill layer.
7. Owner UAT gates Release 1.
```

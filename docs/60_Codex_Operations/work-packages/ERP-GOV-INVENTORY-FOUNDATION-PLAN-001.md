---
type: work-package
project: BYL ERP
work_package_id: ERP-GOV-INVENTORY-FOUNDATION-PLAN-001
title: Release 1 Mainline and Inventory Foundation Planning Sync
status: GOVERNANCE/DESIGN ONLY / IMPLEMENTATION NOT AUTHORIZED
execution_provider: GPT_WEB
baseline: main@d4bd3a6ae9eba16e8b3e5e35a957fa4632c8669d
branch: docs/erp-inventory-foundation-plan-001
updated: 2026-08-25
---

# ERP-GOV-INVENTORY-FOUNDATION-PLAN-001

## Objective

Synchronize canonical repository planning with the Owner's current direction:

- Release 1 internal operations / Tencent Docs replacement is the current mainline;
- ERP-INQUIRY-M1 becomes a paused B2B track requiring explicit Owner reactivation;
- `ERP-INVENTORY-FOUNDATION-M1-001` is recorded as the Warehouse / Inventory truth required to close Shipping warehouse selection.

## Scope

Documentation/design mutation only. No PHP, Vue, route, permission, migration, database, menu baseline, UAT or deployment mutation is authorized.
## Required planning outputs

```text
AI/CURRENT_CONTEXT.md
01_MASTER_REQUIREMENTS.md
02_PRODUCT_AND_MENU_MAP.md
04_LIVING_ROADMAP.md
10_DECISION_REGISTER.md
11_CHANGE_LOG.md
12_WORK_PACKAGE_INDEX.md
13_PROGRAM_ROADMAP_AND_HOURS.md
16_ERP_INVENTORY_FOUNDATION_M1_001.md
```

## Menu gate

This planning sync records future Inventory page URLs but does not mutate `menu/APPROVED_MENU.json` or runtime navigation.

```text
MENU_IMPACT: NO RUNTIME CHANGE
APPROVED_MENU_CHECK: PASS / READ
FUTURE_LEVEL_2_CHANGE: REQUIRES SYNCHRONIZED OWNER-APPROVED MENU UPDATE
```

## Acceptance

- no source-of-truth conflict leaves Inquiry marked CURRENT;
- Release 1 is clearly the current business mainline;
- Inventory Foundation scope/dependencies are recorded;
- implementation remains explicitly not started;
- Git diff is documentation-only and passes `git diff --check`.
## PROJECT POSITION

```text
PROGRAM_ROADMAP: Architecture [COMPLETE] → Release 1 Internal Operations / Tencent Docs Replacement [CURRENT] → MVP1.1 WooCommerce Inbound → MVP1.2 Tracking Outbound → MVP1.3 Sync Orchestration → Release 2 Growth Operations → Release 3 Full Core ERP → Release 4 Platform Expansion / Production Hardening; ERP-INQUIRY-M1 B2B track PAUSED / Owner reactivation required
CURRENT_TRACK_PHASE_MAP: Shipping UAT remediation → Inventory Foundation planning/preflight → Inventory implementation → Shipping↔Inventory integration → Tencent Docs productivity layer → Release 1 Owner UAT
COMPLETED_PHASES: Architecture Foundation; MVP1 Foundation/Order Core/Fulfillment/Manual Sync/Finance foundations; User & Access / Permission Matrix
CURRENT_PHASE: Release 1 planning synchronization
CURRENT_WORK_PACKAGE: ERP-GOV-INVENTORY-FOUNDATION-PLAN-001
PACKAGE_STATUS: GOVERNANCE/DESIGN ONLY / IMPLEMENTATION NOT AUTHORIZED
WAITING_PHASES: ERP-INVENTORY-FOUNDATION-M1-001 implementation; Shipping warehouse-selection closure; Release 1 productivity/UAT
BASELINE: main@d4bd3a6ae9eba16e8b3e5e35a957fa4632c8669d
GITHUB_STATE: docs branch only until review/merge; main unchanged by this package
SOURCE_CONFLICTS: merged Roadmap still marks Inquiry CURRENT; this package records the Owner superseding priority decision
GLOBAL_GATE: PASS
PRODUCTION_DEPLOYMENT_STATUS: NO
NEXT_DEPENDENCY: Owner review/merge of planning sync, then Inventory implementation preflight
EVIDENCE_AS_OF: 2026-08-25
```

## ROADMAP HOURS

```text
CURRENT_PACKAGE_ESTIMATE: 1–2h / planning value 1.5h / GPT Web documentation-design sync
KNOWN_CURRENT_TRACK_REMAINING: UNKNOWN WITH EVIDENCE / parallel Release 1 scopes not yet normalized into a non-overlapping estimate
ARCHITECTURE_GATE_REMAINING: 0h / complete
MVP1_TOTAL_ESTIMATE: 122–190h historical total estimate
MVP1_PLANNING_BASELINE: 166h
MVP1_MAXIMUM_ENVELOPE: 192h
MVP1_REMAINING: UNKNOWN WITH EVIDENCE
POST_MVP_KNOWN_INCREMENT: approximately 68–120h historical Woo inbound + single outbound increment
LONG_TERM_CUMULATIVE_RANGE: 603–1,018h first automated operations MVP; 1,595–2,714h full core ERP; ranges are cumulative and non-additive
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE
ESTIMATE_CONFIDENCE: HIGH for this docs-only package; UNKNOWN for Inventory implementation until preflight
ESTIMATE_AS_OF: 2026-08-25
ESTIMATE_METHOD: bounded documentation mutation; no fabricated implementation estimate
```
## ESTIMATED CODEX HOURS

```text
PREFLIGHT_AND_SCOPE: 0.4h
CORE_EXECUTION: 0.6h
TEST_AND_VALIDATION: 0.3h
REMEDIATION_BUFFER: 0.2h
ESTIMATED_EFFECTIVE_HOURS: 1.5h
ESTIMATE_RANGE: 1–2h
ESTIMATE_BASIS: GPT Web documentation/design synchronization only; no product implementation
OWNER_REVIEW: INCLUDED
MERGE: EXCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
```
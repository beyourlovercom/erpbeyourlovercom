---
type: current-context
project: BYL ERP
updated: 2026-08-23
status: baseline-reconciled-inquiry-m1-roadmap-current
---

# Current Context

## Authority and operating model

```text
OWNER: final product, UAT, merge and release authority
DESIGN_DIAGNOSIS_AUTHORITY: GPT Web + Owner
IMPLEMENTATION_PROVIDER: US Mac / Claude Code / implementation only
TEST_PROVIDER: China Mac / Codex CLI / test, verification and evidence only
SOURCE_OF_TRUTH: GitHub / beyourlovercom/erpbeyourlovercom
PRIMARY_LOCAL_RUNTIME: http://erp.b
PRIMARY_TEST_ENVIRONMENT: China Mac
REMOTE_MILESTONE_TARGET: https://erp.beyourlover.com
AICC_IN_ERP_EXECUTION_CHAIN: NO
```

Claude and Codex do not design, diagnose, select architecture, decide root cause, or decide the next Work Package. They may report raw implementation/test facts and errors only.

## Canonical baseline

```text
PR_82_LOCAL_BASELINE_AUDIT: MERGED
BASELINE_MERGE_SHA: faa45f9620211f3c398dde7f30c1a886313ced14
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
LEGACY_HUMAN_UAT_FINDINGS_OPEN: 5
```

The five migrated Human UAT findings remain open. This Roadmap revision does not close, reinterpret or silently supersede them. They may proceed as separate bounded remediation packages when prioritized and when no undeclared shared-foundation dependency conflicts with the current Inquiry milestone.

## Current milestone

```text
CURRENT_PROGRAM_MILESTONE: ERP-INQUIRY-M1
CURRENT_GOVERNANCE_WORK_PACKAGE: ERP-GOV-INQUIRY-ROADMAP-REVISION-001
CURRENT_ACTIVITY: ROADMAP REVISION / NO PRODUCT IMPLEMENTATION
NEXT_AFTER_ROADMAP_MERGE: ERP-INQUIRY-M1.0 ARCHITECTURE & DATA CONTRACT
```

ERP-INQUIRY-M1 sequence:

```text
M1.0 Inquiry Architecture / Data / API Contract
→ M1.1 Company
→ M1.2 Contact
→ M1.3 Case
→ M1.4 Inbound Message
→ M1.5 Inquiry Inbox
→ M1.6 Email Intake
→ M1.7 WhatsApp Intake
→ M1.8 Yesterday / Waiting Dashboard
→ M1.9 Local Milestone UAT
→ Owner Milestone PASS
→ Remote release of exact GitHub revision to https://erp.beyourlover.com
```

## Business objective

Email and WhatsApp inquiries are captured automatically, deduplicated, attached to the correct Company / Contact / Case, preserved as original inbound-message evidence, and shown in ERP with accurate yesterday-new and waiting-to-handle counts.

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
1. Complete and merge ERP-GOV-INQUIRY-ROADMAP-REVISION-001 after Owner review.
2. GPT Web performs repository-grounded design of ERP-INQUIRY-M1.0.
3. Freeze Company / Contact / Case / Inbound Message ownership, identity, dedupe, status, timeline, API and migration contracts.
4. Split implementation into bounded Work Packages.
5. US Claude implements approved tasks and pushes to GitHub.
6. China Codex tests exact GitHub revisions on erp.b and returns evidence only.
7. GPT Web diagnoses failures and issues remediation instructions.
8. Owner UAT and merge gates apply.
```

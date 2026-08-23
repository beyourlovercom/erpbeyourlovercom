---
type: local-baseline-audit
project: BYL ERP
date: 2026-08-23
status: BASELINE_RECONCILED / OWNER_REVIEW
---

# ERP Local Baseline Audit — 2026-08-23

## Authority

This audit records the reconciled China-Mac local ERP baseline before the next product milestone.

```text
SOURCE_OF_TRUTH: GitHub
REPOSITORY: beyourlovercom/erpbeyourlovercom
PRIMARY_LOCAL_RUNTIME: http://erp.b
PRIMARY_TEST_ENVIRONMENT: China Mac
PRIMARY_IMPLEMENTATION_ENVIRONMENT: US Mac / Claude Code
DESIGN_DIAGNOSIS_AUTHORITY: GPT Web + Owner
CODEX_ROLE: test / verification / evidence only
CLAUDE_ROLE: implementation only
AICC_IN_ERP_EXECUTION_CHAIN: NO
REMOTE_MILESTONE_TARGET: https://erp.beyourlover.com
```

## Canonical Git Baseline

```text
MAIN: 75cb7fc822d30fd9ac34799b5971702c069a8de0
LOCAL_MAIN_MATCHES_ORIGIN_MAIN: YES
PRIMARY_WORKTREE_CLEAN: YES
LOCAL_RUNTIME_HTTP: 200
```

## Runtime Stack

```text
PHP: 8.3.32
Laravel: 13.23.0
Composer: 2.10.2
Node: 26.7.0
npm: 11.19.0
Database: MySQL 8.4.11
Frontend: Inertia + Vue 3 + TypeScript + Tailwind + Vite
APP_URL: http://erp.b
```

## Existing Product / Platform Capability

Current repository evidence already contains these implemented foundations:

- Identity / authentication / authorization;
- application shell and governed navigation;
- Dashboard foundation;
- Runtime health foundation;
- immutable Audit foundation;
- Sync Outbox and manual sync foundation;
- Orders, order items, addresses and external references;
- Shipments / Logistics workflow;
- Finance / order analysis;
- Redis / Queue / Scheduler validation foundation;
- PHPUnit, Vitest and Browser E2E infrastructure;
- local MySQL UAT seed / backup / restore evidence.

The existing ERP is therefore not a bootstrap-only application and must not be replaced by a fresh Jutdo fork or a new Laravel/Filament application.

Jutdo remains a product/engineering reference and reusable foundation source where appropriate; it is not authorized to overwrite the current ERP codebase.

## Current Automated Baseline Evidence

Executed on the reconciled China-Mac local repository during this audit:

```text
PHPUNIT: PASS — 159 passed / 2 skipped / 1 risky / 1 deprecated / 1623 assertions
VITEST: PASS — 12 files / 71 tests
TYPECHECK: PASS
PRODUCTION_BUILD: PASS
ERP.B: HTTP 200
```

The two runtime-specific PHPUnit validations that are intentionally disabled in the default test environment remain skipped; this audit does not reinterpret them as failures.

## Active Local Database Snapshot

```text
DATABASE: byl_erp_local_uat
ENGINE: MySQL 8.4.11
PRIMARY_SCHEMA_TABLES: 23
ORDERS: 13
SHIPMENTS: 8
MANUAL_SYNCS: 7
AUDIT_RECORDS: 44
USERS: 4
```

The local database is test/UAT data. No customer secrets, `.env`, credentials or raw production data are authorized for GitHub synchronization.

## Local Worktree Reconciliation

Five previously dirty worktrees were reviewed and reconciled.

### 1. App Shell WIP — KEEP / PRESERVED

```text
BRANCH: feature/byl-erp-ui-application-shell-001
PRESERVED_COMMIT: 53d2b79
GITHUB_PUSHED: YES
MERGE_READY: NO
PR_CREATED: NO
```

Reason: contains substantive shell/navigation work not present in PR #81. Focused verification exposed one unresolved test failure in the new localStorage restoration test, so it is preserved only as WIP and must not be merged without a new bounded review/remediation task.

### 2. Dashboard Data Foundation — ARCHIVE / PRESERVED

```text
ARCHIVE_BRANCH: archive/20260823-dashboard-data-foundation
PRESERVED_COMMIT: d0cb61e
GITHUB_PUSHED: YES
MERGE_READY: NO
PR_CREATED: NO
```

Reason: useful exploratory data-contract and DATA_GAP evidence exists, but it is not required for the current Inquiry milestone and contains deferred dashboard concerns. It is preserved for reference, not activated.

### 3. Fast UAT Inbox Scratch — DISCARD LOCAL DELTA

Reason: the valid 30-day persistent-login finding was already implemented and Owner-UAT-passed in PR #78; the remaining local scratch entry was unrelated AICC noise. No unique unresolved ERP evidence was lost.

### 4. Dashboard Candidate-B Scratch — DISCARD LOCAL DELTA

Reason: docs-only mutually exclusive alternative to PR #81; implementation never started. No production code was removed.

### 5. Dashboard UX Redesign Scratch — DISCARD LOCAL DELTA

Reason: documentation-only preflight stopped at DATA_GAP and had no authorized implementation. Relevant exploratory data-gap evidence is retained in the archived Data Foundation branch.

After reconciliation, all five reviewed worktrees are clean.

## Open GitHub Items Requiring Later Owner Disposition

```text
PR #81: OPEN / DRAFT — Dashboard operational workspace redesign
PR #20: OPEN / DRAFT — historical Menu temporary-closure documentation
```

Neither PR is part of this baseline audit merge decision.

## Current Product Direction

The next product milestone is not a new ERP bootstrap. It extends the existing modular monolith with the B2B inquiry domain.

```text
ERP-INQUIRY-M1
Company
→ Contact
→ Case
→ Inbound Message
→ Inquiry Inbox
→ Email Intake
→ WhatsApp Intake
→ Yesterday / Waiting Dashboard
```

Initial business objective:

> Email and WhatsApp inquiries are captured automatically, assigned to the correct Company / Contact / Case, and visible in ERP with accurate yesterday-new and waiting-to-handle counts.

## Gate

```text
BASELINE_RECONCILIATION: COMPLETE
GITHUB_CANONICAL_MAIN_SYNC: COMPLETE
LOCAL_ERP_RUNTIME: HEALTHY
NEXT_ACTION: define ERP-INQUIRY-M1 architecture/data/API contract before implementation
REMOTE_DEPLOYMENT: NOT AUTHORIZED UNTIL MILESTONE STABLE
```

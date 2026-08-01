---
type: byl-erp-work-package
project: BYL ERP
work_package: ERP-ARCH-MIGRATION-001D-B
status: prepared
standard_id: LARGE-PROJECT-EXEC-001
---

# ERP-ARCH-MIGRATION-001D-B — Audit Foundation

## Work Package

```text
ID: ERP-ARCH-MIGRATION-001D-B
TITLE: Audit Foundation
STATUS: PREPARED / IMPLEMENTATION NOT STARTED
OWNER_IMPLEMENTATION_AUTHORIZATION: NO
MIGRATION_FILE_AUTHORING: NO
MIGRATION_EXECUTION: NO
DEPENDENCY_CHANGE: NO
MERGE_AUTHORIZATION: NO
DEPLOYMENT_AUTHORIZATION: NO
PREPARATION_BASE_SHA: 50803c3fcd51e9f8b3485f4fb57565154a284bf7
IMPLEMENTATION_BASE_SHA: TBD AFTER THIS PREPARATION PR MERGES
```

This prepared package grants no application, Migration, merge or deployment authorization.

## Purpose

Establish one forward-compatible, immutable and security-safe Audit Foundation while preserving the existing 001B authentication audit data and contracts.

## Frozen Scope for Future Separately Authorized Implementation

- inspect the existing `audit_records` schema, model and authentication hooks;
- preserve all existing 001B audit records and behaviour;
- additive compatibility only;
- one application-level Audit Service boundary;
- authenticated user actor contract;
- trusted system actor contract;
- actor ID and actor type/source;
- action and entity correlation;
- entity type and entity ID where applicable;
- structured before and after metadata;
- request correlation where available;
- source and reason metadata;
- sensitive-field rejection;
- immutable creation behaviour;
- explicit failure behaviour for security-critical audit writes;
- authorization boundary for audit viewing;
- isolated automated tests.

## Explicit Exclusions

- destructive replacement of `audit_records`;
- deletion or rewriting of historical audit data;
- Sync Outbox or ERP-ARCH-MIGRATION-001D-C;
- external providers;
- Order, Shipment, Sync UI, accounting, inventory or business workflows;
- ERP-ARCH-MIGRATION-001E;
- UAT or deployment.

## Sensitive Information Contract

The Audit Foundation must recursively reject or remove:

- passwords and password confirmations;
- session identifiers and remember tokens;
- CSRF values;
- authorization headers and cookies;
- API credentials;
- complete request payloads;
- secrets and private keys.

## Migration Gate

The future implementation preflight must inspect the actual existing schema before deciding whether application-only compatibility is sufficient.

If an additive Migration file is required:

1. stop before creating it;
2. report the exact proposed schema delta;
3. obtain separate Owner Migration-file-authoring authorization;
4. do not create the Migration under this prepared package.

Migration execution always requires another separate Owner authorization.

## Acceptance Criteria for Future Implementation

- existing authentication audit behaviour remains passing;
- one Audit Service replaces scattered direct inserts;
- approved metadata is persisted deterministically;
- sensitive keys are recursively rejected or removed;
- actor and system-source semantics are explicit;
- audit records are immutable through normal application APIs;
- security-critical audit-write failure behaviour is tested;
- audit viewing remains separately authorized;
- no business workflow or Sync Outbox code is introduced;
- no production mutation occurs.

## Required Future Test Plan

- existing audit compatibility;
- authenticated actor and trusted system actor;
- entity correlation and before/after metadata;
- source and request correlation;
- recursive sensitive-field rejection and raw payload rejection;
- immutable update/delete behaviour;
- audit-write failure behaviour;
- authorization for audit viewing;
- authentication and Application Shell regression;
- no external service dependency.

## Preliminary Estimate

```text
Preflight and compatibility audit: 1.5–2.5h (planning value 2h)
Audit service and additive compatibility: 3.5–5.5h (planning value 4.5h)
Automated tests and validation: 2–3h (planning value 2.5h)
Remediation buffer: 2–3h (planning value 3h)
Estimated effective hours: 12h
Estimate range: 9–14 effective hours
```

The planning value reconciles to `2 + 4.5 + 2.5 + 3 = 12` hours. The range reconciles to `1.5 + 3.5 + 2 + 2 = 9` through `2.5 + 5.5 + 3 + 3 = 14` hours. This estimate confers no authorization.

## Final Preparation Verdict

```text
FINAL_PREPARATION_VERDICT: 001D_B_SCOPE_READY_FOR_OWNER_REVIEW
```

## Project Position

```text
PHASE_MAP:
001A Foundation Bootstrap             MERGED / PASS
001B Authentication / Authorization   MERGED / PASS
001C Application Shell                MERGED / PASS
→ 001D Runtime / Audit / Sync         IN PROGRESS
   001D-A Runtime Foundation          MERGED / PASS
   → 001D-B Audit Foundation          PREPARED / IMPLEMENTATION NOT STARTED
   001D-C Sync Outbox Foundation      WAITING
001E Shared UI / Browser E2E          WAITING
001F Architecture Re-audit            WAITING
COMPLETED_PHASES: 001A, 001B, 001C, 001D-A
CURRENT_PHASE: 001D-B Audit Foundation preparation
CURRENT_WORK_PACKAGE: ERP-ARCH-MIGRATION-001D-B
PACKAGE_STATUS: PREPARED / OWNER IMPLEMENTATION AUTHORIZATION NO
WAITING_PHASES: 001D-C, 001E, 001F
BASELINE: main@50803c3fcd51e9f8b3485f4fb57565154a284bf7
GITHUB_STATE: PR #9 MERGED / CLOSED; no 001D-B implementation PR exists
GLOBAL_GATE: FAIL
PRODUCTION_DEPLOYMENT_STATUS: NO
NEXT_DEPENDENCY: Owner reviews this prepared scope and separately decides implementation and Migration-file-authoring authorization
EVIDENCE_AS_OF: 2026-08-01; merged PR #9, existing 001B Audit foundation and frozen 001D execution order
```

## Estimated Codex Hours

```text
PREFLIGHT_AND_SCOPE: 1.5-2.5 hours (planning value 2 hours)
CORE_EXECUTION: 3.5-5.5 hours (planning value 4.5 hours)
TEST_AND_VALIDATION: 2-3 hours (planning value 2.5 hours)
REMEDIATION_BUFFER: 2-3 hours (planning value 3 hours)
ESTIMATED_EFFECTIVE_HOURS: 12 hours
ESTIMATE_RANGE: 9-14 effective hours
ESTIMATE_BASIS: planning values 2 + 4.5 + 2.5 + 3 hours; existing audit compatibility, one immutable Audit Service, sensitive-data rejection, authorization and isolated regression testing
OWNER_REVIEW: EXCLUDED
MERGE: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
```

---
type: byl-erp-work-package
project: BYL ERP
work_package: ERP-ARCH-MIGRATION-001D-B
status: prepared
standard_id: LARGE-PROJECT-EXEC-001
standard_version: 1.1
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
PREPARATION_BASE_SHA: d8b08cac5fefff0a6cdc66388bee5ad2a383c70b
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

## PROJECT POSITION

```text
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate → MVP1 Manual Fulfillment / Tencent Docs Replacement → MVP1.1 WooCommerce Inbound → MVP1.2 Single Tracking Outbound → MVP1.3 Sync Orchestration → Customer / Lead Pilot → Product / Media → WordPress Product and Image Sync → Content / SEO → Posts / Pages → GA4 / GSC / Cloudflare → Google Docs Publishing → Full Order Workspace → Logistics OS → KOL OS and Social Providers → Inventory and Procurement → Accounting / ROI Expansion → Shopify and Additional Integrations → Dev UAT and Production Hardening
CURRENT_TRACK_PHASE_MAP: 001A MERGED / PASS → 001B MERGED / PASS → 001C MERGED / PASS → 001D-A MERGED / PASS → 001D-B CURRENT / PREPARED / IMPLEMENTATION NOT STARTED → 001D-C WAITING → 001E WAITING → 001F WAITING
COMPLETED_PHASES: 001A, 001B, 001C, 001D-A
CURRENT_PHASE: 001D-B Audit Foundation preparation
CURRENT_WORK_PACKAGE: ERP-ARCH-MIGRATION-001D-B
PACKAGE_STATUS: PREPARED / OWNER IMPLEMENTATION AUTHORIZATION NO
WAITING_PHASES: 001D-C, 001E, 001F
BASELINE: main@d8b08cac5fefff0a6cdc66388bee5ad2a383c70b
GITHUB_STATE: PR #9 MERGED / CLOSED; PR #11 MERGED / CLOSED; PR #10 OPEN / NON-DRAFT / UNMERGED; no 001D-B implementation PR exists
SOURCE_CONFLICTS: GitHub confirms 001D-A merged; main transition documents remain stale until PR #10 merges; this rebased PR contains the pending 001D-B preparation transition
GLOBAL_GATE: FAIL
PRODUCTION_DEPLOYMENT_STATUS: NO
NEXT_DEPENDENCY: Owner reviews this prepared scope and separately decides implementation and Migration-file-authoring authorization
EVIDENCE_AS_OF: 2026-08-01; merged PR #9, existing 001B Audit foundation, origin/main and frozen 001D order
```

## ROADMAP HOURS

```text
CURRENT_PACKAGE_ESTIMATE: 12h planning value; 9–14h range; 001D-B remaining component estimate
KNOWN_CURRENT_TRACK_REMAINING: 23–36h; planning value 30h; 001D-B + 001D-C only
ARCHITECTURE_GATE_REMAINING: UNKNOWN WITH EVIDENCE; 001E and 001F lack frozen estimates
MVP1_TOTAL_ESTIMATE: 122–190h total estimate
MVP1_PLANNING_BASELINE: 166h total planning value
MVP1_MAXIMUM_ENVELOPE: 192h maximum total envelope
MVP1_REMAINING: UNKNOWN WITH EVIDENCE; accepted actual-hours ledger and overlap map are incomplete
POST_MVP_KNOWN_INCREMENT: approximately 68–120h incremental beyond MVP1
LONG_TERM_CUMULATIVE_RANGE: 235–402h through 1,819–3,146h; cumulative non-additive horizons
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE; cumulative horizons and project-wide actual ledger are incomplete
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE; accepted project-wide Work Log ledger is incomplete
ESTIMATE_CONFIDENCE: HIGH for quoted totals; MEDIUM for 001D-B/C sum; LOW / UNKNOWN for remaining totals
ESTIMATE_AS_OF: 2026-08-01
ESTIMATE_METHOD: repository classification; no unsafe subtraction; no cumulative or overlapping aggregation
```

## ESTIMATED CODEX HOURS

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
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
```

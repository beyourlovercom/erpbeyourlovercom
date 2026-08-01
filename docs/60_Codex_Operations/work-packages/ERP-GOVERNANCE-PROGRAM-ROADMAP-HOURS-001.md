---
type: byl-erp-work-package
project: BYL ERP
work_package: ERP-GOVERNANCE-PROGRAM-ROADMAP-HOURS-001
status: documentation-governance-authorized
standard_id: LARGE-PROJECT-EXEC-001
standard_version: 1.1
---

# ERP-GOVERNANCE-PROGRAM-ROADMAP-HOURS-001

## Work Package

```text
ID: ERP-GOVERNANCE-PROGRAM-ROADMAP-HOURS-001
TITLE: Enforce complete Program Roadmap and Roadmap Hours reporting
STATUS: DOCUMENTATION GOVERNANCE AUTHORIZED / IN PROGRESS
OWNER_DOCUMENTATION_AUTHORIZATION: YES
APPLICATION_IMPLEMENTATION_AUTHORIZATION: NO
PR10_MODIFICATION_AUTHORIZATION: NO
MIGRATION_AUTHORIZATION: NO
MERGE_AUTHORIZATION: NO
DEPLOYMENT_AUTHORIZATION: NO
BASE_SHA: 50803c3fcd51e9f8b3485f4fb57565154a284bf7
BRANCH: docs/erp-governance-program-roadmap-hours-001
```

## Root Cause

Version 1.0 required only the current phase map and current package estimate. It did not require the complete Program Roadmap, a distinct current-track map, project/track remaining-hours classifications, an evidence-backed UNKNOWN contract, or a full final-report footer. This permitted partial roadmap reporting, unsafe aggregation and final reports without complete merge-readiness evidence.

## Exact Scope and Files

Exactly eleven governance Markdown files are authorized: the standard, Entrypoint, task/final templates, Command Center, Living Roadmap, Effective Hours, Decision Register, Change Log, Program Roadmap/Hours source and this Work Package. No application or PR #10 branch change is authorized.

## Version Transition

- repository-local `LARGE-PROJECT-EXEC-001` advances from 1.0 to 1.1;
- version 1.1 applies after merge to newly issued formal work and all open/unmerged work reviewed afterward;
- historical merged packages remain valid under their issued standard;
- PR #10 remains open and unchanged here, then must be updated under 1.1 before merge;
- this governance decision occupies D-021; PR #10's unmerged transition decision must become D-022 after rebase.

## Acceptance Criteria

- every formal task and final report ends with `PROJECT POSITION`, `ROADMAP HOURS`, `ESTIMATED CODEX HOURS` in that order;
- Program Roadmap and current-track map are distinct and complete;
- Roadmap Hours classifies current, remaining, total, incremental, cumulative and actual values;
- unsupported remaining totals use `UNKNOWN WITH EVIDENCE`;
- cumulative/overlapping estimates are not summed;
- `MIGRATION` remains mandatory and cannot be replaced by subfields;
- final reports require actual effective hours and variance;
- Program Roadmap/Hours source exists and is linked;
- PR #10 remains unchanged; exactly eleven Markdown files change.

## Validation

Run diff checks, exact-scope checks and bounded searches for `PROGRAM_ROADMAP`, `ROADMAP HOURS`, `MIGRATION:` and `ACTUAL_EFFECTIVE_HOURS`. Manually validate module order, number classifications, UNKNOWN evidence, PR #10 immutability and clean worktree. Application tests are excluded.

## PR #10 Handling

```text
GITHUB_FACT: 001D-A PR #9 is MERGED / PASS.
MAIN_GOVERNANCE_FACT: main documentation remains stale regarding the 001D-B transition.
OPEN_PR_FACT: PR #10 contains the pending transition and is not repository truth.
OPEN_PR10: REMEDIATION REQUIRED AFTER GOVERNANCE MERGE
PR10_CURRENT_D021: UNMERGED / NOT REPOSITORY TRUTH
DECISION_NUMBER_AFTER_REBASE: D-022
```

## Remediation

```text
REMEDIATION_ID: ERP-GOVERNANCE-PROGRAM-ROADMAP-HOURS-001-REMEDIATION-001
ROOT_CAUSE: Version 1.1 rewrite unintentionally removed the structured task scaffold and several version 1.0 downstream-authorization safeguards.
REMEDIATION: Restore the task scaffold, repository-local boundary, downstream inclusion gates and variance formulas while preserving all version 1.1 additions.
PR: #11
PREVIOUS_HEAD: 2e79cfec3109dcf3f56f014359fdb410d491ed30
```

## Final Report Contract

Report standard/version effects, Program and current-track contracts, Roadmap Hours and unknown rules, actual ledger, PR #10 status, D-021/D-022 rule, exact eleven-file diff and all prohibition statuses. The final report must use the version 1.1 three-module footer and include actual hours and variance.

## PROJECT POSITION

```text
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate → MVP1 Manual Fulfillment / Tencent Docs Replacement → MVP1.1 WooCommerce Inbound → MVP1.2 Single Tracking Outbound → MVP1.3 Sync Orchestration → Customer / Lead Pilot → Product / Media → WordPress Product and Image Sync → Content / SEO → Posts / Pages → GA4 / GSC / Cloudflare → Google Docs Publishing → Full Order Workspace → Logistics OS → KOL OS and Social Providers → Inventory and Procurement → Accounting / ROI Expansion → Shopify and Additional Integrations → Dev UAT and Production Hardening
CURRENT_TRACK_PHASE_MAP: 001A MERGED → 001B MERGED → 001C MERGED → 001D-A MERGED BY GITHUB → 001D-B TRANSITION HELD IN PR #10 → 001D-C WAITING → 001E WAITING → 001F WAITING
COMPLETED_PHASES: 001A, 001B, 001C, 001D-A BY VERIFIED GITHUB STATE
CURRENT_PHASE: Governance standard remediation blocking 001D-B transition
CURRENT_WORK_PACKAGE: ERP-GOVERNANCE-PROGRAM-ROADMAP-HOURS-001
PACKAGE_STATUS: OWNER AUTHORIZED FOR DOCUMENTATION GOVERNANCE ONLY
WAITING_PHASES: PR #10 remediation; 001D-B implementation; 001D-C; 001E; 001F; MVP1 and later Program phases
BASELINE: main@50803c3fcd51e9f8b3485f4fb57565154a284bf7
GITHUB_STATE: PR #9 MERGED / CLOSED; PR #10 OPEN at fba4359caddace6a618348a054fd4ff0fc11d5b8 and held from merge
SOURCE_CONFLICTS: GitHub confirms 001D-A merged; main governance is stale; PR #10 contains unmerged pending transition content
GLOBAL_GATE: FAIL
PRODUCTION_DEPLOYMENT_STATUS: NO
NEXT_DEPENDENCY: merge this governance PR, then rebase and remediate PR #10 under version 1.1
EVIDENCE_AS_OF: 2026-08-01 13:33 +08:00; main governance, PR #9, PR #10, Living Roadmap, MVP1 and Effective Hours
```

## ROADMAP HOURS

```text
CURRENT_PACKAGE_ESTIMATE: 4.5h planning value; 3.5–6h range; current governance task estimate
KNOWN_CURRENT_TRACK_REMAINING: 23–36h; planning value 30h; 001D-B + 001D-C only
ARCHITECTURE_GATE_REMAINING: UNKNOWN WITH EVIDENCE; 001E and 001F unestimated
MVP1_TOTAL_ESTIMATE: 122–190h total estimate
MVP1_PLANNING_BASELINE: 166h total planning value
MVP1_MAXIMUM_ENVELOPE: 192h maximum total envelope
MVP1_REMAINING: UNKNOWN WITH EVIDENCE; accepted actual ledger and overlap map incomplete
POST_MVP_KNOWN_INCREMENT: approximately 68–120h incremental beyond MVP1
LONG_TERM_CUMULATIVE_RANGE: 235–402h through 1,819–3,146h; alternative cumulative horizons, not additive
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE; cumulative horizons and incomplete project actuals prevent safe calculation
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE; accepted project-wide Work Log ledger incomplete
ESTIMATE_CONFIDENCE: HIGH quoted totals; MEDIUM 001D-B/C sum; LOW / UNKNOWN remaining totals
ESTIMATE_AS_OF: 2026-08-01 13:33 +08:00
ESTIMATE_METHOD: repository classifications; no subtraction without accepted actuals; no cumulative or overlapping addition
```

## ESTIMATED CODEX HOURS

```text
PREFLIGHT_AND_SCOPE: 0.5-0.75 hours (planning value 0.5 hours)
CORE_EXECUTION: 2-3.5 hours (planning value 2.75 hours)
TEST_AND_VALIDATION: 0.5-0.75 hours (planning value 0.5 hours)
REMEDIATION_BUFFER: 0.5-1 hours (planning value 0.75 hours)
ESTIMATED_EFFECTIVE_HOURS: 4.5 hours
ESTIMATE_RANGE: 3.5-6 effective hours
ESTIMATE_BASIS: planning values 0.5 + 2.75 + 0.5 + 0.75 hours; eleven governance Markdown files, standard versioning, Program Roadmap/Hours source, templates and one PR
OWNER_REVIEW: EXCLUDED
MERGE: EXCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
```

---
type: work-package
project: BYL ERP
work_package_id: ERP-VERIFICATION-TIER-POLICY-001
status: GPT_AUTHORED / OWNER_POLICY_APPROVED / MERGE_NOT_AUTHORIZED
execution_provider: GPT_WEB
baseline: main@02ce87dba5ede14d791b646b7cbe976a12064cae
---

# ERP-VERIFICATION-TIER-POLICY-001

## Objective

Persist the Owner-approved verification strategy into repository-level execution governance so ordinary development does not waste time by running the same complete regression suite on both the US and China Macs.

## Owner decision

Owner instruction on 2026-08-23 approves this operational default:

```text
DAILY DEVELOPMENT:
US_MAC = PRIMARY FULL VERIFICATION
CHINA_MAC = EXACT-SHA SMOKE VERIFICATION

MILESTONE / RELEASE / HIGH-RISK:
US_MAC = FULL VERIFICATION
CHINA_MAC = FULL INDEPENDENT VERIFICATION
```

## Scope

- bump `AI/EXECUTION_ROLE_MODEL.md` from 1.0 to 1.1;
- bump `AI/LARGE_PROJECT_EXECUTION_STANDARD.md` from 1.2 to 1.3;
- define the daily asymmetric verification contract;
- define milestone/release/high-risk dual-host triggers;
- preserve China Codex as an independent verifier without requiring duplicated full regression during ordinary development;
- preserve GitHub as the only source-code synchronization path.

## Prohibitions

This governance package does not change product code, schema, routes, permissions, dependencies, deployment state, PR #87 product scope, Inquiry architecture, or Owner merge/release authority.

## Acceptance criteria

- ordinary daily Work Packages default to one US full verification plus one China exact-SHA smoke;
- China smoke is bounded to SHA/clean/runtime/task-critical evidence and does not duplicate the entire US suite by default;
- milestone/release and explicitly high-risk work require full independent verification on both hosts;
- smoke failure returns raw evidence and requires GPT classification before verification scope expands;
- new packages can declare `DAILY`, `MILESTONE_RELEASE`, `HIGH_RISK`, or `OWNER_OVERRIDE`;
- historical/open packages may adopt the policy prospectively without retrospective document churn;
- implementation/test/diagnosis authority boundaries remain unchanged.

## Validation

```text
EXECUTION_PROVIDER = GPT_WEB
DOCUMENT_SCOPE_ONLY = YES
PRODUCT_CODE_CHANGED = NO
SCHEMA_CHANGED = NO
ROUTES_CHANGED = NO
PERMISSIONS_CHANGED = NO
DEPENDENCIES_CHANGED = NO
VALIDATION = git diff --check + governance text consistency review
```

PROJECT POSITION
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate [COMPLETE] → MVP1 Existing Operations / Tencent Docs Replacement [IMPLEMENTATION COMPLETE; HUMAN UAT REMEDIATION OPEN] → ERP-INQUIRY-M1 B2B Inquiry Foundation [CURRENT] → ERP-INQUIRY-M1 Local Milestone UAT → Milestone-gated Remote Release to erp.beyourlover.com → MVP1.1 WooCommerce Inbound → MVP1.2 Single Tracking Outbound → MVP1.3 Sync Orchestration → Product / Media → WordPress Product and Image Sync → Content / SEO → Posts / Pages → GA4 / GSC / Cloudflare → Google Docs Publishing → Full Order Workspace → Logistics OS → KOL OS and Social Providers → Inventory and Procurement → Accounting / ROI Expansion → Shopify and Additional Integrations → Production Hardening / Expansion
CURRENT_TRACK_PHASE_MAP: ERP-INQUIRY-M1.0 [COMPLETE] → ERP-INQUIRY-M1.1 [WAITING] → ERP-INQUIRY-M1.2 [WAITING] → ERP-INQUIRY-M1.3 [WAITING] → ERP-INQUIRY-M1.4 [WAITING] → ERP-INQUIRY-M1.5 [WAITING] → ERP-INQUIRY-M1.6 [WAITING] → ERP-INQUIRY-M1.7 [WAITING] → ERP-INQUIRY-M1.8 [WAITING] → ERP-INQUIRY-M1.9 [WAITING]
COMPLETED_PHASES: Architecture Foundation; ERP-INQUIRY-M1.0 contract
CURRENT_PHASE: Cross-cutting execution governance refinement
CURRENT_WORK_PACKAGE: ERP-VERIFICATION-TIER-POLICY-001
PACKAGE_STATUS: OWNER_POLICY_APPROVED / GOVERNANCE_PR_PENDING
WAITING_PHASES: ERP-INQUIRY-M1.1 through ERP-INQUIRY-M1.9
BASELINE: main@02ce87dba5ede14d791b646b7cbe976a12064cae
GITHUB_STATE: governance/erp-verification-tier-policy-001; Draft PR #88 OPEN / NOT MERGED
SOURCE_CONFLICTS: NONE WITH EVIDENCE — this package intentionally supersedes the prior default that normal regression remained on the China Mac
GLOBAL_GATE: OWNER MERGE AUTHORIZATION REQUIRED
PRODUCTION_DEPLOYMENT_STATUS: NO WITH EVIDENCE — governance-only package
NEXT_DEPENDENCY: PR #88 review and explicit Owner merge authorization
EVIDENCE_AS_OF: 2026-08-23

ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 0.75h planning value; 0.5–1.0h range
KNOWN_CURRENT_TRACK_REMAINING: UNKNOWN WITH EVIDENCE — this governance package does not recalculate Inquiry delivery estimates
ARCHITECTURE_GATE_REMAINING: UNKNOWN WITH EVIDENCE — not recalculated by this package
MVP1_TOTAL_ESTIMATE: UNKNOWN WITH EVIDENCE — not recalculated by this package
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE — not recalculated by this package
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE — not recalculated by this package
MVP1_REMAINING: UNKNOWN WITH EVIDENCE — not recalculated by this package
POST_MVP_KNOWN_INCREMENT: UNKNOWN WITH EVIDENCE — not recalculated by this package
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE — not recalculated by this package
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE — not recalculated by this package
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE — no accepted Work Log created by this package
ESTIMATE_CONFIDENCE: HIGH
ESTIMATE_AS_OF: 2026-08-23
ESTIMATE_METHOD: bounded governance text mutation and consistency validation

ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 0.10h planning value
CORE_EXECUTION: 0.40h planning value
TEST_AND_VALIDATION: 0.15h planning value
REMEDIATION_BUFFER: 0.10h planning value
ESTIMATED_EFFECTIVE_HOURS: 0.75h
ESTIMATE_RANGE: 0.5–1.0h
ESTIMATE_BASIS: GPT-authored governance-only change; legacy footer label retained for schema compatibility
OWNER_REVIEW: INCLUDED
MERGE: EXCLUDED
MIGRATION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED

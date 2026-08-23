---
type: work-package
project: BYL ERP
work_package_id: ERP-GOV-EXECUTION-ROLE-ALIGNMENT-001
standard_id: LARGE-PROJECT-EXEC-001
standard_version: 1.2
status: ACTIVE / GOVERNANCE ONLY
updated: 2026-08-23
---

# ERP-GOV-EXECUTION-ROLE-ALIGNMENT-001

## Objective

Make the repository entrypoints and current governance enforce Owner decision D-034 before `ERP-INQUIRY-M1.0` begins.

## Authority

```text
OWNER_AUTHORIZATION_BASIS: approved direct GPT / Claude / Codex operating model and PR #83
BASELINE: main@c5fb481b5e8c97eca709b12371b644013eee46ec
EXECUTION_PROVIDER: GPT_WEB
PRODUCT_IMPLEMENTATION: NO
MIGRATION: NO
REMOTE_DEPLOYMENT: NO
PRODUCTION_ACCESS: NO
```
## Scope

- add one canonical execution-role overlay;
- add the Claude implementation entrypoint;
- convert the Codex entrypoint to test/evidence only;
- update AGENTS and Repository Manifest role gates;
- advance Large Project / Parallel Work governance to role-aligned versions;
- synchronize current context, roadmap status, decision/change records and package index;
- preserve all historical merged evidence unchanged.

## Acceptance criteria

```text
GPT_DESIGN_DIAGNOSIS_AUTHORITY = EXPLICIT
CLAUDE_IMPLEMENTATION_ONLY = EXPLICIT
CODEX_TEST_EVIDENCE_ONLY = EXPLICIT
CODEX_TRACKED_FILE_MUTATION = FORBIDDEN
GITHUB_UNIQUE_SYNC_SOURCE = EXPLICIT
CHINA_LOCAL_TEST_URL = http://erp.b
REMOTE_TARGET = MILESTONE_GATED_ONLY
CURRENT_PROGRAM_ROADMAP = ERP-INQUIRY-M1 CURRENT
HISTORICAL_RECORDS_REWRITTEN = NO
APPLICATION_CODE_CHANGED = NO
```
## Prohibited actions

- no product code;
- no schema or Migration creation/execution;
- no Email or WhatsApp provider connection;
- no remote access or deployment;
- no merge of unrelated PRs;
- no closure of the five existing MVP1 Human UAT findings.

## Validation

```text
git diff --check
tracked-file scope review
role-conflict grep across AGENTS / entrypoints / active governance
Program Roadmap consistency check against document 13
local main baseline remains c5fb481 before this branch
```

## PROJECT POSITION

```text
PROGRAM_ROADMAP: Architecture Foundation [COMPLETE] → MVP1 Existing Operations [implementation complete; Human UAT remediation open] → ERP-INQUIRY-M1 [CURRENT] → ERP-INQUIRY-M1 Local Milestone UAT → milestone-gated erp.beyourlover.com release → WooCommerce Inbound → Single Tracking Outbound → Sync Orchestration → Product/Media → WordPress Product/Image Sync → Content/SEO → Posts/Pages → GA4/GSC/Cloudflare → Google Docs Publishing → Full Order Workspace → Logistics OS → KOL/Social → Inventory/Procurement → Accounting/ROI → Shopify/Additional Integrations → Production Hardening/Expansion
CURRENT_TRACK_PHASE_MAP: PR #83 roadmap revision [MERGED] → ERP-GOV-EXECUTION-ROLE-ALIGNMENT-001 [CURRENT] → ERP-INQUIRY-M1.0 [WAITING] → M1.1–M1.9 [WAITING]
COMPLETED_PHASES: Architecture Foundation; existing MVP1 implementation; Local Baseline Audit; Inquiry roadmap revision
CURRENT_PHASE: execution-role governance alignment
CURRENT_WORK_PACKAGE: ERP-GOV-EXECUTION-ROLE-ALIGNMENT-001
PACKAGE_STATUS: ACTIVE / GOVERNANCE ONLY
WAITING_PHASES: ERP-INQUIRY-M1.0 through M1.9
BASELINE: main@c5fb481b5e8c97eca709b12371b644013eee46ec
GITHUB_STATE: PR #83 MERGED; this package branch pending Draft PR
SOURCE_CONFLICTS: active entrypoints/standards contain stale Codex/OpenCode implementation assumptions and one stale embedded Program Roadmap; this package resolves them
GLOBAL_GATE: PASS
PRODUCTION_DEPLOYMENT_STATUS: NO
NEXT_DEPENDENCY: Owner approval/merge of this governance package, then GPT-authored ERP-INQUIRY-M1.0
EVIDENCE_AS_OF: 2026-08-23
```
## ROADMAP HOURS

```text
CURRENT_PACKAGE_ESTIMATE: 3h planning value / 2–4h range
KNOWN_CURRENT_TRACK_REMAINING: ERP-INQUIRY-M1 implementation remains UNKNOWN WITH EVIDENCE until M1.0 contract is frozen
ARCHITECTURE_GATE_REMAINING: 0h / complete
MVP1_TOTAL_ESTIMATE: 122–190h total historical estimate
MVP1_PLANNING_BASELINE: 166h
MVP1_MAXIMUM_ENVELOPE: 192h
MVP1_REMAINING: UNKNOWN WITH EVIDENCE
POST_MVP_KNOWN_INCREMENT: approximately 68–120h historical WooCommerce inbound + single outbound increment
LONG_TERM_CUMULATIVE_RANGE: 235–402h; 603–1,018h; 1,059–1,802h; 1,595–2,714h; 1,819–3,146h
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE
ESTIMATE_CONFIDENCE: HIGH for this bounded governance package; LOW/UNKNOWN for full program remaining
ESTIMATE_AS_OF: 2026-08-23
ESTIMATE_METHOD: bounded documentation/entrypoint synchronization; no product implementation
```

## ESTIMATED CODEX HOURS

```text
EXECUTION_PROVIDER: GPT_WEB
PREFLIGHT_AND_SCOPE: 0.5–1h; planning value 0.75h
CORE_EXECUTION: 1–2h; planning value 1.5h
TEST_AND_VALIDATION: 0.5–0.75h; planning value 0.5h
REMEDIATION_BUFFER: 0–0.25h; planning value 0.25h
ESTIMATED_EFFECTIVE_HOURS: 3h
ESTIMATE_RANGE: 2–4h
ESTIMATE_BASIS: role-conflict audit plus bounded governance/entrypoint edits and consistency validation
OWNER_REVIEW: INCLUDED
MERGE: EXCLUDED
MIGRATION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
```

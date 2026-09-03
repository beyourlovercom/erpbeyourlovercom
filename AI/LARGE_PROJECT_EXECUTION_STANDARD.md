---
type: execution-governance-standard
project: BYL ERP
standard_id: LARGE-PROJECT-EXEC-001
version: 1.5
---

# Large Project Execution Standard

## Standard Identification

```text
Standard ID: LARGE-PROJECT-EXEC-001
Version: 1.5
```

## Applicability

This repository-local standard governs every formal BYL ERP task: implementation, remediation, documentation, validation, Migration preparation/execution, UAT and deployment preparation. Exploration must become a complete formal package before repository mutation, commit, PR work, Migration, UAT or deployment.

## Execution Role Authority

`AI/EXECUTION_ROLE_MODEL.md` is the forward-effective authority for who may design, implement, test and diagnose. GPT Web + Owner own design/diagnosis. GPT Web may implement bounded approved work; US Codex CLI may implement frozen deterministic low-to-medium complexity Work Packages; US Claude Code implements complex/high-ambiguity Work Packages.

The legacy footer label `ESTIMATED CODEX HOURS` is retained only for repository schema compatibility. New packages must explicitly declare one actual provider: `EXECUTION_PROVIDER: GPT_WEB`, `EXECUTION_PROVIDER: US_CODEX_CLI`, or `EXECUTION_PROVIDER: CLAUDE_CODE`. Verification-only packages may use `EXECUTION_PROVIDER: CODEX_CLI`.

## Verification Tier Contract

`AI/EXECUTION_ROLE_MODEL.md` defines provider and host roles. This standard defines verification depth.

### Daily development default

For ordinary implementation/remediation work:

```text
US implementation host = ONE complete package verification
SECONDARY HOST = NONE BY DEFAULT
DUPLICATE_FULL_SUITE = NO BY DEFAULT
```

The US full verification must run on the merge-candidate revision before or immediately before push and satisfy the active Work Package validation contract. It may include PHPUnit, Vitest, typecheck, build, Architecture/Menu checks, System Chrome E2E, MySQL/Redis/runtime checks and other bounded validation.

No China Mac smoke is required by default. An independent secondary-host check occurs only when GPT/Owner explicitly requests it or a concrete cross-machine/region-specific defect requires it; the Work Package must identify the host and exact scope.

### High-risk / milestone verification

High-risk triggers include migration/schema/database changes, PHP/MySQL/Redis/runtime/environment changes, authentication/authorization/security changes, browser/runtime compatibility concerns, deployment/release tooling changes and milestone/release candidates.

High-risk work requires stronger verification on the US implementation host. It does not automatically require a China Mac or dual-host run. GPT/Owner may explicitly add independent verification when risk justifies it.

Every newly activated implementation/remediation Work Package must state one verification classification:

```text
VERIFICATION_TIER = DAILY
VERIFICATION_TIER = MILESTONE_RELEASE
VERIFICATION_TIER = HIGH_RISK
VERIFICATION_TIER = OWNER_OVERRIDE
```

If omitted after version 1.5 becomes effective, the default is `DAILY`. Historical/open packages do not require retrospective rewrites solely for this field.

## Repository-Local Implementation

This file is the BYL ERP repository's local implementation of `LARGE-PROJECT-EXEC-001`. It directly governs only the BYL ERP repository.

Other projects must install this standard or a project-adapted implementation in their own repositories. Each repository implementation governs that project's facts, authorization gates and execution constraints.

Merging this standard does not automatically govern another repository, and no task or report may claim otherwise.

## Version Transition

- version 1.5 becomes effective when its governance PR merges;
- it applies to newly activated formal tasks and materially re-scoped open work;
- historical merged packages remain governed by their issued version and are not retroactively invalidated;
- version 1.5 adopts Execution Role Model v1.4 and `BYL_ERP_CONTINUOUS_OWNER_AUTHORIZATION_V1`: US Codex retains frozen deterministic implementation authority, Claude remains the complex/high-ambiguity provider, China remains outside the default verification chain, and routine in-scope Git delivery no longer requires repeated Owner approval;
- historical Codex/OpenCode/China verification records remain history only and do not override current role authority;
- the legacy `ESTIMATED CODEX HOURS` footer remains schema-compatible, while new packages declare their actual `EXECUTION_PROVIDER`.

## Completeness Gates

A formal package must contain scope, authority, prohibitions, acceptance criteria, validation, repository rules and the mandatory footer. A final report must contain task results and the same footer.

```text
TASK_PACKAGE = INCOMPLETE when any mandatory footer module is missing, incomplete, inconsistent, incorrectly ordered or followed by semantic content.
EXECUTION = PROHIBITED for an incomplete task package.
FINAL_REPORT = INCOMPLETE when its footer or actual/variance evidence is incomplete.
MERGE_READINESS = FAIL for an incomplete final report.
```

Pure Markdown fence closures may follow the final field; semantic content may not.

## Mandatory Footer Order and Fields

Every formal task and final report must end with these modules in this exact order:

```text
PROJECT POSITION
PROGRAM_ROADMAP: <complete top-level BYL ERP delivery sequence>
CURRENT_TRACK_PHASE_MAP: <complete current track/subphase sequence>
COMPLETED_PHASES:
CURRENT_PHASE:
CURRENT_WORK_PACKAGE:
PACKAGE_STATUS:
WAITING_PHASES:
BASELINE:
GITHUB_STATE:
SOURCE_CONFLICTS: <all evidenced conflicts or NONE WITH EVIDENCE>
GLOBAL_GATE:
PRODUCTION_DEPLOYMENT_STATUS: YES|NO|PARTIAL|UNKNOWN WITH EVIDENCE
NEXT_DEPENDENCY:
EVIDENCE_AS_OF:

ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: <planning value and range; must match final module>
KNOWN_CURRENT_TRACK_REMAINING: <frozen non-overlapping packages only>
ARCHITECTURE_GATE_REMAINING: <remaining estimate or UNKNOWN WITH EVIDENCE>
MVP1_TOTAL_ESTIMATE: <total estimate>
MVP1_PLANNING_BASELINE: <total planning value>
MVP1_MAXIMUM_ENVELOPE: <maximum total envelope>
MVP1_REMAINING: <remaining estimate or UNKNOWN WITH EVIDENCE>
POST_MVP_KNOWN_INCREMENT: <incremental estimate>
LONG_TERM_CUMULATIVE_RANGE: <cumulative horizons; never summed>
PROGRAM_REMAINING: <remaining estimate or UNKNOWN WITH EVIDENCE>
COMPLETED_ACTUAL_EFFECTIVE_HOURS: <accepted Work Log ledger or UNKNOWN WITH EVIDENCE>
ESTIMATE_CONFIDENCE:
ESTIMATE_AS_OF:
ESTIMATE_METHOD:

ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE:
CORE_EXECUTION:
TEST_AND_VALIDATION:
REMEDIATION_BUFFER:
ESTIMATED_EFFECTIVE_HOURS:
ESTIMATE_RANGE:
ESTIMATE_BASIS:
OWNER_REVIEW: INCLUDED|EXCLUDED
MERGE: INCLUDED|EXCLUDED
MIGRATION: INCLUDED|EXCLUDED
MIGRATION_FILE_AUTHORING: INCLUDED|EXCLUDED (optional detail)
MIGRATION_EXECUTION: INCLUDED|EXCLUDED (optional detail)
UAT: INCLUDED|EXCLUDED
DEPLOYMENT: INCLUDED|EXCLUDED
```

`ESTIMATED CODEX HOURS` is always the final semantic module. Optional Migration detail fields may not replace mandatory `MIGRATION`.

## Project Position Contract

`PROGRAM_ROADMAP` and `CURRENT_TRACK_PHASE_MAP` are distinct and mandatory. The Program Roadmap is the complete top-level sequence in `docs/10_Planning_Roadmap/13_PROGRAM_ROADMAP_AND_HOURS.md`; the current Architecture map cannot replace it. The current-track map must contain all completed/current/waiting subphases and agree with the status fields.

Project Position derives from `AI/CURRENT_CONTEXT.md`, Living Roadmap, document 13, the active Work Package and current Git/GitHub state. GitHub governs mutable PR/merge facts; main governs merged repository documentation; open PR content is pending and not repository truth.

When sources conflict, preserve every evidenced value in `SOURCE_CONFLICTS`, identify source authority and stop at the applicable gate. Never silently select stale Markdown or unmerged content. Empty categories use `NONE WITH EVIDENCE`; unverifiable facts use `UNKNOWN WITH EVIDENCE`, never invented facts.

`GLOBAL_GATE` remains separate. `PRODUCTION_DEPLOYMENT_STATUS` independently reports actual production state and is distinct from estimate inclusion.

## Program Roadmap

The BYL ERP top-level sequence is:

```text
Architecture Migration / Global Architecture Gate [COMPLETE]
→ MVP1 Existing Operations / Tencent Docs Replacement [IMPLEMENTATION COMPLETE; HUMAN UAT REMEDIATION OPEN]
→ ERP-INQUIRY-M1 B2B Inquiry Foundation [CURRENT]
→ ERP-INQUIRY-M1 Local Milestone UAT
→ Milestone-gated Remote Release to erp.beyourlover.com
→ MVP1.1 WooCommerce Inbound
→ MVP1.2 Single Tracking Outbound
→ MVP1.3 Sync Orchestration
→ Product / Media
→ WordPress Product and Image Sync
→ Content / SEO
→ Posts / Pages
→ GA4 / GSC / Cloudflare
→ Google Docs Publishing
→ Full Order Workspace
→ Logistics OS
→ KOL OS and Social Providers
→ Inventory and Procurement
→ Accounting / ROI Expansion
→ Shopify and Additional Integrations
→ Production Hardening / Expansion
```

The current Architecture track separately shows 001A through 001F, including 001D-A/B/C where applicable.

## Roadmap Hours Contract

Document 13 is the estimate-classification and actual-ledger source. Every number is labeled total, remaining, incremental, cumulative, planning value, range or actual effort.

- `CURRENT_PACKAGE_ESTIMATE` agrees with `ESTIMATED_EFFECTIVE_HOURS` and `ESTIMATE_RANGE`;
- known track remaining includes only frozen non-overlapping packages;
- a total estimate is not automatically remaining;
- remaining effort requires accepted actuals, an overlap map and documented subtraction;
- cumulative horizons are never summed;
- components are not added to cumulative values already containing them;
- unknown is never converted to zero;
- `UNKNOWN WITH EVIDENCE` states checked sources, missing data and why arithmetic is unsafe;
- completed actual hours derive only from accepted Work Logs, never PR elapsed time, timestamps, tokens or chat memory.

## Current Package Estimate

Every package retains separate `PREFLIGHT_AND_SCOPE`, `CORE_EXECUTION`, `TEST_AND_VALIDATION` and `REMEDIATION_BUFFER`. Components contain ranges and auditable planning values. `ESTIMATED_EFFECTIVE_HOURS` equals all planning values; `ESTIMATE_RANGE` equals all lower through all upper bounds. Estimates do not confer authorization.

## Effective and Actual Hours

Effective hours are productive in-scope work. They exclude waiting, external approval delays, access delays, unrelated outages and idle UAT. Rejected/repeated in-scope work remains actual effort and requires explanation.

The final `ESTIMATED CODEX HOURS` module retains the issued estimate and additionally includes:

```text
ACTUAL_EFFECTIVE_HOURS:
ACTUAL_HOURS_BREAKDOWN:
VARIANCE: <actual minus estimate; signed hours and percentage; zero explicit>
VARIANCE_REASON: <evidence-based; NONE only for zero variance>
```

The issued estimate is never retrospectively rewritten. Missing actual hours or variance makes the final report incomplete and merge readiness FAIL.

Variance is calculated as:

```text
VARIANCE_HOURS = ACTUAL_EFFECTIVE_HOURS - ESTIMATED_EFFECTIVE_HOURS
VARIANCE_PERCENT = VARIANCE_HOURS / ESTIMATED_EFFECTIVE_HOURS * 100
```

Positive variance means over estimate; negative variance means under estimate; zero variance must be reported explicitly. Every non-zero variance requires an evidence-based explanation.

## Downstream Activity Inclusion

`OWNER_REVIEW`, `MERGE`, `MIGRATION`, `UAT` and `DEPLOYMENT` are mandatory INCLUDED/EXCLUDED fields. `INCLUDED` means estimated execution work is in scope; it does not override any hard Owner gate. Within an Owner-confirmed Work Package/Roadmap, routine `MERGE` is continuously authorized by `BYL_ERP_CONTINUOUS_OWNER_AUTHORIZATION_V1`; Owner UAT, irreversible real-data execution, Owner-only credential actions, material scope changes, and Production formal release/deployment remain hard gates.

`MERGE` may be marked `INCLUDED` when the Work Package is Owner-confirmed, bounded, and has acceptance criteria plus a validation contract; no additional per-merge Owner prompt is required. `MIGRATION`, `UAT` and `DEPLOYMENT` may be marked `INCLUDED` only when their applicable hard-gate authorization and validation contract are present. Authoring a reversible migration file is not the same as executing an irreversible real-data migration.

Owner waiting time is always excluded from effective hours, including when preparation for Owner review is included. Manual stakeholder UAT time and passive deployment observation are excluded unless the package defines measurable authorized Codex work for them.

No downstream hard-gate activity is inferred from another: Merge does not imply irreversible real-data Migration execution; Migration does not imply Owner UAT; UAT does not imply Production Deployment. Routine merge authority instead derives from the active Owner-confirmed scope plus `BYL_ERP_CONTINUOUS_OWNER_AUTHORIZATION_V1`.

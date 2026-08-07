---
type: work-package
project: BYL ERP
work_package_id: ERP-GOV-PARALLEL-001
standard_id: LARGE-PROJECT-EXEC-001
standard_version: 1.1
supplement: PARALLEL-WORK-EXEC-001 v1.1 proposed in this package
status: REMEDIATION COMPLETE / PENDING INDEPENDENT RE-REVIEW
---

# ERP-GOV-PARALLEL-001 — Parallel Work Package / Worktree / Dependency Governance

## WORK PACKAGE

```text
ID: ERP-GOV-PARALLEL-001
TITLE: Parallel Work Package / Worktree / Dependency Governance
STATUS: REMEDIATION COMPLETE / PENDING INDEPENDENT RE-REVIEW
OWNER_AUTHORIZATION: Owner explicitly requested PR #79 remediation into the final parallel model on 2026-08-07.
STANDARD_ID: LARGE-PROJECT-EXEC-001
STANDARD_VERSION: 1.1
```

## REPOSITORY

```text
REMOTE: git@github.com:beyourlovercom/erpbeyourlovercom.git
LOCAL_WORKTREE: connector-backed PR branch; no claim of local /Users/a worktree execution
TARGET_BRANCH: governance/parallel-work-package-worktree-dependency
DELIVERY_PR: #79
```

## BASELINE

```text
BASE_BRANCH: main
ORIGINAL_PR_BASE_SHA: f5d028ae45620b7b476aca97c363197b265cfa24
BASE_SHA: ed77cbdc8c52f57aa72a512a0733167670027f0f
BASE_TRANSITION_REASON: PR #78 merged after PR #79 was opened; #79 was rebuilt on current main without carrying stale base content forward.
BASE_SYNC_EVIDENCE: compare current main → PR branch = ahead_by 1 / behind_by 0 after synchronization
BASE_VERIFIED_AT: 2026-08-07
DEPENDENCIES: NONE
DEPENDENCY_TYPE: NONE
DEPENDENCY_STATUS: NONE
PARALLEL_SAFE: YES — governance/docs-only package; no application runtime dependency
FOUNDATION_CHANGE_REQUIRED: NO
```

## AUTHORIZED ACTIONS

- Remediate PR #79 so BYL ERP formally supports multiple concurrent Work Packages, PRs, OpenCode sessions and dedicated worktrees.
- Preserve the four Owner-requested governance rules.
- Add a forward-effective transition from the historical Work Package PR → Implementation PR → Closure PR topology to one new Work Package → one branch → one delivery PR.
- Grandfather historical merged packages and legacy active packages with an issued multi-PR contract.
- Define the prospective Draft delivery PR lifecycle, dependency declaration, shared-foundation extraction, merge behavior and post-merge closure behavior.
- Update agent/entrypoint/repository governance references required to make the standard discoverable and enforceable.
- Add this bounded Work Package, evidence log, Decision Register entry and Change Log entry.
- Synchronize the PR to current main if an intervening merge occurs, preserving unrelated merged work.
- Keep PR #79 Draft until remediation is complete and independently re-reviewed.

## PROHIBITED ACTIONS

- No application implementation.
- No schema or Migration file change.
- No Migration execution.
- No database mutation.
- No dependency or lock-file change.
- No menu/product-scope change.
- No Remote Dev, Staging or Production access.
- No UAT execution.
- No deployment.
- No merge of PR #79 without a separate Owner merge authorization.
- No retroactive invalidation of historical merged Work Packages.

## SCOPE

Authorized repository files:

```text
AGENTS.md
CODEX_ENTRYPOINT.md
AI/PARALLEL_WORK_EXECUTION_STANDARD.md
AI/REPOSITORY_MANIFEST.md
docs/10_Planning_Roadmap/10_DECISION_REGISTER.md
docs/10_Planning_Roadmap/11_CHANGE_LOG.md
docs/60_Codex_Operations/work-packages/ERP-GOV-PARALLEL-001.md
docs/60_Codex_Operations/logs/2026-08-07_ERP-GOV-PARALLEL-001.md
```

## ACCEPTANCE CRITERIA

1. The standard explicitly states that multiple active Work Packages, PRs, OpenCode sessions and dedicated worktrees are allowed.
2. The canonical model remains Project → Work Package → Branch → Delivery PR, with one active mutating agent per dedicated worktree.
3. All four mandatory Owner-requested rules are present without semantic weakening.
4. `ONE_WORK_PACKAGE_ONE_BRANCH_ONE_PR` is forward-effective for newly activated packages, while historical merged packages remain valid and legacy active packages may finish under an issued contract.
5. Newly activated packages use one Draft delivery PR for Work Package contract, implementation, validation, remediation and final pre-merge evidence.
6. A second post-merge Closure PR under the same new Work Package identity is prohibited; genuine post-merge repository mutation becomes a separately identified Work Package.
7. Parallel PR dependency declaration and shared-foundation extraction gates remain mandatory.
8. Independent page repairs are explicitly documented as a valid parallel OpenCode use case when the preflight gate passes.
9. The Owner decision is recorded in the canonical Decision Register and Change Log.
10. Intervening main changes are preserved and the PR is synchronized when required; no unrelated merged work is overwritten.
11. PR #79 remains Draft pending independent remediation re-review; merge remains unauthorized.

## VALIDATION

```text
REQUIRED_CHECKS:
- inspect PR #79 changed files and diff
- confirm all four rule identifiers exist
- confirm MULTIPLE_ACTIVE_WORK_PACKAGES / PRS / OPENCODE_SESSIONS are ALLOWED
- confirm Version Transition exists
- confirm prospective single Delivery PR lifecycle exists
- confirm post-merge closure rule exists
- confirm Decision Register + Change Log + Work Package + Work Log evidence exists
- confirm application/schema/dependency files are untouched by PR #79
- confirm current main is an ancestor of the PR branch after synchronization
- confirm PR remains Draft before independent re-review
EXPECTED_EVIDENCE: GitHub exact-head diff, changed-file set, compare and PR metadata
HARD_GATES: no merge; no app/migration/deployment mutation
```

## COMMIT / PR RULES

```text
COMMIT_MESSAGE: docs(governance): finalize parallel execution model
PR_TITLE: docs(governance): add parallel Work Package / Worktree / dependency rules
PR_BASE: main
PR_DRAFT_STATE: DRAFT THROUGH REMEDIATION; may become Ready only after independent remediation re-review PASS
MERGE_AUTHORIZED: NO
```

## FINAL REPORT FORMAT

```text
REQUIRED_FIELDS: exact Head SHA from PR metadata; changed files; review result; four-rule check; transition check; delivery lifecycle check; post-merge closure check; current-main ancestry check; prohibited-scope check; actual effective hours and variance
ALLOWED_FINAL_VERDICT: PASS / CHANGES_REQUIRED / BLOCKED
```

## PROJECT POSITION

```text
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate → MVP1 Manual Fulfillment / Tencent Docs Replacement → MVP1.1 WooCommerce Inbound → MVP1.2 Single Tracking Outbound → MVP1.3 Sync Orchestration → Customer / Lead Pilot → Product / Media → WordPress Product and Image Sync → Content / SEO → Posts / Pages → GA4 / GSC / Cloudflare → Google Docs Publishing → Full Order Workspace → Logistics OS → KOL OS and Social Providers → Inventory and Procurement → Accounting / ROI Expansion → Shopify and Additional Integrations → Dev UAT and Production Hardening
CURRENT_TRACK_PHASE_MAP: Architecture Migration COMPLETE / PASS → MVP1 Manual Fulfillment ACTIVE; ERP-GOV-PARALLEL-001 is an authorized governance side-track within current MVP1 development
COMPLETED_PHASES: Architecture Migration / Global Architecture Gate; MVP1 Foundation; MVP1 Order Core; MVP1 Fulfillment per current repository evidence
CURRENT_PHASE: MVP1 Manual Fulfillment / governance hardening side-track
CURRENT_WORK_PACKAGE: ERP-GOV-PARALLEL-001
PACKAGE_STATUS: REMEDIATION COMPLETE / PENDING INDEPENDENT RE-REVIEW
WAITING_PHASES: independent re-review of PR #79; Owner merge authorization if PASS
BASELINE: main@ed77cbdc8c52f57aa72a512a0733167670027f0f; original PR base f5d028ae45620b7b476aca97c363197b265cfa24 retained as history
GITHUB_STATE: PR #79 OPEN / DRAFT; synchronized to current main; compare shows ahead_by 1 / behind_by 0 before final evidence-only Work Package update
SOURCE_CONFLICTS: Historical multi-PR Work Package topology conflict resolved prospectively by explicit grandfathering; intervening PR #78 main movement preserved by branch rebuild on current main
GLOBAL_GATE: PASS per current repository context; this governance package does not change the architecture gate
PRODUCTION_DEPLOYMENT_STATUS: NO WITH EVIDENCE — governance/docs-only PR; Production untouched
NEXT_DEPENDENCY: independent remediation re-review of PR #79 exact Head
EVIDENCE_AS_OF: 2026-08-07
```

## ROADMAP HOURS

```text
CURRENT_PACKAGE_ESTIMATE: 1.5h planning value; range 1.0–2.5h
KNOWN_CURRENT_TRACK_REMAINING: UNKNOWN WITH EVIDENCE — this governance package does not recalculate product-track remaining work
ARCHITECTURE_GATE_REMAINING: 0h / COMPLETE per current repository context
MVP1_TOTAL_ESTIMATE: 122–190h total estimate from document 13
MVP1_PLANNING_BASELINE: 166h total planning value from document 13
MVP1_MAXIMUM_ENVELOPE: 192h maximum total planning envelope from document 13
MVP1_REMAINING: UNKNOWN WITH EVIDENCE — accepted complete actual-hours and overlap ledger remains incomplete
POST_MVP_KNOWN_INCREMENT: approximately 68–120h beyond MVP1 per document 13
LONG_TERM_CUMULATIVE_RANGE: 235–402h architecture foundation; 603–1,018h first highly automated operations MVP; 1,059–1,802h product/SEO growth layer; 1,595–2,714h full core ERP; 1,819–3,146h all expansion — cumulative horizons, never summed
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE — cumulative horizons and incomplete actual ledger prevent safe subtraction
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE — project-wide ledger remains incomplete
ESTIMATE_CONFIDENCE: HIGH for this bounded docs-only package; repository-wide remaining values retain document-13 confidence classifications
ESTIMATE_AS_OF: 2026-08-07
ESTIMATE_METHOD: bounded governance-file scope; no application/test runtime work; no subtraction from project totals
```

## ESTIMATED CODEX HOURS

```text
PREFLIGHT_AND_SCOPE: 0.2–0.5h; planning value 0.3h
CORE_EXECUTION: 0.5–1.2h; planning value 0.7h
TEST_AND_VALIDATION: 0.2–0.5h; planning value 0.3h
REMEDIATION_BUFFER: 0.1–0.3h; planning value 0.2h
ESTIMATED_EFFECTIVE_HOURS: 1.5h
ESTIMATE_RANGE: 1.0–2.5h
ESTIMATE_BASIS: eight-file maximum governance/docs scope; exact-head diff review; no runtime implementation, Migration, UAT or deployment
OWNER_REVIEW: INCLUDED
MERGE: EXCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
```

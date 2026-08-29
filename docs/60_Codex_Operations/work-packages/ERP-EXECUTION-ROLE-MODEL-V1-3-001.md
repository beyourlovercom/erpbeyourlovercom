---
type: work-package
project: BYL ERP
work_package_id: ERP-EXECUTION-ROLE-MODEL-V1-3-001
status: LOCAL_IMPLEMENTATION_COMPLETE / DRAFT_PR_123_OPEN
execution_provider: GPT_WEB
verification_tier: DAILY
baseline: origin/main@8a887e0e743c06fe0e5dd8991692e204dcfe9307
---

# Execution Role Model V1.3

## Owner authorization
Owner authorized the v1.3 role-model upgrade in chat on 2026-08-29.

## Purpose
Enable US Mac Codex CLI to implement frozen, deterministic low/medium-complexity Work Packages while retaining GPT/Owner design authority and reserving Claude Code for complex or high-ambiguity implementation.

China Mac is not part of the default development or verification chain.

## Frozen provider model
- OWNER: final product, UAT, merge and release authority.
- GPT_WEB: architecture, design, diagnosis, Work Package definition and bounded direct implementation.
- US_CODEX_CLI: frozen/deterministic low-to-medium complexity implementation and local verification.
- US_CLAUDE_CODE: complex/high-ambiguity/broad implementation, complex schema/refactors and external integrations.
- GITHUB: unique source of truth and synchronization path.
## Provider selection rules
Use provider complexity/ambiguity, not historical habit or file count alone.

GPT direct is preferred for narrow, established-root-cause remediation and exact follow-up work.

US Codex CLI is permitted when architecture/business behavior is already frozen and the implementation is deterministic enough that Codex does not need to invent architecture, business rules or replacement designs.

US Claude Code remains preferred for complex external integrations, materially ambiguous schema/data-model design, large cross-module refactors, concurrency/state-machine work and other high-complexity implementation.

## Codex stop contract
US Codex must stop and return raw evidence when the frozen Work Package becomes ambiguous, requires architecture/design changes, needs scope expansion, or encounters a failure whose remediation is not already explicit.

GPT Web diagnoses and issues the next bounded instruction. Codex does not self-expand scope.

## Collision rule
`ONE_ACTIVE_IMPLEMENTATION_PROVIDER_PER_WORKTREE` applies equally to GPT+DC, US Codex CLI and US Claude Code. No two providers may concurrently mutate the same worktree or tracked file.

## Git delivery
Commit, push and Draft PR actions remain controlled by the active Work Package. Merge and deployment always require separate explicit Owner authorization.

## Acceptance criteria
- Execution Role Model is v1.3 and names GPT Web, US Codex CLI and US Claude Code as distinct implementation providers.
- US Codex implementation is limited to frozen deterministic low-to-medium complexity Work Packages.
- Claude remains the complex/high-ambiguity provider and is not selected merely because a task is new or multi-file.
- China Mac is not a default development/verification dependency.
- `ONE_ACTIVE_IMPLEMENTATION_PROVIDER_PER_WORKTREE` covers GPT+DC, US Codex and Claude.
- Git delivery/merge/deployment gates remain unchanged.
- Historical records are preserved rather than rewritten.

## Local validation evidence
- Realigned onto latest `origin/main@8a887e0e743c06fe0e5dd8991692e204dcfe9307` after main moved during implementation.
- New Global Data Grid V1.1 AGENTS rule from latest main is preserved.
- Current-governance version/role contract audit: PASS for all eight active entry/governance files.
- `git diff --check`: PASS.
- No application code, schema, dependency or runtime configuration changed.
- No commit, push, PR, merge or deployment executed.

## Git delivery record
- Owner authorized commit + push + Draft PR on 2026-08-29.
- Initial delivery commit: `9c2165de81d40f2013765be765a767d13f426870`.
- Draft PR: `#123` — `Governance: Execution Role Model V1.3`.
- PR base at creation: `main@8a887e0e743c06fe0e5dd8991692e204dcfe9307`.
- Merge remains a separate Owner gate.

PROJECT POSITION
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate → MVP1 Existing Operations → Inquiry M1 → Local Milestone UAT → Remote Milestone Release → WooCommerce Inbound → Tracking Outbound → Sync Orchestration → Product / Media → WordPress Sync → Content / SEO → Posts / Pages → GA4/GSC/Cloudflare → Google Docs Publishing → Full Order Workspace → Logistics OS → KOL OS / Social Providers → Inventory / Procurement → Accounting / ROI → Additional Integrations → Production Hardening
CURRENT_TRACK_PHASE_MAP: Execution Role Model v1.2 merged → V1.3 local governance implementation COMPLETE → Git delivery WAITING → Owner merge gate WAITING → V1.3 effective after merge
COMPLETED_PHASES: V1.3 design; dedicated worktree; latest-main realignment; current-governance synchronization; local contract audit
CURRENT_PHASE: Git delivery authorization gate
CURRENT_WORK_PACKAGE: ERP-EXECUTION-ROLE-MODEL-V1-3-001
PACKAGE_STATUS: LOCAL_IMPLEMENTATION_COMPLETE / GIT_DELIVERY_NOT_AUTHORIZED
WAITING_PHASES: commit/push/Draft PR authorization; Owner review; explicit merge authorization
BASELINE: origin/main@8a887e0e743c06fe0e5dd8991692e204dcfe9307
GITHUB_STATE: local governance branch only / no push / no PR
SOURCE_CONFLICTS: NONE WITH EVIDENCE after realignment; latest-main Global Data Grid V1.1 AGENTS rule preserved
GLOBAL_GATE: LOCAL_CONTRACT_PASS / MERGE_NOT_AUTHORIZED
PRODUCTION_DEPLOYMENT_STATUS: NO
NEXT_DEPENDENCY: Owner Git delivery authorization
EVIDENCE_AS_OF: 2026-08-29

ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 0.8h planning value / 0.5-1.2h range
KNOWN_CURRENT_TRACK_REMAINING: Git delivery + Owner review/merge only; implementation complete locally
ARCHITECTURE_GATE_REMAINING: NONE for this governance package
MVP1_TOTAL_ESTIMATE: UNKNOWN WITH EVIDENCE / this package does not rebaseline MVP1 estimates
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE / unchanged by this package
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE / unchanged by this package
MVP1_REMAINING: UNKNOWN WITH EVIDENCE / no safe subtraction performed
POST_MVP_KNOWN_INCREMENT: UNKNOWN WITH EVIDENCE / unchanged by this package
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE / unchanged by this package
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE / governance-only package does not re-estimate program
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE / no accepted Work Log ledger available
ESTIMATE_CONFIDENCE: HIGH for this bounded governance package
ESTIMATE_AS_OF: 2026-08-29
ESTIMATE_METHOD: bounded current-governance file inventory + consistency audit + latest-main realignment

ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 0.2h planning value / 0.1-0.3h
CORE_EXECUTION: 0.4h planning value / 0.2-0.6h
TEST_AND_VALIDATION: 0.1h planning value / 0.1-0.2h
REMEDIATION_BUFFER: 0.1h planning value / 0.1-0.1h
ESTIMATED_EFFECTIVE_HOURS: 0.8h
ESTIMATE_RANGE: 0.5-1.2h
ESTIMATE_BASIS: governance-only synchronized role/entrypoint/standard changes; no product/runtime implementation
OWNER_REVIEW: EXCLUDED
MERGE: EXCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED

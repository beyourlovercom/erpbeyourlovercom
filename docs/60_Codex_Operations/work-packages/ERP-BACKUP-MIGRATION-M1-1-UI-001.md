---
id: ERP-BACKUP-MIGRATION-M1-1-UI-001
title: ERP Backup & Migration M1.1 — UI and navigation
status: IMPLEMENTATION_COMPLETE / VERIFIED / GIT_DELIVERY_AUTHORIZED
branch: feature/erp-backup-migration-m1-1-ui-001
base: e9912acbd49d27e47641f085e8877d4c508e14b4
target_base: main@e9912acbd49d27e47641f085e8877d4c508e14b4
EXECUTION_PROVIDER: US_CODEX_CLI
VERIFICATION_TIER: HIGH_RISK
implementation_authorization: YES
git_delivery: OWNER_GRANTED_2026-09-01
merge: NOT_AUTHORIZED
migration_execution: NOT_AUTHORIZED
production_deployment: NOT_AUTHORIZED
---

# Scope

Deliver the read-only Backup & Migration workspace at `/settings/backup`, its authorized Settings child-menu entry, route/permission coverage, focused Vue coverage, and synchronized menu/architecture evidence.

The pre-engine page is truthful: no backup records are fabricated and no operational POST, DELETE, backup engine, persistence, job, GitHub transport, restore execution, or scheduler behavior is introduced.

## Menu baseline synchronization disclosure

M1.1 menu baseline synchronization includes reconciliation of pre-existing main/runtime drift for these already merged Owner-approved items: `kol-approval-kols`, `kol-approval-collaborations`, `kol-approval-payouts`, and `products-weight-evidence`. These four items are governance baseline reconciliation, not new feature implementation in this package. M1.1 adds only `settings-backup` as new Backup scope.

## Acceptance and prohibitions

- Display the approved Chinese action cards, history, storage usage, policy, local restore, and GitHub restore sections.
- Keep all pre-engine operations unavailable and explain the empty state.
- Gate the canonical route with `backup.view`; retain the singular legacy redirect.
- Do not modify backup engine persistence/jobs/services, GitHub transport, restore execution, scheduler, dependency manifests, or run migrations.
- Menu runtime configuration and approved baseline move together, with focused menu and architecture assertions refreshed.

## Verified evidence

- Backend focused/regression gate: `33/33` tests, `1735` assertions PASS.
- Frontend focused Vitest: `2/2` tests PASS.
- `vue-tsc --noEmit`: PASS.
- `git diff --check`: PASS.
- Secret-pattern review: no findings.
- Dependency manifests/locks: unchanged.
- Base drift at Git Delivery gate: none; `origin/main` remained `e9912acbd49d27e47641f085e8877d4c508e14b4`.
- No migration execution, deployment, restore, or Production mutation performed.

## Validation contract

Focused PHPUnit Backup route/permission and menu tests, focused Vitest page test, architecture/module-provider checks, `git diff --check`, and secret-pattern review. Local ignored dependency metadata may be repaired; no Git delivery or deployment is authorized.

PROJECT POSITION
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate [COMPLETE] → Backup & Migration M1.0 Contract Foundation → Backup & Migration M1.1 UI → M1.2 Backup Engine → M1.3 GitHub Release → M1.4 Restore → M1.5 Scheduled Backup → Integration UAT / DR UAT
CURRENT_TRACK_PHASE_MAP: M1.0 Contract Foundation [merged prerequisite] → M1.1 UI [current] → M1.2 Backup Engine → M1.3 GitHub Release → M1.4 Restore → M1.5 Scheduled → Integration UAT / DR UAT
COMPLETED_PHASES: M1.0 contract foundation
CURRENT_PHASE: M1.1 UI
CURRENT_WORK_PACKAGE: ERP-BACKUP-MIGRATION-M1-1-UI-001
PACKAGE_STATUS: IMPLEMENTATION_AUTHORIZED / IN_PROGRESS
WAITING_PHASES: M1.2 Backup Engine, M1.3 GitHub Release, M1.4 Restore, M1.5 Scheduled, Integration UAT / DR UAT
BASELINE: e9912acbd49d27e47641f085e8877d4c508e14b4
GITHUB_STATE: local branch only; no commit, push or PR
SOURCE_CONFLICTS: NONE WITH EVIDENCE; current task and M1.0 contract freeze this scope
GLOBAL_GATE: menu baseline and high-risk authorization verification active
PRODUCTION_DEPLOYMENT_STATUS: NO; no production mutation executed
NEXT_DEPENDENCY: Owner-authorized Git delivery and merge, then M1.2 engine package
EVIDENCE_AS_OF: 2026-09-01 America/Los_Angeles

ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 4–7h planning range
KNOWN_CURRENT_TRACK_REMAINING: M1.2–M1.5 and UAT; estimate not recalculated here
ARCHITECTURE_GATE_REMAINING: 0h / complete
MVP1_TOTAL_ESTIMATE: UNKNOWN WITH EVIDENCE; outside package scope
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE; outside package scope
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE; outside package scope
MVP1_REMAINING: UNKNOWN WITH EVIDENCE; outside package scope
POST_MVP_KNOWN_INCREMENT: UNKNOWN WITH EVIDENCE
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE; outside package scope
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE; outside package scope
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE; no accepted Work Log
ESTIMATE_CONFIDENCE: MEDIUM-HIGH
ESTIMATE_AS_OF: 2026-09-01
ESTIMATE_METHOD: bounded UI, route, menu and focused verification estimate

ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 0.5–1h
CORE_EXECUTION: 2–4h
TEST_AND_VALIDATION: 1–1.5h
REMEDIATION_BUFFER: 0.5h
ESTIMATED_EFFECTIVE_HOURS: 4–7h
ESTIMATE_RANGE: 4–7h
ESTIMATE_BASIS: frozen M1.0 contract and deterministic read-only UI scope
OWNER_REVIEW: EXCLUDED
MERGE: EXCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED

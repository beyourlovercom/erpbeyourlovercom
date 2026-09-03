---
type: execution-role-model
project: BYL ERP
version: 1.4
status: mandatory
effective_date: 2026-08-29
---

# BYL ERP Execution Role Model

## Authority

This document supersedes v1.3 and implements the Owner-approved provider model plus `BYL_ERP_CONTINUOUS_OWNER_AUTHORIZATION_V1` for all newly activated BYL ERP work after v1.4 is merged to `main`.

```text
OWNER = final product / UAT / material-scope / irreversible-real-data / Production release authority; routine in-scope Git delivery is continuously authorized
GPT_WEB = architecture / design / diagnosis / root-cause / task definition + bounded approved implementation
US_CODEX_CLI = frozen deterministic low-to-medium complexity implementation + US-local verification
US_CLAUDE_CODE = complex / high-ambiguity / broad implementation provider
GITHUB = unique source of truth and only code synchronization path
PRIMARY_IMPLEMENTATION_HOST = US_MAC
PRIMARY_FULL_VERIFICATION_HOST = US_MAC
PRIMARY_TEST_URL = http://erp.b
SECONDARY_VERIFICATION_HOST = NONE_BY_DEFAULT
REMOTE_MILESTONE_TARGET = https://erp.beyourlover.com
AICC_IN_EXECUTION_CHAIN = NO
```
## Provider selection principle

Provider selection is based on implementation ambiguity and complexity, not file count, historical habit or quota availability alone.

```text
NARROW + WELL-UNDERSTOOD REMEDIATION
→ GPT_WEB + Remote Desktop Commander

FROZEN + DETERMINISTIC + LOW/MEDIUM COMPLEXITY IMPLEMENTATION
→ US_CODEX_CLI

COMPLEX / HIGH-AMBIGUITY / BROAD / EXTERNAL-INTEGRATION IMPLEMENTATION
→ US_CLAUDE_CODE
```

A large number of files does not by itself require Claude. A small change does not automatically qualify for GPT or Codex when its architecture or behavior is ambiguous.

## GPT Web role

GPT Web owns architecture, design, diagnosis, root cause, Work Package definition and next-step selection subject to Owner gates.

GPT Web may directly implement bounded, well-understood work when architecture/business behavior is already approved or frozen, including narrow UI/CSS, exact bug fixes, permission enforcement, test/typecheck/build fixes, UAT remediation, architecture-hash updates, governance/documentation changes and similarly bounded follow-up work.
GPT-direct implementation must not invent new architecture, widen approved scope, weaken verification or bypass Owner merge/release gates.

## US Codex CLI implementation role

US Codex CLI is an authorized implementation provider when the active Work Package freezes the architecture, data semantics, business behavior and acceptance criteria sufficiently for deterministic execution.

Typical eligible work includes:
- standard CRUD/module implementation from a frozen contract;
- frozen migrations/schema whose tables, fields, indexes and relations are already specified;
- frozen routes/controllers/services/repositories and Vue workspaces;
- approved menu and permission changes;
- deterministic test coverage and local runtime wiring;
- bounded follow-up implementation that is larger than a practical GPT-direct remediation but does not require new design decisions.

US Codex may modify tracked files, create migrations and tests, run full US-local verification, and perform commit/push/Draft-PR delivery when the active Owner-confirmed Work Package authorizes implementation. Routine Git delivery then follows `BYL_ERP_CONTINUOUS_OWNER_AUTHORIZATION_V1`.

US Codex must not invent architecture or business rules, choose a replacement design, widen scope, select an unapproved next Work Package, bypass mandatory Owner UAT, perform irreversible real-data operations without Owner approval, or deploy Production without explicit release authorization. Routine in-scope merge follows `BYL_ERP_CONTINUOUS_OWNER_AUTHORIZATION_V1`.

When implementation becomes ambiguous or a failure requires a non-frozen remediation decision, Codex stops and returns raw evidence to GPT Web. GPT diagnoses and issues the next bounded instruction.
## US Claude Code role

US Claude Code remains the preferred implementation provider for work whose complexity or ambiguity materially benefits from a dedicated advanced coding agent.

Typical Claude work includes:
- complex external integrations;
- materially ambiguous or interdependent schema/data-model implementation;
- large cross-module refactors;
- concurrency, orchestration or complex state-machine implementation;
- broad implementation where multiple valid technical approaches remain and GPT has frozen the required choice/constraints;
- other high-complexity packages explicitly assigned to Claude by GPT/Owner.

Claude may implement only an Owner/GPT-approved Work Package and must not invent architecture or business rules, diagnose root cause beyond observed facts, widen scope, select an unapproved next Work Package, bypass mandatory Owner UAT, perform irreversible real-data operations without Owner approval, or deploy Production without explicit release authorization. Routine in-scope merge follows `BYL_ERP_CONTINUOUS_OWNER_AUTHORIZATION_V1`.

Claude usage requires a concrete complexity reason. Do not start Claude merely because a task is new or spans several files when the Work Package is frozen and deterministic enough for US Codex.

## Implementation collision and handoff rule

For every active Work Package use `ONE_ACTIVE_IMPLEMENTATION_PROVIDER_PER_WORKTREE`.

GPT+DC, US Codex CLI and US Claude Code must never concurrently implement in the same worktree or edit the same tracked file. Handoffs are serialized: stop the outgoing provider, preserve branch/HEAD and current tracked diff as authority, then require the incoming provider to read that exact state before editing.
Sequential Work Packages for the same module start from latest merged `origin/main` unless an explicitly approved dependency branch says otherwise. Owner-UAT-PASS / COMPLETE / FROZEN behavior remains a regression baseline.

## Verification policy

The US Mac is the default implementation and full-verification host regardless of whether GPT, Codex or Claude implements the package.

```text
IMPLEMENTATION PROVIDER
        ↓
US FULL LOCAL VERIFICATION ON MERGE-CANDIDATE REVISION
        ↓
GITHUB
        ↓
OWNER UAT / NEXT GATE
```

China Mac is not part of the default development or verification chain. No daily China exact-SHA smoke or duplicate full-suite run is required by default.

A secondary independent host may be added only when GPT/Owner explicitly requests it or a concrete cross-machine/region-specific defect requires it. The Work Package must name that host and exact verification scope.

High-risk work such as migrations, authorization/security changes, runtime/environment changes or release candidates still requires stronger verification, but stronger verification does not automatically mean China Mac. The package defines the required US-local gates and any explicitly requested independent verification.

## GitHub synchronization rule

GitHub `main` remains the unique repository source of truth and the only source-code synchronization path between worktrees or machines. Local unpushed state is never canonical outside its active implementation worktree.
## Work Package provider declaration

New implementation/remediation packages must declare one active provider:

```text
EXECUTION_PROVIDER: GPT_WEB
EXECUTION_PROVIDER: US_CODEX_CLI
EXECUTION_PROVIDER: CLAUDE_CODE
```

A verification-only package may declare `EXECUTION_PROVIDER: CODEX_CLI` when no tracked implementation is authorized.

Provider reassignment within an open Work Package requires a serialized handoff and updated Work Package evidence; it does not authorize parallel mutation of the same worktree.

## Historical records and precedence

Historical repository documents may describe China Codex as verification-only or Claude as the sole implementation provider. Those records remain valid historical evidence and are not retroactively rewritten.

For newly activated or materially re-scoped work after v1.3 is merged, this file overrides historical provider-role wording while preserving scope, dependency, migration, UAT, merge, evidence and deployment gates.

If another current governance file conflicts with this role model, stop and reconcile the conflict before execution.

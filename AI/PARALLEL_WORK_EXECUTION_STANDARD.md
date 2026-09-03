---
type: repository-governance-standard
project: BYL ERP
standard_id: PARALLEL-WORK-EXEC-001
version: 1.4
status: MANDATORY_SUPPLEMENT
parent_standard: LARGE-PROJECT-EXEC-001
effective_when: MERGED_TO_MAIN
---

# Parallel Work Package / Worktree / Dependency Governance

## Standard Identification

```text
Standard ID: PARALLEL-WORK-EXEC-001
Version: 1.4
Parent Standard: LARGE-PROJECT-EXEC-001
Status: MANDATORY_SUPPLEMENT
Effective: when the governance PR containing this version is merged to main
```

## Purpose

This standard enables multiple BYL ERP Work Packages, approved implementation-provider sessions, branches, worktrees and Pull Requests to run concurrently without sharing mutable execution state or hiding dependencies.

Parallel execution is explicitly allowed. This standard does not impose a one-PR-at-a-time project limit.

```text
MULTIPLE_ACTIVE_WORK_PACKAGES = ALLOWED
MULTIPLE_ACTIVE_PRS = ALLOWED
MULTIPLE_IMPLEMENTATION_PROVIDER_SESSIONS = ALLOWED
MULTIPLE_DEDICATED_WORKTREES = ALLOWED
```

Parallel execution never weakens hard Owner gates, scope boundaries, validation requirements, continuous-delivery eligibility, Migration authorization, UAT authorization, deployment authorization, or repository-truth rules.

## Execution Role Boundary

`AI/EXECUTION_ROLE_MODEL.md` governs provider roles. Parallel repository mutation may be performed by GPT+DC, US Codex CLI or US Claude Code when the active Work Package authorizes that provider. Verification-only Codex sessions do not receive implementation authority.

## Applicability

This repository-local standard governs all BYL ERP formal work that uses, or may use, more than one active Work Package, branch, Pull Request, worktree, or agent execution stream at the same time.

It is a mandatory supplement to `AI/LARGE_PROJECT_EXECUTION_STANDARD.md`. A formal task is not compliant when it violates this supplement, even if it otherwise satisfies `LARGE-PROJECT-EXEC-001`.

## Version Transition

This version is forward-effective when its governance PR merges to `main`.

Historical merged packages are not retroactively invalidated.

```text
HISTORICAL_MERGED_PACKAGES = GRANDFATHERED
LEGACY_ACTIVE_PACKAGES_WITH_ISSUED_CONTRACT = MAY_FINISH_UNDER_ISSUED_CONTRACT
NEWLY_ACTIVATED_PACKAGES_AFTER_EFFECTIVE_MERGE = MUST_USE_THIS_STANDARD
```

A legacy Work Package already activated under an issued contract that explicitly uses separate Work Package / Implementation / Closure PRs may complete under that issued contract. Its historical PR topology remains valid evidence.

After this standard becomes effective, a newly activated Work Package must not create separate Work Package, Implementation and Closure PRs for the same Work Package identity. It uses one delivery branch and one delivery PR for that Work Package lifecycle.

If an old package is materially re-scoped after this standard becomes effective, the new scope must become a new Work Package and follow this standard rather than extending the legacy multi-PR topology.

## Canonical Project / Work Package / Branch / PR Model

The governance identity model is:

```text
PROJECT
│
├── Work Package A
│   └── Branch A
│       └── Delivery PR A
│
├── Work Package B
│   └── Branch B
│       └── Delivery PR B
│
└── Work Package C
    └── Branch C
        └── Delivery PR C
```

A Git worktree is the execution-isolation boundary for an active agent. It does not replace the Work Package / Branch / PR identity model.

For active implementation, the expected binding is:

```text
ONE ACTIVE IMPLEMENTATION PROVIDER SESSION
        ↕
ONE DEDICATED WORKTREE
        ↕
ONE WORK PACKAGE
        ↕
ONE BRANCH
        ↕
ONE DELIVERY PR
```

The project may have many such bindings active at the same time when the Parallelization Preflight Gate passes.

## Prospective Work Package Lifecycle

For every newly activated Work Package after this standard becomes effective, use one Draft delivery PR for the entire pre-merge lifecycle:

```text
Work Package defined
        ↓
Dedicated branch + dedicated worktree
        ↓
Draft Delivery PR opened
        ↓
Work Package contract / scope evidence committed
        ↓
Owner implementation authorization recorded when required
        ↓
Implementation on the same branch / same PR
        ↓
Tests + validation + remediation on the same branch / same PR
        ↓
Final report / merge-readiness evidence committed to the same PR
        ↓
Ready for independent review
        ↓
Independent review PASS
        ↓
Continuous delivery gate satisfied under `BYL_ERP_CONTINUOUS_OWNER_AUTHORIZATION_V1`
        ↓
Merge to main
```

A Draft PR may therefore exist before implementation begins. Draft state is the container for the Work Package contract and later implementation evidence; opening the Draft PR does not itself authorize implementation. Merge eligibility derives from the Owner-confirmed scope, required checks, applicable Owner UAT, and `BYL_ERP_CONTINUOUS_OWNER_AUTHORIZATION_V1`.

Do not create a second Implementation PR or a third Closure PR for the same newly activated Work Package identity.

## Mandatory Governance Rules

### 1. ONE_WORK_PACKAGE_ONE_BRANCH_ONE_PR

For newly activated Work Packages after this standard becomes effective, each bounded Work Package maps to exactly one delivery branch and one delivery Pull Request before merge.

Required behavior:

- unrelated Work Packages must not share a branch or delivery PR;
- a branch must not accumulate opportunistic work from another Work Package;
- the Work Package contract, implementation, validation, remediation and final pre-merge evidence normally remain in the same delivery PR;
- remediation for the same pre-merge Work Package remains in the same branch and PR;
- a successor, split, post-merge remediation, or materially expanded scope requires an explicit new Work Package identity and its own branch / PR;
- unrelated governance, architecture, product, or cleanup changes must be separated;
- historical merged packages and legacy active packages explicitly grandfathered by Version Transition are not violations.

Violation status:

```text
WORK_PACKAGE_BRANCH_PR_BOUNDARY = FAIL
```

### 2. ONE_ACTIVE_AGENT_ONE_WORKTREE

Every simultaneously active implementation provider session (GPT+DC, US Codex CLI or US Claude Code) must operate in its own dedicated Git worktree bound to its assigned Work Package and branch.

Required behavior:

- two active agents must not mutate the same worktree;
- one agent must not switch its active worktree between unrelated Work Packages during the same execution package;
- the primary `main` worktree should remain a coordination / verification location unless a separately authorized Work Package explicitly uses it;
- worktree path, branch, Work Package ID, and current HEAD must be reported in execution evidence when parallel work is active;
- an abandoned or completed worktree must not be silently reused for a different active package without rebinding and validating branch / HEAD / cleanliness.

Violation status:

```text
ACTIVE_AGENT_WORKTREE_ISOLATION = FAIL
```

### 3. PARALLEL_PR_MUST_NOT_HAVE_UNDECLARED_DEPENDENCIES

Every Work Package participating in parallel execution must explicitly declare its dependencies and base assumptions before implementation begins.

Each parallel Work Package must state:

```text
BASELINE:
DEPENDENCIES:
DEPENDENCY_TYPE: NONE | MERGED_PR | STACKED_PR | EXTERNAL
DEPENDENCY_STATUS:
PARALLEL_SAFE: YES | NO
```

Required behavior:

- no PR may silently depend on code that exists only in another unmerged PR;
- when no dependency exists, declare `DEPENDENCIES: NONE` and provide the verified baseline;
- when a dependency exists on a merged prerequisite, identify the prerequisite PR / merge commit;
- when Owner explicitly authorizes stacked PRs, identify the parent PR / branch and merge order;
- when a prerequisite is not merged and stacked execution is not explicitly authorized, the dependent Work Package remains waiting;
- a change to a dependency after implementation begins must be recorded and the affected package revalidated.

Violation status:

```text
PARALLEL_DEPENDENCY_DECLARATION = FAIL
```

### 4. COMMON_FOUNDATION_CHANGES_MUST_BE_EXTRACTED_BEFORE_PAGE-LEVEL_PARALLELIZATION

Shared foundation changes required by multiple page-level or module-level Work Packages must be extracted into a separate foundation Work Package before those dependent packages are parallelized.

Foundation scope includes, when shared by multiple downstream packages:

- application shell or shared layout;
- navigation framework;
- shared Vue components;
- design tokens or global styles;
- shared TypeScript contracts;
- common API contracts;
- shared routes or route conventions;
- shared permissions / authorization primitives;
- schema or Migration primitives;
- shared test infrastructure;
- other cross-package code whose duplication would create competing implementations.

Default execution order:

```text
Foundation Work Package
        ↓
Foundation Branch
        ↓
Foundation Delivery PR
        ↓
Continuously authorized merge after required checks/gates
        ↓
Updated canonical main baseline
        ↓
Page / Module Work Package A ── Delivery PR A
Page / Module Work Package B ── Delivery PR B
Page / Module Work Package C ── Delivery PR C
```

Page-level PRs must not duplicate, fork, or independently recreate the same foundation change in order to bypass this rule.

Exception: stacked dependency execution is allowed only when explicitly authorized by Owner and declared under Rule 3.

Violation status:

```text
COMMON_FOUNDATION_EXTRACTION_GATE = FAIL
```

## Parallelization Preflight Gate

Before starting two or more active implementation streams, record for each candidate Work Package:

```text
WORK_PACKAGE_ID:
BRANCH:
WORKTREE:
AGENT_SESSION:
BASELINE:
EXPECTED_FILE_SCOPE:
DEPENDENCIES:
DEPENDENCY_TYPE:
DEPENDENCY_STATUS:
PARALLEL_SAFE:
FOUNDATION_CHANGE_REQUIRED: YES | NO
```

Parallel implementation may start only when:

- every package has an independent bounded scope;
- every active agent has a dedicated worktree;
- dependencies are declared;
- required common foundation work has already been extracted or explicitly authorized as a stacked dependency;
- expected file overlap does not create an undeclared coupling.

If these conditions cannot be demonstrated:

```text
PARALLEL_EXECUTION_GATE = FAIL
EXECUTION = SERIALIZE_OR_REPLAN
```

There is no repository-wide rule that only one PR may be active. The gate decides whether a particular set of Work Packages is safe to execute concurrently.

## Page-Level Parallelization Example

Independent page repairs are a valid parallel use case:

```text
Codex A  → Worktree A → Dashboard Work Package → Branch A → PR A
Claude B → Worktree B → Orders Work Package    → Branch B → PR B
GPT+DC C → Worktree C → Settings Work Package  → Branch C → PR C
```

This is permitted when the packages have bounded file scopes, declared dependencies and no unresolved shared-foundation requirement.

If Dashboard, Orders and Settings all require the same unfinished `AppShell`, `Sidebar`, design-token or shared-component change, extract that common change first or explicitly declare an authorized stacked dependency.

## File-Overlap and Conflict Rule

File overlap between parallel PRs is a risk signal, not automatically a violation. The executor must determine whether the overlap is independent, generated, documentation-only, or evidence of a shared foundation dependency.

When the same shared implementation file is expected to be materially changed by multiple parallel Work Packages, default to `PARALLEL_SAFE: NO` and either:

1. extract the shared change into a foundation Work Package; or
2. serialize the dependent Work Packages; or
3. use an explicitly Owner-authorized stacked dependency.

## Main Baseline Movement During Parallel Work

A change to `main` does not by itself require every parallel branch to rebase or restart.

Re-synchronization and revalidation are required when the new `main` change:

- creates a merge conflict;
- changes a declared dependency;
- changes shared foundation code used by the Work Package;
- invalidates the package's acceptance criteria, tests, architecture assumptions, or runtime behavior.

A branch must never claim current-main compatibility without evidence when its baseline is older than relevant merged changes.

## Merge and Post-Merge Closure

Parallel PRs remain independently reviewable and independently gated.

```text
PR A PASS does not imply PR B PASS.
PR A validation/merge eligibility does not imply PR B validation/merge eligibility; each PR must independently satisfy its checks, dependencies, applicable Owner UAT, and the continuous-delivery gate.
PR A failure does not automatically block unrelated PR C.
```

Merge order must follow declared dependencies. A dependent PR must not merge before its prerequisite unless the resulting target branch is independently valid and the dependency declaration is updated with evidence.

For a newly activated Work Package under this standard, merge of its delivery PR is the terminal repository mutation of that Work Package. The merge commit or squash commit becomes repository truth and is sufficient to identify the merged result.

A second post-merge Closure PR must not be created under the same Work Package identity merely to record the merge SHA.

If repository files genuinely require mutation after merge, create a separately identified Work Package such as a bounded closure-correction or remediation package. That new Work Package receives its own branch, worktree and PR and must not be presented as a second PR belonging to the original Work Package.

External status systems or non-repository notes may record the merge SHA without creating a repository mutation, provided they do not become a competing source of truth.

## Independent Parallel Gates

Each concurrent Work Package carries its own gates:

```text
SCOPE_GATE
DEPENDENCY_GATE
WORKTREE_ISOLATION_GATE
TEST_GATE
REVIEW_GATE
CONTINUOUS_DELIVERY_GATE
```

A PASS or authorization for one Work Package is not inherited by another.

## Mandatory Stop Conditions

Stop the affected Work Package with an explicit failure / waiting status when any of the following is true:

```text
WORK_PACKAGE_BRANCH_PR_BOUNDARY = FAIL
ACTIVE_AGENT_WORKTREE_ISOLATION = FAIL
PARALLEL_DEPENDENCY_DECLARATION = FAIL
COMMON_FOUNDATION_EXTRACTION_GATE = FAIL
PARALLEL_EXECUTION_GATE = FAIL
```

Do not solve these failures by silently widening scope, moving files between agents, sharing a dirty worktree, copying unmerged code, or creating undeclared stacked PRs.

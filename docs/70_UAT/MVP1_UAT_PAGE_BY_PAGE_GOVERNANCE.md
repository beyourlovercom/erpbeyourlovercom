# MVP1 UAT Page-by-Page Governance

## Governing Model

```text
GOVERNANCE_MODEL: ONE_PAGE_ONE_WORK_PACKAGE_ONE_BRANCH_ONE_PR
LONG_RUNNING_REMEDIATION_PR: PROHIBITED
```

Each formal UAT finding must retain source traceability in
`docs/70_UAT/MVP1_UAT_FINDING_INDEX.md`. Once Findings have an Owner-approved
page classification, one page Work Package may contain one or more formal
Findings when all included Findings are explicitly classified to the same
approved page scope. The package remains one page, one work package, one
branch, and one PR. Each Finding keeps independent traceability and closure;
one Finding reaching PASS does not close other Findings in the same package.
`CROSS_PAGE` and `UNRESOLVED` are classifications, not authorization to create
a broad page remediation PR; they require reclassification, a separate Work
Package, or a Foundation Work Package before remediation begins.

## Required Lifecycle

```text
FAST_CAPTURE
    ↓
FORMAL_FINDING
    ↓
PAGE_CLASSIFICATION
    ↓
READ_ONLY_PAGE_AUDIT
    ↓
OWNER_DESIGN_DECISION
    ↓
REMEDIATION_WORK_PACKAGE
    ↓
LOCAL_IMPLEMENTATION
    ↓
AUTOMATED_GATES
    ↓
OWNER_UAT
    ↓
DRAFT_PR
    ↓
INDEPENDENT_READ_ONLY_REVIEW
    ↓
OWNER_MERGE_AUTHORIZATION
    ↓
MERGED
    ↓
FINDING_CLOSED
```

`FINDING_CLOSED` requires the relevant remediation PR to be merged and its
Owner UAT result to be recorded as PASS. A local result, draft PR, automated
gate, or remediation document alone does not close a Finding.

## Work Package Contract

Every remediation work package must record:

```text
PAGE
PAGE_ROUTE_OR_CANONICAL_PAGE_ID
FINDING_IDS
DESIGN_BASELINE
KNOWN_PAGE_GAPS
BASELINE_BRANCH
BASELINE_SHA
WORKING_BRANCH
REMEDIATION_PR
OWNER_DESIGN_DECISION
AUTOMATED_GATES
OWNER_UAT
MERGE_SHA
CLOSURE_STATUS
```

`FINDING_IDS` is an explicit list, not a replacement for individual Finding
records. Every listed Finding must independently progress through:

```text
OPEN → IN_REMEDIATION → OWNER_UAT_PASS → MERGED_CLOSED
```

The remediation branch and PR must name the page and work package. A page Work
Package may contain one or more Findings only when all included Findings are
explicitly classified to that same approved page scope. Adding another page,
unresolved cross-page scope, or undeclared shared-foundation work requires
reclassification, a separate Work Package, or a Foundation Work Package.

## Shared Foundation Rule

The current `main` baseline includes `PARALLEL-WORK-EXEC-001 v1.1`. Its shared
foundation rule applies to every page audit. If an audit identifies
`AppShell`, `Sidebar`, shared layout, design tokens, shared components, shared
styles, shared route or permission primitives as common necessary changes for
multiple page packages, record:

```text
FOUNDATION_CHANGE_REQUIRED: YES
```

Extract a separate Foundation Work Package before starting the affected page
remediation. Do not place shared foundation changes in the first page PR. A
stacked dependency is allowed only with explicit Owner authorization and a
declared dependency; otherwise page remediation waits for the Foundation PR to
merge.

## PR #73 Migration Status

```text
PR: #73
ROLE: LEGACY_HUMAN_UAT_FINDING_CONTAINER
CURRENT_STATUS: OPEN / DRAFT / LEGACY
SOURCE_BRANCH: codex/mvp1-local-human-uat-session
TARGET_STATUS: CLOSED_UNMERGED / SUPERSEDED
TARGET_OPERATIONAL_STATE: CLOSED_UNMERGED_AFTER_MIGRATION_PR_MERGE
MERGE_TO_MAIN: PROHIBITED
SOURCE_BRANCH_RETENTION: REQUIRED_UNTIL_OWNER_SEPARATELY_AUTHORIZES_DELETION
```

PR #73 may be closed only when all conditions below are true:

1. Every Finding is migrated to the Finding Index.
2. The Finding Index is merged to `main`.
3. This governance document is merged to `main`.
4. The Owner explicitly authorizes closure of PR #73.

Until all four conditions are met, PR #73 remains OPEN/DRAFT and its source
branch remains preserved. PR #73 must never be merged to `main`.

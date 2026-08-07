# MVP1 UAT Page-by-Page Governance

## Governing Model

```text
GOVERNANCE_MODEL: ONE_PAGE_ONE_WORK_PACKAGE_ONE_BRANCH_ONE_PR
LONG_RUNNING_REMEDIATION_PR: PROHIBITED
```

Each formal UAT finding must retain source traceability in
`docs/70_UAT/MVP1_UAT_FINDING_INDEX.md`. Once a Finding has an Owner-approved
page classification, its remediation is limited to one page, one work package,
one branch, and one PR. `CROSS_PAGE` and `UNRESOLVED` are classifications, not
authorization to create a broad remediation PR; they require an Owner decision
before a specific single-page remediation package is created.

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
FINDING_ID
PAGE
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

The remediation branch and PR must name the page and work package. A package
may not silently expand to another page or a second Finding. New scope requires
a separate formal Finding and a separate page-by-page work package.

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

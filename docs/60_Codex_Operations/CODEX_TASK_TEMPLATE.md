---
type: codex-task-template
project: BYL ERP
standard_id: LARGE-PROJECT-EXEC-001
standard_version: 1.1
---

# Codex Task Template

## WORK PACKAGE

```text
ID:
TITLE:
STATUS:
OWNER_AUTHORIZATION:
STANDARD_ID: LARGE-PROJECT-EXEC-001
STANDARD_VERSION:
```

## REPOSITORY

```text
REMOTE:
LOCAL_WORKTREE:
TARGET_BRANCH:
```

## BASELINE

```text
BASE_BRANCH: main
BASE_SHA:
BASE_VERIFIED_AT:
```

## AUTHORIZED ACTIONS

- List every authorized action.

## PROHIBITED ACTIONS

- List explicit hard gates and out-of-scope operations.

## SCOPE

- List the only files, modules and behavior in scope.

## ACCEPTANCE CRITERIA

- Define observable completion criteria.

## VALIDATION

```text
REQUIRED_COMMANDS:
EXPECTED_EVIDENCE:
HARD_GATES:
```

## COMMIT / PR RULES

```text
COMMIT_MESSAGE:
PR_TITLE:
PR_BASE:
PR_DRAFT_STATE:
MERGE_AUTHORIZED:
```

## FINAL REPORT FORMAT

```text
REQUIRED_FIELDS:
ALLOWED_FINAL_VERDICT:
```

## PROJECT POSITION

```text
PROGRAM_ROADMAP: <complete top-level sequence from 13_PROGRAM_ROADMAP_AND_HOURS>
CURRENT_TRACK_PHASE_MAP: <complete current track; prefix current phase with →>
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
```

## ROADMAP HOURS

```text
CURRENT_PACKAGE_ESTIMATE: <planning value and range; must match final module>
KNOWN_CURRENT_TRACK_REMAINING: <frozen non-overlapping packages only>
ARCHITECTURE_GATE_REMAINING: <estimate or UNKNOWN WITH EVIDENCE>
MVP1_TOTAL_ESTIMATE: <total estimate>
MVP1_PLANNING_BASELINE: <total planning value>
MVP1_MAXIMUM_ENVELOPE: <maximum total envelope>
MVP1_REMAINING: <estimate or UNKNOWN WITH EVIDENCE>
POST_MVP_KNOWN_INCREMENT: <incremental estimate>
LONG_TERM_CUMULATIVE_RANGE: <cumulative horizons; never summed>
PROGRAM_REMAINING: <estimate or UNKNOWN WITH EVIDENCE>
COMPLETED_ACTUAL_EFFECTIVE_HOURS: <accepted ledger or UNKNOWN WITH EVIDENCE>
ESTIMATE_CONFIDENCE:
ESTIMATE_AS_OF:
ESTIMATE_METHOD:
```

## ESTIMATED CODEX HOURS

```text
PREFLIGHT_AND_SCOPE: <range and planning value>
CORE_EXECUTION: <range and planning value>
TEST_AND_VALIDATION: <range and planning value>
REMEDIATION_BUFFER: <range and planning value>
ESTIMATED_EFFECTIVE_HOURS: <sum of planning values>
ESTIMATE_RANGE: <sum of lower bounds>-<sum of upper bounds>
ESTIMATE_BASIS: <scope, arithmetic, assumptions and comparable evidence>
OWNER_REVIEW: INCLUDED|EXCLUDED
MERGE: INCLUDED|EXCLUDED
MIGRATION: INCLUDED|EXCLUDED
MIGRATION_FILE_AUTHORING: INCLUDED|EXCLUDED (optional; cannot replace MIGRATION)
MIGRATION_EXECUTION: INCLUDED|EXCLUDED (optional; cannot replace MIGRATION)
UAT: INCLUDED|EXCLUDED
DEPLOYMENT: INCLUDED|EXCLUDED
```

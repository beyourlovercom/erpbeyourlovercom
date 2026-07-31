---
type: codex-task-template
project: BYL ERP
standard_id: LARGE-PROJECT-EXEC-001
---

# Codex Task Template

## WORK PACKAGE

```text
ID:
TITLE:
STATUS:
OWNER_AUTHORIZATION:
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
MERGE_AUTHORIZED: NO
```

## FINAL REPORT FORMAT

```text
REQUIRED_FIELDS:
ALLOWED_FINAL_VERDICT:
```

## PROJECT POSITION

```text
PHASE_MAP:
<complete Roadmap sequence; prefix the current phase with →>
COMPLETED_PHASES: <repository-derived completed phases>
CURRENT_PHASE: <repository-derived current phase>
CURRENT_WORK_PACKAGE: <repository-derived current package>
PACKAGE_STATUS: <repository- and GitHub-derived status>
WAITING_PHASES: <repository-derived waiting phases>
BASELINE: <origin/main SHA or explicitly authorized baseline>
GITHUB_STATE: <verified relevant branch and PR state>
GLOBAL_GATE:
PRODUCTION_DEPLOYMENT_STATUS: YES|NO|PARTIAL|UNKNOWN WITH EVIDENCE
NEXT_DEPENDENCY: <repository-derived next dependency>
EVIDENCE_AS_OF: <timestamp and sources checked>
```

## ESTIMATED CODEX HOURS

```text
PREFLIGHT_AND_SCOPE: <hours or hour range>
CORE_EXECUTION: <hours or hour range>
TEST_AND_VALIDATION: <hours or hour range>
REMEDIATION_BUFFER: <hours or hour range>
ESTIMATED_EFFECTIVE_HOURS: <sum of all component planning values>
ESTIMATE_RANGE: <sum of all component lower bounds>-<sum of all component upper bounds>
ESTIMATE_BASIS: <scope, assumptions, component planning values and comparable evidence>
OWNER_REVIEW: INCLUDED|EXCLUDED
MERGE: INCLUDED|EXCLUDED
MIGRATION: INCLUDED|EXCLUDED
UAT: INCLUDED|EXCLUDED
DEPLOYMENT: INCLUDED|EXCLUDED
```

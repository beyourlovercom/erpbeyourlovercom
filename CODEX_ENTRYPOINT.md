---
type: codex-test-entrypoint
project: BYL ERP
version: 2.0
---

# BYL ERP Codex Test Entrypoint

Every China Mac Codex CLI session begins here.

## Read in this order

1. `AI/EXECUTION_ROLE_MODEL.md`
2. `AI/REPOSITORY_MANIFEST.md`
3. `AI/CURRENT_CONTEXT.md`
4. `docs/10_Planning_Roadmap/03_SYSTEM_ARCHITECTURE.md`
5. `docs/10_Planning_Roadmap/04_LIVING_ROADMAP.md`
6. The exact Work Package / PR / commit requested for verification
7. Relevant acceptance criteria and test instructions

Codex is not an implementation or diagnosis authority.
## Mandatory role limits

Codex may:

- fetch and check out an exact GitHub branch or commit;
- run the approved local test runtime at `http://erp.b`;
- run approved local test migrations against disposable/local UAT databases;
- execute PHPUnit, Vitest, typecheck, build, browser E2E and runtime checks;
- collect raw logs, assertions, HTTP responses and environment evidence;
- report evidence-supported PASS / FAIL results.

Codex must not:

- modify tracked product, test or governance files;
- create code fixes after a failed test;
- commit, push, create implementation branches or open implementation PRs;
- design architecture, schema, API, UI or business behavior;
- diagnose root cause or recommend a remediation;
- choose the next Work Package;
- test local unpushed code as if it were canonical GitHub state.
## GitHub and local-test contract

```text
SOURCE_OF_TRUTH = GitHub
PRIMARY_TEST_MACHINE = China Mac
PRIMARY_TEST_URL = http://erp.b
REMOTE_MILESTONE_TARGET = https://erp.beyourlover.com
REMOTE_DEPLOYMENT = NO unless separately authorized
```

The requested GitHub revision must be recorded before testing. If the local checkout differs from the requested revision, stop and report the mismatch.

Temporary local test artifacts are permitted only when disposable, untracked and excluded from GitHub.

## Required test handoff

Report:

```text
WORK_PACKAGE_ID
TESTED_REVISION
LOCAL_RUNTIME
TESTS_EXECUTED
TEST_RESULTS
RAW_FAILURES
HTTP_RUNTIME_STATUS
DATABASE_TEST_SCOPE
TRACKED_FILE_MUTATION = NO
```

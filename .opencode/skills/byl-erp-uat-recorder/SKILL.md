---
name: byl-erp-uat-recorder
description: Record BYL ERP local human UAT defects from Owner text and screenshots into the existing defect register and UAT Draft PR. Never modify business code, implement fixes, close defects, or mark Owner retest as passed.
compatibility: opencode
metadata:
  project: byl-erp
  workflow: local-human-uat-defect-intake
---

# BYL ERP Human UAT Defect Recorder

## Purpose

Use this skill when the Owner reports a local BYL ERP UAT defect using text, screenshots, browser URLs, reproduction steps, expected behavior, or actual behavior.

This skill records defects only.

It must not:

- modify application business code;
- implement a fix;
- create a remediation branch;
- merge a pull request;
- close a defect;
- mark Owner retest as passed;
- access Remote Dev or Production;
- access real external providers;
- record secrets or credentials.

## Repository contract

Repository:

/Users/a/AI/007/erpbeyourlovercom

Primary register:

docs/70_UAT/MVP1_LOCAL_UAT_DEFECT_REGISTER.md

Checklist:

docs/70_UAT/MVP1_LOCAL_UAT_CHECKLIST.md

Evidence directory:

docs/70_UAT/evidence/YYYY-MM-DD/

UAT state:

HUMAN_UAT_STATUS: IN PROGRESS
UAT_GATE: CHANGES REQUIRED

## Intake fields

Extract or request only genuinely missing information:

- PAGE
- ROUTE
- UAT_SCENARIO
- TITLE
- MODULE
- REPRODUCTION_STEPS
- ACTUAL_RESULT
- EXPECTED_RESULT
- SEVERITY
- SCREENSHOTS
- REPORTED_AT
- REPORTED_BY

Do not invent reproduction steps or expected behavior.

When the Owner has clearly supplied enough evidence, do not ask unnecessary questions.

## Defect identification

1. Read the existing Defect Register.
2. Find the highest existing sequential defect number.
3. Allocate the next available ID:

MVP1-UAT-DEFECT-NNN

4. Never reuse an old ID.
5. When one report contains separate root causes, record a parent defect first and mark:

SPLIT_REVIEW: REQUIRED

Do not split it automatically unless the evidence clearly proves separate defects.

## Severity rules

BLOCKER:
The UAT environment or all critical workflows cannot continue.

CRITICAL:
Data identity, data integrity, authorization, financial calculations, or core workflow correctness is broken.

HIGH:
A core operation fails or the UI makes an approved workflow unusable.

MEDIUM:
The workflow works but has a meaningful usability or consistency defect.

LOW:
Cosmetic or minor text/layout issue with no material workflow impact.

Do not reduce a severity without Owner approval.

## Evidence handling

For each attached image:

1. Inspect it for:
   - passwords;
   - cookies;
   - tokens;
   - API keys;
   - session identifiers;
   - real customer personal information;
   - database credentials.
2. Stop and report REDACTION_REQUIRED when sensitive information is visible.
3. Save acceptable evidence under:

docs/70_UAT/evidence/YYYY-MM-DD/

4. Use filenames:

MVP1-UAT-DEFECT-NNN-01.png
MVP1-UAT-DEFECT-NNN-02.png

5. Reference images using repository-relative Markdown paths.
6. Do not alter the substantive visual evidence except approved redaction.

## Defect record schema

Each defect entry must contain:

DEFECT_ID
UAT_SCENARIO
TITLE
MODULE
SEVERITY
STATUS
REPORTED_AT
REPORTED_BY
PAGE
ROUTE
PRECONDITION
REPRODUCTION_STEPS
EXPECTED_RESULT
ACTUAL_RESULT
EVIDENCE
CONTRACT_REFERENCE
ROOT_CAUSE
AFFECTED_FILES
FIX_BRANCH
FIX_PR
AUTOMATED_TESTS
OWNER_RETEST
CLOSURE_STATUS

Initial values:

STATUS: REPORTED
ROOT_CAUSE: PENDING
AFFECTED_FILES: PENDING
FIX_BRANCH: PENDING
FIX_PR: PENDING
AUTOMATED_TESTS: PENDING
OWNER_RETEST: PENDING
CLOSURE_STATUS: OPEN

## Status restrictions

Allowed intake statuses:

- REPORTED
- NEEDS_OWNER_DECISION
- TRIAGED

This skill must never assign:

- IMPLEMENTING
- READY_FOR_OWNER_RETEST
- RETEST_PASS
- CLOSED
- ACCEPTED_DEFERRED

Those states require the remediation workflow or explicit Owner decision.

## UAT document updates

After adding a defect:

1. Update the total OPEN_DEFECTS count.
2. Preserve:

HUMAN_UAT_STATUS: IN PROGRESS
UAT_GATE: CHANGES REQUIRED

3. Do not mark the related UAT scenario PASS.
4. If the scenario was previously marked PASS, change it to:

REOPENED / DEFECT FOUND

5. Keep all Markdown fenced code blocks correctly closed.

## Git workflow

1. Run a read-only Git preflight.
2. Do not work directly on main.
3. Locate the existing Human UAT documentation branch and Draft PR.
4. Append the defect to that same branch and Draft PR.
5. Do not create a new documentation PR for every defect.
6. Commit only:
   - Defect Register updates;
   - Checklist status updates;
   - approved screenshot evidence.
7. Do not commit:
   - .env;
   - local databases;
   - SQL backups;
   - passwords;
   - cookies;
   - tokens;
   - PHP or Valet system configuration;
   - .codex/byl-erp-local-uat-access.txt.
8. Run:
   - git diff --check;
   - Markdown fence validation;
   - secret scan.
9. Push the existing UAT documentation branch.
10. Keep the UAT documentation PR OPEN and DRAFT.
11. Never merge it.

## Safety

Forbidden:

- git reset --hard
- git clean -fd
- stash pop
- stash drop
- commit -a
- direct commits to main
- Remote Dev access
- Production access
- SSH
- real external API writes
- automatic defect fixes

## Final response

Return only:

STATUS
DEFECT_ID
TITLE
MODULE
SEVERITY
DEFECT_STATUS
REGISTER_FILE
EVIDENCE_FILES
COMMIT_SHA
DRAFT_PR
OPEN_DEFECTS
HUMAN_UAT_STATUS
UAT_GATE
NEEDS_OWNER_DECISION
NEXT_OWNER_ACTION

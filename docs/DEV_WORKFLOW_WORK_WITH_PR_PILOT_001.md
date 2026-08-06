# DEV-WORKFLOW-WORK-WITH-PR-PILOT-001

## Work Package Identity and Purpose

- **Work Package:** `DEV-WORKFLOW-WORK-WITH-PR-PILOT-001`
- **Purpose:** Record the authorized Phase 1 pilot workflow for using `work-with-pr` to plan and execute work under Owner-controlled gates.
- **Delivery state:** Draft PR; the Owner merge gate is waiting.

## Install Path and Phase 1 Executor

- **User-level install path:** `/Users/a/.config/opencode/skills/work-with-pr`
- **Phase 1 executor:** `/ulw-plan` creates the approved plan, `/start-work` executes it, and Ralph continuation resumes incomplete planned work when required.

## Owner Gate Model

The Owner authorizes the Work Package, base, worktree, file boundary, and validation matrix before execution. The executor may act only within those limits. Owner review and the Owner merge gate remain separate from implementation evidence; this record does not claim unobserved review or CI results.

## Prohibitions

The pilot does not authorize Migration, Deploy, Remote Dev, Production, business-code changes, credential handling, dependency changes, configuration changes, marking the PR Ready, merging the PR, or deleting existing branches or worktrees. The pilot explicitly authorizes one documentation-only commit, push, and one Draft PR from docs/work-with-pr-pilot-001 to main.

## Pilot Verification Steps

Verification is local and limited to the authorized worktree:

1. Check whitespace with `git diff --check -- docs/DEV_WORKFLOW_WORK_WITH_PR_PILOT_001.md`.
2. Confirm balanced Markdown fence delimiters.
3. Confirm that only the authorized file differs from the base or is uncommitted.
4. Confirm file existence and required-topic headings/content assertions.
5. Scan only this file for common private-key and API-token patterns.

## Manual QA Evidence

Manual QA evidence consists of the exact file path, changed-file list, section headings, validation commands, and concrete command outcomes. No browser, application, database, deployment, or remote-environment evidence is claimed.

## Rollback Method

The Draft PR may be closed before merge; the pilot branch and worktree are retained; deleting the branch or worktree requires separate Owner authorization; reset --hard, git clean, and force push are not allowed.

## Final Pilot State

The authorized branch has been committed and pushed. Delivery through exactly one Draft PR targeting main is pending. The Owner merge gate remains waiting. CI and review results must not be asserted until directly observed.

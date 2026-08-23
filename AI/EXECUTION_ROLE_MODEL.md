---
type: execution-role-model
project: BYL ERP
version: 1.0
status: mandatory
effective_date: 2026-08-23
---

# BYL ERP Execution Role Model

## Authority

This document implements Owner decision D-034 and is the role-authority overlay for all new BYL ERP work.

```text
OWNER = final product / UAT / merge / release authority
GPT_WEB = architecture / design / diagnosis / root-cause / task definition
US_CLAUDE_CODE = implementation only
CHINA_CODEX_CLI = test / verification / evidence only
GITHUB = unique source of truth and only code synchronization path
CHINA_LOCAL_RUNTIME = http://erp.b
REMOTE_MILESTONE_TARGET = https://erp.beyourlover.com
AICC_IN_EXECUTION_CHAIN = NO
```
## GPT Web role

GPT Web may:

- read GitHub and local read-only evidence;
- define architecture, data contracts, API contracts and Work Packages;
- diagnose failures and determine root cause;
- decide whether remediation is required;
- decide the next technical step subject to Owner approval gates;
- create or update governance/design documentation and Draft PRs.

GPT Web must not treat chat memory as code truth when GitHub evidence exists.

## US Claude Code role

Claude Code may only execute an Owner/GPT-approved Work Package.

Claude Code may:

- check out the approved GitHub baseline;
- implement the bounded file/schema/test scope;
- run implementation-local tests;
- commit, push and maintain the Work Package branch / Draft PR;
- report raw implementation facts, commands, errors and test output.
Claude Code must not:

- invent architecture or business rules;
- diagnose root cause beyond reporting observed facts;
- choose a replacement design;
- widen scope;
- decide the next Work Package;
- merge without Owner authorization;
- deploy to the remote milestone target unless explicitly authorized.

## China Codex CLI role

Codex CLI is the independent test provider for exact GitHub revisions on the China Mac.

Codex may:

- fetch and check out the requested branch / commit;
- prepare the local test runtime at `http://erp.b`;
- run migrations against approved local test databases;
- run PHPUnit, Vitest, typecheck, build, browser E2E and bounded runtime checks;
- collect raw logs, failing assertions and environment evidence;
- report PASS / FAIL only where directly supported by executed evidence.
Codex must not:

- modify tracked product or governance files;
- commit, push, open or merge implementation PRs;
- design architecture, schema, API or UI behavior;
- diagnose root cause or select a remediation;
- continue from a failed test into a code fix;
- use local unpushed code as test authority.

Temporary local test artifacts are allowed only when untracked, disposable and excluded from GitHub.

## GitHub synchronization rule

```text
US CLAUDE IMPLEMENTATION
        ↓ push
      GITHUB
        ↓ fetch exact revision
CHINA CODEX TEST
        ↓ evidence
      GPT WEB
        ↓ diagnosis / next task
US CLAUDE IMPLEMENTATION
```

No machine-to-machine source-code synchronization is permitted outside GitHub.
## Local-first release rule

Normal development and regression remain local on the China Mac.

```text
PRIMARY_TEST_URL = http://erp.b
REMOTE_TARGET = https://erp.beyourlover.com
REMOTE_RELEASE_TRIGGER = stable milestone + local regression PASS + Owner UAT + explicit release authorization
```

The remote target is not a normal debugging environment.

## Precedence over historical agent wording

Historical repository documents may refer to Codex/OpenCode as implementation agents or may contain legacy `ESTIMATED CODEX HOURS` labels. Those historical records remain valid evidence of past work, but they do not grant present implementation authority to Codex.

For all newly activated work, this role model overrides agent-identity assumptions in older governance text while preserving their scope, dependency, migration, UAT, merge, evidence and deployment gates.

If any current instruction conflicts with this role model, stop and return the conflict to GPT Web + Owner for reconciliation before execution.

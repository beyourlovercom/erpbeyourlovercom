---
type: execution-role-model
project: BYL ERP
version: 1.2
status: mandatory
effective_date: 2026-08-29
---

# BYL ERP Execution Role Model

## Authority

This document implements Owner decision D-034 and is the role-authority overlay for all new BYL ERP work.

```text
OWNER = final product / UAT / merge / release authority
GPT_WEB = architecture / design / diagnosis / root-cause / task definition + bounded approved remediation implementation
US_CLAUDE_CODE = primary provider for large/complex implementation
CHINA_CODEX_CLI = test / verification / evidence only
GITHUB = unique source of truth and only code synchronization path
US_LOCAL_RUNTIME = http://erp.b
CHINA_LOCAL_RUNTIME = http://erp.b
PRIMARY_FULL_VERIFICATION_HOST = US_MAC
DAILY_CHINA_VERIFICATION = EXACT_SHA_SMOKE_BY_DEFAULT
DUAL_HOST_FULL_VERIFICATION = MILESTONE_RELEASE_OR_RISK_TRIGGER_ONLY
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
- create or update governance/design documentation and Draft PRs;
- directly implement bounded, well-understood remediation when architecture/business behavior is already approved or frozen, including narrow UI/CSS, exact bug fixes with established root cause, approved permission enforcement, test/typecheck/build fixes, UAT remediation, architecture-hash updates, and similarly bounded follow-up work;
- run required US-local verification and perform Owner-authorized Git delivery for that bounded Work Package.

GPT-direct implementation must not invent new architecture, widen approved scope, weaken verification, or bypass Owner merge/release gates. GPT Web must not treat chat memory as code truth when GitHub evidence exists.

## US Claude Code role

Claude Code is the primary implementation provider for large new modules/features, broad cross-file implementation, materially complex schema/data-model work, complex external integrations, and high-complexity refactors. Claude Code may only execute an Owner/GPT-approved Work Package.

For bounded remediation that GPT can safely implement and verify, GPT is the default provider. Do not create a fresh Claude session merely for a one/few-file exact follow-up. When Claude is used, continuation of the same Work Package/session is preferred where practical to avoid repeated context loading.

Claude Code may:

- check out the approved GitHub baseline;
- implement the bounded file/schema/test scope;
- run the complete implementation-host validation required by the Work Package before presenting a revision as a merge candidate;
- use the US local `http://erp.b` runtime for full local regression when the Work Package requires browser/runtime evidence;
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

## Implementation collision and handoff rule

For every active Work Package, use `ONE_ACTIVE_IMPLEMENTATION_PROVIDER_PER_WORKTREE`. GPT+DC and Claude must not concurrently implement in the same worktree or edit the same tracked file. Handoffs are serialized: stop the outgoing provider, preserve current branch/HEAD and working-tree diff as authority, then require the incoming provider to read that exact state before editing. A stale Claude session must not overwrite later GPT remediation.

Sequential Work Packages for the same module start from the latest merged `origin/main` unless an explicitly approved dependency branch says otherwise. Owner-UAT-PASS / COMPLETE / FROZEN UI and behavior are regression baselines and may only be extended incrementally unless the Owner explicitly authorizes redesign/removal. Codex remains exact-revision verification-only and never creates a competing implementation.

## China Codex CLI role

Codex CLI is the independent exact-revision verifier on the China Mac. It is not the default second full-regression host for ordinary daily development.

Codex may:

- fetch and check out the requested branch / commit;
- prepare the China local test runtime at `http://erp.b`;
- perform the default daily exact-SHA smoke contract: verify HEAD, clean tracked state, local runtime reachability and bounded task-critical smoke checks;
- run migrations against approved local test databases when the verification tier requires them;
- run PHPUnit, Vitest, typecheck, build, browser E2E and bounded runtime checks when the package is milestone/release, high-risk, or GPT/Owner explicitly escalates verification;
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

## Verification tier policy

The default verification strategy is asymmetric. Ordinary development gets one complete verification on the US implementation host and only a bounded independent smoke on the China host. The same complete suite must not be duplicated on both machines by default.

### Tier A — Daily development

```text
US CLAUDE IMPLEMENTATION
        ↓
US FULL LOCAL VERIFICATION ON MERGE-CANDIDATE REVISION
        ↓ push
      GITHUB
        ↓ exact SHA
CHINA CODEX SMOKE VERIFICATION
        ↓
OWNER UAT / NEXT GATE
```

US full local verification runs the complete validation required by the active Work Package, which may include focused/full PHPUnit, Vitest, typecheck, build, Architecture/Menu contracts, System Chrome E2E, MySQL/Redis checks and `http://erp.b` runtime evidence.

China daily smoke verification is intentionally bounded. By default it checks exact GitHub SHA, clean tracked state, `http://erp.b` reachability and only one or a few task-critical smoke assertions. It must not repeat the entire US PHPUnit + frontend + build + browser suite merely for duplication.

### Tier B — Milestone / release / high-risk

Full independent verification on both machines is required only when at least one of these conditions applies:

- milestone acceptance or release candidate;
- migration, schema or database-risk work;
- PHP/MySQL/Redis/runtime/environment configuration changes;
- authentication, authorization, permission or other security-sensitive changes;
- browser/runtime compatibility or cross-machine reproducibility concerns;
- deployment/release tooling changes;
- a China smoke failure that GPT classifies as requiring broader verification;
- explicit GPT or Owner instruction requiring dual-host full verification.

For Tier B, the US host runs the complete package validation first. After the exact revision is pushed, China Codex independently runs the complete required validation against that exact GitHub revision.

A smoke failure does not authorize Codex to fix code or automatically run an unbounded suite. Codex returns raw evidence; GPT selects the bounded next verification or remediation step.

## GitHub synchronization rule

```text
US CLAUDE IMPLEMENTATION
        ↓ US full verification
        ↓ push
      GITHUB
        ↓ fetch exact revision
CHINA CODEX DAILY SMOKE
        ↓ evidence
      GPT WEB / OWNER
        ↓ milestone/high-risk escalation only when required
CHINA CODEX FULL INDEPENDENT VERIFICATION
```

No machine-to-machine source-code synchronization is permitted outside GitHub.
## Local-first release rule

Normal development uses the US Mac as the primary full-verification host. The China Mac remains an independent exact-revision verifier and uses smoke verification by default during daily development.

```text
PRIMARY_FULL_TEST_HOST = US_MAC
PRIMARY_TEST_URL = http://erp.b
SECONDARY_EXACT_SHA_HOST = CHINA_MAC
SECONDARY_TEST_URL = http://erp.b
DAILY_SECONDARY_MODE = SMOKE
MILESTONE_RELEASE_SECONDARY_MODE = FULL_INDEPENDENT_VERIFICATION
REMOTE_TARGET = https://erp.beyourlover.com
REMOTE_RELEASE_TRIGGER = stable milestone + required local verification PASS + Owner UAT + explicit release authorization
```

The remote target is not a normal debugging environment.

## Precedence over historical agent wording

Historical repository documents may refer to Codex/OpenCode as implementation agents or may contain legacy `ESTIMATED CODEX HOURS` labels. Those historical records remain valid evidence of past work, but they do not grant present implementation authority to Codex.

For all newly activated work, this role model overrides agent-identity assumptions in older governance text while preserving their scope, dependency, migration, UAT, merge, evidence and deployment gates.

If any current instruction conflicts with this role model, stop and return the conflict to GPT Web + Owner for reconciliation before execution.

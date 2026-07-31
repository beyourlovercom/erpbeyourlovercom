---
type: codex-governance-standard
project: BYL ERP
standard_id: LARGE-PROJECT-EXEC-001
version: 1.0
---

# Large Project Codex Execution Standard

## Standard identification

```text
Standard ID: LARGE-PROJECT-EXEC-001
```

## Applicability

This standard applies to every formal Codex execution package that authorizes work in the BYL ERP repository, including implementation, remediation, documentation, validation, migration execution or preparation, UAT support and deployment preparation.

Exploration that does not authorize repository changes may use a shorter prompt. It becomes a formal execution package before Codex may modify files, create commits, open or update a PR, run a migration, conduct UAT or deploy.

## Repository-local implementation

This file is the BYL ERP repository's local implementation of `LARGE-PROJECT-EXEC-001`. It directly governs only the BYL ERP repository.

Other large projects must install the same standard or a project-adapted implementation in their own repositories. ChatGPT global instructions are responsible for chat-level output consistency; each repository standard is responsible for that project's facts, governance gates and Codex execution constraints.

Merging the PR that introduces this file does not automatically govern any other repository, and no report may claim that it does.

## Task package completeness gate

A formal execution package is executable only when it contains the required scope, authority, prohibitions, acceptance criteria, validation and repository rules, and ends with the mandatory footer defined below.

If either footer module is missing, incomplete, out of order or followed by any additional semantic content:

```text
TASK_PACKAGE = INCOMPLETE
EXECUTION = PROHIBITED
```

Codex must report the completeness failure and must not infer the missing content.

## Mandatory command footer

Every formal Codex execution command must end with these two top-level modules in this exact order:

```text
PROJECT POSITION
PHASE_MAP: <complete repository-derived sequence; prefix the current phase with →>
COMPLETED_PHASES: <repository-derived completed phases>
CURRENT_PHASE: <repository-derived value>
CURRENT_WORK_PACKAGE: <repository-derived value>
PACKAGE_STATUS: <repository- and GitHub-derived value>
WAITING_PHASES: <repository-derived waiting phases>
BASELINE: <origin/main SHA or explicitly authorized baseline>
GITHUB_STATE: <verified PR, branch and merge state relevant to the package>
GLOBAL_GATE: <repository-derived value>
PRODUCTION_DEPLOYMENT_STATUS: YES|NO|PARTIAL|UNKNOWN WITH EVIDENCE
NEXT_DEPENDENCY: <repository-derived value>
EVIDENCE_AS_OF: <timestamp and sources checked>

ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: <hours or hour range>
CORE_EXECUTION: <hours or hour range>
TEST_AND_VALIDATION: <hours or hour range>
REMEDIATION_BUFFER: <hours or hour range>
ESTIMATED_EFFECTIVE_HOURS: <single planning value in hours>
ESTIMATE_RANGE: <lower-upper effective hours>
ESTIMATE_BASIS: <scope, assumptions and comparable evidence>
OWNER_REVIEW: INCLUDED|EXCLUDED
MERGE: INCLUDED|EXCLUDED
MIGRATION: INCLUDED|EXCLUDED
UAT: INCLUDED|EXCLUDED
DEPLOYMENT: INCLUDED|EXCLUDED
```

`ESTIMATED CODEX HOURS` must be the final semantic module. No new explanation, command, note, attachment, status, question or other semantic content may follow its fields.

Markdown code-fence closures and other purely syntactic formatting closures are allowed after the final field. A normal closing fence does not count as additional content and must not cause `TASK_PACKAGE = INCOMPLETE`.

## Dynamic project position

`PROJECT POSITION` is a point-in-time operational summary, not copied boilerplate. Before issuing or executing a package, Codex must derive it from all of the following:

1. `AI/CURRENT_CONTEXT.md`;
2. `docs/10_Planning_Roadmap/04_LIVING_ROADMAP.md`;
3. the current activated Work Package and its acceptance criteria;
4. current Git and GitHub state, including `origin/main`, the package branch, PR state, review state and merge state where relevant.

`PHASE_MAP` must show the complete phase sequence present in the current repository Roadmap, not merely the current package. It must include completed, current and waiting phases, use `→` to mark the current phase, and agree with the separately reported `COMPLETED_PHASES`, `CURRENT_PHASE` and `WAITING_PHASES`. These fields remain mandatory when a category is empty and must then state `NONE WITH EVIDENCE` rather than invent a phase. `GLOBAL_GATE` must remain a separate field and must not be folded into a phase label.

When the project has no numbered phases, `PHASE_MAP` must use its actual Milestone or Work Package sequence. Codex must not invent phases, infer an unevidenced sequence or renumber repository facts.

`PRODUCTION_DEPLOYMENT_STATUS` must independently report the current production state as `YES`, `NO`, `PARTIAL` or `UNKNOWN WITH EVIDENCE`. `UNKNOWN WITH EVIDENCE` must identify what was checked and why the status remains unverifiable. This current-state field is distinct from `DEPLOYMENT: INCLUDED|EXCLUDED`, which only declares whether deployment work is included in the package estimate. Neither field may substitute for the other.

Repository `main` is the project truth. Current GitHub state governs facts that can change outside the checked-out files, such as whether a PR is open, merged or closed. A stale branch, cached output or earlier chat statement must not override current `origin/main` or verified GitHub state.

If sources conflict, Codex must state the conflict in `PROJECT POSITION`, preserve each evidenced state, and stop at the applicable governance gate. Codex must not silently select an old value.

`UNKNOWN` is not a generic placeholder. It may be used only when a required fact cannot be verified after checking every authoritative source available to the task. The field must identify the unavailable fact and the failed or missing evidence. Codex must never invent an `UNKNOWN` state for a fact that is verifiable, and must never replace an evidenced state with `UNKNOWN` to avoid resolving a conflict.

## Estimated Codex hours

Each package must provide at least these four effort components:

- `PREFLIGHT_AND_SCOPE`;
- `CORE_EXECUTION`;
- `TEST_AND_VALIDATION`;
- `REMEDIATION_BUFFER`.

Every component must contain hours or an hour range. `REMEDIATION_BUFFER` must remain independently visible and must not be hidden in `CORE_EXECUTION`. A package may add task-specific components, but every additional component becomes part of the same total reconciliation.

Each package must also provide both:

- `ESTIMATED_EFFECTIVE_HOURS`: the positive single planning value used for variance;
- `ESTIMATE_RANGE`: the credible low-to-high range for the authorized scope.

`ESTIMATED_EFFECTIVE_HOURS` must equal the sum of the planning values for every component. `ESTIMATE_RANGE` must equal the sum of every component's lower bounds through the sum of every component's upper bounds. When a component is expressed only as a range, `ESTIMATE_BASIS` must identify the planning value selected within that range for the single-value total.

The package must make the arithmetic auditable. It must not provide only a total or total range, omit a required component, exclude an added component from the sum, or publish totals inconsistent with the displayed components. An inconsistent breakdown makes the package incomplete.

The estimate must be prepared from the bounded scope, acceptance criteria, required validation, repository condition, identified risks and comparable completed work when available. It must not include unauthorized follow-on work.

An estimate is a planning statement, not authorization. Owner authorization, merge authorization, migration authorization, UAT authorization and deployment authorization remain separate gates.

## Effective hours

Effective hours are productive execution time spent on the authorized package. They include applicable repository inspection, planning, implementation, documentation, automated validation, review remediation and final reporting.

Effective hours exclude idle or elapsed wall-clock time, waiting for Owner review or authorization, external approval delays, account-access delays, unrelated outages and idle UAT waiting. Owner waiting time must never be included in any estimate component. Effective hours are neither token usage nor the duration between task creation and completion.

Actual effective hours must record only completed in-scope productive work. Time spent on rejected or repeated work remains actual effort and must be explained in the variance reason.

## Downstream activity inclusion

Every package must explicitly mark `OWNER_REVIEW`, `MERGE`, `MIGRATION`, `UAT` and `DEPLOYMENT` as `INCLUDED` or `EXCLUDED` in `ESTIMATED CODEX HOURS`.

- `INCLUDED` means the package scope and estimate include Codex's authorized execution work for that activity. It does not grant the authorization itself.
- `EXCLUDED` means neither the activity nor its execution effort is part of the package.
- Owner waiting time is always excluded from effective hours, even when preparation for Owner review is included.
- Merge, Migration, UAT and Deployment may be marked `INCLUDED` only when their required authorization, acceptance criteria and validation are explicitly present in the package.
- Manual stakeholder UAT time and deployment observation time are excluded unless the package explicitly defines measurable Codex work for them.

No downstream activity may be inferred from a parent activity. For example, Owner Review does not imply Merge; Merge does not imply Migration; Migration does not imply UAT; and UAT does not imply Deployment.

## Estimate and actual variance

The final report must record:

```text
ESTIMATED_EFFECTIVE_HOURS: <the package planning value>
ACTUAL_EFFECTIVE_HOURS: <productive hours actually used>
ACTUAL_HOURS_BREAKDOWN: <optional productive-hours distribution by component>
VARIANCE: <actual minus estimated hours; signed, with percentage>
VARIANCE_REASON: <scope, risk, rework or efficiency explanation>
FINAL_VERDICT: <authorized verdict>
```

The estimate copied into the final report must be the issued package value. It must not be retrospectively rewritten to match actual effort. If an Owner-approved scope change requires re-estimation, retain the original estimate, record the approved revised estimate and its evidence in the work log, and calculate final variance against the latest approved estimate.

Calculate variance as:

```text
VARIANCE_HOURS = ACTUAL_EFFECTIVE_HOURS - ESTIMATED_EFFECTIVE_HOURS
VARIANCE_PERCENT = VARIANCE_HOURS / ESTIMATED_EFFECTIVE_HOURS * 100
```

Positive variance is over estimate; negative variance is under estimate. `VARIANCE_REASON` is mandatory whenever variance is non-zero and must describe evidence rather than assign an unexplained label.

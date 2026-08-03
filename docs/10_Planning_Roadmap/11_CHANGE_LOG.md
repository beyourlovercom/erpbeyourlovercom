---
type: roadmap-change-log
project: BYL ERP
version: 1.0
---

# Roadmap Change Log

## 2026-07-31 — Planning foundation

Added:

- complete requirements;
- final-compatible MVP1 principle;
- one-month Tencent Docs replacement objective;
- Finance / Accounting order analysis;
- sync naming and providers;
- Product/SEO requirements;
- KOL social enrichment;
- effective-hours baseline;
- Obsidian/Codex operating system.

Current implementation status was not changed.

## 2026-07-31 — 001B merged / 001C prepared

- 001B Authentication / Authorization merged in PR #1 at `5e100cc8e80480ecb6dbd1b38cbfbe9bddba12f4`.
- 001C application shell branch prepared; implementation not started.
- Global Architecture Gate remains FAIL; deployment remains NO.

## 2026-08-01 — 001C merged / 001D prepared

```text
Owner decision: Accepted and merged PR #5; proceed to 001D preparation only.
Previous state: 001C current / implementation active or pending merge.
New state: 001C MERGED / PASS; 001D CURRENT / PREPARED; implementation authorization NO.
Reason: Complete the ordered architecture migration sequence.
Affected modules: Application Shell, Runtime, Audit, Sync.
Affected Work Packages: ERP-ARCH-MIGRATION-001C, ERP-ARCH-MIGRATION-001D.
Estimate impact: No MVP estimate change in this governance package.
Architecture conflict: NONE.
Documents updated: AI/CURRENT_CONTEXT.md; docs/10_Planning_Roadmap/04_LIVING_ROADMAP.md; docs/10_Planning_Roadmap/10_DECISION_REGISTER.md; docs/10_Planning_Roadmap/11_CHANGE_LOG.md; docs/10_Planning_Roadmap/12_WORK_PACKAGE_INDEX.md; docs/60_Codex_Operations/logs/2026-08-01_ERP-ARCH-MIGRATION-001C.md; docs/60_Codex_Operations/work-packages/ERP-ARCH-MIGRATION-001D.md.
```

## 2026-08-01 — 001D scope frozen and split

```text
Owner decision: Freeze 001D into three ordered, independently authorized implementation subpackages.
Previous state: 001D was one prepared Runtime / Audit / Sync package with a preliminary 51-hour execution concept.
New state: 001D remains CURRENT / PREPARED; 001D-A Runtime Foundation is PREPARED / AUTHORIZATION NO; 001D-B Audit Foundation and 001D-C Sync Outbox Foundation are WAITING; execution order is 001D-A → 001D-B → 001D-C.
Reason: Bound implementation, review, Migration authoring and risk into independently executable packages before any implementation authorization.
Affected modules: Runtime, Audit, Sync Outbox.
Affected Work Packages: ERP-ARCH-MIGRATION-001D, ERP-ARCH-MIGRATION-001D-A, ERP-ARCH-MIGRATION-001D-B, ERP-ARCH-MIGRATION-001D-C.
Estimate impact: Replaced the preliminary 51-hour concept with 001D-A 12–18h, 001D-B 9–14h and 001D-C 14–22h; combined planning value 45h and range 35–54h.
Architecture conflict: NONE.
Documents updated: docs/60_Codex_Operations/work-packages/ERP-ARCH-MIGRATION-001D.md; docs/10_Planning_Roadmap/10_DECISION_REGISTER.md; docs/10_Planning_Roadmap/11_CHANGE_LOG.md; docs/10_Planning_Roadmap/12_WORK_PACKAGE_INDEX.md.
```

## 2026-08-01 — Program Roadmap and Roadmap Hours governance

```text
Owner decision: Upgrade LARGE-PROJECT-EXEC-001 to version 1.1 and require complete Program Roadmap and Roadmap Hours reporting.
Previous standard: Version 1.0 required only a current phase map and current-package estimate; final-report template was incomplete.
Identified deficiencies: Program Roadmap and current track were conflated; remaining/total/cumulative estimates lacked mandatory classification; UNKNOWN evidence and final-report actual/variance gates were incomplete.
New state: Version 1.1 requires PROJECT POSITION → ROADMAP HOURS → ESTIMATED CODEX HOURS; Program and track maps are distinct; actual hours and variance are mandatory in final reports.
PR #10 transition requirement: PR #10 remains open and unchanged here; after this governance PR merges it must be rebased/remediated under 1.1, and its unmerged D-021 must become D-022.
Application code: NO CHANGE.
Migration: NO.
Deployment: NO.
Global Architecture Gate: FAIL.
Architecture conflict: NONE.
Documents updated: AI/LARGE_PROJECT_EXECUTION_STANDARD.md; CODEX_ENTRYPOINT.md; docs/60_Codex_Operations/CODEX_TASK_TEMPLATE.md; docs/60_Codex_Operations/CODEX_FINAL_REPORT_TEMPLATE.md; docs/10_Planning_Roadmap/00_COMMAND_CENTER.md; docs/10_Planning_Roadmap/04_LIVING_ROADMAP.md; docs/10_Planning_Roadmap/09_EFFECTIVE_HOURS.md; docs/10_Planning_Roadmap/10_DECISION_REGISTER.md; docs/10_Planning_Roadmap/11_CHANGE_LOG.md; docs/10_Planning_Roadmap/13_PROGRAM_ROADMAP_AND_HOURS.md; docs/60_Codex_Operations/work-packages/ERP-GOVERNANCE-PROGRAM-ROADMAP-HOURS-001.md.
```

## 2026-08-01 — 001D-A merged / 001D-B prepared

```text
Owner decision: Accepted and merged PR #9; prepare 001D-B only.
Decision: D-022 — 001D-A completion and 001D-B preparation.
Previous state: 001D-A implementation complete and pending merge; 001D-B waiting.
New state: 001D-A MERGED / PASS; 001D-B CURRENT / PREPARED / IMPLEMENTATION NOT STARTED; Owner implementation authorization NO.
Reason: Continue the frozen 001D-A → 001D-B → 001D-C execution order.
Affected modules: Runtime, Audit.
Affected Work Packages: ERP-ARCH-MIGRATION-001D; ERP-ARCH-MIGRATION-001D-A; ERP-ARCH-MIGRATION-001D-B.
Estimate impact: 001D-B remains 9–14 effective hours with planning value 12 hours. No overall architecture estimate expansion.
Architecture conflict: NONE.
Global Architecture Gate: FAIL.
Deployment: NO.
Documents updated: AI/CURRENT_CONTEXT.md; docs/10_Planning_Roadmap/04_LIVING_ROADMAP.md; docs/10_Planning_Roadmap/10_DECISION_REGISTER.md; docs/10_Planning_Roadmap/11_CHANGE_LOG.md; docs/10_Planning_Roadmap/12_WORK_PACKAGE_INDEX.md; docs/60_Codex_Operations/work-packages/ERP-ARCH-MIGRATION-001D.md; docs/60_Codex_Operations/work-packages/ERP-ARCH-MIGRATION-001D-A.md; docs/60_Codex_Operations/logs/2026-08-01_ERP-ARCH-MIGRATION-001D-A.md; docs/60_Codex_Operations/work-packages/ERP-ARCH-MIGRATION-001D-B.md.
```

## Change entry format

## 2026-08-01 — 001E preparation remediated / 001E-A Preflight required

```text
Owner decision: Authorize governance-only remediation at Head 3d26f09943daaf3ddf50b69428588027e4440d2e.
Decision: D-025 — 001E-A Preflight gate and browser-artifact protection.
Previous state: 001E-A PREPARED / AUTHORIZATION NO; parent component list and estimates could be read as implementation-ready; authentication artifact upload relied on a future redaction promise.
New state: 001E-A PREFLIGHT REQUIRED / IMPLEMENTATION NOT READY; independent 001E-A package added; parent 40–62h / 51h is planning-only; child estimates require Preflight confirmation; authentication Trace/Video CI upload prohibited until verifiable sanitization exists.
Token source: proposed unique semantic source resources/css/tokens.css; no file created or CSS modified in this governance task.
Exact first-wave components: UiButton, FormField, TextInput, UiAlert and EmptyState; all other parent candidates deferred until an actual consumer Preflight.
Application code: NO CHANGE.
CSS: NO CHANGE.
Dependency and lock files: NO CHANGE.
Playwright: NOT INSTALLED.
Workflow: NO CHANGE.
Migration: NO.
PR: NO.
UAT: NO.
Deployment: NO.
001F: NOT STARTED.
Estimate impact: parent 001E estimate retains 40–62h / 51h as planning-only; 001E-A Preflight is estimated at 2–4h / planning value 3h; child implementation estimate remains pending Preflight.
Architecture conflict: NONE.
Global Architecture Gate: FAIL.
Documents updated: AI/CURRENT_CONTEXT.md; docs/10_Planning_Roadmap/04_LIVING_ROADMAP.md; docs/10_Planning_Roadmap/10_DECISION_REGISTER.md; docs/10_Planning_Roadmap/11_CHANGE_LOG.md; docs/10_Planning_Roadmap/12_WORK_PACKAGE_INDEX.md; docs/10_Planning_Roadmap/13_PROGRAM_ROADMAP_AND_HOURS.md; docs/60_Codex_Operations/work-packages/ERP-ARCH-MIGRATION-001E.md; docs/60_Codex_Operations/work-packages/ERP-ARCH-MIGRATION-001E-A.md; affected 001D closure status footers.
```

## 2026-08-01 — 001D closed / 001E prepared

```text
Owner decision: Accept PR #13 and authorize 001D closure records plus 001E preparation only.
Decision: D-024 — 001D completion and 001E preparation split.
Previous state: 001D-C implementation complete pending independent review; 001D remained in progress; 001E waiting.
New state: 001D-A, 001D-B and 001D-C MERGED / PASS; parent 001D COMPLETE / PASS; 001E NEXT / PREPARED / IMPLEMENTATION NOT STARTED; 001F WAITING.
PR #13: MERGED; accepted Head e4bc57b7a3f0edbe84ccfde63d02d9135a4875e3; main squash SHA 548d53f1eae2ec14eea7261d3a101c1f2fda0dcc.
Reason: Close the completed Runtime / Audit / Sync architecture foundation and freeze the next Shared UI / Browser E2E package before any implementation.
Affected modules: Governance, Runtime, Audit, Sync Outbox, Shared UI planning and Browser E2E planning.
Affected Work Packages: ERP-ARCH-MIGRATION-001D; ERP-ARCH-MIGRATION-001D-C; ERP-ARCH-MIGRATION-001E.
Evidence limits retained: real-MySQL two-connection claim concurrency UNVERIFIED; MySQL 5.7 UNVERIFIED; Dev/Staging/Production Migration NOT RUN.
Dependency impact: NONE; Playwright is proposed only and not installed.
Application code: NO CHANGE.
Workflow change: NO.
Estimate impact: 001D retains its issued 35–54h / 45h planning estimate; prepared 001E is frozen at 40–62h / 51h planning value pending separate authorization.
Architecture conflict: NONE.
Global Architecture Gate: FAIL.
Migration: NO.
UAT: NO.
Deployment: NO.
Documents updated: AI/CURRENT_CONTEXT.md; docs/10_Planning_Roadmap/04_LIVING_ROADMAP.md; docs/10_Planning_Roadmap/10_DECISION_REGISTER.md; docs/10_Planning_Roadmap/11_CHANGE_LOG.md; docs/10_Planning_Roadmap/12_WORK_PACKAGE_INDEX.md; docs/10_Planning_Roadmap/13_PROGRAM_ROADMAP_AND_HOURS.md; docs/60_Codex_Operations/work-packages/ERP-ARCH-MIGRATION-001D.md; docs/60_Codex_Operations/work-packages/ERP-ARCH-MIGRATION-001D-C.md; docs/60_Codex_Operations/work-packages/ERP-ARCH-MIGRATION-001E.md; docs/60_Codex_Operations/logs/2026-08-01_ERP-ARCH-MIGRATION-001D.md.
```

## 2026-08-01 — 001D-B Audit Foundation implemented pending review

```text
Owner decision: Authorize bounded ERP-ARCH-MIGRATION-001D-B implementation, tests, commit, push and a new independent-review PR.
Decision: D-023 — 001D-B Audit Foundation implementation authorization.
Previous state: 001D-B CURRENT / PREPARED / IMPLEMENTATION NOT STARTED; authorization NO.
New state: 001D-B CURRENT / IMPLEMENTATION COMPLETE / PENDING GITHUB INDEPENDENT REVIEW; authorization YES; merge authorization NO.
Reason: Establish the frozen Audit Foundation before 001D-C.
Affected modules: Audit, authentication audit hooks, provisioning audit hook, audit authorization.
Affected Work Packages: ERP-ARCH-MIGRATION-001D-B.
Schema impact: NONE; the existing 13-field audit_records schema is sufficient.
Estimate impact: Issued estimate remains 9–14 effective hours with planning value 12 hours; actual productive effort recorded as 3 hours.
Architecture conflict: NONE.
Global Architecture Gate: FAIL.
Deployment: NO.
Documents updated: AI/CURRENT_CONTEXT.md; docs/10_Planning_Roadmap/04_LIVING_ROADMAP.md; docs/10_Planning_Roadmap/10_DECISION_REGISTER.md; docs/10_Planning_Roadmap/11_CHANGE_LOG.md; docs/10_Planning_Roadmap/12_WORK_PACKAGE_INDEX.md; docs/60_Codex_Operations/work-packages/ERP-ARCH-MIGRATION-001D-B.md; docs/60_Codex_Operations/logs/2026-08-01_ERP-ARCH-MIGRATION-001D-B.md.
```

```text
Date:
Owner decision:
Previous state:
New state:
Reason:
Affected modules:
Affected Work Packages:
Estimate impact:
Architecture conflict:
Documents updated:
```

## 2026-08-03 — 001E-A and supporting packages closed / 001E-B Preflight frozen

```text
Owner decision: Close the accepted 001E-A, Local Admin Bypass, Account Dock and Menu Baseline packages; authorize 001E-B Preflight and one Draft governance PR only.
Decision: D-026 — 001E-A closure and 001E-B Browser E2E Preflight.
Previous state: 001E-A implementation/remediation awaited final merged governance status; 001E-B was WAITING.
New state: 001E-A, ERP-LOCAL-ADMIN-BYPASS-001, ERP-SHELL-ACCOUNT-DOCK-001 and ERP-MENU-BASELINE-001 are MERGED / PASS; 001E-B is PREFLIGHT COMPLETE / PENDING INDEPENDENT REVIEW / IMPLEMENTATION NOT AUTHORIZED.
Accepted evidence: PR #17 Head e46fb54a1b41a1efa620b4af3646e104ff7bcbbc → main 442a3a872628764fecb7209049d2daf752624e9a; PR #16 Head 6b63229b7d776104a5736334de639d9b20fb69f3 → main 55ba7320a7046deb04a03abd99053ccd7b7ed11e; PR #15 Head 0cac340443d47591b7e242ebe051fb4407d0a986 → main 32d7244b9b1c6d1fb0cf8863cb9af694e7acc10f with Owner Visual Acceptance PASS; PR #14 Head 6d5595e879778895105215dc98b1f740521b6f1a → main 493f32ce7bf1be4368a7e9f97de9ecf9f9c9c9d8.
001E-B proposal: exact @playwright/test 1.62.1; first-wave Chromium only; repository-external runtime directory; disposable SQLite; synthetic users; no authentication Trace/Video uploads and no initial E2E artifact uploads.
Owner gates: dependency, package-lock, browser install, Workflow, disposable E2E Migration execution and implementation are REQUIRED / NOT GRANTED.
Application code: NO CHANGE.
Menu impact: NO; approved baseline check PASS.
Dependency / lock / browser install: NO CHANGE / NOT RUN.
Workflow: NO CHANGE.
Migration / database: NO / NO CHANGE.
UAT / deployment: NO / NO.
001E-C / 001F: WAITING / WAITING.
Estimate impact: 001E-A is completed; known 001E remaining becomes the non-overlapping 001E-B/C parent allocation of 24–38h, planning value 31h. 001E-B confirms 12–18h, planning value 15h; 001F remains unknown.
Architecture conflict: NONE.
Global Architecture Gate: FAIL.
Documents updated: AI/CURRENT_CONTEXT.md; Roadmap, Decision Register, Change Log, Work Package Index, Program Roadmap and Hours; parent/child Work Packages and accepted Work Logs.
```

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

```

## 2026-08-03 — 001E closed and 001F-A gate scope frozen

```text
Owner decision: Correct merged 001E closure status and freeze 001F-A gate scope under one bounded remediation.
Review result: CHANGES_REQUIRED; PR #23 remediation.
Previous state: 001E-B/C current summaries conflicted with merged Git state; 001F hours and gate classifications were inconsistent.
New state: 001E-B/C MERGED / PASS / COMPLETE / CLOSED; 001F-A IMPLEMENTED / PENDING INDEPENDENT REVIEW; Global Architecture Gate remains FAIL.
Reason: Align current governance with accepted PR #21/#22 evidence without rewriting historical records.
Affected modules: Governance and Architecture Migration status only.
Affected Work Packages: ERP-ARCH-MIGRATION-001E-B; ERP-ARCH-MIGRATION-001E-C; ERP-ARCH-MIGRATION-001F-A governance scope.
Estimate impact: 001F-B through 001F-F remains 12–24h, planning value 18h; actual hours UNKNOWN WITH EVIDENCE; no completion-time guarantee.
Architecture conflict: NONE.
Application code: NO CHANGE.
Dependency / package-lock / Workflow / Migration / database: NO CHANGE.
UAT: NO.
Deployment: NO.
Global Architecture Gate: FAIL.
Documents updated for PR #23 scope: AI/CURRENT_CONTEXT.md; docs/10_Planning_Roadmap/04_LIVING_ROADMAP.md; docs/10_Planning_Roadmap/10_DECISION_REGISTER.md; docs/10_Planning_Roadmap/11_CHANGE_LOG.md; docs/10_Planning_Roadmap/12_WORK_PACKAGE_INDEX.md; docs/10_Planning_Roadmap/13_PROGRAM_ROADMAP_AND_HOURS.md; docs/60_Codex_Operations/work-packages/ERP-ARCH-MIGRATION-001E-B.md; docs/60_Codex_Operations/logs/2026-08-03_ERP-ARCH-MIGRATION-001E-B.md; docs/60_Codex_Operations/work-packages/ERP-ARCH-MIGRATION-001E-C.md; docs/60_Codex_Operations/logs/2026-08-03_ERP-ARCH-MIGRATION-001E-C.md. This remediation changes only the eight files authorized above; no standards, templates or Command Center files.
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

## 2026-08-05 — MVP1 Order Core implementation merged / closure in progress

```text
Implementation PR: #59 merged after independent exact-head review PASS at 3956f206e63dc77142f0d353f2ad3cede3816a19; main squash 5ce98ad34b4adac0e931e348bfce0961e3d4bfd6.
Scope: Orders single truth, address snapshots, items, controlled sources/statuses, correction, bounded Logistics shipment transition, Manual Sync queue, order-level Finance analysis, local synthetic UAT seeder and related tests.
Validation: focused workflow 4/56 PASS; Architecture 16/201 PASS; full PHPUnit 156/1567 PASS with 2 intentional isolated-runtime skips and 1 pre-existing risky test; Vitest 71 PASS; typechecks/build PASS; Browser E2E 16/16 PASS with disposable SQLite cleanup PASS; local MySQL 8.4.11 migration/seed/backup/restore PASS; local Redis 8.10 PASS.
Local UAT evidence: byl_erp_local_uat on 127.0.0.1:13306; backup SHA-256 e4e998f7f8b2fa1b5ee9510b5c79bc6cef98aa0617579f8bffa8e00282445593; UAT access remains local-only and human UAT is NOT STARTED.
Current state: MVP1-ORDER-CORE-001 COMPLETE / PASS / MERGED; post-merge closure PR #60 merged at fb58cb14c3aa2c998abc1a4d4b2aab2e3634db26; MVP1-FULFILLMENT-001 current.
MENU_IMPACT: NO. APPROVED_MENU_CHECK: PASS. Remote Dev: NO. Production: NO. SSH: NO. Deployment: NO.
Actual effective hours: 4.5h against issued 13h planning value; variance -8.5h / -65.38% because restored implementation reduced new implementation scope and remediation remained bounded.
```

## 2026-08-05 — MVP1 Order Core post-merge closure

```text
Closure PR: #60; squash merge: fb58cb14c3aa2c998abc1a4d4b2aab2e3634db26; main synchronized and verified equal to origin/main.
Closure validation: merged documentation records preserve implementation, local MySQL 8.4.11, backup/restore, Redis, test and Browser E2E evidence; no new code or migration was introduced.
Current state: MVP1-ORDER-CORE-001 COMPLETE / PASS / CLOSED; MVP1-FULFILLMENT-001 current under continuous Owner authorization.
MENU_IMPACT: NO. APPROVED_MENU_CHECK: PASS. Remote Dev: NO. Production: NO. SSH: NO. Human UAT: NOT STARTED.
```

## 2026-08-05 — 001F-E and 001F-F architecture closure

```text
Owner decision: Continuous authorization BYL-ERP-001F-TO-MVP1-LOCAL-UAT-20260805 permits local E completion, final F audit, governance closure and MVP1 preflight within the stated prohibitions.
New state: 001F-E COMPLETE / PASS / CLOSED through PR #47; 001F-F implementation COMPLETE / PASS / MERGED through PR #49; final closure is this package; Global Architecture Gate becomes PASS after this closure merges; MVP1 becomes AUTHORIZED / PREFLIGHT / 0%.
Evidence: local MySQL 8.4.11 migration, backup/restore, local Redis 8.10, full regression, menu/route/permission checks, secret scan and Browser E2E 16/16 with 0 external requests.
Architecture conflict: NONE WITH EVIDENCE.
Remote Dev access: NO. Production access: NO. SSH: NO. Human UAT: NOT STARTED.
Application behavior: no change in this closure package.
Migration: no new migration execution in F; E local validation remains local-only.
```

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

## 2026-08-03 — 001F-B1 module contract frozen

```text
Owner decision: Approve RECOMMEND_OPTION_A1 and freeze the v1.1 physical module contract.
Decision: D-031 — Adopt A1 Physical Module Contract for Architecture Standard v1.1.
Previous state: 001F-B was reviewed but lacked an executable physical module contract.
HISTORICAL PRE-MERGE STATE: New state: 001F-B COMPLETE / REVIEWED / OPTION A1 APPROVED; 001F-B1 CURRENT / IMPLEMENTED / PENDING INDEPENDENT REVIEW; B2-B5 PROPOSED / NOT AUTHORIZED.
Standard: BYL-ERP-ARCH-001 v1.1 effective 2026-08-03; v1.0 did not explicitly require app/Modules/**.
Migration policy: historical database/migrations remain in place; no application or directory migration in B1.
Estimate impact: B2 1–2 Codex / 4–8h; B3 1–2 / 5–10h; B4 1–2 / 6–12h; B5 1–2 / 5–10h; combined 4–8 Codex / 20–40h; planning value 30h; actual hours UNKNOWN WITH EVIDENCE.
Global Architecture Gate: FAIL. MVP1: NOT STARTED / 0%. UAT: NO. Deployment: NO.
Documents updated: exactly 7 existing authorized Markdown files and 2 new B1 Markdown files; no application, dependency, namespace, provider, route, migration, database, test or workflow changes.
```

## 2026-08-04 — 001F-B1 post-merge closure

```text
Owner decision: Close 001F-B1 after substantive review and remediate authority-file status consistency.
Decision: D-031 remains unchanged; no new D-XXX required.
HISTORICAL PRE-MERGE STATE: PR #24 merged B1 contract into main, but 001F-B1 was still recorded as CURRENT / IMPLEMENTED / PENDING INDEPENDENT REVIEW across Current Context, Living Roadmap, Work Package Index, Program Roadmap and Hours, and the B1 Work Package. PR #25 opened as Draft closure.
New state: 001F-B1 COMPLETE / PASS / CLOSED; PR #24 accepted Head f26b510b499ffef95e1723eb19d12bac84c93718; main squash b3ac6268488b9ea96ceebaf7e7dcb23e8b5f2c9f; closure PR #25.
001F-B2 Module Skeleton & Automated Boundary Enforcement: PREFLIGHT COMPLETE / IMPLEMENTATION NOT STARTED.
001F-B3 through 001F-B5 and 001F-C through 001F-F: PROPOSED / NOT AUTHORIZED.
Reason: Unify authoritative status records after merge; transition to B2 formalization.
Review status: INDEPENDENT_REVIEW: UNAVAILABLE / REVIEWER RETURNED EMPTY TASK_RESULT; SUBSTANTIVE_REVIEW: KIMI ORCHESTRATOR DETERMINISTIC GIT AND DOCUMENT REVIEW COMPLETED; SUBSTANTIVE_REVIEW_RESULT: PASS_WITH_NON_BLOCKING_FINDINGS; GITHUB_INDEPENDENT_REVIEW: CHANGES REQUIRED IDENTIFIED AND REMEDIATED IN PR #25; OWNER_CLOSURE_DECISION: GRANTED.
Owner decision basis: Owner explicitly authorized Codex Luna to complete B1 closure after reviewing the available substantive evidence and the reviewer-unavailable condition.
Application code: NO CHANGE.
Dependency / package-lock / Workflow / Migration / database: NO CHANGE.
UAT: NO.
Deployment: NO.
Global Architecture Gate: FAIL.
MVP1: NOT STARTED / 0%.
Documents updated: AI/CURRENT_CONTEXT.md; docs/10_Planning_Roadmap/04_LIVING_ROADMAP.md; docs/10_Planning_Roadmap/11_CHANGE_LOG.md; docs/10_Planning_Roadmap/12_WORK_PACKAGE_INDEX.md; docs/10_Planning_Roadmap/13_PROGRAM_ROADMAP_AND_HOURS.md; docs/60_Codex_Operations/work-packages/ERP-ARCH-MIGRATION-001F-B1.md; docs/60_Codex_Operations/logs/2026-08-03_ERP-ARCH-MIGRATION-001F-B1.md.
```

## 2026-08-04 — 001F-B2 Work Package formalization

```text
HISTORICAL PRE-MERGE STATE: Owner authorization: Formalize the completed B2 read-only Preflight into a docs-only Work Package and Draft PR. B2 application implementation remains unauthorized.
Authorized base: origin/main@9ed96acdd2e78ba49c5f57f469e4046fb2074cab; B1 closure PR #25 is MERGED / COMPLETE / PASS / CLOSED.
Decision: D-031 remains unchanged; no new D-XXX required.
Source: Codex completed read-only B2 Preflight; no full Preflight repeat was performed.
Recommendation: RECOMMEND_B2_S2.
HISTORICAL PRE-MERGE STATE: New state: 001F-B2 PREFLIGHT COMPLETE / WORK PACKAGE FORMALIZED / IMPLEMENTATION NOT STARTED / IMPLEMENTATION NOT AUTHORIZED; status was FORMALIZED / PENDING GITHUB REVIEW.
Contract: empty-directory-free skeleton; no app/Modules, app/Shared or candidate module directories in formalization; explicit static Provider manifest; App → Modules → Fortify order; fail-fast missing Provider behavior; existing AppServiceProvider duties remain unchanged.
Application code: NO CHANGE.
Provider, test, dependency, route, permission, menu, migration, database, frontend and workflow changes: NO CHANGE.
Global Architecture Gate: FAIL.
MVP1: NOT STARTED / 0%.
Documents added: docs/60_Codex_Operations/work-packages/ERP-ARCH-MIGRATION-001F-B2.md; docs/60_Codex_Operations/logs/2026-08-04_ERP-ARCH-MIGRATION-001F-B2.md.
Documents updated: AI/CURRENT_CONTEXT.md; docs/10_Planning_Roadmap/04_LIVING_ROADMAP.md; docs/10_Planning_Roadmap/11_CHANGE_LOG.md; docs/10_Planning_Roadmap/12_WORK_PACKAGE_INDEX.md; docs/10_Planning_Roadmap/13_PROGRAM_ROADMAP_AND_HOURS.md.
```

HISTORICAL PRE-MERGE STATE — Target state after PR #26: B2_WORK_PACKAGE_PR: #26; B2_WORK_PACKAGE_STATUS: FORMALIZED / APPROVED / IMPLEMENTATION NOT STARTED; B2_IMPLEMENTATION_AUTHORIZATION: NOT GRANTED; CURRENT_PHASE: 001F-B2 IMPLEMENTATION AWAITING OWNER AUTHORIZATION; GITHUB_STATE: PR #25 MERGED; PR #26 B2 WORK PACKAGE PR; NEXT_DEPENDENCY: Separate Owner authorization for ERP-ARCH-MIGRATION-001F-B2 implementation. PR #26 Draft and pending-review wording above is historical pre-merge state only. At that historical stage, no B2 implementation code was authorized.
## 2026-08-04 — 001F-B2 Implementation Merge and Post-Merge Closure

Owner authorized PR #27 Ready and Squash Merge after final acceptance review PASS at `fe6512f655944e7668c1786899f06880af049f29`.

```text
B2_WORK_PACKAGE_PR: #26
B2_IMPLEMENTATION_PR: #27
B2_ACCEPTED_HEAD: fe6512f655944e7668c1786899f06880af049f29
B2_MAIN_SQUASH_COMMIT: 6df005f8e0e59c877d95fcf41fb5c507c507378f
B2_MERGED_AT: 2026-08-04T13:24:22Z
FOCUSED_ARCHITECTURE_TESTS: 16 tests / 171 assertions / PASS
BASELINE_FULL_PHPUNIT: 134 tests / 998 assertions / 28 failures / 1 error
B2_FULL_PHPUNIT: 150 tests / 1169 assertions / 28 failures / 1 error
NEW_FAILURES: 0
NEW_ERRORS: 0
FULL_SUITE_NO_NEW_REGRESSION: PASS
SOURCE_BRANCH_DELETED: NO
UAT: NO
DEPLOYMENT: NO
POST_MERGE_CLOSURE: PR #28 MERGED / PASS / CLOSED
POST_MERGE_CLOSURE_SQUASH: b2bc2d6e25699bc93b6ef352bc20f593c1cacd56
```

B2 implementation and docs-only closure are `COMPLETE / PASS / CLOSED`; B3 is the next active package under continuous authorization. B4 through B5 and 001F-C through 001F-F remain not started.

## 2026-08-05 — 001F-B2 closed / 001F-B3 activated under continuous authorization

```text
Owner continuous authorization: ERP-ARCH-MIGRATION-001F-CONTINUOUS-20260805.
New state: 001F-B2 COMPLETE / PASS / CLOSED after PR #28 merged; closure main SHA b2bc2d6e25699bc93b6ef352bc20f593c1cacd56.
Current state: 001F-B3 Identity & Access Boundary Migration PREFLIGHT COMPLETE / WORK PACKAGE FORMALIZED / IMPLEMENTATION AUTHORIZED.
Evidence: B3 read-only audit covered Identity models, Fortify, Gate, local bypass, auth routes/controller, provisioning command, Audit boundary, Provider manifest, architecture tests, auth tests and approved menu baseline.
Baseline: Architecture 16 tests / 171 assertions / PASS; auth-focused baseline 29 tests / 157 assertions / 4 failures / 1 error, retained for comparison.
Menu impact: NO; approved baseline check PASS.
Global Architecture Gate: FAIL. MVP1: NOT STARTED / 0%. UAT: NO. Staging Migration: NO. Production Migration: NO. Production Deployment: NO.
Documents updated: AI/CURRENT_CONTEXT.md; Living Roadmap; Change Log; Work Package Index; Program Roadmap and Hours; B2 Work Package; B2 Work Log; new B3 Work Package and Work Log.
```

## 2026-08-05 — 001F-B3 implementation merge / closure in progress

```text
Owner continuous authorization: ERP-ARCH-MIGRATION-001F-CONTINUOUS-20260805.
B3 Work Package PR: #29; main squash 6ad69f0fdac9a2bd2a65c9ac220ff022c062a0ed.
B3 Implementation PR: #30; accepted Head 90b18665e5f797eb2b8f0cd91d3b3baeb8073595; main squash 906fea71b1d598de3b38ae89b34a33142cec0982.
Final independent review: PASS at 90b18665e5f797eb2b8f0cd91d3b3baeb8073595.
Validation: Architecture 16/172 PASS; B3 focused 55/317 PASS; full PHPUnit 150/1494 PASS with ERP_LOCAL_ADMIN_BYPASS=false; Vitest 71 PASS; application and E2E typechecks PASS; build PASS; safety contracts 15 PASS; disposable Browser E2E 16 PASS with zero external requests and cleanup PASS; route/menu/diff/fsck checks PASS.
Scope: Identity canonical boundary implemented; compatibility aliases/wrappers retained; no migrations or menu changes; Orders and Logistics not created; MVP1 NOT STARTED / 0%; Global Architecture Gate FAIL; UAT NO; Staging Migration NO; Production Migration NO; Production Deployment NO.
Current state: 001F-B3 IMPLEMENTATION COMPLETE / PASS / MERGED / POST-MERGE CLOSURE IN PROGRESS. B4 is next and is preparing read-only Preflight.
Closure PR: pending creation.
```

## 2026-08-05 — 001F-B3 closed / 001F-B4 activated under continuous authorization

```text
001F-B3 Closure PR: #31 merged; main closure SHA 0af914ebf8fc9f94b18e63e008fd78739bba6811.
Current state: 001F-B3 COMPLETE / PASS / CLOSED.
New state: 001F-B4 Runtime / Audit / Sync Boundary Migration PREFLIGHT COMPLETE / WORK PACKAGE FORMALIZED / IMPLEMENTATION AUTHORIZED.
B4 preflight evidence: Runtime, Audit and Sync ownership audit; provider, route, migration, table, wrapper and compatibility review; menu baseline read and PASS.
B4 estimate: 6–12h; planning value 9h. Known B4–B5 remaining: 11–22h; planning value 16.5h.
Global Architecture Gate: FAIL. MVP1: NOT STARTED / 0%. UAT: NO. Staging Migration: NO. Production Migration: NO. Production Deployment: NO.
Documents added: B4 Work Package and Work Log. Documents updated: Current Context, Living Roadmap, Work Package Index, Program Roadmap and Hours, Change Log.
```

## 2026-08-05 — 001F-B4 implementation merged / post-merge closure opened

```text
Implementation PR: #33 merged after final independent review PASS at 403152de84b85ead62d48d2a9c2135e759f007e1.
Main squash: ee9908c57cabbe4ac93b3628d28154601d96a4c1.
Scope: canonical Runtime, Audit and Sync boundaries with compatibility wrappers and deterministic providers; no menu or migration change.
Validation: Architecture 16/175 PASS; focused 88/755 PASS; full PHPUnit 150/1497 PASS; Vitest 71 PASS; typechecks/build PASS; Browser E2E 16 PASS with disposable external SQLite cleanup PASS.
Current state: B4 IMPLEMENTATION COMPLETE / PASS / MERGED / POST-MERGE CLOSURE IN PROGRESS; B5 remains next read-only Preflight.
Global Architecture Gate: FAIL. MVP1: NOT STARTED / 0%. UAT: NO. Deployment: NO.
```

## 2026-08-05 — 001F-B4 closed / 001F-B5 preflight formalized

```text
B4 Closure PR: #34 merged; main closure SHA 26e22150f4f729aaf321a71e052a3602df9aec61.
Current state: B4 COMPLETE / PASS / CLOSED.
New state: B5 Shell / Dashboard Boundary & Compatibility Cleanup PREFLIGHT COMPLETE / WORK PACKAGE FORMALIZED / IMPLEMENTATION AUTHORIZED.
B5 preflight: app-level Shell/Dashboard controllers, navigation helpers, route closures and legacy prototype paths audited; approved menu baseline read and unchanged.
No B5 implementation, migration, database operation, UAT or deployment occurs in this Work Package.
Global Architecture Gate: FAIL. MVP1: NOT STARTED / 0%.
```

## 2026-08-05 — 001F-B5 implementation merged / post-merge closure opened

```text
Work Package PR: #35 merged; main squash 330f23ecc145d6205b786edfbfb57b5979ac074a.
Implementation PR: #36 merged after final independent review PASS at 010b62d8601843d406acc322a93a4a7f4e290bd9.
Main squash: 1e876120285e988a46123742928c4307bcde934b.
Scope: canonical Shell and Dashboard modules with compatibility aliases and module-owned routes; route, permission, approved menu and legacy prototype contracts preserved.
Validation: Architecture 16/177 PASS; route/menu 23/484 PASS; full PHPUnit 150/1499 PASS; Vitest 71 PASS; typechecks/build PASS; Browser E2E 16 PASS with external disposable SQLite cleanup PASS.
Current state: B5 IMPLEMENTATION COMPLETE / PASS / MERGED / POST-MERGE CLOSURE IN PROGRESS; 001F-C is next after closure.
Global Architecture Gate: FAIL. MVP1: NOT STARTED / 0%. Migration: no file change or real database operation. UAT: NO. Deployment: NO.
```

## 2026-08-05 — 001F-B5 closed / 001F-C preflight formalized

```text
B5 Closure PR: #37 merged; main closure SHA a5035cbe0e8d4aed0bf1683c1081b4417f8936be.
Current state: B5 COMPLETE / PASS / CLOSED.
New state: C MySQL Two-Connection Concurrency Validation PREFLIGHT COMPLETE / WORK PACKAGE FORMALIZED / IMPLEMENTATION AUTHORIZED.
C preflight: existing Sync claim state machine and historical isolated MySQL 8.4.11 schema evidence reviewed. MySQL Community 8.4.11 is available keg-only; existing local MariaDB and all shared targets are explicitly excluded.
No C implementation, Migration execution, database operation, UAT or deployment occurs in this Work Package.
Global Architecture Gate: FAIL. MVP1: NOT STARTED / 0%.
```

## 2026-08-05 — 001F-C implementation merged / post-merge closure opened

```text
Work Package PR: #38 merged; main squash 1cd76e0f178c3a95e71bb48d4762a198858b5524.
Implementation PR: #39 merged after final independent review PASS at 8a11ae851743db5c3d9835ce2be2ddfb6036e0f9.
Main squash: f939c15f9e84adcfa45aefce28a09e1026f00dca.
Validation: isolated MySQL 8.4.11 two-connection claim concurrency 1/20 PASS; full PHPUnit 150 passed plus one intentional isolated-environment skip; Vitest 71 PASS; typechecks/build and Browser E2E 16 PASS.
Current state: C IMPLEMENTATION COMPLETE / PASS / MERGED / POST-MERGE CLOSURE IN PROGRESS; D is next after closure.
Global Architecture Gate: FAIL. No migration file, real database, menu, UAT or deployment change.
```

## 2026-08-05 — 001F-C closed / 001F-D preflight formalized

```text
C Closure PR: #40 merged; main closure SHA 63d1d2d3923d531b032d092eeb87d792f82eabaa.
Current state: C COMPLETE / PASS / CLOSED.
New state: D Redis / Queue / Scheduler Runtime Validation PREFLIGHT COMPLETE / WORK PACKAGE FORMALIZED / IMPLEMENTATION AUTHORIZED.
D preflight: existing Runtime health and sole bounded scheduler event reviewed; Redis 8.10 installed locally but not started as a service. D confines validation to a disposable external Redis instance and deterministic Runtime-only behavior.
Global Architecture Gate: FAIL. MVP1: NOT STARTED / 0%. No Migration, database, UAT or deployment action occurs in this Work Package.
```

## 2026-08-05 — 001F-D implementation merged / post-merge closure opened

```text
Work Package PR: #41 merged; main squash e760e032cb6854e086bc0189bc76f0134266deba.
Implementation PR: #42 merged after final independent review PASS at 35bf66da9e98455074fc24ba5f9cd4081cdb9f81.
Main squash: 6fbd4b751a057ab2060a3f191df7ddf2dc0ab5a7.
Validation: isolated Redis 8.10 cache, health, real queue worker, deterministic retry and existing scheduler event PASS; full PHPUnit 150 passed plus two isolated-environment skips; Vitest 71 PASS; typechecks/build and Browser E2E 16 PASS.
Current state: D IMPLEMENTATION COMPLETE / PASS / MERGED / POST-MERGE CLOSURE IN PROGRESS; E is next after closure.
Global Architecture Gate: FAIL. No migration, real database, menu, UAT or deployment change.
```

## 2026-08-05 — 001F-E local environment scope remediation

```text
Current environment contract: LOCAL http://erp.b at /Users/a/AI/007/erpbeyourlovercom; REMOTE_DEV https://deverp.beyourlover.com; PRODUCTION https://erp.beyourlover.com.
001F-E is Local Development Migration Validation only. Remote server access, remote deployment, remote database access, remote migration, Staging, UAT and Production remain excluded.
HISTORICAL SAFETY RECORD: the earlier fail-closed result was correct under an incorrect remote-Dev assumption; no remote connection, remote database access, remote migration or Production access was attempted.
Current state: E scope remediation in progress; local identity and backup/reconstructability gates remain mandatory before any local database mutation.
Global Architecture Gate: FAIL. MVP1: NOT STARTED / 0%.
```

## 2026-08-07 — Parallel Work Package / Worktree / Delivery PR governance

```text
Owner decision: Remediate PR #79 into the final BYL ERP parallel-development model.
Decision: D-033 — Parallel Work Package / Worktree / Delivery PR governance.
Previous state: PR #79 proposed the four parallel rules but `ONE_WORK_PACKAGE_ONE_BRANCH_ONE_PR` was unqualified against the repository's historical Work Package PR → Implementation PR → Closure PR topology; lifecycle and post-merge closure rules were incomplete; the governance PR lacked its own formal Work Package/evidence record and canonical decision/change entries.
New state after remediation: `PARALLEL-WORK-EXEC-001` v1.1 explicitly allows multiple active Work Packages, PRs, OpenCode sessions and dedicated worktrees when Parallelization Preflight passes. Newly activated packages after effective merge use one Work Package → one branch → one Draft delivery PR across contract, implementation, validation, remediation and final pre-merge evidence. Historical merged packages remain valid; legacy active packages with an issued multi-PR contract may finish under that contract.
Post-merge rule: the original Work Package terminates its repository mutation at merge; no second Closure PR is created under the same new Work Package identity merely to record the merge SHA. Genuine post-merge repository mutation requires a separately identified Work Package with its own branch/worktree/PR.
Parallel page work: independent Dashboard / Orders / Settings-style page repairs may run concurrently through separate OpenCode sessions and dedicated worktrees when scopes/dependencies are bounded. Shared App Shell, navigation, design tokens, shared components, common routes/permissions/schema/test infrastructure must be extracted first unless a declared stacked dependency is explicitly authorized.
Mandatory rules retained: ONE_WORK_PACKAGE_ONE_BRANCH_ONE_PR; ONE_ACTIVE_AGENT_ONE_WORKTREE; PARALLEL_PR_MUST_NOT_HAVE_UNDECLARED_DEPENDENCIES; COMMON_FOUNDATION_CHANGES_MUST_BE_EXTRACTED_BEFORE_PAGE-LEVEL_PARALLELIZATION.
Work Package: ERP-GOV-PARALLEL-001.
PR: #79 remains Draft pending exact-head remediation re-review; merge authorization NO.
Application code: NO CHANGE. Schema/Migration: NO CHANGE / NOT RUN. Dependency/lock: NO CHANGE. Remote Dev/Production: NOT ACCESSED. UAT: NO. Deployment: NO.
Architecture conflict: NONE. Global Architecture Gate remains PASS per current repository context.
Documents updated/added within bounded governance scope: AGENTS.md; CODEX_ENTRYPOINT.md; AI/PARALLEL_WORK_EXECUTION_STANDARD.md; AI/REPOSITORY_MANIFEST.md; Decision Register; Change Log; ERP-GOV-PARALLEL-001 Work Package; ERP-GOV-PARALLEL-001 Work Log.
```

## 2026-08-23 — Local baseline accepted and ERP-INQUIRY-M1 promoted to current milestone

```text
Owner decision: approve PR #82 Local Baseline Audit and proceed with Roadmap revision.
Baseline merge: PR #82 → main faa45f9620211f3c398dde7f30c1a886313ced14.
Previous program sequence: MVP1 → WooCommerce inbound → tracking outbound → sync orchestration → Customer / Lead Pilot.
New program sequence: completed Architecture → existing MVP1 operations with 5 Human UAT findings still open → ERP-INQUIRY-M1 current → local milestone UAT → milestone-gated erp.beyourlover.com release → WooCommerce / tracking / sync and later expansion.
Business reason: the immediate operational requirement is automatic Email / WhatsApp inquiry capture and a daily waiting-to-handle view, not WooCommerce-first expansion.
ERP-INQUIRY-M1 children planned: M1.0 Contract; M1.1 Company; M1.2 Contact; M1.3 Case; M1.4 Inbound Message; M1.5 Inquiry Inbox; M1.6 Email Intake; M1.7 WhatsApp Intake; M1.8 Yesterday/Waiting Dashboard; M1.9 Local Milestone UAT.
Execution model: GPT Web + Owner design/diagnosis; US Claude implementation only; China Codex test/evidence only; GitHub unique source; China http://erp.b primary local runtime/test; erp.beyourlover.com milestone-only remote target.
Existing ERP is retained; no Jutdo fork/Filament rebuild. Jutdo remains reference/reusable foundation only.
MVP1 Human UAT: 5 migrated findings remain OPEN; this change does not close them.
Estimate impact: ERP-INQUIRY-M1 estimate UNKNOWN WITH EVIDENCE until M1.0 contracts are frozen. Program remaining remains UNKNOWN WITH EVIDENCE.
Application code: NO CHANGE. Schema/Migration: NO CHANGE / NOT RUN. External channel connection: NO. Remote deployment: NO. Production access: NO.
Decision: D-034.
```

## 2026-08-23 — Execution-role governance alignment after Inquiry roadmap merge

```text
Owner decision basis: D-034 operating model and explicit approval of PR #83.
Roadmap merge: PR #83 → main c5fb481b5e8c97eca709b12371b644013eee46ec.
Audit finding: current AGENTS / CODEX_ENTRYPOINT / Large Project / Parallel Work standards still contained forward-looking Codex/OpenCode implementation assumptions and an embedded pre-Inquiry Program Roadmap.
New governance package: ERP-GOV-EXECUTION-ROLE-ALIGNMENT-001.
New authority overlay: AI/EXECUTION_ROLE_MODEL.md.
New implementation entrypoint: CLAUDE_ENTRYPOINT.md.
Codex entrypoint: converted to China-local test / verification / evidence only.
Claude: implementation only; no architecture, diagnosis, root-cause or next-Work-Package authority.
GPT Web + Owner: architecture, design, diagnosis and next-step authority.
GitHub: unique source of truth and machine-to-machine code synchronization path.
China local runtime: http://erp.b.
Remote target: https://erp.beyourlover.com remains milestone-gated only.
Product code / Migration / external channels / deployment: NO CHANGE.
Decision: D-035.
```

## 2026-08-23 — ERP-INQUIRY-M1.0 Owner acceptance / canonical sync

Owner approved all six ERP-INQUIRY-M1.0 gates. PR #85 merged to `main` as `02ce87dba5ede14d791b646b7cbe976a12064cae`.

Canonical sources are synchronized from `OWNER REVIEW REQUIRED / estimate unknown` to `OWNER APPROVED / FROZEN`, with ERP-INQUIRY-M1 implementation estimate `86–150h`, planning value `116h`, and M1.1 Company `6–10h`, planning value `8h`.

No product code, Migration, database data, dependencies, Email/WhatsApp connection or remote environment is changed. The next product Work Package is M1.1 Company and remains implementation-unauthorized until separate Owner approval.

## 2026-08-24 — Canonical Order / Finance / KOL contract frozen; Unified Order Fulfillment reprioritized

```text
Owner decision: Approve the canonical Order / Finance / KOL business semantics and prioritize Unified Manual Order → Logistics before ERP-INQUIRY-M1.2 Contact.
Decision: D-037 — Freeze canonical Order / Finance / KOL data contract and reprioritize Unified Order Fulfillment before Inquiry M1.2.
Previous state: ERP-INQUIRY-M1.1 Company was complete/merged and M1.2 Contact was the default next slice; MVP1 Order/Fulfillment foundations existed but four Human UAT findings remained open and customer/KOL order semantics were not yet normalized.
New state: ERP-ORDER-FULFILLMENT-M1-001 is CURRENT / CONTRACT FORMALIZED / IMPLEMENTATION NOT AUTHORIZED on branch feature/erp-order-fulfillment-m1-001 from main@6ac25f2168dea7e8cd9e8f4c49c10752642a44df. ERP-INQUIRY-M1.2 Contact is PAUSED / NOT CANCELLED and resumes after the Order/Fulfillment Owner gate unless Owner reprioritizes again.
Frozen contract: one canonical orders.id; external_order_number is the primary user-visible business order number; internal_order_number is generated/global-unique/immutable/hidden by default; order_kind=customer|kol_fulfillment independent from source; Affiliate-attributed consumer sales remain customer Orders plus attribution; KOL fulfillment means products/samples/gifts/replacements sent to a KOL.
Finance: same canonical Order; base currency USD; shipping revenue vs shipping cost and tax collected vs tax cost/liability remain separate; Order-level and future Item-level profitability supported; real item facts win and shared costs default to sales-value proportion when stronger allocation evidence is absent.
AffiliateWP boundary: AffiliateWP remains Affiliate/Referral/Payout source of truth. ERP does not clone Visits/Conversion/aggregate Earnings/full Referral-Payout administration; ERP stores only minimum KOL identity binding, Order attribution and commission facts needed for Finance/analytics. pending=projected, unpaid=recognized/payable, paid=recognized+actually paid for V1, rejected=zero recognized cost; unpaid→paid cannot double-count expense.
Shipment: V1 remains 1 Order : 1 Shipment. Future split shipments may widen to 1:N without replacing Order truth.
Human UAT targets: MVP1-UAT-DEFECT-002, DEFECT-003, DEFECT-004 and FINDING-005 require exact revalidation; none is closed by this documentation change.
Verification: schema semantics + authorization/data scope classify the package as Tier B: US full verification + China exact-SHA full independent verification + Owner Local UAT before merge.
Estimate impact: ERP-ORDER-FULFILLMENT-M1 exact implementation estimate is UNKNOWN WITH EVIDENCE until the US implementation preflight confirms legacy source/backfill, external-number duplicate state, indexes and permission/data-scope surfaces. ERP-INQUIRY-M1 approved 86–150h / 116h milestone range is retained; M1.1 is completed and M1.2–M1.9 remain planned.
Architecture conflict: D-034 uninterrupted Inquiry execution order is intentionally superseded only for current priority; the frozen Inquiry M1.0 contract remains valid. No second Order truth is introduced.
Application code: NO CHANGE in this formalization phase.
Schema/Migration: NO CHANGE / NOT AUTHORIZED / NOT RUN.
AffiliateWP/WooCommerce external connection: NO implementation in this package formalization.
Production/remote access: NO.
Deployment: NO.
Merge authorization: NO.
Documents added/updated: Canonical Order/Finance/KOL Data Contract V1; ERP-ORDER-FULFILLMENT-M1-001 Work Package and Work Log; Current Context; Living Roadmap; Product/Menu Map; MVP1 Fast Track; Finance/Order Analysis spec; KOL spec; Decision Register; Change Log; Work Package Index; Program Roadmap and Hours.
```


## 2026-08-27 — Inventory M1 formally closed; Shipping ↔ Inventory becomes next

```text
Owner UAT: PASS on US Local/remote UAT.
PR #96: MERGED to main as f5247a2f9d18e8912dd64d7e728a30ec6bef9a9b.
Inventory Foundation: COMPLETE.
Inventory Batch Sync M1: COMPLETE.
Dynamic warehouse shortcuts: retained as data-driven active Warehouse Master links.
Verification policy: US Mac is the default sufficient verification/UAT host; China is special-case only unless explicitly requested or required by a concrete cross-machine issue.
Temporary UAT service 127.0.0.1:18103: STOPPED.
Inventory batch/foundation/planning worktrees: REMOVED after clean-state and merge ancestry checks.
Stale planning PR #94: CLOSED UNMERGED / SUPERSEDED BY PR #96.
Next phase: Shipping ↔ Inventory M1 / NOT YET FORMALIZED.
Production deployment: NO.
Decision: D-038.
```

## 2026-08-27 — Manual-first fulfillment roadmap frozen; intelligent fulfillment deferred beyond MVP1

```text
Owner decision: MVP1 remains the direct Tencent Docs replacement and must preserve human warehouse/carrier judgment; intelligent decisions are deferred to later iterations.
Decision: D-039.
Previous next phase: Shipping ↔ Inventory M1 focused on automatic warehouse eligibility/recommendation + Reservation.
New current phase: MVP1 Manual Fulfillment Integration / Tencent Docs Replacement. Existing Order + Product + Inventory + Logistics foundations are connected manually first.
MVP1 target workflow: canonical Order → Product/Inventory visibility → manual Warehouse assignment → Logistics workspace → manual carrier/service/tracking/shipped time → Shipment → Manual Sync queue → Audit.
MVP1.1: WooCommerce Inbound.
MVP1.2: Tracking Outbound.
MVP1.3: Sync Orchestration.
MVP2: Reservation → Warehouse Allocation recommendation → Packing/Chargeable Weight → Shipping Cost Estimator → Transport Mode Decision → Carrier Rule Engine, recommendation-first with human confirmation/override.
MVP3: canonical WarehouseConnector + US East / US West / China / Germany adapters.
MVP4: Automatic Fulfillment.
Future fulfillment backbone: Product → Order → Inventory Availability → Warehouse Allocation → Reservation → Packing → Chargeable Weight → Shipping Cost → Transport Mode → Carrier → Warehouse Connector → Shipment.
Future chargeable-weight rule: max(actual_weight, volumetric_weight); volumetric divisor is carrier/service configuration.
Future cost rule: best express cost > configurable threshold (initial business threshold 300, currency configurable) triggers ocean-freight evaluation, not forced ocean; later decision also considers SLA/time, product restrictions, destination and explicit business rules.
Scope protection: these future extension points do not authorize premature schema/engine implementation in MVP1.
Verification policy: US Mac remains default sufficient verification/UAT host; China special-case only unless explicitly requested or required by a concrete cross-machine issue.
Production deployment: NO / milestone gated.
Architecture conflict: NONE.
Documents synchronized: 04_LIVING_ROADMAP.md; 05_MVP1_FAST_TRACK.md; 13_PROGRAM_ROADMAP_AND_HOURS.md; 10_DECISION_REGISTER.md; 11_CHANGE_LOG.md; AI/CURRENT_CONTEXT.md.
```
## 2026-08-28 — Full ERP Roadmap reconciliation after KOL M1.1

```text
Owner direction: perform a full ERP Roadmap conflict cleanup before starting the next feature.
Decision: D-040.
Baseline: main@8c3a24d0a202d3180106d1ac7befbd848539494b after PR #110.
Merged facts synchronized: PR #101 Manual Fulfillment; #104 WooCommerce Inbound; #106 Tracking Outbound; #108 Sync Orchestration; #103 KOL M1.0; #110 KOL M1.1.
Old current/future markers for those completed phases: REMOVED.
KOL M1.2: split to M1.2A Collaboration + M1.2B Canonical Fulfillment Bridge.
Truth boundary: KOL cannot duplicate Product/Inventory/Order/Shipment/Tracking or MVP2–MVP4 fulfillment engines.
KOL physical fulfillment: canonical orders.id + order_kind=kol_fulfillment + existing Shipment/Tracking projection.
PR #98: CLOSED UNMERGED / SUPERSEDED.
PR #109: CLOSED UNMERGED / SUPERSEDED BY PR #110.
PR #97: retained as design reference only / not execution authority.
PR #81: retained as stale legacy experiment / not roadmap authority.
Current product Work Package after reconciliation: NONE.
Next feature: explicit Owner selection required among bounded eligible tracks.
Application code: NO CHANGE.
Schema/Migration/DB: NO CHANGE.
Dependencies: NO CHANGE.
Production/deployment: NO.
Merge of reconciliation PR: requires separate explicit Owner authorization.
```

## 2026-08-28 — KOL V1 full completion selected as next program priority

Owner selected the KOL route after the full ERP roadmap reconciliation. Next sequence: M1.2A Collaboration → M1.2B Canonical Fulfillment Bridge → M1.3 Content → M1.4 Finance/Affiliate → Cross-phase Closure. MVP2 and Inquiry M1.2 remain paused. Canonical Product/Inventory/Order/Shipment/Tracking ownership is unchanged. Decision: D-041.

## 2026-08-29 — KOL M1.4 expanded to ERP-operated AffiliateWP workflow

```text
Owner decision: KOL Specialists and Managers must complete normal influencer, AffiliateWP, Coupon, commission and payout operations from ERP without routine WordPress login.
Decision: D-042.
Previous state: M1.4 prototype supported local Affiliate bindings, Coupon metadata, Referral commission projections and ERP settlements but explicitly excluded live AffiliateWP connector/sync and full payout administration.
New state: M1.4 is split into M1.4A Connector Foundation, M1.4B Influencer Sync/Approval/Provisioning, M1.4C Coupon Sync/Provisioning, M1.4D Referral/Commission Sync, M1.4E Commission Payout Workflow, and M1.4F Approval Center/Dashboard.
Approval policy: ALL | THRESHOLD | NONE; Manager/Admin creation auto-approves; THRESHOLD auto-approves when any configured platform exemption threshold is met and requires review when all are below threshold or reliable facts are missing.
Coupon rule: normalized global uniqueness; one Coupon Code -> at most one Affiliate/KOL; one KOL -> many Coupons permitted.
Payout rule: exact unpaid Referral snapshot -> Specialist application -> Manager approval -> manual PayPal -> recorded transaction -> AffiliateWP Payout reconciliation; approval != paid; PAID_SYNC_PENDING retries sync only.
Provider truth: WordPress User = WordPress; Coupon/customer Order = WooCommerce; Affiliate/Referral/Payout = AffiliateWP; ERP = KOL master + operating/approval/audit/Finance workspace.
External dependency: Andy Core v1.5.3 WordPress Connector; v1.5.2 remains released baseline; implementation occurs in separate Andy Core chat/repository.
Current Work Package: ERP-KOL-M1.4A-AFFILIATEWP-CONNECTOR-FOUNDATION-001.
Production/deployment: NO. Merge: separately gated.
```

## 2026-08-29 — Execution Role Model v1.3

```text
Decision: D-043.
GPT Web: architecture/design/diagnosis/Work Package authority + bounded approved implementation.
US Codex CLI: newly authorized for frozen deterministic low-to-medium complexity implementation + US-local verification.
US Claude Code: complex/high-ambiguity/broad implementation provider.
Provider selection: complexity/ambiguity based; file count alone does not require Claude.
Collision gate: ONE_ACTIVE_IMPLEMENTATION_PROVIDER_PER_WORKTREE applies to GPT+DC / US Codex / Claude.
China Mac: removed from default development and verification chain; special-case only.
Primary implementation/full verification host: US Mac / http://erp.b.
GitHub: unique source of truth.
Merge / Production release: separately Owner-gated; unchanged.
Historical records: preserved, not rewritten.
```


## 2026-09-05 — Roadmap + Production Reconciliation V2

Reconciled stale menu progress against main, Closure/UAT and live bounded Production. Purchase OS V1 is now live after the earlier 404 snapshot. Added a four-dimension truth model, corrected approved menu completion metadata, and changed the strategic current line to Production Consolidation → Fulfillment Intelligence. Decision: D-044.

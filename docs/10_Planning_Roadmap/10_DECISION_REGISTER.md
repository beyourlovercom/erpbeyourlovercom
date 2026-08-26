---
type: decision-register
project: BYL ERP
version: 1.0
updated: 2026-08-23
---

# Decision Register

## D-001 Mandatory architecture

Laravel modular monolith + Inertia/Vue/TypeScript/Tailwind + MySQL/Redis/Queue/Scheduler + session auth + server authorization + audit.

## D-002 Dashboard

`/dashboard` is the only ERP homepage.

## D-003 Risk and urgent

Manual internal flags or controlled statuses; no invented SLA logic.

## D-004 Sync naming

User-facing `同步`; backend `sync`; route `/sync`; permissions `sync.*`.

## D-005 Sync is primary navigation

Sync is a high-frequency primary menu.

## D-006 Public registration

Disabled. Accounts are administrator-created.

## D-007 MVP1 target

Replace Tencent Docs manual order/fulfillment handoff in approximately one month.

## D-008 MVP1 architecture

Manual functionality uses final-compatible Order, Shipment, External Reference, Sync Outbox and Cost models. No temporary truth tables.

## D-009 MVP sequence

```text
MVP1 manual
→ MVP1.1 WooCommerce inbound
→ MVP1.2 single tracking outbound
→ MVP1.3 batch/retry/conflict
```

## D-010 Manual paths remain

Manual order entry and manual website-sync remain permanent fallback capabilities.

## D-011 Finance order analysis

Order-level profitability is included in MVP1 under Finance / Accounting.

## D-012 Cost status

Costs distinguish estimated, confirmed and adjusted.

## D-013 Discount

Do not deduct website discount twice when amount paid already reflects it.

## D-014 Product synchronization

WordPress product detail and featured image enter Product OS; first direction is inbound.

## D-015 SEO OS

WordPress content, Google Docs publishing and source-specific GA4/GSC/Cloudflare metrics enter SEO OS through sync.

## D-016 KOL social

KOL center can request Instagram/X enrichment; official APIs only by default; follower snapshots and local avatar mirror.

## D-017 Obsidian

Repository Markdown is the source of truth. Obsidian reads the repository; no duplicate Roadmap.

## D-018 Documentation branch

Planning foundation must not be mixed into PR #1.

## D-019 001C closure, 001D preparation and Operations paths

001C is accepted and merged. 001D becomes the current prepared architecture package, but implementation still requires separate Owner authorization. Global Architecture Gate remains FAIL; no Migration or deployment is authorized.

Formal Work Packages are stored under `docs/60_Codex_Operations/work-packages/` and named `<WORK-PACKAGE-ID>.md`, beginning with `ERP-ARCH-MIGRATION-001D`. Work Logs retain `docs/60_Codex_Operations/logs/YYYY-MM-DD_<WORK-PACKAGE-ID>.md`.

## D-020 001D scope freeze and ordered execution split

001D is split into `ERP-ARCH-MIGRATION-001D-A` Runtime Foundation, `ERP-ARCH-MIGRATION-001D-B` Audit Foundation and `ERP-ARCH-MIGRATION-001D-C` Sync Outbox Foundation, executed in that order. The split grants no implementation authorization; 001D-A remains prepared with authorization NO, while 001D-B and 001D-C remain waiting.

Redis is the Queue and Cache baseline. Current Session configuration is preserved without a forced Redis migration. Laravel Scheduler is the runtime scheduling foundation; production cron remains a documentation contract only. The existing 001B audit foundation must be preserved and extended additively, and Sync Outbox remains provider-independent integration intent rather than business truth. Migration file authoring may be authorized within a future subpackage, but Migration execution requires separate explicit authorization. External APIs, Sync UI, Order, Shipment and deployment remain excluded.

## D-021 Complete Program Roadmap and Roadmap Hours governance

Every formal task and final report must show the complete top-level Program Roadmap and a distinct complete current-track map. `ROADMAP HOURS` becomes mandatory between `PROJECT POSITION` and final `ESTIMATED CODEX HOURS`. Current-package estimates and program remaining estimates are distinct; unknown remaining estimates require evidence, and cumulative or overlapping values cannot be summed.

`MIGRATION` remains a mandatory umbrella field and cannot be replaced by authoring/execution subfields. Final reports require actual effective hours, breakdown, variance and variance reason. Repository-local standard version 1.1 applies prospectively and to open/unmerged work reviewed after merge; historical merged work is not retroactively invalidated. Open PR #10 must be updated before merge, and its unmerged transition D-021 must become D-022 after rebase. Architecture conflict: NONE. Application implementation, Migration and deployment authorization remain NO.

## D-022 001D-A completion and 001D-B preparation

PR #9 was accepted and squash merged. ERP-ARCH-MIGRATION-001D-A is MERGED / PASS, including the timeout-coherence remediation at accepted Head `3d8f92b701370db41bed0df707183207f6924dd5` and main commit `50803c3fcd51e9f8b3485f4fb57565154a284bf7`. Local validation passed, but live Redis PASS was not claimed.

ERP-ARCH-MIGRATION-001D-B becomes the current prepared Audit Foundation package with implementation authorization NO. Migration file authoring and Migration execution remain NO. ERP-ARCH-MIGRATION-001D-C remains WAITING, Global Architecture Gate remains FAIL, and deployment remains NO. Architecture conflict: NONE.

## D-023 001D-B Audit Foundation implementation authorization

The Owner authorized ERP-ARCH-MIGRATION-001D-B implementation from `main@0720a47ddc8825b9506ab28651fa07a8c1994e62`, including one Audit Service, explicit user and trusted-system actors, correlated structured metadata, recursive sensitive-field removal, immutable application writes, security-critical failure handling and separately authorized audit viewing.

The existing 001B `audit_records` schema contains all required fields, so the exact proposed schema delta is NONE. No Migration file was created or modified and no Migration was executed. ERP-ARCH-MIGRATION-001D-C remains WAITING, merge requires a separate Owner decision after GitHub independent review, Global Architecture Gate remains FAIL, and deployment remains NO. Architecture conflict: NONE.

## D-024 001D completion and 001E preparation split

PR #13 was independently reviewed, accepted at Head `e4bc57b7a3f0edbe84ccfde63d02d9135a4875e3` and squash merged as main commit `548d53f1eae2ec14eea7261d3a101c1f2fda0dcc`. Together with accepted 001D-A and 001D-B, ERP-ARCH-MIGRATION-001D is COMPLETE / PASS.

ERP-ARCH-MIGRATION-001E becomes NEXT / PREPARED / IMPLEMENTATION NOT STARTED and is split into 001E-A Shared UI and Design Tokens, 001E-B Browser E2E Foundation and 001E-C Authentication / Permission / App Shell E2E. This decision authorizes governance and preparation only. It does not authorize implementation, Playwright installation, dependency or lock-file changes, Workflow changes, Migration, UAT, deployment or 001F.

The accepted 001D evidence remains bounded: real-MySQL two-connection Sync Outbox claim concurrency is UNVERIFIED; MySQL 5.7 is UNVERIFIED; Dev, Staging and Production Migration execution was NOT RUN. Global Architecture Gate remains FAIL. Architecture conflict: NONE.

## D-025 001E-A Preflight gate and browser-artifact protection

001E-A is not implementation-ready. Its status is `PREFLIGHT REQUIRED / IMPLEMENTATION NOT READY`, and a separate Work Package freezes one proposed semantic token source (`resources/css/tokens.css`), five first-wave components with actual consumers, bounded candidate files, migration/test contracts and a Preflight-only estimate. The parent 16–24h allocation remains planning-only until the child Preflight confirms or replaces the implementation estimate.

001E-B authentication traces and videos cannot be uploaded until a repeatable sanitization process and automated artifact scan are implemented and independently reviewed. Authentication screenshots are also local-only unless deterministic masking, synthetic-data proof and pre-upload verification pass. This decision authorizes governance remediation only; application/CSS implementation, dependencies, Playwright, Workflow, Migration, PR, UAT, deployment and 001F remain unauthorized. Architecture conflict: NONE. Global Architecture Gate: FAIL.

## D-026 001E-A closure and 001E-B Browser E2E Preflight

PR #17 is MERGED / PASS at accepted Head `e46fb54a1b41a1efa620b4af3646e104ff7bcbbc` and main squash commit `442a3a872628764fecb7209049d2daf752624e9a`. Supporting packages are also MERGED / PASS: Local Admin Bypass PR #16 at accepted Head `6b63229b7d776104a5736334de639d9b20fb69f3` and main commit `55ba7320a7046deb04a03abd99053ccd7b7ed11e`; Account Dock PR #15 at accepted Head `0cac340443d47591b7e242ebe051fb4407d0a986` and main commit `32d7244b9b1c6d1fb0cf8863cb9af694e7acc10f`, with Owner Visual Acceptance PASS; Menu Baseline PR #14 at accepted Head `6d5595e879778895105215dc98b1f740521b6f1a` and main commit `493f32ce7bf1be4368a7e9f97de9ecf9f9c9c9d8`.

001E-B Browser E2E Foundation is frozen at Preflight only. The proposed dependency is exact `@playwright/test@1.62.1`, Chromium-only for the first implementation, with repository-external runtime state, a disposable SQLite database, synthetic users and fail-closed artifact handling. Authentication traces and videos remain prohibited from upload; initial 001E-B uploads no E2E artifacts. Dependency, package-lock, browser installation, Workflow, disposable E2E Migration execution and implementation each require a separate Owner grant. No application, menu, package, lock, Workflow, Migration, database, UAT or deployment change is authorized here. 001E-C and 001F remain WAITING, Global Architecture Gate remains FAIL, and architecture conflict is NONE.

## D-027 001E closure and 001F-A governance correction

PR #21 (`3b67586cafd2b4af3e248e3079719cb73b4e4163` → `4b9fbb0deacdfc02439fb947398b87c148602c75`) and PR #22 (`2844d8f2f9214a3fb058e546eb92751e49cbe5af` → `3218473b1cfc93f098a2e9607aabe9adb16bcf0d`) are merged. 001E-B and 001E-C are MERGED / PASS / CLOSED; 001E is COMPLETE / PASS. 001F is ACTIVE / PREFLIGHT REVIEWED and 001F-A is IMPLEMENTED / PENDING INDEPENDENT REVIEW. Historical pending-review text in child records is retained as history only. Global Architecture Gate remains FAIL. Architecture conflict: NONE.

## D-028 MySQL support baseline

MySQL 8.x is the supported baseline; MySQL Community 8.4.11 is the verified isolated reference for Migration up/down/up and physical Schema contracts. Real two-connection Sync Outbox claim concurrency remains UNVERIFIED and is a Global Gate blocker. MySQL 5.7 is OUT OF SCOPE / NOT SUPPORTED BASELINE and is not a current blocker; any future 5.7 requirement requires a new Architecture Decision and Compatibility Work Package. Current code must not be described as MySQL 5.7 compatible.

## D-029 Browser and environment evidence gates

Local Chromium Browser E2E is ACCEPTED LOCAL ARCHITECTURE EVIDENCE. GitHub Actions Browser E2E is NOT REQUIRED for the current Global Architecture Gate and remains future CI hardening requiring separate Owner authorization; no remote PASS is claimed. Disposable MySQL 8 validation is required and passed for the bounded Schema contract. Dev Migration is a Global Gate and MVP1 integration blocker; Staging Migration blocks UAT; Production Migration plan/rehearsal blocks deployment; Production real Migration is only a separately authorized deployment action.

## D-030 001F gate scope

HISTORICAL DECISION-TIME STATE: the remaining 001F sequence was 001F-B Modular Boundary Decision / Remediation, 001F-C MySQL Concurrency Validation, 001F-D Redis / Queue / Scheduler Runtime Validation, 001F-E Dev Migration Validation and 001F-F Final Architecture Gate Re-audit. At that point these packages were proposed and not authorized. The current status is recorded by later package closures and D-032. Global Architecture Gate remains FAIL; MVP1 remains NOT STARTED and at 0% business completion.

## D-032 001F-E local development environment scope

The current 001F-E contract supersedes the earlier remote-Dev assumption. `http://erp.b` at `/Users/a/AI/007/erpbeyourlovercom` is the only permitted 001F-E validation target. `https://deverp.beyourlover.com` is Remote Dev and `https://erp.beyourlover.com` is Production; neither may be accessed, deployed to, migrated or synchronized during 001F-E. The earlier fail-closed result is a historical safety record, not a code defect. A local identity and backup/reconstructability gate remains mandatory before any local database mutation. Global Architecture Gate remains FAIL; MVP1 remains NOT STARTED / 0%.

## D-031 Adopt A1 Physical Module Contract for Architecture Standard v1.1

Owner reviewed 001F-B and selected `RECOMMEND_OPTION_A1`. Standard ID is `BYL-ERP-ARCH-001`; v1.0 did not explicitly require `app/Modules/**`; v1.1 now adopts that path as a forward-effective physical module contract. B1 freezes the contract only; historical migrations remain in place, `App\Support` is legacy compatibility rather than Shared Kernel, default Composer PSR-4 remains sufficient, and B2 establishes the skeleton and automated boundary tests. B3, B4 and B5 remain staged and unauthorized. Global Architecture Gate remains FAIL; MVP1 remains NOT STARTED / 0%; no application, namespace, provider, route, migration or database change is authorized.

## D-033 Parallel Work Package / Worktree / Delivery PR governance

Owner authorized remediation of PR #79 into the final parallel execution model for BYL ERP.

`PARALLEL-WORK-EXEC-001` v1.1 is adopted prospectively when the governance PR containing it merges to `main`. Multiple active Work Packages, Pull Requests, OpenCode sessions and dedicated Git worktrees are explicitly allowed when each candidate Work Package passes the Parallelization Preflight Gate.

The mandatory rules are:

1. `ONE_WORK_PACKAGE_ONE_BRANCH_ONE_PR`
2. `ONE_ACTIVE_AGENT_ONE_WORKTREE`
3. `PARALLEL_PR_MUST_NOT_HAVE_UNDECLARED_DEPENDENCIES`
4. `COMMON_FOUNDATION_CHANGES_MUST_BE_EXTRACTED_BEFORE_PAGE-LEVEL_PARALLELIZATION`

For newly activated Work Packages after the standard becomes effective, one bounded Work Package maps to one delivery branch and one delivery PR. The same Draft delivery PR carries the Work Package contract, implementation, validation, remediation and final pre-merge evidence. Separate Work Package / Implementation / Closure PRs are not created for the same new Work Package identity.

Historical merged packages remain valid and are not retroactively invalidated. A legacy active Work Package with an already-issued contract that explicitly uses separate Work Package / Implementation / Closure PRs may finish under that contract. A material re-scope after the new standard becomes effective must become a new Work Package under the new model.

A merge SHA becoming repository truth does not require a second Closure PR under the same new Work Package identity. If repository mutation is genuinely required after merge, it must be a separately identified closure-correction or remediation Work Package with its own branch, worktree and PR.

Independent page-level repairs such as Dashboard, Orders and Settings may run concurrently through separate OpenCode sessions and dedicated worktrees when scopes and dependencies are bounded. Shared App Shell, navigation, design tokens, common components, routes, permissions, schema primitives or test infrastructure required by multiple downstream pages must be extracted first unless Owner explicitly authorizes a declared stacked dependency.

This decision changes governance only. It authorizes no application implementation, Migration, UAT, deployment or Production access, and it does not authorize merge of PR #79. Architecture conflict: NONE; Global Architecture Gate remains PASS per current repository context.

## D-034 Promote ERP-INQUIRY-M1 to current business milestone and adopt local-first dual-machine execution

Owner approved the reconciled local baseline in PR #82 and authorized the Roadmap revision that promotes B2B Inquiry Foundation ahead of the previously scheduled WooCommerce / tracking / sync expansion. PR #82 merged to `main` as `faa45f9620211f3c398dde7f30c1a886313ced14`.

The revised sequence is: completed Architecture Foundation → existing MVP1 operations with Human UAT remediation still open → `ERP-INQUIRY-M1` as the current business milestone → local Milestone UAT → milestone-gated remote release to `https://erp.beyourlover.com` → WooCommerce inbound / tracking outbound / sync orchestration and later modules. This reprioritization does not delete the later roadmap and does not close any of the five migrated Human UAT findings.

`ERP-INQUIRY-M1` is decomposed as M1.0 Architecture/Data/API Contract, M1.1 Company, M1.2 Contact, M1.3 Case, M1.4 Inbound Message, M1.5 Inquiry Inbox, M1.6 Email Intake, M1.7 WhatsApp Intake, M1.8 Yesterday/Waiting Dashboard and M1.9 Local Milestone UAT. No child Work Package is activated merely by this decision.

Development authority is frozen as follows: GPT Web + Owner perform all architecture, design, diagnosis, root-cause analysis and next-step decisions; US Mac Claude Code performs implementation only; China Mac Codex performs test, verification and evidence only. GitHub is the unique source of truth and the only code synchronization path. The China Mac `http://erp.b` is the primary local runtime/test environment. `https://erp.beyourlover.com` is a milestone remote target and is not used for normal development debugging.

This decision authorizes governance/Roadmap synchronization only. It authorizes no product implementation, schema Migration, external Email/WhatsApp connection, remote deployment, production access or merge of unrelated open PRs. The next product activity after this Roadmap revision merges is GPT-authored `ERP-INQUIRY-M1.0` architecture/data/API contract design. `ERP-INQUIRY-M1` implementation hours remain `UNKNOWN WITH EVIDENCE` until that contract is frozen.

## D-035 Enforce GPT / Claude / Codex execution-role separation in repository entrypoints

Owner-approved D-034 established the operating model: GPT Web + Owner perform architecture/design/diagnosis; US Claude Code implements; China Codex CLI tests exact GitHub revisions on `http://erp.b`; GitHub is the unique synchronization source.

Repository audit after PR #83 found current entrypoints and governance standards still contained forward-looking Codex/OpenCode implementation instructions and an embedded pre-Inquiry Program Roadmap. Those instructions conflict with D-034 if applied to newly activated work.

Decision: add `AI/EXECUTION_ROLE_MODEL.md` as the role-authority overlay; add `CLAUDE_ENTRYPOINT.md`; convert `CODEX_ENTRYPOINT.md` to test/verification-only; update `AGENTS.md`, repository manifest and current governance standards so Codex cannot mutate tracked files, fix code, design or diagnose, while Claude cannot invent architecture, diagnose root cause or choose the next Work Package.

Historical Codex/OpenCode implementation records remain valid historical evidence. The legacy `ESTIMATED CODEX HOURS` footer label remains temporarily schema-compatible in `LARGE-PROJECT-EXEC-001` v1.2 but does not grant Codex implementation authority. New packages must explicitly declare `EXECUTION_PROVIDER`.

This decision changes governance and entrypoints only. It authorizes no product implementation, schema Migration, external Email/WhatsApp connection, remote deployment or Production access. After this governance package merges, GPT Web proceeds to `ERP-INQUIRY-M1.0` architecture/data/API contract design.

## D-036 Accept and freeze ERP-INQUIRY-M1.0 contract

Owner explicitly approved all six M1.0 decision gates on 2026-08-23: Case number `CASE-YYYYMMDD-<id>`; WhatsApp continuation default 72h; `/customers` as Inquiry Inbox landing page; permissions `customers.operate` and `channels.manage`; serial M1.1→M1.9 default sequence; and the 86–150h / 116h planning estimate classification.

PR #85 merged the design contract to `main` as `02ce87dba5ede14d791b646b7cbe976a12064cae`. The contract is now the frozen design authority for ERP-INQUIRY-M1 unless a later Owner-approved design revision supersedes it.

This acceptance does not itself authorize product implementation, Migration execution, Email connection, WhatsApp registration, remote deployment or Production access. The next bounded product package is `ERP-INQUIRY-M1.1-COMPANY-001`, and it requires separate explicit Owner implementation authorization before Claude writes product code or Migration files.

## D-038 Adopt BYL ERP Entity Architecture V2 and unified search model

Owner approved the long-term Entity Architecture V2 on 2026-08-26. The design authority is `docs/10_Planning_Roadmap/21_BYL_ERP_ENTITY_ARCHITECTURE_V2.md`.

`orders.id/order_id` and `products.id/product_id` remain the canonical Order and Product anchors. Every future major business object receives its own canonical ID. Core business relationships use explicit foreign keys/relation tables, while generic entity relationship/search indexes are derived discovery/navigation infrastructure only and never replace source-module truth.

Product/Order and future Warehouse, Supplier, Project, Shipment, Purchase Order, Tooling and other 360 surfaces shall support bidirectional canonical deep links. Product classification uses multi-dimensional taxonomy; Project and Tooling are independent entities rather than fixed Product columns. First-screen 360 aggregates use bounded read projections as scale grows.

Owner also approved a unified search model: a persistent search icon on the Header right opens Global Search across registered entities, initially Product, Order and Inventory context; Product and Order pages also provide Product-only and Order-only scoped search. Global and scoped search reuse one extensible Search Contract/Search Provider architecture. Search results are authorization-filtered, server-side, grouped by entity type and deep-link to canonical 360 pages or pre-filtered module views.

Target planning scale is at least 10,000 Products and 100,000 Orders with room for materially larger datasets. This decision freezes architecture/design direction only; it does not authorize schema migration, search implementation, external search infrastructure, merge, remote deployment or Production access.

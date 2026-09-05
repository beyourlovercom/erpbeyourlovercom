---
type: decision-register
project: BYL ERP
version: 1.0
updated: 2026-08-24
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

PR #21 (`3b67586cafd2b4af3e248e3079719cb73b4e4163` → `4b9fbb0deacdfc02439fb947398b87c148602c75`) and PR #22 (`2844d8f2f9214a3fb058e546eb92751e49cbe5af` → `3218473b1cfc93f098a2e9607aabe9adb16bcf0d`) are merged. 001E-B and 001E-C are MERGED / PASS / COMPLETE / CLOSED; 001E is COMPLETE / PASS. 001F is ACTIVE / PREFLIGHT REVIEWED and 001F-A is IMPLEMENTED / PENDING INDEPENDENT REVIEW. Historical pending-review text in child records is retained as history only. Global Architecture Gate remains FAIL. Architecture conflict: NONE.

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

A merge SHA becoming repository truth does not require a second Closure PR under the same new Work Package identity. If repository mutation is genuinely required after merge, it must be a separately identified closure-correction or remediation Work Package with its own branch/worktree/PR.

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

## D-037 Freeze canonical Order / Finance / KOL data contract and reprioritize Unified Order Fulfillment before Inquiry M1.2

Owner approved the canonical Order / Finance / KOL business semantics on 2026-08-24 after reviewing real WooCommerce and AffiliateWP export examples outside the repository. The frozen design authority is `docs/10_Planning_Roadmap/16_BYL_ERP_CANONICAL_ORDER_FINANCE_KOL_DATA_CONTRACT_V1.md`.

The canonical transaction truth remains one `orders.id`. `external_order_number` is the primary user-visible business order number; manually entered business numbers are preserved and cannot duplicate within the manual ERP connection scope. `internal_order_number` remains generated, globally unique, immutable and hidden by default. `order_kind` is frozen as `customer | kol_fulfillment` and is independent from source/provider. Affiliate-attributed consumer sales remain `customer` Orders plus attribution; `kol_fulfillment` means products/samples/gifts/replacements sent to a KOL.

Finance uses the same canonical Order. ERP analytical base currency is USD. Shipping revenue and shipping cost remain separate; tax collected and tax cost/liability remain separate; Order-level and future Item-level profitability are supported. Shared Item costs default to sales-value proportion when no real item-level/weight evidence exists, while real item facts override allocation. V1 shipment cardinality remains one Order to one Shipment.

AffiliateWP remains the Affiliate / Referral / Payout source of truth. ERP does not clone AffiliateWP Visits, Conversion, aggregate Earnings history or full Referral/Payout administration. ERP stores only the minimum identity binding, Order attribution and commission facts needed for canonical Finance/analytics. Commission recognition is frozen as pending=projected only, unpaid=recognized/payable cost, paid=recognized cost plus actually paid, rejected=zero recognized cost; `unpaid → paid` must never double-count expense. AffiliateWP `paid` is accepted as actually paid for V1.

Program priority is revised after the already-merged ERP-INQUIRY-M1.1 Company slice: `ERP-ORDER-FULFILLMENT-M1` becomes CURRENT; ERP-INQUIRY-M1.2 Contact is PAUSED, not cancelled, and resumes after the Order/Fulfillment Owner gate unless Owner changes priority again. The four remaining MVP1 Order/Fulfillment Human UAT findings become explicit revalidation targets; none is closed by this decision alone.

`ERP-ORDER-FULFILLMENT-M1-001` follows D-033: one Work Package, one branch `feature/erp-order-fulfillment-m1-001`, one Draft delivery PR spanning contract → approved implementation → validation → remediation → final pre-merge evidence. This decision authorizes contract/Roadmap/Work-Package formalization only. Product implementation, Migration authoring/execution, external AffiliateWP connector implementation, Production access, remote deployment and merge remain NOT AUTHORIZED until their explicit gates.

Because the package changes schema semantics and authorization/data scope, its verification classification is Tier B: full US verification plus full China exact-SHA independent verification before Owner Local UAT/merge. The exact implementation estimate remains `UNKNOWN WITH EVIDENCE` until the implementation preflight confirms migration/backfill, legacy source data and permission/data-scope surfaces; no fabricated estimate is permitted.

Architecture conflict: D-034 serial Inquiry sequence is intentionally superseded only in current priority order; the approved ERP-INQUIRY-M1 contract itself remains valid and resumes at M1.2 after this package. Remote milestone deployment remains gated and unchanged.


## D-038 Close Inventory M1 and set US-only default verification path

Owner formally accepted Inventory M1 on 2026-08-27 after successful US Local/remote UAT and authorized merge. PR #96 merged Inventory Foundation + Inventory Batch Sync M1 to `main` as `f5247a2f9d18e8912dd64d7e728a30ec6bef9a9b`. The accepted Inventory baseline includes canonical Product Master matching, warehouse inventory truth, multi-lot sellable/defective separation, partial/full batch sync, Preview → Confirm → Audit, dynamic active-Warehouse menu shortcuts, and local/UAT `admin` / `password` provisioning restricted to local/testing.

Owner also revised the default verification policy: US Mac verification/UAT is sufficient for normal development unless the Owner explicitly requests China verification or a concrete cross-machine/region-specific defect requires it. China is no longer a default merge gate.

The next program phase is Shipping ↔ Inventory M1, but this decision does not authorize its implementation. Its first bounded contract must define warehouse eligibility, stock availability, reservation semantics, `selected_warehouse_id`, one-Order/one-Warehouse behavior, manual exception handling, rollback and audit. Carrier rules, Warehouse Connectors, automatic fulfillment and Production deployment remain later separately gated scope.

Architecture conflict: NONE. GitHub remains the unique source of truth. Remote Production deployment remains NOT AUTHORIZED.

## D-039 Freeze manual-first fulfillment roadmap and defer intelligent decisions beyond MVP1

Owner approved the upgraded fulfillment backbone on 2026-08-27 and explicitly froze the delivery priority: MVP1 must first replace the current Tencent Docs manual order/fulfillment workflow. Existing human operational judgment remains human in MVP1; ERP must present canonical Product / Order / Inventory / Logistics facts, record manual Warehouse and Carrier decisions, persist Shipment/Manual Sync state, and provide Audit without requiring automatic warehouse/carrier decisions.

The long-term fulfillment backbone is frozen as: `Product → Order → Inventory Availability → Warehouse Allocation → Reservation → Packing → Chargeable Weight → Shipping Cost → Transport Mode → Carrier → Warehouse Connector → Shipment`. MVP1 must remain forward-compatible with this chain but must not implement the intelligent engines merely because the extension points exist.

Program phases are now: MVP1 Manual Fulfillment / Tencent Docs Replacement; MVP1.1 WooCommerce Inbound; MVP1.2 Tracking Outbound; MVP1.3 Sync Orchestration; MVP2 Reservation + assisted Warehouse Allocation + Packing/Chargeable Weight + Shipping Cost + Transport Mode + Carrier rules; MVP3 Warehouse Connectors; MVP4 Automatic Fulfillment. Recommendation-first / human-confirmation behavior precedes full automation.

Future logistics costing is also frozen at the architecture level only: `chargeable_weight = max(actual_weight, volumetric_weight)`; volumetric divisor is carrier/service configuration rather than Order/Inventory hard-code. The Shipping Cost Estimator must evaluate eligible rate candidates. If best express cost exceeds a configurable threshold (initial business threshold `300`, currency configurable), the system must evaluate ocean freight; it must not automatically force ocean, because SLA/time commitment, product restrictions, destination and other explicit business constraints remain part of the later Transport Mode decision.

MVP1 may preserve provenance/extension concepts such as manual vs future rule-engine/override Warehouse and Carrier selection, plus later decision snapshots and estimated-vs-actual shipping cost, but this decision does not require premature schema columns or implementation of Reservation, Allocation, Packing, rate shopping, ocean evaluation, Carrier rules, Warehouse connectors or Automatic Fulfillment.

Verification policy remains D-038: US Mac verification/UAT is sufficient by default; China verification is special-case only unless Owner explicitly requests it or a concrete cross-machine/region-specific defect requires it. GitHub remains the unique source of truth. Production deployment remains separately gated. Architecture conflict: NONE.
## D-040 Reconcile ERP roadmap after PR #110 and freeze KOL-to-Fulfillment truth boundary

Owner directed a full ERP Roadmap conflict cleanup on 2026-08-28 after KOL M1.1 merged by PR #110. Repository audit confirmed that the canonical Living Roadmap / Program Roadmap still listed already-merged Manual Fulfillment, WooCommerce Inbound, Tracking Outbound and Sync Orchestration as current/future work, while the KOL Unified Roadmap still grouped Collaboration and Fulfillment under one M1.2 phase name.

Decision: merged GitHub reality through `main@8c3a24d0a202d3180106d1ac7befbd848539494b` becomes the reconciliation baseline. PR #101 Manual Fulfillment, #104 WooCommerce Inbound, #106 Tracking Outbound, #108 Sync Orchestration, #103 KOL M1.0 and #110 KOL M1.1 are recorded COMPLETE. No product Work Package is implicitly current after this docs-only reconciliation; the Owner must select the next bounded track explicitly.

KOL M1.2 is split into `M1.2A Collaboration` and `M1.2B Canonical Fulfillment Bridge`. KOL owns formal Collaboration facts but does not own Product, Inventory, Order, Shipment, Tracking, Reservation, warehouse allocation, shipping-cost, transport-mode, carrier-rule or WMS-connector truth/engines. Physical KOL fulfillment uses the existing canonical `orders.id` with `order_kind=kol_fulfillment`, canonical Order Items/Product references, existing Inventory/Warehouse truth, existing Shipment and existing Tracking/Sync; KOL may store relations and read projections only.

PR #98 is closed unmerged as a superseded roadmap proposal. PR #109 is closed unmerged as duplicate/superseded by merged PR #110. PR #97 remains long-term docs/design reference only; PR #81 remains a stale legacy Dashboard experiment. Neither is current Roadmap authority.

Eligible next tracks after this reconciliation are alternatives requiring explicit Owner selection: KOL M1.2A Collaboration, MVP2 Inventory Reservation Foundation, or reactivation of ERP-INQUIRY-M1.2 Contact. This decision authorizes governance/docs synchronization only; no product implementation, schema/migration, dependency, Production access, deployment or merge is authorized.

## D-041 Prioritize full KOL V1 completion and stabilization

Owner selected the KOL track on 2026-08-28 as the next program priority after Roadmap Reconciliation. The frozen sequence is `M1.2A Collaboration → M1.2B Canonical Fulfillment Bridge → M1.3 Content → M1.4 Finance / Affiliate → KOL V1 Cross-phase Closure`.

KOL remains bounded by D-040 canonical ownership: it may reference Product and create/link `order_kind=kol_fulfillment` Orders through the bridge, but it must not duplicate Product, Inventory, Order, Shipment, Tracking, Reservation, allocation, shipping-cost, carrier-rule or WMS truth. MVP2 and ERP-INQUIRY-M1.2 remain paused until KOL V1 closure or explicit Owner reprioritization. Every implementation PR still requires its own test/UAT and explicit merge authorization.

## D-042 Expand KOL M1.4 into ERP-operated AffiliateWP workflow

Owner expanded KOL M1.4 on 2026-08-29 from a minimal Finance/Affiliate projection into the complete operating workflow required for KOL Specialists and KOL Managers to perform normal AffiliateWP work from ERP without logging into WordPress. AffiliateWP remains Affiliate/Referral/Payout provider truth, WooCommerce remains Coupon and customer-order provider truth where applicable, and WordPress remains WordPress User truth. ERP is the KOL master, operating, approval, payout-request, audit and canonical Finance workspace and may keep explicit synchronized mirrors/projections needed to operate and reconcile against provider truth.

M1.4 is decomposed under D-033 into six independently delivered packages: `M1.4A AffiliateWP Connector Foundation -> M1.4B Influencer Sync + KOL Approval + Provisioning -> M1.4C Coupon Sync + Provisioning -> M1.4D Referral + Commission Sync -> M1.4E Commission Payout Workflow -> M1.4F Manager Approval Center + Weekly/Monthly Dashboard`. Cross-phase Closure remains after M1.4F.

KOL approval policy is frozen to `ALL | THRESHOLD | NONE`. `ALL` requires Manager approval for every Specialist-created KOL. `THRESHOLD` uses configured platform exemption thresholds: reaching any configured threshold auto-approves, while all configured facts below threshold or missing reliable facts require approval. `NONE` auto-approves Specialist-created KOLs. Manager/Admin-created KOLs are immediately approved in every mode. Policy changes are prospective only and every decision preserves a policy snapshot and reason. An approved KOL may then idempotently provision/enable its WordPress User and Active/approved AffiliateWP Affiliate.

Coupon uniqueness is global after trim + case-insensitive normalization. One Coupon Code may belong to at most one Affiliate/KOL; one KOL may own multiple Coupon Codes. ERP must check its synchronized mirror and live WordPress/WooCommerce state before remote creation, hard-block conflicts and read back the created Coupon/Affiliate binding before reporting success.

AffiliateWP Referral commission remains provider-derived and cannot be recreated manually as a second ERP Affiliate commission. Commission state remains `pending=projected`, `unpaid=recognized/payable`, `paid=recognized+paid`, `rejected=zero`; `unpaid -> paid` never double-counts canonical `kol_commission`. ERP manual settlement remains only for non-AffiliateWP fixed fee, bonus, content fee, reimbursement or other approved costs.

Commission payout is an ERP workflow. Specialists select eligible unpaid Referral groups and submit exact Referral snapshots. A Referral may belong to at most one active payout request. Manager approval means approved-for-payment, not paid. Initial money movement is manual PayPal. After a Manager records the real PayPal transaction ID/amount/currency/date, ERP asks the WordPress Connector to create/complete the exact AffiliateWP Payout and reconcile Referrals to paid. If money is paid but provider sync fails, status becomes `PAID_SYNC_PENDING`; only sync may retry, never the payment.

The external WordPress execution dependency is an Andy Core `v1.5.3` WordPress Connector module. Andy Core `v1.5.2` remains the released baseline. The connector is implemented in the Andy Core repository/another chat and must conform to the ERP-side frozen contract `25_ANDY_CORE_V1.5.3_WORDPRESS_CONNECTOR_CONTRACT.md`. This decision authorizes the M1.4A ERP implementation package only; it does not authorize Andy Core repository mutation, Production deployment, or merge of any future PR without its normal gate.

## D-043 Upgrade Execution Role Model to v1.3 and authorize US Codex implementation

Owner approved this governance change on 2026-08-29 after reviewing the merged v1.2 rules and confirming that daily development should use the US Mac, not China Mac, for Codex implementation.

Execution Role Model v1.3 adds `US_CODEX_CLI` as a formal implementation provider for frozen, deterministic low-to-medium complexity Work Packages. GPT Web + Owner retain architecture, business-rule, diagnosis and next-step authority. US Claude Code remains the preferred provider for complex/high-ambiguity implementation, complex external integrations, materially interdependent schema/data-model work, large cross-module refactors, concurrency/state-machine work and similar high-complexity packages.

Provider choice is based on ambiguity and complexity, not file count or historical habit. US Codex must stop and return raw evidence when a frozen contract becomes ambiguous or requires a new design/remediation decision. `ONE_ACTIVE_IMPLEMENTATION_PROVIDER_PER_WORKTREE` applies equally to GPT+DC, US Codex CLI and US Claude Code.

D-038 remains the verification baseline: US Mac verification/UAT is sufficient by default. China Mac is removed from the default execution and verification chain and is used only when explicitly requested or required by a concrete cross-machine/region-specific defect. GitHub remains the unique source of truth. Merge and Production deployment remain separately Owner-gated.


## D-044 Reconcile Roadmap, menu progress and bounded Production truth V2

Owner directed a second full reconciliation on 2026-09-05 after substantial Product, Customer, KOL, Inventory, Connector and Purchase progress plus multiple bounded Production releases. `main@a36e9d3`, formal Closure/UAT evidence, `menu/APPROVED_MENU.json`, and the live Production release are now four explicit independent truth dimensions. Menu progress means approved surface implementation only. Production must never be inferred from main because ERP uses bounded exact releases. KOL historical import + Affiliate binding is now Production CLOSED (961 canonical KOLs; 856 exact bindings; 2,416 referrals resolved; no unresolved ambiguity). Current strategic line becomes `Production Consolidation → Fulfillment Intelligence`. Four parallel packages may run only with exclusive worktree/file ownership and no cross-lane shared-file edits.

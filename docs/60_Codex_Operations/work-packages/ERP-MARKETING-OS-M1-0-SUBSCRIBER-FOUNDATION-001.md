---
type: work-package
project: BYL ERP
work_package_id: ERP-MARKETING-OS-M1-0-SUBSCRIBER-FOUNDATION-001
status: OWNER_UAT_PASS
execution_provider: US_CODEX_CLI
verification_tier: HIGH_RISK
baseline: origin/main@be1afca30e839361975cfabe941b99f253b708fb
---

# Marketing OS M1.0 — Subscriber Foundation

## Authority and sequencing
Owner approved Marketing OS on 2026-08-29 with five level-2 workspaces: 邮件订阅、群发邮件、营销活动、自动化营销、优惠促销.

OWNER_IMPLEMENTATION_AUTHORIZATION: GRANTED IN CHAT 2026-08-29
EXECUTION_PROVIDER: US_CODEX_CLI
GIT_DELIVERY_AUTHORIZATION: GRANTED IN CHAT 2026-08-29 FOR COMMIT + PUSH + DRAFT PR ONLY; merge remains NOT AUTHORIZED.

This package is the shared foundation required before Customer M1.3 Subscriber Integration. Customer M1.3 remains parked and must not invent its own Subscriber table or consent authority.

Andy Core 1.5.3 is being developed independently in another execution stream. This package MUST NOT edit Andy Core or assume unfinished Andy Core endpoints.

## Parallel preflight
- WORKTREE: `/Users/a/AI/007/worktrees/erp-marketing-os-m1-0-subscriber-foundation-001`
- BRANCH: `feature/erp-marketing-os-m1-0-subscriber-foundation-001`
- BASELINE: `origin/main@be1afca30e839361975cfabe941b99f253b708fb`
- DEPENDENCIES: Customer M1.2 merged PR #120 only.
- DEPENDENCY_TYPE: MERGED_PR
- DEPENDENCY_STATUS: SATISFIED
- PARALLEL_SAFE: YES with Andy Core; Customer M1.3 implementation remains WAITING.
- COMMON_FOUNDATION_CHANGE_REQUIRED: YES; this Work Package is that foundation.

## Frozen Marketing OS navigation
Marketing OS is inserted between 客户 and 网红 in the level-1 shell.

Level-1:
- `营销` → `/marketing` → route `shell.marketing` → permission `marketing.view` → proposed emoji `📣`.

Level-2, in this exact order:
1. `邮件订阅` → `/marketing/subscribers` → `marketing.subscribers`.
2. `群发邮件` → `/marketing/newsletters` → `marketing.newsletters`.
3. `营销活动` → `/marketing/campaigns` → `marketing.campaigns`.
4. `自动化营销` → `/marketing/automations` → `marketing.automations`.
5. `优惠促销` → `/marketing/promotions` → `marketing.promotions`.

`menu/APPROVED_MENU.json`, `config/erp_shell_navigation.php`, menu tests and this WP MUST move together.

## M1.0 product scope
- Add Marketing as a formal Laravel module/provider/route namespace.
- Add `marketing.view` and `marketing.operate`; Administrator receives all automatically, Operations receives both.
- `/marketing` is a real Marketing OS overview, never a generic shell placeholder.
- `/marketing/subscribers` is a real Subscriber workspace with search/filter/pagination and truthful empty state.
- The other four approved level-2 routes may render explicit planned Marketing workspaces with zero fabricated metrics or records.
- No public subscribe endpoint is introduced in M1.0.
- No write-to-Klaviyo, WordPress, WooCommerce or Andy Core is introduced in M1.0.

## Subscriber authority contract
`marketing_subscribers` is the ERP Marketing identity record. It is NOT Customer and MUST NOT be created from an Order or Customer purchase event.

`marketing_subscriptions` records provider/source-specific email subscription/consent facts. Purchase does not imply consent. Customer existence does not imply consent. Missing external authority remains missing/unknown and must never be promoted to subscribed.

Canonical linkage rules:
- normalize email by trim + lower-case for exact identity matching;
- `marketing_subscribers.email` is unique canonical subscriber identity in M1.0;
- Customer M1.3 may later project Subscriber facts by deterministic normalized-email equality only;
- Customer M1.3 must not copy Subscriber/Consent fields into Customer;
- fuzzy matching, name matching and order-recipient matching are prohibited;
- Subscriber creation must never create a Customer automatically.

No real subscriber rows may be fabricated for local runtime. Synthetic rows are allowed only inside tests or explicitly labelled disposable UAT fixtures.

## Schema contract
Create exactly two Marketing tables in this package.

`marketing_subscribers`:
- `id`
- `email` normalized lower-case, unique and required
- `first_name`, `last_name`, `phone`, `country_code`, `language_code` nullable
- timestamps

`marketing_subscriptions`:
- `id`, `subscriber_id` FK
- `channel` (M1.0 supports `email`; schema may remain extensible)
- `provider` required string
- `source_site` nullable string/domain; no Site Registry exists on current main
- `external_subscription_id`, `external_list_id`, `external_list_name` nullable
- `status`: subscribed / unsubscribed / suppressed / pending / unknown
- `consent_source` nullable
- `subscribed_at`, `unsubscribed_at`, `status_updated_at`, `last_synced_at` nullable
- timestamps

Index provider/source/status. When an external subscription ID exists, `(provider, external_subscription_id)` must prevent duplicate imported authority. Do not create a Site/Store master as a side effect of this package.

## M1.0 Subscriber workspace
`/marketing/subscribers` is read-only in M1.0. `marketing.operate` is reserved for future controlled imports/sync/actions and does not justify manual consent creation in this package.

List requirements:
- server pagination, default 50/page;
- search exact/partial email and name;
- filters: subscription status, provider, source site;
- columns: Email, name, current email status, provider, source site, list, subscribed date, unsubscribed date, last sync;
- show multiple provider/source facts without collapsing contradictory states into an invented global consent;
- zero records produces an explicit truthful empty state, not sample subscribers.

Marketing Overview may show real counts only from these tables. If zero rows exist, show zero. It must not derive subscriber count from Customer or Order tables.

The four future workspaces must say that their engines are not implemented yet. They may explain intended scope but must not show synthetic campaign, revenue, send, coupon or automation metrics.

## Permission contract
- `marketing.view`: all Marketing OS read workspaces.
- `marketing.operate`: reserved Marketing mutation/sync authority.
- Add a `营销` group to PermissionCatalog.
- Administrator inherits both through the canonical all-permission mechanism.
- Operations preset receives both permissions.
- Logistics, Sales Representative, KOL Specialist, Finance and Writer presets are unchanged unless separately Owner-approved later.

## Explicit exclusions
- No Andy Core 1.5.3 repository/file mutation.
- No Klaviyo API client, credential, webhook or write integration.
- No WooCommerce/WordPress subscriber import or sync.
- No newsletter sending engine.
- No Campaign business engine.
- No Marketing Automation execution engine.
- No Coupon/Promotion synchronization or adapter implementation.
- No marketing attribution or ROI engine.
- No Customer schema mutation, subscriber copy/backfill or Customer auto-creation.
- No Customer M1.3 implementation in this branch.
- No Site/Store Registry foundation.
- No production migration or deployment.

## Acceptance criteria
1. Marketing is a formal level-1 module between 客户 and 网红 with the five frozen children in exact order.
2. Runtime menu, approved baseline, permission contract and automated menu tests agree exactly.
3. `/marketing` and `/marketing/subscribers` are real Inertia workspaces gated by `marketing.view`.
4. Subscriber/Subscription schema implements the frozen consent separation and rollback cleanly.
5. Empty local runtime contains no fabricated Subscriber/Consent data.
6. A Customer or Order record cannot by itself make a Subscriber subscribed.
7. Synthetic test Subscriber data never creates a Customer.
8. Four future Marketing pages expose only truthful planned states.
9. Existing Customer M1.2 and unrelated modules do not regress.

## Validation contract
VERIFICATION_TIER = HIGH_RISK because this package changes schema and authorization.

US implementation host must run complete package verification before push:
- focused Marketing feature/model/permission tests;
- Approved Menu + App Shell navigation regression;
- Customer M1.2 focused regression;
- disposable database migrate fresh → rollback Marketing migration → reapply;
- `ModuleArchitectureTest` with new Marketing boundaries/routes/permissions/migration/frontend files registered; unrelated KOL drift must be classified, not repaired here;
- frontend tests where added, `npm run typecheck`, fresh `npm run build`;
- `git diff --check`; `public/hot` absent;
- real System Chrome local runtime for `/marketing`, `/marketing/subscribers` and all four planned workspaces.

Under Execution Role Model V1.3, US Mac is the primary implementation and verification host. No China/secondary-host verification is required by default; a secondary exact-SHA verification is added only if Owner explicitly requests it or a cross-host issue requires it.

Owner UAT must visually approve Marketing shell placement, five children, Overview and Subscriber workspace before any merge authorization. Merge and production deployment are separate Owner gates.

## Verification evidence — Owner UAT handoff
- US Codex CLI implementation: `gpt-5.6-luna`, reasoning effort `medium`.
- Focused Marketing/Menu/Shell PHP: 32 passed / 1736 assertions.
- Full PHPUnit: 550 passed / 5058 assertions; only 3 failures remain and all 3 reproduce on exact `main@be1afca` in `ModuleArchitectureTest` (pre-existing KOL/Finance baseline drift).
- Vitest: 353 passed / 3 failed; all 3 failures reproduce on exact `main@be1afca` in Orders/Create and Products/Edit stale assertions; no Marketing Vitest regression.
- `npm run typecheck`: PASS.
- fresh `npm run build`: PASS; UAT browser loaded the current manifest bundle.
- `npm run typecheck:e2e`: PASS.
- E2E artifact/runner contracts: 16/16 PASS.
- Disposable SQLite migrate fresh → rollback Marketing migration → reapply: PASS.
- Real System Chrome: `/marketing`, `/marketing/subscribers`, `/marketing/newsletters`, `/marketing/campaigns`, `/marketing/automations`, `/marketing/promotions` all HTTP 200; five Marketing children visible; Subscriber truthful zero-data state visible.
- Owner UAT URL: `http://erp.b` (local Valet target temporarily points to this Work Package worktree).
- Git delivery remains NOT AUTHORIZED; no commit/push/PR/merge performed.

## Delivery contract
ONE_WORK_PACKAGE_ONE_BRANCH_ONE_PR applies. Contract, implementation, verification, UAT remediation and final evidence stay on this branch/PR until merge.

No commit, push, Draft PR, migration execution outside disposable/local test databases, merge or deployment is authorized merely by this contract. Implementation requires explicit Owner authorization for this Marketing M1.0 package.

PROJECT POSITION
PROGRAM_ROADMAP: Architecture/Foundation → Existing Operations/MVP1 → Customer/KOL/Inventory/Sync expansion → Marketing OS foundation and B2C marketing capabilities → remaining integrations/hardening; repository historical roadmap does not yet enumerate this newly Owner-approved Marketing OS insertion
CURRENT_TRACK_PHASE_MAP: Marketing OS M1.0 Subscriber Foundation → Customer M1.3 Subscriber Integration → Marketing Newsletter → Marketing Campaign → Marketing Automation → Marketing Promotion → later Marketing Analytics
COMPLETED_PHASES: Customer M1.1 merged; Customer M1.2 merged as PR #120 at main@436285f; Marketing OS architecture/menu scope Owner-approved in chat
CURRENT_PHASE: Marketing M1.0 Owner UAT passed; Git delivery authorized for commit + push + Draft PR
CURRENT_WORK_PACKAGE: ERP-MARKETING-OS-M1-0-SUBSCRIBER-FOUNDATION-001
PACKAGE_STATUS: OWNER_UAT_PASS
WAITING_PHASES: Draft PR review; separate Owner merge gate; Customer M1.3 remains parked
BASELINE: origin/main@be1afca30e839361975cfabe941b99f253b708fb
GITHUB_STATE: local implementation and verification complete; Owner authorized commit + push + Draft PR on 2026-08-29; merge remains NOT AUTHORIZED
SOURCE_CONFLICTS: historical repository Program Roadmap does not yet enumerate Marketing OS; current Owner 2026-08-29 product decision is the active Marketing scope authority; no code dependency conflict found
GLOBAL_GATE: OWNER_UAT_READY; package-specific gates pass; repository ModuleArchitecture retains 3 exact-main pre-existing non-Marketing KOL/Finance baseline drift failures that are classified and not repaired here
PRODUCTION_DEPLOYMENT_STATUS: NO WITH EVIDENCE; no production access/migration/deployment authorized
NEXT_DEPENDENCY: Owner UAT decision; no M1.1 or later Marketing package is authorized to start
EVIDENCE_AS_OF: 2026-08-29

ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: planning value 10.25 effective hours; range 8.0–13.5 hours
KNOWN_CURRENT_TRACK_REMAINING: Marketing M1.0 plus Customer M1.3 and later Marketing Newsletter/Campaign/Automation/Promotion packages; later package hours are not frozen here
ARCHITECTURE_GATE_REMAINING: Marketing package-specific implementation/verification included below; repository-global KOL baseline drift is outside this package
MVP1_TOTAL_ESTIMATE: UNKNOWN WITH EVIDENCE; historical program estimate is not recalculated by this newly approved Marketing package
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE; not revised here
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE; not revised here
MVP1_REMAINING: UNKNOWN WITH EVIDENCE; no accepted program-wide actual ledger recalculation in this package
POST_MVP_KNOWN_INCREMENT: UNKNOWN WITH EVIDENCE; future Marketing packages are not estimated in this foundation
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE; not recalculated here
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE; Marketing insertion changes future program scope and requires later roadmap re-estimation
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE; no accepted Work Log exists for this not-yet-implemented package
ESTIMATE_CONFIDENCE: MEDIUM; bounded foundation scope, but migration/permission/Architecture integration can expose repository baseline remediation needs
ESTIMATE_AS_OF: 2026-08-29
ESTIMATE_METHOD: scope-based effective-hour planning; no elapsed chat time/token conversion

ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 0.5–1.0 hours; planning value 0.75
CORE_EXECUTION: 4.5–7.0 hours; planning value 5.5
TEST_AND_VALIDATION: 2.0–3.5 hours; planning value 2.5
REMEDIATION_BUFFER: 1.0–2.0 hours; planning value 1.5
ESTIMATED_EFFECTIVE_HOURS: 10.25 hours
ESTIMATE_RANGE: 8.0–13.5 hours
ESTIMATE_BASIS: legacy footer label retained for repository schema compatibility; actual implementation provider is US Codex CLI per Execution Role Model V1.3; estimate covers Marketing module, schema, permissions, menu, UI, tests and HIGH_RISK verification preparation
OWNER_REVIEW: INCLUDED
MERGE: EXCLUDED
MIGRATION: INCLUDED / LOCAL TEST ONLY
MIGRATION_FILE_AUTHORING: INCLUDED
MIGRATION_EXECUTION: INCLUDED / DISPOSABLE OR APPROVED LOCAL TEST DATABASE ONLY
UAT: INCLUDED
DEPLOYMENT: EXCLUDED

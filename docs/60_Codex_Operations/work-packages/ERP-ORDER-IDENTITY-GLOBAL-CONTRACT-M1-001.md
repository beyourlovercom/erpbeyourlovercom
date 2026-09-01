# ERP-ORDER-IDENTITY-GLOBAL-CONTRACT-M1-001

STATUS: ACTIVE / CUSTOMER GLOBAL SEQUENCE CONTRACT FROZEN / IMPLEMENTATION AUTHORIZED
EXECUTION_PROVIDER: US_CODEX_CLI
VERIFICATION_TIER: HIGH_RISK
OWNER: Harr
SYSTEM: BYL ERP
BASELINE: origin/main@392fbad
BRANCH: feature/erp-order-identity-global-contract-m1-001
WORKTREE: /Users/a/AI/007/worktrees/erp-order-identity-global-contract-m1-001
DEPENDENCIES: NONE
DEPENDENCY_TYPE: NONE
DEPENDENCY_STATUS: N/A
PARALLEL_SAFE: YES; do not touch PR #145 worktree/branch.

## Purpose

Create one canonical ERP business identity layer without replacing `orders.id` or the existing technical `internal_order_number`.
Preserve current source / legacy order values for traceability while adding a globally unique `erp_order_id`.
Add an administrator-editable global order-prefix token to `/settings`.

## Owner amendment — 2026-09-01

The historical fixed-prefix rule is superseded.
The prefix token is global configuration, default `007erp`, editable only by administrators.
The stored token excludes the delimiter. The generator inserts `-` automatically.
Example: token `007erp` -> `007erp-0901-c3721`.
Example: token `007` -> `007-0901-c3721`.
Changing the token affects future generated ERP Order IDs only; no silent historical rename/backfill.
## Frozen data contract

- `orders.id` remains immutable relational truth and FK authority.
- `orders.internal_order_number` remains generated technical identity; do not repurpose it.
- Add nullable unique `orders.erp_order_id` for the new ERP-readable business identity.
- Reuse `orders.external_order_number` as the current source / legacy business number; do not duplicate it into a new `source_id` column in M1.
- Existing `order_external_references` remains connector idempotency authority.
- Add singleton `order_identity_settings` with `id=1`, `prefix_token`, `next_customer_number`, `updated_by`, timestamps.
- Default `prefix_token = 007erp`.
- Prefix token validation: lowercase ASCII alphanumeric only, 1-24 chars; trim and lowercase before save.
- Persist ERP IDs as `{prefix_token}-{MMDD}-{type}{identity_number}{optional_suffix}`.
- `erp_order_id` has database global uniqueness.

## Frozen source-number rules

Customer orders:
- type = `c`.
- identity number = ERP-global Customer sequence allocated transactionally from `order_identity_settings.next_customer_number`.
- the Customer sequence is independent of Woo provider order IDs, site/connection keys and `orders.id`.
- prefix changes do not reset or reuse the Customer sequence.
- Woo orders retain their authoritative provider identity in `order_external_references(provider, connection_key, reference)` and `external_order_number`.
- date = Customer order business/source date; Woo uses Woo source order date, not sync date.
- example: global Customer sequence 3721 on 2026-09-01 -> `007erp-0901-c3721`.

KOL fulfillment:
- type = `k`.
- source number = canonical KOL database identity `kols.id`.
- date = fulfillment order business creation date.
- example: KOL id 381 -> `007erp-0901-k381`.
KOL collision:
- default has no suffix.
- if same `MMDD + kols.id` collides, do not silently mutate.
- generator may accept an explicitly supplied uppercase suffix `A`, `B`, `C`... after validation.
- UI for manual collision editing is in scope only where an existing order edit surface can safely expose it without changing relational keys.

Manual ERP-only Customer orders:
- allocate from the same ERP-global Customer sequence used by Woo Customer orders.
- preserve existing `external_order_number` behavior as source / operator-visible evidence.
- do not derive `c{number}` from `orders.id`, Woo IDs, random values or connector-local counters.

## Settings UX contract

On `/settings`, add a compact module titled `订单编号前缀`.
Show the current token in one input and a live/static example below it.
Only users who pass `system.permissions.manage` may reach or mutate this setting.
Save via a dedicated server-side route protected by the same permission.
Do not add a new level-1 or level-2 navigation item.
Do not require a separate settings page.
Do not store the trailing `-` in the database.

## Service contract

Create one canonical order identity generator/service; Customer, KOL and future connectors must not hand-build ERP IDs independently.
The service owns prefix lookup, normalization, format generation and global uniqueness checks.
Prefix changes never rewrite existing rows.
## Implementation scope

1. Migration/model support for `orders.erp_order_id` and singleton order identity settings.
2. Canonical application service for prefix and ERP ID generation.
3. `/settings` read/update integration and Vue module.
4. Customer creation allocates `erp_order_id` from the ERP-global Customer sequence; Woo uses source order date while provider identity remains separate.
5. Woo inbound retries preserve the existing ERP ID and never regenerate it after creation.
6. KOL collaboration fulfillment creation sets `erp_order_id` from `kols.id` + business creation date.
7. Order payloads/detail/list/search/export expose ERP ID without removing source order values.
8. Global/order search can resolve exact/partial ERP ID and existing external/source order number.
9. Tests cover permission, settings persistence, prefix change behavior, uniqueness, Woo idempotency, KOL generation and no historical rewrite.
10. Update architecture/WP evidence only where required by this package.

## Explicit prohibitions

- No Production mutation, deploy, backfill, tag, release or merge.
- No modification of PR #145 or its worktree.
- No rewrite of existing `external_order_number` values.
- No replacement of `orders.id` relationships.
- No use of editable ERP ID strings as FKs.
- No silent collision suffix.
- No new order type letter beyond `c` and `k`.
- No Claude session for this WP unless Owner explicitly reauthorizes it.
- No China-machine verification by default.
## Acceptance criteria

- Fresh DB migration PASS.
- Existing local DB migration PASS without rewriting historical order numbers.
- Default prefix reads `007erp` when settings row is absent or newly seeded.
- Admin can change prefix token from `007erp` to `007` at `/settings`.
- Non-admin mutation is denied server-side.
- Customer sequence 3721 dated 2026-09-01 generates `007-0901-c3721` after prefix is set to `007`, regardless of Woo order ID.
- Re-syncing the same Woo order keeps the same `erp_order_id`; two Woo connections with the same provider order ID receive different Customer sequence numbers without suffixes.
- New KOL fulfillment for KOL id 381 on 2026-09-01 generates `007-0901-k381` after prefix is set to `007`.
- Existing source / legacy numbers remain unchanged.
- `erp_order_id` uniqueness is enforced by validation/service plus DB constraint.
- Existing order/logistics/inventory/shipment/KOL relationships remain keyed by immutable IDs.
- Relevant PHPUnit, Vitest, typecheck and build PASS.
- Worktree clean at merge-candidate revision.

## Git delivery authority

Local implementation/testing: AUTHORIZED.
Migration file authoring and local migration testing: AUTHORIZED.
Commit/push/Draft PR after package verification: AUTHORIZED by canonical handoff development gate.
Merge: NOT AUTHORIZED.
Production migration/deploy: NOT AUTHORIZED.
Tag/release: NOT AUTHORIZED.

## Discovery evidence

Current main already uses `orders.id` as relational truth, `internal_order_number` as unique technical number, `external_order_number` as visible/source business number, and provider external references for connector identity.
`/settings` is protected by `can:system.permissions.manage` and renders `Settings/Index.vue`.
`kols` has no separate influencer-number field; its canonical database identity is `kols.id`.
PROJECT POSITION
PROGRAM_ROADMAP: Architecture Foundation -> MVP1 Existing Operations -> Inquiry -> Woo Inbound -> Tracking Outbound -> Sync Orchestration -> Product/Media -> WordPress Product Sync -> Content/SEO -> Full Order Workspace -> Logistics OS -> KOL OS -> Inventory/Procurement -> Accounting/ROI -> Additional Integrations -> Production Hardening
CURRENT_TRACK_PHASE_MAP: M1.0 Discovery [COMPLETE] -> M1.1 Contract Freeze [COMPLETE] -> M1.2 Data Model [COMPLETE] -> M1.3 Generator [COMPLETE] -> M1.4 Customer Integration [COMPLETE] -> M1.5 KOL Integration [COMPLETE] -> M1.6 Settings/Edit UX [COMPLETE] -> M1.7 Search/Export/API [COMPLETE] -> M1.8 Regression/UAT [COMPLETE] -> M1.9 Git Delivery [COMPLETE]
COMPLETED_PHASES: READ_ONLY_DISCOVERY_GATE; OWNER PREFIX AMENDMENT; CONTRACT FREEZE
CURRENT_PHASE: GitHub CI / Owner Review
CURRENT_WORK_PACKAGE: ERP-ORDER-IDENTITY-GLOBAL-CONTRACT-M1-001
PACKAGE_STATUS: DRAFT PR #151 OPEN / CI PENDING / MERGE NOT AUTHORIZED
WAITING_PHASES: GitHub CI + Owner merge authorization
BASELINE: origin/main@392fbad
GITHUB_STATE: PR #151 OPEN/DRAFT for this WP; PR #145 remains separate and untouched
SOURCE_CONFLICTS: Uploaded handoff fixed prefix `007erp-` is superseded only for prefix mutability by Owner 2026-09-01 current instruction; latest main still treats external_order_number as visible business number, so this WP adds erp_order_id rather than destroying source evidence
GLOBAL_GATE: ONE_WORK_PACKAGE_ONE_BRANCH_ONE_PR + ONE_ACTIVE_IMPLEMENTATION_PROVIDER_PER_WORKTREE
PRODUCTION_DEPLOYMENT_STATUS: NO; explicitly not authorized
NEXT_DEPENDENCY: GitHub CI must pass; merge requires separate explicit Owner authorization
EVIDENCE_AS_OF: 2026-09-01 +08:00

ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 5.5 effective hours planning value; range 4.0-7.0
KNOWN_CURRENT_TRACK_REMAINING: 5.5h planning value for this bounded WP; broader program remaining not safely recomputed from current repository evidence
ARCHITECTURE_GATE_REMAINING: 0 for historical architecture foundation; this WP is a new cross-module foundation
MVP1_TOTAL_ESTIMATE: UNKNOWN WITH EVIDENCE; current repository roadmap ledger was not recomputed in this package
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE; not needed to authorize this bounded WP
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE; not recomputed
MVP1_REMAINING: UNKNOWN WITH EVIDENCE; parallel completed/open packages prevent safe arithmetic here
POST_MVP_KNOWN_INCREMENT: UNKNOWN WITH EVIDENCE
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE; no accepted Work Log ledger calculated for this WP yet
ESTIMATE_CONFIDENCE: MEDIUM-HIGH
ESTIMATE_AS_OF: 2026-09-01
ESTIMATE_METHOD: bounded schema + service + settings UI + Woo/KOL integration + regression surface
ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 0.5h planning value; range 0.3-0.7h
CORE_EXECUTION: 3.2h planning value; range 2.3-4.1h
TEST_AND_VALIDATION: 1.2h planning value; range 0.9-1.5h
REMEDIATION_BUFFER: 0.6h planning value; range 0.5-0.7h
ESTIMATED_EFFECTIVE_HOURS: 5.5h
ESTIMATE_RANGE: 4.0-7.0h
ESTIMATE_BASIS: Frozen prefix/settings/data semantics with one new nullable unique ERP identity column, one singleton settings authority, one generator, and bounded Woo/KOL integrations.
OWNER_REVIEW: INCLUDED
MERGE: EXCLUDED
MIGRATION: INCLUDED
MIGRATION_FILE_AUTHORING: INCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: INCLUDED
DEPLOYMENT: EXCLUDED

## Execution checkpoint — 2026-09-01

Verified non-conflicting implementation:
- configurable singleton prefix, default `007erp`; admin-only `/settings` mutation; no historical rewrite
- nullable globally unique `orders.erp_order_id`; existing relational PK/FK model unchanged
- canonical generator supports `007-0901-c3721` and `007-0901-k381`; suffix requires explicit input
- KOL fulfillment uses `kols.id`; search/display/export payloads expose ERP ID alongside source identity
- final affected backend regression after rebase: 51 tests / 659 assertions / exit 0
- frontend after rebase: vue-tsc PASS; 43/43 affected Vitest tests PASS; Vite production build PASS
- fresh temporary SQLite migration PASS on latest main baseline, including this WP migration; no Production migration executed

Resolved Customer multi-store identity decision:
- existing Woo identity remains `(provider, connection_key, reference)` and may legitimately repeat the same Woo order ID across stores
- Customer `c{number}` is therefore an ERP-global Customer sequence, not Woo order ID and not `orders.id`
- sequence allocation is serialized through the singleton settings row and never resets when prefix changes
- no automatic Customer `A/B/C` suffix is permitted or required
- Woo retries reuse the existing order row and ERP ID; different connections with the same Woo ID allocate distinct Customer sequence numbers

## Final verification — merge-candidate

- rebased cleanly onto `origin/main@392fbad`; no overlapping files with the four intervening Backup / Inventory commits
- Customer sequence contract: manual Customer and Woo inbound allocate from one ERP-global transactional sequence
- Woo retry preserves ERP ID; different Woo connection keys with the same provider order ID allocate distinct Customer sequence numbers
- KOL fulfillment preserves explicit `k{kols.id}` ERP identity
- affected backend regression: 51 tests / 659 assertions / exit 0
- frontend: `vue-tsc --noEmit` PASS; affected Vitest 43/43 PASS; Vite production build PASS
- fresh temporary SQLite `migrate:fresh --force` PASS through latest migration set
- `git diff --check` PASS
- Production deploy/migration: NOT PERFORMED
- Merge: NOT AUTHORIZED

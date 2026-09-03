# ERP-PRODUCT-REAL-DATA-M1-6-WOO-MEDIA-INBOUND-001

STATUS: OWNER_UAT_PASS / GIT_DELIVERY_IN_PROGRESS
EXECUTION_PROVIDER: US_CODEX_CLI
VERIFICATION_TIER: HIGH_RISK
OWNER_UAT_REQUIRED: YES
GIT_DELIVERY_AUTHORIZATION: AUTHORIZED_BY_CONTINUOUS_OWNER_AUTHORIZATION_V1
PRODUCTION_AUTHORIZATION: NOT_AUTHORIZED

## Goal

Extend the Owner-PASS M1.5 Product360 with WooCommerce Product + Media read-only inbound synchronization.
WooCommerce is an external provider; ERP Product remains canonical. The package must reuse M1.4 external references and existing SyncRun evidence rather than create a competing sync/truth system.

## Owner visual gate

PRE_DEVELOPMENT_RENDER: PASS
APPROVED_INTENT: real Woo product image/gallery, provider product metadata, sync status/history and existing sales/KOL comparison visible in Product360.
VISUAL_BOUNDARY: preserve current ERP shell and all M1.5 frozen Product360 truth. The render does not authorize replacing menu governance or deleting M1.5 tabs/data.

## Canonical ownership

- Product core: `products.id`, canonical SKU, name/type/prices/status.
- Woo identity: `product_external_references(provider=woocommerce, connection_key, reference)`.
- Woo-only evidence: Product-owned provider snapshot table introduced here.
- Product media: Product-owned media table introduced here; downloaded bytes are stored locally under authenticated ERP access.
- Sync evidence/history: existing `sync_runs` / `sync_run_items`; this WP adds only a nullable `product_id` relation to sync items.
- M1.3 Weight Evidence, Inventory, Orders, Shipments, KOL, Finance and Audit truth are never overwritten by Woo product sync.

## Frozen inbound identity rules

1. Exact Woo external reference for `(woocommerce, connection_key, remote_product_id)` wins.
2. Without an external ref, a nonblank remote SKU may resolve only by exact canonical SKU or trusted M1.2 SKU match/alias evidence.
3. If no identity matches and remote SKU is nonblank, create one canonical Product and bind the Woo Product reference.
4. Blank-SKU remote Products without an existing external reference are not auto-created; record a conflict/failure for reconciliation.
5. Once a remote Product reference is bound, a changed remote SKU must never silently rename canonical SKU. Exact canonical/alias evidence is required; otherwise fail closed.
6. Woo variation count is provider metadata only. M1.6 does not invent a new parent/child SKU model or remap existing M1.4 variation references.

## Product core update contract

For a successfully resolved Woo Product, inbound may update only:
- `source_type=woocommerce`
- `product_type`
- `name`
- `sale_price`
- `regular_price`
- `is_active` (`publish` => true; other provider statuses => false)

Inbound must not mutate canonical SKU, GTIN, adopted weights, shipping weight, dimensions, cost/customs, inventory, order or KOL facts.
Newly created Products use the remote SKU as canonical SKU and receive the Product external reference.

## Provider snapshot schema

Create `product_provider_snapshots` with:
- product_id, provider, connection_key, remote_product_id
- permalink, provider_status
- description_text, short_description_text
- category_names JSON
- variation_count
- provider_created_at, provider_modified_at, last_synced_at
- payload_hash
- unique `(provider, connection_key, remote_product_id)`
- unique `(product_id, provider, connection_key)`

## Product media schema / security

Create `product_media` with:
- product_id, provider, connection_key, provider_media_key, remote_media_id nullable
- source_url, storage_path, mime_type, size_bytes, sha256, alt_text
- position, is_primary, last_synced_at
- unique `(product_id, provider, connection_key, provider_media_key)`

Media ingestion rules:
- only HTTPS image URLs from exact Woo base host or configured `WOO_MEDIA_ALLOWED_HOSTS` are allowed;
- redirects are bounded and every redirect target is revalidated;
- MIME allowlist: JPEG, PNG, WEBP, GIF; SVG and executable/polyglot content are not accepted;
- maximum image body size: 10 MiB;
- store on Laravel `local` disk; expose only via authenticated `products.view` media route;
- do not hotlink remote images in Product360;
- successful complete media reconciliation removes stale local rows/files no longer present remotely;
- when any incoming image fetch/validation fails, preserve the last known local media set and mark the product sync item failed/partial rather than deleting good media.

## Woo gateway / sync contract

Add Product-owned `WooCommerceProductGateway` and HTTP implementation using the same `services.woocommerce` URL/credentials/connection key as Order inbound.
Required operations: descriptor/probe, fetch one Product, paginated Products query.
Maximum page size 100; maximum 100 pages per run.
Incremental query uses provider `modified_after` with a 5-minute overlap based on latest successful provider snapshot modified time. If no cursor exists, automatic sync may perform the bounded full Product snapshot.

Add `WooCommerceProductInboundService` using existing `SyncRunService`:
- run_type `woocommerce_product_inbound`
- provider `woocommerce`
- one SyncRunItem per remote Product, operation `woocommerce.product_inbound`
- add nullable `sync_run_items.product_id`; annotate once Product identity resolves
- result codes distinguish created/updated/unchanged/media-failed/conflict
- no outbound Woo mutation.

## Automatic + manual execution

- Add console command `products:sync-woocommerce` supporting full/incremental and a single remote Product ID.
- Add `WOO_PRODUCT_SYNC_ENABLED=false` and optional comma-separated `WOO_MEDIA_ALLOWED_HOSTS` to env/config.
- When enabled, Laravel scheduler runs incremental Product sync hourly with overlap protection.
- Product360 exposes a bounded single-product refresh action only to `sync.operate`; ordinary `products.view` remains read-only.
- Product refresh resolves the configured connection's Woo Product external reference and must not sync a different Product.

## Product360 UI contract

Preserve the M1.5 9-tab structure and frozen sales/KOL/inventory/logistics behavior.
Augment Product360 according to the Owner-PASS render:
- Hero uses the locally stored primary media; clean placeholder remains for no-media Products.
- Compact thumbnail strip/gallery when more media exists.
- Woo sync source/status indicator in Hero; refresh control only when authorized.
- Safe `查看 Woo` link only from validated provider permalink.
- 概览 retains Sales + KOL comparison and adds compact Woo product description/media/sync evidence below the frozen summary cards without hiding existing facts.
- Woo 信息 adds provider status, categories, remote timestamps, variation count, last sync and recent Product sync history.
- Manual/no-Woo and sparse Products remain production-quality empty states.

## Out of scope

- ERP → Woo Product/media writes
- WordPress Posts/Pages/SEO sync
- Product variant/parent-child data-model redesign
- changing canonical SKU from remote updates
- automatic category taxonomy creation in ERP
- remote image hotlinking
- replacing Product360 frozen sales/inventory/logistics/KOL truth
- Production deployment, Production migration execution, tag/release or worktree cleanup

## Route / permission contract

- media read route: auth + `products.view` + numeric media id, fail closed when media is not Product-owned.
- single-product sync route: auth + `products.view` + `sync.operate`.
- existing Product Edit remains `products.operate`; sync permission does not confer Product editing authority.

## Acceptance criteria

1. Exact external-reference/SKU identity rules are fail-closed and idempotent.
2. Core Woo inbound updates only the frozen allowlisted Product fields.
3. Existing adopted M1.3 weight evidence is unchanged after Product sync.
4. Provider snapshot stores description/category/status/permalink/provider timestamps without becoming a second Product truth.
5. Product media downloads to authenticated local storage and Product360 never hotlinks remote media.
6. Unsafe host, redirect, MIME or >10 MiB image fails closed and preserves last known good media.
7. A fully successful later sync removes media that Woo no longer reports.
8. SyncRun/SyncRunItem records Product sync evidence and links items to canonical Product when resolved.
9. Incremental sync uses provider modified time + overlap and remains idempotent.
10. Scheduler is inert unless `WOO_PRODUCT_SYNC_ENABLED` is enabled.
11. Product360 shows real local primary image/gallery, Woo description/categories/status/timestamps and recent sync evidence.
12. Single-product refresh requires `sync.operate` and cannot redirect sync onto a different Product.
13. Manual/no-Woo Products retain clean empty states; no fabricated Woo URL/media/category.
14. M1.5 Product360 Sales/KOL KPI semantics remain `X 件 / Y 笔订单|发货`.
15. M1.2/M1.3/M1.4/M1.5 regressions remain zero-delta except new intended Product/Sync schema/UI changes.
16. No commit/push/PR/merge/deploy occurs without later explicit Owner authorization.

## Verification contract

HIGH_RISK because this package adds migrations, external HTTP/media ingestion and scheduler behavior.
Backend focused tests must cover: identity conflict/create/update/unchanged; provider snapshot; media security/size/MIME/redirect; stale-media reconciliation; SyncRun product relation; incremental cursor; single-product route permissions; M1.3 weight non-overwrite.
Frontend focused tests must cover primary image/gallery, sync indicators, authorized refresh, Woo evidence/history and empty states while preserving M1.5 KPI/tabs.
Run Product + Sync + Woo inbound regression, typecheck, production Vite build, `git diff --check`, migration rollback/forward on isolated DB, scheduler listing, route listing and Architecture regression delta.

Runtime UAT is US Mac only, real Fortify session, Admin Bypass OFF. Use isolated DB/runtime and local `admin/password` only under local/testing environment.
Post-development gate requires real Product360 screenshot evidence reviewed by Owner; no Git delivery before Owner PASS.

## Minimum UAT scenarios

1. Woo Product with multiple images renders local primary image + gallery and provider metadata.
2. Re-sync unchanged product is idempotent.
3. Price/name/status change updates allowlisted fields without changing canonical SKU or weight truth.
4. Removed remote image disappears only after fully successful reconciliation.
5. Invalid remote image yields sync failure/partial evidence while prior gallery survives.
6. Manual Product with no Woo mapping has no fake sync/media data.
7. Viewer can see media but cannot trigger sync; sync operator can refresh current Product.
8. Existing Sales/KOL comparison and all 9 tabs remain usable with no console/page errors.

## Dependencies / provider

BASELINE: `origin/main@f1d2509d54ee62a52b6cee9726b7bf3f0aa9460f`
DEPENDENCIES: Product M1.1-M1.5 merged, including PR #162; Woo Order inbound + SyncRun orchestration already merged.
DEPENDENCY_TYPE: MERGED_PR
DEPENDENCY_STATUS: SATISFIED
PARALLEL_SAFE: YES in dedicated worktree; same-file parallel implementation forbidden.
WORKTREE: `/Users/a/AI/007/worktrees/erp-product-real-data-m1-6-woo-media-inbound-001`
BRANCH: `feature/erp-product-real-data-m1-6-woo-media-inbound-001`

Provider rationale: the external integration is now frozen to the existing Woo REST transport pattern, exact schema, identity rules, media security contract, scheduler behavior and Product360 acceptance. This reduces ambiguity to deterministic medium-complexity implementation suitable for US Codex CLI. Codex must stop if a conflicting identity/schema/security behavior is discovered; GPT owns any re-design/remediation decision.

## Git / release authority

Local implementation, migration authoring, local migration execution on isolated UAT, tests/build and Owner-UAT preparation are authorized by Owner instruction to proceed after render PASS.
Routine commit/push/PR/check/merge now follow `BYL_ERP_CONTINUOUS_OWNER_AUTHORIZATION_V1` after Owner UAT PASS. Production migration/deploy and other Production release actions remain NOT authorized.

PROJECT POSITION
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate → MVP1 Existing Operations / Tencent Docs Replacement → ERP-INQUIRY-M1 B2B Inquiry Foundation → ERP-INQUIRY-M1 Local Milestone UAT → milestone-gated Remote Release → MVP1.1 WooCommerce Inbound → MVP1.2 Single Tracking Outbound → MVP1.3 Sync Orchestration → Product / Media → WordPress Product and Image Sync → Content / SEO → Posts / Pages → GA4 / GSC / Cloudflare → Google Docs Publishing → Full Order Workspace → Logistics OS → KOL OS and Social Providers → Inventory and Procurement → Accounting / ROI Expansion → Shopify and Additional Integrations → Production Hardening / Expansion
CURRENT_TRACK_PHASE_MAP: Product M1.1 Canonical Bootstrap [CLOSED] → M1.2 SKU Alias/Reconciliation [CLOSED] → M1.3 Logistics Weight Evidence [CLOSED] → M1.4 Woo External References [CLOSED] → M1.5 Product360 [CLOSED/MERGED PR #162] → M1.6 Woo Product + Media Inbound [CURRENT]
COMPLETED_PHASES: Product M1.1, M1.2, M1.3, M1.4, M1.5/M1.5A
CURRENT_PHASE: Product M1.6 — Woo Product + Media Inbound Sync
CURRENT_WORK_PACKAGE: ERP-PRODUCT-REAL-DATA-M1-6-WOO-MEDIA-INBOUND-001
PACKAGE_STATUS: OWNER_UAT_PASS / GIT_DELIVERY_IN_PROGRESS
WAITING_PHASES: Content / SEO, Posts / Pages and later roadmap phases
BASELINE: origin/main@f1d2509d54ee62a52b6cee9726b7bf3f0aa9460f
GITHUB_STATE: PR #162 merged; M1.6 local implementation complete; Owner UAT PASS; Git delivery in progress under continuous authorization
SOURCE_CONFLICTS: AI/CURRENT_CONTEXT.md remains stale at 2026-08-29 / PR #110; fetched origin/main and Owner-approved Product sequence are current mutable authority.
GLOBAL_GATE: implementation → high-risk US verification → live-page Owner UAT PASS → continuous Git delivery → merge when required checks are green
PRODUCTION_DEPLOYMENT_STATUS: NO WITH EVIDENCE; Production mutation is not authorized
NEXT_DEPENDENCY: complete Git delivery/CI/merge; Production remains separately gated
EVIDENCE_AS_OF: 2026-09-03

ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 9.0 effective hours planning value; 6.5-12.75h range
KNOWN_CURRENT_TRACK_REMAINING: M1.6 only is frozen in this Product track; later Content/SEO scope remains UNKNOWN WITH EVIDENCE
ARCHITECTURE_GATE_REMAINING: UNKNOWN WITH EVIDENCE; M1.6 requires zero new architecture regression beyond intended schema/registry updates
MVP1_TOTAL_ESTIMATE: UNKNOWN WITH EVIDENCE; stale CURRENT_CONTEXT cannot safely provide current total
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE; not recalculated in this WP
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE; not recalculated in this WP
MVP1_REMAINING: UNKNOWN WITH EVIDENCE; no accepted cross-program Work Log reconciliation loaded
POST_MVP_KNOWN_INCREMENT: UNKNOWN WITH EVIDENCE
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE; no accepted Product-track Work Log ledger loaded
ESTIMATE_CONFIDENCE: MEDIUM-HIGH after architecture freeze
ESTIMATE_AS_OF: 2026-09-03
ESTIMATE_METHOD: bottom-up for schema + Woo gateway + inbound orchestration + authenticated media + Product360 augmentation + high-risk verification; Git delivery/deploy excluded

ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 1.0h planning; 0.75-1.25h range
CORE_EXECUTION: 5.0h planning; 3.5-7.0h range
TEST_AND_VALIDATION: 2.0h planning; 1.5-3.0h range
REMEDIATION_BUFFER: 1.0h planning; 0.75-1.5h range
ESTIMATED_EFFECTIVE_HOURS: 9.0h
ESTIMATE_RANGE: 6.5-12.75h
ESTIMATE_BASIS: frozen Woo REST/product identity/snapshot/media security/scheduler/Product360 contract on merged M1.5 + existing Woo/Sync foundations
OWNER_REVIEW: INCLUDED
MERGE: EXCLUDED
MIGRATION: INCLUDED
MIGRATION_FILE_AUTHORING: INCLUDED
MIGRATION_EXECUTION: INCLUDED (isolated local/UAT only; Production excluded)
UAT: INCLUDED
DEPLOYMENT: EXCLUDED

## Serialized remediation handoff — 2026-09-03

CODEX_IMPLEMENTATION_PROVIDER: US_CODEX_CLI (STOPPED after bounded core implementation; no concurrent provider remains)
CODEX_SESSION: `01a064bc-3dad-7dc1-a40c-41f5cf163ad1`
CODEX_STOP_REASON: implementation was in place, but verification entered repeated local runtime/path/cache diagnosis without further productive convergence.
REMEDIATION_PROVIDER: GPT_WEB+DC (SERIALIZED; authorized bounded remediation under `AI/EXECUTION_ROLE_MODEL.md`)
REMEDIATION_SCOPE: frozen-contract defects only, tests/architecture/runtime verification/UAT preparation; no redesign and no Git delivery.


## Owner UAT closure — 2026-09-03

OWNER_UAT: PASS
UAT_URL: `http://127.0.0.1:18122/products/1`
UAT_ENVIRONMENT: US Mac isolated local runtime; real Fortify session; Admin Bypass OFF
UAT_RESULT: live Product360 inspected by Owner and explicitly PASS; internal browser checks had zero console/page errors and zero failed checks.
DELIVERY_AUTHORITY: routine Git delivery proceeds under `BYL_ERP_CONTINUOUS_OWNER_AUTHORIZATION_V1`; Production remains NOT authorized.

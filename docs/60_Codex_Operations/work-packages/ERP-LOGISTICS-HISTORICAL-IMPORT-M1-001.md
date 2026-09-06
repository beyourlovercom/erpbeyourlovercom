# ERP-LOGISTICS-HISTORICAL-IMPORT-M1-001

STATUS: CLOSED / DEPLOYED / PRODUCTION ACCEPTED
EXECUTION_PROVIDER: US_CODEX_CLI
SYSTEM: BYL ERP
BASELINE: origin/main@50afe18d0e6c30907fe404d0edbfa5e5ee44647e
BRANCH: feature/erp-logistics-historical-import-m1-001
WORKTREE: /Users/a/ai/007/worktrees/erp-logistics-historical-import-m1-001
DEPENDENCY: Logistics OS V1 / PR #189 MERGED

## Purpose
Recover real historical carrier/tracking evidence from the canonical manual KOL source `0902-网红订单.xlsx` without corrupting current Logistics active fulfillment truth.

## Real-source audit facts
- Canonical source: `0902-网红订单.xlsx`, sheet `工作表1`.
- File size: 58,827,902 bytes. Current recovered workbook SHA-256: `164fe6242ec612d024bd521623879b0d3ede7afef2054347a8bce07f8f9d8c41`.
- 1,077 data rows; 971 rows have source Order No.; 964 rows have non-empty Tracking field; 482 rows have non-empty Carrier field.
- Conservative source scan found 820 rows with exact Order No. + one plausible tracking token + non-cancel status + no complex replacement/transfer text; 352 of these also have an explicit simple source carrier.
- 65 rows contain multiple plausible tracking candidates; 72 non-empty tracking cells contain no safe tracking candidate.
- Five source Order No. values occur twice with different tracking evidence. No tracking candidate is duplicated across source rows in the recovered workbook.
- Source Tracking cells include cancellations, no-shipment notes, waiting states, replacement numbers, transfer numbers, query URLs and multi-leg numbers; raw text cannot be bulk-copied into current Shipment.

## Existing ERP evidence
- Production has 961 canonical KOLs from the prior activation flow, but zero historical `kol_collaborations`.
- Existing KOL reconciliation staging has 1,077 replay rows, but that replay intentionally omitted Carrier/Tracking/Warehouse and did not backfill `matched_kol_id`.
- Exact source-row -> KOL mapping is nevertheless recoverable without fuzzy matching through `kol_import_preview_candidates.source_row_ids` plus the two applied `kol_import_activation_items` batches: first activation created 919 KOLs; second activation created the remaining 42.
- Source `KOLxxxx` Order No. is not currently present as `orders.internal_order_number` / `external_order_number`; Historical Import must not assume an existing Order row.

## Frozen architecture
1. Add an additive Logistics Historical Import workspace at `/logistics/historical-import`; do not modify current Logistics queue semantics.
2. Add a server-side XLSX reader that reads only logistics-safe columns from the source workbook: source row, Status, Stock/Warehouse, Carrier, Tracking No., Input Time, Order No., SKU and Quantity. Do not persist/read shipping address, email, phone, social URL or payment PII for this WP.
3. Persist preview/import evidence in dedicated additive tables. The original XLSX bytes are not stored in ERP or Git.
4. Resolve KOL identity only from the existing exact P2/M4 source-row activation graph. No name/email/address/order fuzzy matching.
5. Classification: `ready`, `review`, `skip`. Ready requires exact source Order No., exactly one plausible tracking candidate, non-cancel/no-shipment state, no replacement/transfer ambiguity, and exact KOL resolution. Carrier may be source-missing; preserve it as null rather than infer.
6. Preserve `source_status_raw`, `source_carrier_raw`, `source_warehouse_raw`, `source_tracking_raw`, normalized tracking candidate, source row, file checksum and evidence hash.
7. Do NOT map KOL collaboration status (`2.1 Shipping`, `2.5 Published`, `3.0 Finished`) to current Shipment lifecycle status. They remain raw historical evidence.
8. Do NOT create/update current `shipments` in M1 preview/evidence commit. Do NOT create customer Orders. Do NOT overwrite Logistics OS active truth.
9. A later separately gated projection may create canonical `kol_fulfillment` Order/Collaboration/ShipmentAttempt only after exact review rules are accepted.
10. Import commit is idempotent by source checksum + source row + normalized tracking/evidence hash and emits canonical audit evidence.

## UX / permissions
- Preview + evidence commit require `logistics.operate`; view requires `logistics.view`.
- Page shows source checksum, rows, ready/review/skip counts and a paginated row table with source Order No., KOL link when resolved, raw carrier, normalized tracking, warehouse, classification and reason.
- Commit writes only `ready` evidence rows. Review/skip rows never silently write canonical fulfillment state.
- Production commit remains feature-gated OFF until explicit Owner production-write authorization.

## First real-data UAT
- Use 10 real, non-PII clean logistics rows derived from the recovered 0902 workbook in an untracked/local UAT fixture.
- Prove parse/classification/idempotency and row->KOL exact mapping without writing Production.
- Owner reviews the historical-import page before any merge or Production write.

## Exclusions
- No provider tracking API/polling.
- No automatic carrier inference from tracking prefix.
- No parsing addresses/PII.
- No historical shipping-cost/accounting import.
- No active Shipment mutation.
- No Production write/deploy without a separate Owner gate.

## Implementation evidence — continuation

- Owner visual proposal gate: PASS explicitly received after proposal session 01a071b7-fe5f-76d0-a6cd-fa7b8a1162ea, before this implementation continuation.
- Continuation provenance: current ChatGPT continuation session; its runtime session ID is not exposed to this API agent, so no identifier is invented.
- Execution provider: GPT_WEB direct bounded implementation under the frozen contract; no concurrent provider was used in this worktree.
- Added only additive historical-import tables/models/service/controller/routes/page/tests. Current orders, shipments, kol_collaborations, and active Logistics queue code are not mutated by preview or commit.
- Source parser reads only the approved nine logistics columns and rejects malformed/missing required XLSX structure. No source workbook bytes or PII columns are stored.
- KOL resolution uses only the matching reconciliation batch checksum, preview candidate source_row_ids, and applied activation item created_kol_id; no fuzzy identity matching is used.
- Commit gate is config logistics.historical_import_commit_enabled, default false; committed rows are ready-only and idempotent by source checksum + source row + evidence hash. Audit actions are emitted for preview and commit.
- MENU_IMPACT: NO; APPROVED_MENU_CHECK: PASS. This is a level-3 page under the existing Logistics menu.
- Static verification completed: PHP syntax checks pass for all new PHP files; git diff --check passes.
- Runtime verification pending environment dependency restoration: vendor/autoload.php is absent in this worktree, so php artisan, PHPUnit, route listing, typecheck, build, and browser UAT cannot run from this checkout yet.

## Completion record

Implementation is committed locally only after the required dependency-backed checks are available. Push, PR, merge, deployment, Production writes, and Owner live-page UAT remain intentionally unperformed in this continuation.

PROJECT POSITION
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate → MVP1 Existing Operations / Tencent Docs Replacement → ERP-INQUIRY-M1 B2B Inquiry Foundation → ERP-INQUIRY-M1 Local Milestone UAT → milestone-gated remote release → MVP1.1 WooCommerce Inbound → MVP1.2 Single Tracking Outbound → MVP1.3 Sync Orchestration → Product / Media → WordPress Product and Image Sync → Content / SEO → Posts / Pages → GA4 / GSC / Cloudflare → Google Docs Publishing → Full Order Workspace → Logistics OS → KOL OS and Social Providers → Inventory and Procurement → Accounting / ROI Expansion → Shopify and Additional Integrations → Production Hardening / Expansion
CURRENT_TRACK_PHASE_MAP: Logistics OS V1 merged foundation → Historical Import M1 additive evidence workspace → Owner live-page UAT → merge; current package implementation complete pending dependency-backed verification
COMPLETED_PHASES: Logistics OS V1 / PR #189; frozen historical-import design and Owner visual proposal approval
CURRENT_PHASE: Historical Logistics Evidence Import M1
CURRENT_WORK_PACKAGE: ERP-LOGISTICS-HISTORICAL-IMPORT-M1-001
PACKAGE_STATUS: IMPLEMENTED / LOCAL COMMIT PENDING VERIFICATION
WAITING_PHASES: Owner live Local/UAT inspection; dependency-backed tests/typecheck/build; merge
BASELINE: origin/main@50afe18d0e6c30907fe404d0edbfa5e5ee44647e; branch HEAD recorded at continuation start as 8da6428
GITHUB_STATE: Local branch ahead 1 and behind 2 at continuation start; no push performed
SOURCE_CONFLICTS: NONE WITH EVIDENCE
GLOBAL_GATE: Owner visual proposal PASS recorded; Owner live UAT still required
PRODUCTION_DEPLOYMENT_STATUS: NO WITH EVIDENCE — no Production write/deploy command executed
NEXT_DEPENDENCY: Restore/install repository vendor dependencies, run full verification, prepare Local/UAT page, obtain Owner PASS
EVIDENCE_AS_OF: 2026-09-05

ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: inherited frozen WP estimate; see issued planning record; no retrospective rewrite
KNOWN_CURRENT_TRACK_REMAINING: Owner UAT and merge for this package; later Logistics OS closure packages remain frozen outside scope
ARCHITECTURE_GATE_REMAINING: UNKNOWN WITH EVIDENCE — not recalculated by this package
MVP1_TOTAL_ESTIMATE: UNKNOWN WITH EVIDENCE — not recalculated by this package
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE — issued roadmap source not changed
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE — issued roadmap source not changed
MVP1_REMAINING: UNKNOWN WITH EVIDENCE — no accepted Work Log ledger available in this continuation
POST_MVP_KNOWN_INCREMENT: UNKNOWN WITH EVIDENCE
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE — no accepted Work Log ledger supplied
ESTIMATE_CONFIDENCE: inherited from frozen WP; not recalculated
ESTIMATE_AS_OF: issued WP estimate
ESTIMATE_METHOD: inherited issued Work Package estimate; no retrospective rewrite

ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: inherited issued WP value; not changed
CORE_EXECUTION: inherited issued WP value; not changed
TEST_AND_VALIDATION: inherited issued WP value; not changed
REMEDIATION_BUFFER: inherited issued WP value; not changed
ESTIMATED_EFFECTIVE_HOURS: inherited issued WP value; not changed
ESTIMATE_RANGE: inherited issued WP range; not changed
ESTIMATE_BASIS: frozen Work Package estimate
OWNER_REVIEW: INCLUDED
MERGE: INCLUDED
MIGRATION: INCLUDED
MIGRATION_FILE_AUTHORING: INCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: INCLUDED
DEPLOYMENT: EXCLUDED

## 2026-09-06 final verification continuation — supersedes stale pending-verification text above
- Owner visual/UI proposal gate: PASS.
- Provider provenance: proposal `01a071b7-fe5f-76d0-a6cd-fa7b8a1162ea`; interrupted Codex implementation `01a071be-60cd-79b3-a43f-23e2ba828a86`; recovery Codex `01a071c2-9976-7f13-b612-d1422b874623`; both Codex child processes were explicitly terminated before GPT_WEB bounded remediation continued.
- Implementation commit already present locally: `dc21245e69d71b12ef4f7f8bb8ed28432939fe42`; final verification/test commit follows this record.
- Focused backend: `8 tests / 59 assertions` PASS under PHPUnit 12.5.33 with no failures.
- Broader backend regression: `92 tests / 2296 assertions`, 0 failures, covering Logistics OS V1, Manual Fulfillment/Inventory integration, Woo tracking outbound, shell/menu, KOL reconciliation/preview/activation. Artisan's existing `file_get_contents` warnings are repository-baseline warnings; focused PHPUnit run is clean.
- New Historical Import frontend test: `2/2` PASS.
- `npm run typecheck`: PASS.
- `npm run build`: PASS (generic existing chunk-size warning only).
- Historical migration fresh -> rollback -> reapply: PASS.
- `git diff --check`: PASS.
- Architecture suite on this branch: 5 pass / 3 fail. Exact `origin/main` baseline independently reproduces the same 5 pass / 3 fail categories (`frozen module and support boundaries`, `layer scans`, `all rule ids`), so unrelated frozen-baseline drift is not modified in this WP.
- Logistics Carrier frontend legacy test independently fails on both this branch and exact `origin/main` for the same stale Inertia `usePage` mock; this WP does not update that unrelated baseline.
- Latest-main reconciliation after verification: `origin/main@ac091842322d5cf3aef014f6d1ca4933c1a143b9` (Inventory V2 replenishment/safety-stock merge). Drift from prior base is Inventory-only and has zero path overlap with this WP. Rebase onto this exact main is required before UAT delivery.
- Production historical evidence commit gate remains OFF; no Production write/deploy has been performed.
- Real-data UAT source recovered from Library: `0902-网红订单.xlsx`, 58,827,902 bytes. First isolated UAT uses 10 safe, non-PII logistics rows while preserving original source row numbers. No PII columns are copied into the fixture.

## 2026-09-06 post-rebase real-data isolated UAT gate
- Rebased cleanly onto latest `origin/main@ac091842322d5cf3aef014f6d1ca4933c1a143b9`; path overlap with Inventory V2 main drift: ZERO.
- Post-rebase focused backend: `8 tests / 59 assertions` PASS.
- Post-rebase broad regression: `92 tests / 2296 assertions`, 0 failures.
- Post-rebase Historical Import frontend: `2/2` PASS; typecheck PASS; production build PASS; diff-check PASS.
- Architecture comparison against exact latest main: branch and main both report the identical three pre-existing failure categories; this WP does not rewrite architecture baselines.
- Real source recovered from the Owner-provided `0902-网红订单.xlsx`; UAT fixture contains only approved safe logistics columns and rows 6, 7, 9, 10, 12, 15, 18, 19, 28, 32. No PII columns are copied.
- Isolated UAT database: `/tmp/erp-logistics-historical-uat.sqlite`; no Production database is used.
- Exact two-batch source-row -> KOL activation graph is seeded locally only for the ten real source rows; no fuzzy matching.
- Real ten-row preview result: total=10, ready=10, review=0, skip=0.
- First Evidence Commit result: 10 historical evidence rows.
- Replay result: still exactly 10 historical evidence rows (idempotency PASS).
- Canonical truth isolation: Orders count unchanged, Shipments count unchanged, KOL Collaborations count unchanged.
- Owner UAT runtime is served from the US Mac on port 18132 and China Mac reachability was independently verified with HTTP 200 to the login page over Tailscale.
- Production historical import commit gate remains OFF; no Production data mutation, push, PR, merge or deployment is authorized by this UAT setup.
- NEXT GATE: Owner inspects the 10-row Historical Logistics Evidence page and replies PASS or lists remediation.

## Owner Real-Data UAT — PASS — 2026-09-06
- Owner inspected the isolated Historical Logistics Evidence workspace containing the ten real, non-PII source rows and explicitly replied `pass`.
- Owner Real-Data UAT status: PASS.
- Latest-main reconciliation after PASS: rebased cleanly onto `origin/main@152d3290e59ad6cf7881a9b850b2b36d5d6da698`; intervening main drift is Woo automatic-order-sync only and has zero path overlap with this WP.
- Post-rebase focused backend: 8 tests / 59 assertions PASS.
- Post-rebase Historical Import frontend: 2/2 PASS.
- Post-rebase typecheck + diff-check: PASS.
- Production Historical Evidence Apply remains separately gated and OFF. Owner UAT PASS authorizes Git delivery of the reviewed M1 workspace; it does not mutate Production historical data.
- PACKAGE_STATUS: OWNER_UAT_PASS / READY_FOR_GIT_DELIVERY.
## Final closure — 2026-09-06
- Final status: **CLOSED / DEPLOYED / PRODUCTION ACCEPTED**. This section supersedes all earlier pending/authorization status text in this historical work log.
- Production Batch #1 source remains `0902-网红订单.xlsx`, 1,077 source rows, checksum `164fe6242ec612d024bd521623879b0d3ede7afef2054347a8bce07f8f9d8c41`.
- M1 committed exactly **788 Ready rows -> 788 Historical Evidence**.
- M1.1 subsequently rescued **141 Review rows -> 277 additional Historical Evidence**, preserving multi-leg tracking as separate evidence.
- Final Production Historical Evidence total for this source: **1,065**.
- The remaining **3 exact-KOL-unresolved Review rows are intentionally left uncommitted by explicit Owner decision**; no M1.2 remediation is required.
- The **145 Skip rows remain skipped by design** and are not imported.
- Historical Import did not create active Shipments or KOL Collaborations; current fulfillment truth remains separate from historical evidence.
- Production Ready commit gate and Review Rescue commit gate are both OFF after completion.
- Historical Import M1/M1.1 is fully closed. Reopening requires a new explicit Owner work package.

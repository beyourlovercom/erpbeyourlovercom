# ERP-KOL-AFFILIATE-ACCOUNT-IMPORT-LIST-VISIBILITY

STATUS: PR_READY — PHASE 2 IMPLEMENTED / EXACT-HEAD VERIFIED / OWNER UAT PENDING
EXECUTION_PROVIDER: GPT_WEB + Remote Desktop Commander
VERIFICATION_TIER: DAILY
START_BASELINE: `main@6f854b9fa0b1e144fd8455a3e05ca2147839d8bf`
LATEST_MAIN_RECONCILED: `1d7cd0e22880cf6aea0183c57fc0e0c3da726d8d` (Inventory PR #188; no KOL/shared-file overlap)
DEPENDENCIES: KOL historical canonical import + exact AffiliateWP binding Production closure (#186); Andy Core Affiliate snapshot contract v1.
DEPENDENCY_TYPE: MERGED_PR + EXTERNAL
PARALLEL_SAFE: YES — dedicated worktree; no dependency on parallel Inventory/Procurement implementation.

## Goal

Refresh real provider-backed AffiliateWP / WordPress account facts without reopening historical KOL identity work, then expose the exact WordPress account and AffiliateWP ID in the existing paginated KOL library. Historical KOL identity and the audited exact bindings remain frozen authority.

## Frozen business baseline

- canonical KOLs: **961**
- exact established AffiliateWP bindings: **856**
- historical `no_affiliate`: **105**
- remaining manual Affiliate conflicts: **0**
- no fuzzy identity matching and no retroactive provisioning for the 105 unbound KOLs

## Phase 1 — Production read-only Preview — PASS

The preview uses only signed `AffiliateWpGateway::snapshot('affiliates')` GET calls plus ERP SELECTs. It does not call `AffiliateSnapshotSyncService::sync()`, does not write Provider state and does not mutate ERP rows.

Real Production evidence:

- snapshot pages: **18**
- Provider Affiliates: **1,789** / unique IDs **1,789**
- ERP Affiliate mirror before refresh: **1,774**
- established exact bindings: **856**
- canonical KOLs: **961**
- KOLs without bound Affiliate: **105**
- missing Provider IDs for established bindings: **0**
- bound WordPress user-ID drift: **0**
- bound WordPress email drift: **0**
- Provider status drift: **0**
- Affiliate-ID / WP-user-ID / username collision groups: **0**
- unbound Provider Affiliates: **933**
- Provider has **15** Affiliates not yet present in the ERP mirror

Identity capability evidence:

- `user_id`: 1,789 / 1,789; bound 856 / 856
- `email`: 1,786 / 1,789; bound 856 / 856
- `user_login` key: 0 / 1,789
- `username`: 1,786 / 1,789; bound 856 / 856

Direct Andy Core source verification established that Affiliate snapshot `username` is exactly WordPress `$user->user_login`; it is not inferred from email, display name or social handle.

## Phase 1 implementation

- `AffiliateAccountPreviewService`: full cursor-chain fetch, aggregate/redacted evidence, fail-closed structural and established-binding identity guards.
- `kol:affiliate-account-preview --json`: redacted aggregate console surface.
- focused tests prove complete pagination, zero DB mutation, no fuzzy binding, redacted JSON and fail-closed collisions/missing-bound behavior.

## Phase 2 — minimal mirror + KOL list visibility — IMPLEMENTED LOCALLY

1. Extend the existing `kol_affiliate_accounts` table only; no second Affiliate table.
2. Add nullable `wordpress_user_login` to the existing mirror.
3. Existing `AffiliateSnapshotSyncService` maps explicit Provider `user_login ?? username` to `wordpress_user_login` while preserving exact `(connection_key, affiliate_id)` upsert and persisted `kol_id` binding.
4. Add the existing-mirror relation to `Kol` and eager-load only the configured connection key to avoid N+1.
5. Normal `/kol/library` projection exposes only the minimum account fields needed by this UI:
   - `affiliate_id`
   - `wordpress_user_id`
   - `wordpress_user_login`
6. UI compact display:
   - `WordPress: <provider user_login>`
   - fallback only when login absent: `WP User #<id>`
   - `AffiliateWP: #<affiliate_id>`
   - unbound KOL: muted `未绑定 Affiliate`
7. WordPress email, provider status and reconciliation state remain in the mirror/workspace but are intentionally not sent in normal KOL-list props.
8. `/kol/library` reuses the canonical Customer/KOL account-synced grid VI rather than introducing a KOL-only variant:
   - right-edge `…` column settings trigger
   - `红人名称` pinned as the identity column
   - configurable visibility + drag reorder + `恢复默认`
   - namespace `grid.kol.library.v1` persisted through the existing `/ui/preferences/{namespace}` account preference service with local cache fallback
   - the existing misaligned `城市` header now projects canonical `shipping_city`; unsupported `店铺数量` is not exposed as a fake configurable column.

## Safety boundaries

- Existing 856 exact bindings are never recomputed from names/emails/usernames.
- The 105 historical `no_affiliate` KOLs remain unbound; list rendering cannot provision or bind them.
- No password is imported, persisted, displayed or logged.
- No Coupon / Referral / Payout mutation in this WP.
- Historical KOLs never enter the M5 new-KOL provisioning flow.
- No Production snapshot sync, migration, deployment or other write occurs without the separate Production Owner gate.

## Verification evidence on latest-main exact head

- PHP focused/regression: **48 tests / 216 assertions PASS**
  - read-only Preview
  - Affiliate snapshot sync
  - KOL library scope/projection
  - exact Affiliate binding activation
  - migration rollback → reapply
- Rebased exact-head full KOL backend suite: **exit 0 / 1,253 assertions**. Existing asset/manifest notices are warning-only; no test failure.
- KOL frontend before grid-VI reconciliation: **15 files / 101 tests PASS**
- KOL frontend after canonical grid-VI reconciliation: **15 files / 104 tests PASS**
- KOL Index account + column-settings tests: **6 / 6 PASS**
- targeted KOL foundation after grid-preference projection: **21 tests / 48 assertions PASS**
- `vue-tsc --noEmit`: PASS
- Vite production build: PASS
- `git diff --check`: PASS
- build only emits existing non-fatal chunk/plugin timing warnings.

## Current Git / parallel reconciliation

Branch: `feature/erp-kol-affiliate-account-import-list-visibility`
Worktree: `/Users/a/AI/007/worktrees/erp-kol-affiliate-account-import-list-visibility`
Start HEAD: `6f854b9fa0b1e144fd8455a3e05ca2147839d8bf`
Latest reconciled `origin/main`: `1d7cd0e22880cf6aea0183c57fc0e0c3da726d8d`
Rebased implementation HEAD verified before this documentation-only closure update: `392ce4644a8dc461f3c248107f26014ddd74cdc0`

Inventory PR #188 changed only:
- `app/Modules/Inventory/Application/Sync/XlsxAdapter.php`
- `docs/60_Codex_Operations/work-packages/ERP-INVENTORY-REAL-DATA-IMPORT-V1-001.md`
- `tests/Feature/InventoryBatchSyncXlsxTest.php`

There is no file overlap with this KOL WP and no migration-name/timestamp collision.

## NEXT EXACT GATE

1. Push/open the single WP delivery PR from the verified rebased branch.
2. Let CI complete and review the exact PR diff.
3. Prepare the real US-Mac KOL library page for Owner UAT without Production write.
4. Stop at Owner UAT / Production write gates. A Production migration + normal Affiliate snapshot sync is a separate explicit Owner-authorized event.

## PROJECT POSITION

CURRENT_TRACK_PHASE_MAP: historical KOL import CLOSED → exact Affiliate binding CLOSED → Production read-only Affiliate preview PASS → mirror/list visibility IMPLEMENTED → exact-head verification PASS → PR DELIVERY CURRENT → Owner UAT WAITING → Production write/deploy WAITING.
CURRENT_WORK_PACKAGE: `ERP-KOL-AFFILIATE-ACCOUNT-IMPORT-LIST-VISIBILITY`
PACKAGE_STATUS: PR_READY — implementation and latest-main exact-head verification complete.
GLOBAL_GATE: Production write prohibited until explicit Owner authorization.
PRODUCTION_DEPLOYMENT_STATUS: NO.
EVIDENCE_AS_OF: 2026-09-05.

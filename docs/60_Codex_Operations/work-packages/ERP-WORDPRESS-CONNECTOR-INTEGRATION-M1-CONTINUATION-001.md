# ERP-WORDPRESS-CONNECTOR-INTEGRATION-M1-CONTINUATION-001

Status: ACTIVE / PARALLEL INDEPENDENT WP

## Governance
- Execution: `PARALLEL INDEPENDENT WP`.
- Delivery: `ONE WP / ONE BRANCH / ONE WORKTREE / ONE PR`.
- Branch: `feature/erp-wordpress-connector-integration-m1-continuation-001`.
- Worktree: `/Users/a/AI/007/worktrees/erp-wordpress-connector-integration-m1-continuation-001`.
- Base: `origin/main@264fe20b79b7f145f3b976d939ddce0a07c57d8d`.
- Historical ERP Connector PR #134 is already merged and must not be reused as a second delivery PR.
- No Customer OS or Marketing OS redevelopment.
- No direct work on `main`; no Production deployment without explicit Owner authorization.

## Purpose
Continue the remaining ERP WordPress Connector integration after PR #134 delivered connection settings, health, Subscribers integration and the unified `/sync/wpapi` foundation.

## Current canonical ownership
- Subscribers → Marketing domain; reuse only.
- Affiliates → KOL `AffiliateSnapshotSyncService` / `KolAffiliateAccount`.
- Coupons → KOL `CouponSnapshotSyncService` / `KolAffiliateCoupon`.
- Referrals → KOL `ReferralCommissionSyncService` / `KolAffiliateReferral`.
- Payouts → pending canonical KOL M1.4E / Finance domain implementation; do not invent a generic payout master.
- Transport → KOL `HttpAffiliateWpGateway` + `HmacV1Signer`.
- Run evidence → `SyncRunService`, `sync_runs`, `sync_run_items`.

## External authority
- Andy Core stable release: `v1.5.3@715b83e4df9591cc5d47eb73dc8f4df30d157054`.
- Connector authority: YBY-Core `main@1b5d1b2ad13d26c15cf47db259351083c418230e` after PR #28.
- Contract version remains `1`; Referral snapshots include `paid`, `unpaid`, `rejected`, `pending`, `draft`, `failed`.

## Remaining scope
1. Add unified `/sync/wpapi` manual actions for Affiliates, Coupons and Referrals using existing domain services.
2. Record each Connector action in shared Sync Run / Sync Run Item evidence without a second sync engine.
3. Present per-resource latest run status/counts on the unified Connector page.
4. Preserve current Subscribers implementation unchanged except for unified presentation.
5. Keep Payouts explicitly dependency-gated until canonical KOL M1.4E payout domain exists in `main`.
6. Do not duplicate KOL master/mirror tables and do not refactor Marketing transport unless strictly required.

## Initial exact file-change map
- `app/Modules/Sync/Experience/Controllers/SyncWorkspaceController.php` — unified resource status/run presentation.
- `app/Modules/Sync/Experience/Routes/web.php` — Connector resource orchestration endpoints.
- `resources/js/Pages/Sync/WpApi.vue` — enable available resource actions and show latest run evidence.
- New narrowly scoped Sync application orchestration service/adapter only if needed to wrap existing domain services with `SyncRunService` evidence.
- Focused tests under `tests/Feature/Sync/` for permissions, orchestration, failure evidence and no-domain-duplication behavior.

## Hard boundaries
- No Customer module files.
- No Marketing domain redesign.
- No KOL mirror/domain duplication.
- No generic payout table.
- No Tailnet IP in application code/config.
- No merge or Production deploy without explicit Owner authorization.

## Entry gate
`CONTINUATION_M1.0 = FOUNDATION_FROZEN`

## M1.2A unified read orchestration checkpoint — 2026-09-01

`M1.2A = READ_ORCHESTRATION_CODE_READY`

Implemented:
- `/sync/wpapi` is now a Sync-scoped unified Connector surface; viewing requires `sync.view`, while each operation keeps its domain-specific operate permission.
- Subscribers remain on the existing Marketing route/service and were not reimplemented.
- Affiliates, Coupons and Referrals now expose manual Connector actions that invoke the existing canonical KOL snapshot services.
- Payouts remain explicitly disabled and dependency-gated until the independent KOL M1.4E / Finance payout domain is present in `main`.
- Existing KOL snapshot services now return exact provider references for orchestration evidence; their persistence ownership and reconciliation logic are unchanged.
- `SyncRunService` gained narrow running-run metadata updates for requested item count and safe context annotation.
- Each successful Affiliate/Coupon/Referral provider object creates one `sync_run_item` with its exact provider ID; failed provider calls create an explicit failed run item and never report success.
- Connector page shows per-resource latest run status/counts and keeps Shared Secret redacted.
- YBY-Core PR #28 compatibility is covered with a Referral `failed` provider-status fixture; provider status remains preserved without coercion.

Actual implementation file map:
- `app/Modules/Kol/Application/AffiliateWp/AffiliateSnapshotSyncService.php`
- `app/Modules/Kol/Application/AffiliateWp/CouponSnapshotSyncService.php`
- `app/Modules/Kol/Application/AffiliateWp/ReferralCommissionSyncService.php`
- `app/Modules/Sync/Application/SyncRunService.php`
- `app/Modules/Sync/Experience/Controllers/SyncWorkspaceController.php`
- `app/Modules/Sync/Experience/Routes/web.php`
- `resources/js/Pages/Sync/WpApi.vue`
- `tests/Feature/Sync/WordPressConnectorReadOrchestrationTest.php`
- `tests/Feature/Sync/WordPressConnectorSettingsTest.php`
- `tests/Feature/Marketing/WordPressSubscriberSyncTest.php`
- `tests/Architecture/ModuleArchitectureTest.php` (only the frozen `WpApi.vue` hash was updated)

Verification:
- PHP syntax on changed PHP implementation/tests: PASS.
- `git diff --check`: PASS.
- `npm run typecheck`: PASS.
- `npm run build`: PASS; existing bundle-size warning only.
- Focused Connector + Marketing + KOL read suites: PASS, 43 tests / 253 assertions / 0 functional failures; pre-existing `file_get_contents` warning class remains.
- Architecture frozen module/support boundary: PASS.
- Architecture compatibility/hash test: PASS, 448 assertions; PHPUnit marks one test risky but reports no functional failure.

Architecture baseline classification:
- Full `ModuleArchitectureTest` stops at existing `APP-001` Application→Infrastructure violations in Audit / Customers / Logistics / Shell / Support.
- The entire M1.2A working diff was temporarily stashed and the same test was rerun with application code equivalent to exact `origin/main@264fe20b79b7f145f3b976d939ddce0a07c57d8d` (only documentation commits remained).
- The same `APP-001` failure reproduced before restoring M1.2A changes.
- Classification: `BASELINE_ARCHITECTURE_VIOLATION`, not Connector regression. This WP does not reopen or modify Customer OS or other unrelated modules to repair it.

Isolation / security:
- Customer module touch: `0`.
- Marketing Application/Infrastructure domain touch: `0`.
- No Tailnet IP, Shared Secret, credential literal or temporary UAT secret appears in the implementation diff.
- No generic Connector/KOL/Payout master table was added.
- No Production deployment, merge, push or PR creation performed at this checkpoint.

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

# ERP-WORDPRESS-CONNECTOR-INTEGRATION-M1-CONTINUATION-001

Status: ACTIVE / PARALLEL INDEPENDENT WP

## Governance
- Execution: `PARALLEL INDEPENDENT WP`.
- Delivery: `ONE WP / ONE BRANCH / ONE WORKTREE / ONE PR`.
- Branch: `feature/erp-wordpress-connector-integration-m1-continuation-001`.
- Worktree: `/Users/a/AI/007/worktrees/erp-wordpress-connector-integration-m1-continuation-001`.
- Current rebased base: `origin/main@4f2bc7fb51030e7acc231173d1b2d66bd28eca79`; original continuation base was `264fe20b79b7f145f3b976d939ddce0a07c57d8d`.
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
- Payouts → KOL M1.4E `PayoutSnapshotSyncService` / `KolAffiliatePayout`; reuse only. Payout write workflow remains owned by `PayoutWorkflowService`.
- Transport → KOL `HttpAffiliateWpGateway` + `HmacV1Signer`.
- Run evidence → `SyncRunService`, `sync_runs`, `sync_run_items`.

## External authority
- Andy Core stable release: `v1.5.3@715b83e4df9591cc5d47eb73dc8f4df30d157054`.
- Connector authority for current live read UAT: YBY-Core `origin/main@3619625b6a477b335e2c077cb4ef3dc4e643a947`, containing PR #28 Referral all-status support and PR #29 Payout snapshot normalization/reconciliation fixes.
- Contract version remains `1`; Referral snapshots include `paid`, `unpaid`, `rejected`, `pending`, `draft`, `failed`.

## Remaining scope
1. Add unified `/sync/wpapi` manual actions for Affiliates, Coupons, Referrals and Payouts using existing domain services.
2. Record each Connector action in shared Sync Run / Sync Run Item evidence without a second sync engine.
3. Present per-resource latest run status/counts on the unified Connector page.
4. Preserve current Subscribers implementation unchanged except for unified presentation.
5. Reuse the now-merged KOL M1.4E payout domain for Payout read synchronization; do not duplicate or move payout write ownership into Sync.
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

## M1.2B live provider read UAT checkpoint — 2026-09-01

`M1.2B = LIVE_PROVIDER_READ_UAT_PASS`

Environment / transport:
- Real provider: Windows Local WordPress `localdev.beyourlover.com`; no Production provider was used.
- ERP UAT persistence: isolated SQLite `/tmp/erp-wpconnector-m12b.sqlite`; regular ERP application data was not used for mirror writes.
- ERP application URL remained `https://localdev.beyourlover.com`; a temporary loopback CONNECT proxy mapped only that hostname to the Windows Tailnet peer for transport. No Tailnet IP was written into ERP source or Connector application config.
- Existing Connector key ID / encrypted Shared Secret were reused without rendering or rotating the secret.
- Signed `/health` passed with contract version `1`, matching connection key and Andy Core `1.5.3`.

Final idempotency evidence:
- Affiliates Round 1: Run #1 `completed`, `1758/1758` succeeded, `0` failed.
- Affiliates Round 2: Run #6 `completed`, `1758/1758` succeeded, mirror remained `1758`, duplicate provider IDs `0`.
- Coupons first successful run: Run #4 `completed`, `2827/2827` succeeded, `0` failed.
- Coupons Round 2: Run #7 `completed`, `2827/2827` succeeded, mirror remained `2827`, duplicate provider IDs `0`.
- Referrals first full run: Run #5 `completed`, `3299/3299` succeeded, `0` failed.
- Referrals final repeat: Run #10 `completed`, `3299/3299` succeeded, mirror remained `3299`, duplicate provider IDs `0`.
- Final row-level evidence was exact: Run #6 `1758` items / `1758` distinct provider references; Run #7 `2827/2827`; Run #10 `3299/3299`.
Referral provider truth after final repeat:
- `paid=1548`, `unpaid=834`, `failed=598`, `rejected=223`, `pending=95`, `draft=1`; total `3299`.
- Final referral reconciliation: `present=3299`; no `missing_from_latest_snapshot` rows remained.

Observed failure evidence retained:
- Runs #2 and #3 failed with provider `RATE_LIMITED` after sequential large-resource execution exceeded Andy Core's fixed `60 requests / 60 seconds` protection. No partial Coupon/Referral mirror was committed.
- Run #8 failed with a transport resolution error after the resumed CLI invocation omitted the temporary loopback proxy environment; the existing 3299 Referral mirror was unchanged.
- Run #9 completed with only `2700` Referrals and exposed provider runtime drift rather than ERP duplication: exactly `598 failed + 1 draft = 599` rows were omitted and marked `missing_from_latest_snapshot`.

Provider runtime drift classification and recovery:
- Windows Local runtime `inc/class-yby-connector.php` SHA256 was `90e00bdaa1da3677debb7c4a606f0d1c05005eed2d0a451d16e1bd23b0febbeb`, exactly matching released Andy Core v1.5.3 baseline and therefore missing post-release PR #28.
- YBY-Core PR #28 changes only the Referral snapshot query to request all provider statuses; its connector SHA256 is `57cf3261ffc902c16b3f254acdc3f7c7ad3b2eb4910c257cfe483c1e7834819e`.
- Only that already-merged PR #28 three-line all-status patch was applied to Windows Local runtime. Final runtime SHA256 exactly matched `57cf3261...`; later PR #29 payout changes were not deployed as part of this gate.
- After recovery, Run #10 returned all 3299 Referrals and restored all 599 previously missing rows to `present`.

Cleanup / configuration correction:
- US Mac main development DB Connector `site_url` was corrected from the stale Tailnet IP to `https://localdev.beyourlover.com`; connection key, key ID, encrypted Shared Secret and `verify_tls=false` were preserved.
- No WordPress mutation endpoints, AffiliateWP payout/provision/status writes, merge, Production deploy, version bump, tag or release were executed.
- Temporary worktree `.env`, isolated SQLite database and loopback proxy were removed after evidence capture; Windows helper/download temp files were also removed.

Final checkpoint verification:
- `git diff --check`: PASS.
- `tests/Feature/Sync/WordPressConnectorReadOrchestrationTest.php`: PASS, 3 tests / 60 assertions / 0 functional failures; 3 instances of the pre-existing warning class remain.
- `M1.2B` classification: `LIVE_PROVIDER_READ_UAT_PASS`.

## M1.2C rebase / payout domain alignment / Draft PR gate — 2026-09-01

`M1.2C = PAYOUT_READ_ALIGNMENT_UAT_PASS / DRAFT_PR_READY`

Main drift / rebase:
- Before Draft PR creation, `origin/main` had advanced by five commits to `4f2bc7fb51030e7acc231173d1b2d66bd28eca79`.
- The new main includes ERP PR #139 / KOL M1.4E (`6ac92fd`, feature commit `44c4c91`) and ERP PR #140 Product SKU alias work.
- Continuation branch rebased cleanly onto current main with no manual conflict; the only overlapping file was the frozen architecture hash baseline.
- Because M1.4E is now canonical in `main`, the prior Payout dependency gate is resolved rather than carried into the Draft PR.

Payout read alignment:
- Reused M1.4E `PayoutSnapshotSyncService`; no payout table/domain was invented.
- `PayoutSnapshotSyncService` now returns exact provider payout IDs as `provider_references` for shared Sync Run evidence; persistence/reconciliation behavior is unchanged.
- Unified `/sync/wpapi/payouts` invokes the canonical Payout snapshot service under `sync.operate + kol.payouts.manage`.
- Connector UI enables Payouts using a dedicated `can_sync_payouts` permission result; Subscribers/other KOL permissions remain unchanged.
- Payout write/approval/complete remains exclusively owned by the existing KOL M1.4E workflow and was not called from Sync.

Code verification:
- Connector + M1.4E payout + settings suites: PASS, 14 tests / 136 assertions / 0 functional failures; existing warning class only.
- `npm run typecheck`: PASS.
- Architecture frozen boundary/hash tests: PASS, 460 assertions; existing one-risky classification only.
- `npm run build`: PASS; existing chunk-size advisory only.
- Updated frozen `WpApi.vue` SHA256: `24026ee2b4d4f404f039ebf4cd383215a8b8d88f6ad3b77ac6476e26a4d43652`.

Live Payout read UAT:
- Provider: Windows Local `localdev.beyourlover.com`; Production not used.
- Windows Local Connector was advanced from PR #28 state to current merged YBY-Core connector authority including PR #29. The Windows raw file uses CRLF; after CRLF→LF normalization its SHA256 is exactly `77c8bd5e72e4acccceb97fabc81931f1025b1db076da69b6cc0d643acb070e2e`, matching current YBY-Core main connector content.
- Signed health: PASS, contract `1`, matching connection key, Andy Core `1.5.3`, AffiliateWP active.
- ERP persistence: isolated `/tmp/erp-wpconnector-payout-m12c.sqlite`; encrypted Connector setting copied without exposing Shared Secret.
- Round 1: Run #1 `completed`, `1550/1550` succeeded, `0` failed, mirror `1550`, duplicate payout IDs `0`, provider status `paid=1550`.
- Round 2: Run #2 `completed`, `1550/1550` succeeded, mirror remained `1550`, duplicate payout IDs `0`, `1550` Sync Run Items / `1550` distinct provider references.
- `kol_unresolved=1550` in the isolated payout-only UAT is expected because real KOL/Affiliate mapping data was intentionally not copied into the isolated database; it does not indicate provider or persistence failure.
- No `/payouts/complete`, provisioning, affiliate status mutation, payout write, Production request or other WordPress mutation was executed.

## M1.2D PR #142 CI / final review checkpoint — 2026-09-01

Initial GitHub CI result:
- ERP KOL M1.4C Coupon CI: PASS.
- ERP KOL M1.4E Payout CI: PASS.
- ERP WordPress Connector M1 CI: FAIL in `Focused PHP connector gate`; Typecheck and Build had already passed.

Failure classification:
- `tests/Feature/SyncWorkspaceTest.php` still asserted the pre-M1.2A Sync overview contract: four domain cards, and the `/sync/wpapi` card keyed as `marketing` and gated by `marketing.view`.
- The canonical M1.2A behavior is five cards with a Sync-scoped `wordpress` Connector card visible under `sync.view`; Subscribers remain Marketing-owned only at the operation/domain-service level.
- No controller, service, route or UI implementation change was required to resolve the CI failure.
- Classification: `STALE_TEST_CONTRACT`, not Connector regression.

Repair verification:
- Updated only `tests/Feature/SyncWorkspaceTest.php` to assert the canonical unified Sync surface.
- Re-ran the exact GitHub focused PHP test set plus `WordPressConnectorReadOrchestrationTest`: PASS, 83 tests / 337 assertions / 0 functional failures; pre-existing warning class remains.
- Merge remains forbidden until GitHub rerun is green and explicit Owner authorization is provided.

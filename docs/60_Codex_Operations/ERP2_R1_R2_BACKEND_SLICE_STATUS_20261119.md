# ERP2 R1/R2 backend slice status — 2026-11-19

Work package: `ERP2-R1R2-LARAVEL-READONLY-20261119`
Branch: `feat/erp2-logistics-readonly-r1r2-20261119`

## Delivered backend scope

Three authenticated, read-only JSON projections are implemented:

- `erp2.readonly.logistics.pending-allocation.v1` — allocation queue rooted in the canonical Order query `LogisticsStatusSummary::pendingAllocationQuery()`.
- `erp2.readonly.logistics.domestic.pending.v1` — domestic pending queue rooted in the canonical Order query extracted to `DomesticPendingQueue`; pending membership does not require `domestic_reviewed`.
- `erp2.readonly.logistics.overseas.v1` — overseas queue rooted in canonical Shipment queries extracted to `OverseasShipmentQueue`, retaining warehouse aliases, current-warehouse review checks, OMS issued/draft distinctions, historical/cancelled exclusions and split-shipment behavior.

All three routes are in the existing authenticated web route group, require `can:logistics.view`, and are guarded to `local`/`testing` with `ERP2_READONLY_PROJECTIONS_ENABLED` defaulting to `false`. They expose GET/HEAD only. Filters and sort keys are allowlisted; pagination is 20/50/100; responses use private no-store caching and preserve persisted `final_sku` nulls.

## Verification

- The completed six-suite run reported by the preceding US Luna execution passed against SQLite `:memory:`: the three new ERP2 projection suites plus `PendingAllocationEntryPolicyTest`, `DomesticBusinessReviewTest`, and `OverseaWarehouseWorkbenchTest` — **117 tests, 2,252 assertions, PASS**.
- This delivery-session rerun used the same six suites and SQLite `:memory:`. It could not reproduce a clean full-suite result because this restricted worktree has no writable `/var/tmp`, which the existing overseas file tests hard-code for `tempnam()`. PHPUnit reported 117 tests, 2,020 assertions and 4 failures tied to that temporary-file environment. The three projection suites plus allocation and domestic regression suites passed separately: **40 tests, 424 assertions**.
- Composer dependencies were verified using an offline install with scripts disabled (`COMPOSER_DISABLE_NETWORK=1 composer install --no-scripts --no-interaction --prefer-dist`); no package download occurred.
- PHP lint passed for changed PHP files and `git diff --check` passed.
- No Production database/data, OMS, WooCommerce, external warehouse service, migration, or customer data was accessed or changed.

## Remaining gates and boundaries

- **R0 Dev runtime DB isolation remains pending**; static source review is not a runtime isolation attestation.
- **Owner-rendered visual approval remains pending** before any ERP2 frontend implementation.
- **PR #766 frontend dependency remains pending**; this backend package does not modify or claim frontend integration.
- **007windows Dev UAT remains pending** and requires the approved frontend dependency plus isolated synthetic Dev fixtures and staff sessions.
- Production deployment and real-data migration remain out of scope and unauthorized. No frontend or Production readiness is claimed.

Status: **BACKEND TESTS PASS / R0 RUNTIME + UI GATES PENDING**.

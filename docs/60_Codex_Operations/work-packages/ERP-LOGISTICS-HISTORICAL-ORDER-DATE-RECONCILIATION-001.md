---
type: work-package
id: ERP-LOGISTICS-HISTORICAL-ORDER-DATE-RECONCILIATION-001
status: READY_FOR_GIT_DELIVERY
execution_provider: GPT_WEB
baseline: dedicated worktree HEAD before this package
dependencies: historical order materialization and ShipmentAttempt projection
dependency_type: MERGED_PR
---

# Historical Order date reconciliation

This bounded package adds a disabled-by-default, additive preview/apply correction workflow for historical materialized Orders. Preview is idempotent and evaluates every applied materialization row whose canonical Order date is after the fixed as-of date `2026-09-06`. Apply is transactional, ready-only, drift-checked, and changes only canonical `orders.order_date` plus that row's `materialization_manifest.order_date`; ERP identity and all shipment/import/evidence truth remain unchanged.

Frozen Production evidence baseline for code contract/tests only: 49 candidates, 47 Ready, 2 Review; ready-set SHA `8c9c4f24f7026fcaa98604ee5dbc5d373e549735b566767a4a0fab3a4327c2c6`. Rows 87 and 107 remain Review with no proposed date. The exact rollover anchor is source row 78, `KOL010101`, parsing to `2026-01-01`.

No Production access or mutation, `.env` change, Architecture baseline update, commit, push, PR, or merge is included in this implementation.


## Verification evidence — 2026-09-06

- Focused Date Correction + Materialization: 23/23 tests, 128 assertions PASS.
- Historical Logistics + Fulfillment regression: 75/75 tests, 563 assertions PASS.
- PHP lint: PASS.
- `git diff --check`: PASS.
- Architecture A/B: branch and pure `origin/main@069b00b` both report the same 3 pre-existing stale `ModuleArchitectureTest` baseline failures; no Architecture baseline update is in scope.
- `MigrationIndexNameTest`: PASS.
- Local test dependencies (`vendor`, `public/build`) are gitignored and not part of delivery.
- No Production mutation occurred during implementation or verification.

## Delivery gate

Code is ready for normal Git delivery. Production schema migration, Preview, and correction Apply remain separate Owner-controlled release/real-data gates. Apply stays disabled by default.

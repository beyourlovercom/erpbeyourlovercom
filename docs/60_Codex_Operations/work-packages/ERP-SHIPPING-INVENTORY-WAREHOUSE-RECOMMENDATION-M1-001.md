# ERP-SHIPPING-INVENTORY-WAREHOUSE-RECOMMENDATION-M1-001

STATUS: IMPLEMENTED / VALIDATED / PR #152 OPEN / MERGE NOT AUTHORIZED
OWNER: Harr
SYSTEM: BYL ERP
BASELINE: origin/main@01d11061c7d02fc6275353deee59e69dbef62216
BRANCH: feature/erp-shipping-inventory-warehouse-recommendation-m1-001
WORKTREE: /Users/a/AI/007/worktrees/erp-shipping-inventory-warehouse-recommendation-m1-001
PARALLEL_SAFE: YES after rebase onto PR #151; no unresolved overlap.
PRODUCTION_MUTATION: 0
LOCALERP_BUSINESS_DATA_MUTATION: 0

## Purpose

Connect Shipping/Fulfillment to Inventory authority with a safe warehouse recommendation layer. M1 recommends and explains; the operator remains the final decision-maker.

## Frozen M1 contract

Recommendation eligibility is fail-closed and requires all of the following:

1. Order country is a valid two-letter country code.
2. Every order line has canonical `product_id` identity.
3. Duplicate product lines are aggregated before stock coverage is evaluated.
4. Warehouse is active and has at least one confirmed `full` inventory snapshot (`can_auto_allocate=true`).
5. Warehouse has an explicit `warehouse_service_regions` match for the destination country.
6. One warehouse can cover the full order quantity for every required product.
7. Candidate ordering is service-region priority, then warehouse priority, then warehouse id.

Blocked reason codes are explicit: `missing_country`, `invalid_country_code`, `unresolved_product`, `no_items`, `no_inventory_authority`, `service_regions_unconfigured`, `no_service_region_match`, `insufficient_stock`.

M1 does not automatically save a shipment, mark an order shipped, reserve inventory, or overwrite an operator-selected warehouse. `采用推荐` only updates the browser-side draft; persistence still uses the existing manual fulfillment flow.

## Unknown is not zero

Shipping and Order Detail inventory visibility now preserves the Inventory freshness contract:

- No authoritative SKU row in a warehouse without a confirmed full baseline => `库存未知`; quantities are projected as null.
- Confirmed full baseline with no SKU row => authoritative numeric zero.
- Existing inventory rows retain their numeric QOH / reserved / available values.

This prevents a never-imported or otherwise non-authoritative warehouse from being presented as a real zero-stock fact.

## Real-data readiness evidence

Read-only preflight against the Golden local ERP environment found `warehouse_service_regions` currently empty.

Therefore real orders intentionally remain blocked from automatic recommendation until Owner-approved service-country configuration exists. No country-to-warehouse rule is hard-coded or guessed.

## Implementation surface

- Extend Inventory availability projection with warehouse priority, `can_auto_allocate`, service regions, and inventory-state-aware SKU availability.
- Add `WarehouseRecommendationService` as a Logistics Application service using only the Inventory Application contract.
- Project recommendation evidence into `/logistics/pending`.
- Add recommendation card and `采用推荐` action without automatic persistence.
- Preserve manual warehouse selection and shipment flow.
- Show `库存未知` instead of fabricated zero in Logistics and Order Detail.
- Add unit, integration, and Vue tests.

## Rebase / PR #151 compatibility evidence

This WP rebased cleanly onto `origin/main@01d11061c7d02fc6275353deee59e69dbef62216` after PR #151 (Order Identity) merged. The overlapping files were semantically complementary: PR #151 added `erp_order_id`; this WP adds inventory visibility/recommendation behavior.

During rebase validation, canonical main was found to have frozen Architecture baseline omissions introduced by PR #151. This WP contains only the required compatibility housekeeping for those already-merged files:

- register migration `2026_09_01_160000_create_order_identity_settings_and_erp_ids.php` in MIG-001 baseline;
- refresh FE-001 hashes for PR #151-modified Orders/Settings surfaces plus this WP surfaces;
- register route `settings.order-identity.update`;
- register `OrderIdentityService.php` and `OrderIdentitySetting.php` in frozen module file lists;
- include `OrderIdentityService.php` in the Application scan list.

No PR #151 business behavior was rewritten.

## Validation evidence

Post-rebase focused regression:

- PHP: 42 passed / 384 assertions.
- Vue focused: 41 passed.
- `npm run typecheck`: PASS.
- Production `npm run build`: PASS.
- `git diff --check`: PASS.

Architecture compatibility:

- `all_rule_ids_have_real_assertions_and_compatibility_evidence`: exit 0 / 467 assertions.
- Full strict Architecture scan reaches APP-001 and reports 14 upstream Application→Infrastructure violations: 12 historical violations plus 2 inherited from PR #151 `OrderIdentityService`. `WarehouseRecommendationService` contributes 0 violations.

Full PHP Unit + Feature regression:

- 838 passed / 6607 assertions.
- 1 existing PHPUnit deprecation.
- 2 environment-dependent tests skipped by their configured runtime gates.
- 0 failures.

Full Vitest regression:

- 58 test files passed, 2 failed; 406 tests passed, 3 failed out of 409.
- All 3 failures are upstream UI test drift on files byte-identical to `origin/main` and outside this WP:
  - `resources/js/Pages/Products/Edit.test.ts`: 2 failures.
  - `resources/js/Pages/Orders/Create.test.ts`: 1 failure.
- `Products/Edit.vue`, `Products/Edit.test.ts`, `Orders/Create.vue`, and `Orders/Create.test.ts` are all `ORIGIN_IDENTICAL` relative to `origin/main`.
- This WP's `Logistics/Index.test.ts` passes 3/3; `Orders/Show.test.ts` passes 38/38.

Final production frontend build after the full regression: PASS.

## Explicit prohibitions

- No Production deploy/migration/data mutation.
- No localerp business-data mutation in this WP.
- No automatic shipment persistence.
- No automatic mark-shipped action.
- No automatic inventory reservation.
- No guessed country/warehouse mapping.
- No merge without a separate explicit Owner authorization.
- No tag or release.

## Git delivery authority

Owner explicitly authorized on 2026-09-02: `Git Delivery：commit + push + create PR` after final validation.

Commit: AUTHORIZED.
Push: AUTHORIZED.
Create PR: AUTHORIZED.
Merge: NOT AUTHORIZED.
Production deploy/mutation: NOT AUTHORIZED.

## Current gate

`IMPLEMENTATION = PASS`
`FOCUSED_REGRESSION = PASS`
`FULL_PHP_REGRESSION = PASS`
`TYPECHECK = PASS`
`BUILD = PASS`
`ARCHITECTURE_COMPATIBILITY = PASS`
`FULL_VITEST = UPSTREAM_BASELINE_3_FAILURES / THIS_WP_GREEN`
`GIT_DELIVERY = PASS`
`IMPLEMENTATION_COMMIT = c6a540e933e9817aa2fc5d144002d5ed398e42d7`
`PULL_REQUEST = #152 / OPEN / NOT MERGED`

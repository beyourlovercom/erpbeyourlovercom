---
type: work-package
project: BYL ERP
work_package_id: ERP-INVENTORY-FOUNDATION-M1-001
title: Warehouse / Inventory Foundation M1
status: ACTIVE / OWNER AUTHORIZED
execution_provider: GPT_WEB_VIA_US_DC
verification_tier: OWNER_OVERRIDE_US_ONLY
baseline: integration@fa4c4a07922a311d0ea299d25d748b2ce376d7f4
branch: feature/erp-inventory-foundation-m1-001
updated: 2026-08-25
---

# ERP-INVENTORY-FOUNDATION-M1-001

## Objective
Create canonical Warehouse / Inventory truth for Release 1 Shipping without inventing warehouse addresses, stock, or routing rules.

## Preflight result
```text
OWNER_PLAN: PR #94 / c8f47f2c2e9c6f7864fd0a22b132ee8c56ebef0b
CURRENT_MAIN: d4bd3a6ae9eba16e8b3e5e35a957fa4632c8669d
PRODUCT_DEPENDENCY: PR #93 / 7bbb7f934f973f7079cecded67762888878601e9
DEPENDENCY_TYPE: STACKED_PR
INTEGRATION_BASE: product branch + current main
INTEGRATION_HEAD: fa4c4a07922a311d0ea299d25d748b2ce376d7f4
PHASE_1_TO_4_PARALLEL_SAFE: YES
PHASE_5_SHIPPING_INTEGRATION: WAITING / shipping branch is not yet GitHub authority
CHINA_VERIFICATION: NOT REQUIRED BY CURRENT OWNER OVERRIDE
PRODUCTION_DEPLOYMENT: NO
```

## Scope
- Warehouse Master and six initial warehouse identities with blank/nullable address data until real values are entered.
- SKU × Warehouse inventory linked by canonical `products.id`; SKU itself remains Product Master truth.
- `/inventory`, `/inventory/add`, `/inventory/list`, `/inventory/products`, and `/inventory/{slug}`.
- Stable warehouse slugs: `us-la`, `us-ny`, `de`, `ca`, `factory`, `007`.
- On-hand/reserved/available, inbound time, stay time, location, Standard QoH, expected restock quantity.
- Warehouse service-region country codes and priority data for later Shipping consumption.
- Collapsible editable Markdown billing source.
- Immutable Audit records for warehouse, region, billing and inventory mutations.

## Data invariants
`quantity_available = max(quantity_on_hand - quantity_reserved, 0)` is computed from canonical stored quantities and is not independently editable.

No warehouse address, stock quantity, service region or carrier rule may be fabricated. Missing data is shown explicitly as missing.

## Permissions
```text
inventory.view
inventory.operate
inventory.warehouses.manage
```

## Menu contract
No Owner-approved level-1/level-2 menu taxonomy mutation in this package. Existing `/inventory/warehouses` remains a compatibility redirect to canonical `/inventory/list`.

## Phase gate
Phases 1–4 are authorized now. Phase 5 Shipping ↔ Inventory integration starts only after the Shipping revision becomes GitHub authority (commit/push/PR/merge or explicit stacked GitHub dependency).

## Acceptance
- six warehouses exist exactly once by slug;
- warehouse addresses are editable and never guessed;
- Product Master remains SKU authority;
- cross-warehouse product inventory table works;
- stay time is calculated from inbound time;
- Markdown billing content is editable/audited;
- inventory mutations are audited;
- no duplicate Shipping warehouse truth is introduced;
- US tests/build/runtime UAT pass before merge consideration.

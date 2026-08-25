---
type: roadmap-module-plan
project: BYL ERP
plan_id: ERP-INVENTORY-FOUNDATION-M1-001
title: Warehouse / Inventory Foundation for Shipping
status: OWNER APPROVED PLAN / IMPLEMENTATION NOT STARTED
updated: 2026-08-25
---

# ERP-INVENTORY-FOUNDATION-M1-001

## 1. Objective

Establish the canonical Warehouse / Inventory truth required by Release 1 manual fulfillment and Shipping.

Primary outcomes:

```text
Warehouse Master
    ↓
SKU × Warehouse inventory truth
    ↓
Inventory dashboard / warehouse pages / product inventory table
    ↓
Shipping warehouse candidate + recommendation
    ↓
operator override + save + Audit
```

This package exists so Shipping never hard-codes or guesses a warehouse.
## 2. Planned user-facing pages

```text
/inventory                 Dashboard / 库存概览
/inventory/add             增加仓库
/inventory/list            仓库清单 + 每仓 SKU / 库存汇总
/inventory/products        产品 × 仓库库存总表
/inventory/us-la           美东洛杉矶仓
/inventory/us-ny           美西纽约仓
/inventory/de              德国仓
/inventory/ca              加拿大仓库
/inventory/factory         中国工厂
/inventory/007             007本地仓库
```

Warehouse URL slugs are stable technical identifiers and are independent from future display-name corrections.

`/inventory/add` and warehouse detail pages are actions/details, not automatically new governed level-2 menu items.
Any later level-2 menu replacement/addition must update the approved menu baseline, runtime navigation and menu tests together.

## 3. Warehouse Master

Minimum canonical fields:

```text
id
slug / code / name
country_code
address fields
timezone
type
active
priority
billing_markdown
created_at / updated_at
```
Warehouse address is canonical ERP data. The detail page must show address and other operational warehouse metadata.

## 4. SKU × Warehouse inventory truth

Minimum inventory record:

```text
warehouse_id
sku
quantity_on_hand
quantity_reserved
quantity_available
inbound_at
location
standard_qoh
expected_restock_qty
updated_at
```

Canonical formula:

```text
quantity_available = quantity_on_hand - quantity_reserved
```

Shipping eligibility uses `quantity_available`, never only the displayed on-hand quantity.

SKU identity must converge on the canonical Product Master SKU contract. If Product Master schema is still unmerged when implementation starts, this dependency must be resolved explicitly before creating a competing SKU truth.

## 5. Product inventory table visual contract

`/inventory/products` follows the Owner-provided overseas-warehouse summary sheet as the primary visual reference.
Core columns:

```text
所属仓库
SKU
产品 / Client ID
上架时间 / Inbound Time
停留时间
Location
当前库存 / Standard QoH
可用库存
预计备货数量
实际 Warehouse 名称
```

`停留时间` is calculated from `inbound_at` to the current date; it is not a manually maintained field.

The table must support warehouse/SKU/product search and filters suitable for complete stock counting across all warehouses.

## 6. Warehouse detail page

Each warehouse page must include:

```text
基本信息 + 仓库地址
SKU 数 / 总库存 / 可用库存
缺货 / 低库存 / 待补货 summary
产品列表
每个产品上架时间 + 停留时间
Location
预计备货数量
可折叠仓库计费表
```

The warehouse billing table is stored as editable Markdown source and rendered for reading. Replacing/editing the Markdown must be audited.
## 7. Shipping warehouse selection contract

Shipping consumes Inventory; Shipping does not own warehouse truth.

```text
Order destination
+ SKU / required quantity
+ warehouse quantity_available
+ warehouse service region
+ warehouse priority
        ↓
eligible warehouse candidates
        ↓
default recommended warehouse
        ↓
operator may override
        ↓
💾 save
        ↓
Audit
```

If no warehouse can satisfy the order, the UI must show an explicit `无可用仓库` state. It must not silently select a fake/default warehouse.

Multi-SKU orders require a warehouse that can satisfy the required quantities under the current Release 1 one-shipment assumption. Multi-warehouse split shipment remains a future scope unless separately approved.

## 8. Inventory audit / mutation contract

Meaningful mutations must be auditable:

```text
warehouse created / updated / disabled
inventory adjustment
expected restock quantity change
warehouse billing Markdown change
shipping warehouse override
```
Permissions to finalize during implementation preflight:

```text
inventory.view
inventory.operate
inventory.warehouses.manage
```

No permission name is runtime-authoritative until implementation preflight verifies current Identity catalog compatibility.

## 9. Phased implementation plan

```text
Phase 1 — Warehouse / Inventory schema + Warehouse Master + permission preflight
Phase 2 — /inventory + /inventory/add + /inventory/list
Phase 3 — /inventory/products spreadsheet-style inventory table
Phase 4 — six warehouse detail pages + editable Markdown billing table
Phase 5 — Shipping ↔ Inventory warehouse candidate/recommendation integration
```

## 10. Explicit exclusions

```text
warehouse-provider API connectors
automatic third-party stock synchronization
purchase orders / procurement workflows
inventory accounting valuation
carrier APIs / shipping labels
multi-warehouse split shipment
fake stock or mock operational metrics
production deployment
```
## 11. Dependencies / parallelization

```text
UPSTREAM: canonical Order truth; canonical Product Master SKU truth
DOWNSTREAM: ERP-SHIPPING-CARRIER-M1-001 warehouse selection closure
PARALLEL_SAFE: CONDITIONAL
```

Inventory may use a dedicated Worktree/branch, but any shared Product, navigation, permission or Shipment schema dependency must be declared before implementation.

## 12. Acceptance direction

Inventory Foundation is complete only when:

```text
Warehouse Master truth exists
six initial warehouse detail pages work
SKU × Warehouse quantities are queryable
/inventory/products provides full cross-warehouse stock view
inbound/stay-time/restock data is visible
billing Markdown is editable and auditable
Shipping receives real warehouse candidates from Inventory
no hard-coded shipping warehouse rule remains
```

Implementation is not activated by this planning record. A dedicated implementation Work Package/branch/worktree must pass dependency and menu preflight first.
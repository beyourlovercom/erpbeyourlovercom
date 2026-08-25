# ERP Accounting Data Contract V1

**Status: OWNER_APPROVED / FROZEN**
**Date: 2026-08-24**
**PR: #93 (Draft)**

---

## 1. Truth Ownership

| Domain | Owns | Must Not Duplicate |
|--------|------|--------------------|
| **Products** | `products` table — master data, standard costs (reference only) | Order financials, shipment costs |
| **Orders** | `orders`, `order_items` — order truth, line-item snapshots | Product master, shipment allocations |
| **Logistics** | `shipments` — carrier, tracking, actual shipping cost | Order financial allocations |
| **Accounting** | Financial ledgers, cost allocations, historical snapshots, P&L | Any source-of-truth already owned by another module |

---

## 2. Joining IDs

| Key | Direction |
|-----|-----------|
| `product_id` | Orders/Logistics → Products (nullable, nullOnDelete — historical snapshot survives) |
| `order_id` | Logistics/Accounting → Orders |
| `order_item_id` | Accounting → OrderItems |
| `shipment_id` | Accounting → Shipments |
| `supplier_id` *(future)* | Products/Accounting → Suppliers |
| `kol_id` *(future)* | Orders/Accounting → KOLs |

---

## 3. Product Standard Costs — Reference Values Only

Fields `standard_production_cost`, `standard_packaging_cost`, `standard_purchase_cost`, `cost_currency`, `cost_note` on `products` are **ERP-maintained reference values**. They are:

- Editable by operations staff in the Product edit UI.
- Never overwritten by Woo CSV import.
- **Not authoritative historical cost.** They represent current estimates only.
- Never used directly by the Accounting engine to close a period. The Accounting module must snapshot the effective cost at time of transaction.

---

## 4. Future: `product_cost_versions`

When cost versioning is implemented, the table schema will be:

```
product_cost_versions
  id
  product_id          FK → products.id
  cost_type           enum: production | packaging | purchase | other
  amount              decimal(18,4)
  currency            char(3)
  effective_from      date
  effective_to        date nullable (null = current)
  supplier_id         FK → suppliers.id nullable
  source_type         enum: manual | imported
  reference_number    string nullable
  note                text nullable
  created_by          FK → users.id
  created_at / updated_at
```

The `products` standard cost fields remain as a quick-lookup convenience cache, populated from the latest active version row. The Accounting engine will always join `product_cost_versions` by date range for authoritative historical cost, never reading `products.standard_*` directly.

---

## 5. Future: Order-Item Accounting Snapshots

When an order-item is costed, Accounting creates a snapshot row in an `order_item_cost_snapshots` table (or equivalent within `order_cost_entries`), capturing:

- `order_item_id` (FK, not nullable at snapshot time)
- `product_id` snapshot value
- `cost_type`, `amount`, `currency` at the effective date
- `exchange_rate`, `amount_base_currency`
- `source`: `standard_cost` | `actual_purchase` | `manual_override`
- Timestamps and `created_by`

The snapshot is immutable after creation. Corrections are additive (new reversal + new snapshot row), never in-place edits.

---

## 6. Shipment Actual Cost and Allocation

Shipments carry `actual_shipping_cost` and `actual_currency`. Accounting allocates this cost across order items via an allocation table, not by duplicating the shipping record. Allocation strategies (weight-based, unit-based, manual) are owned by Accounting; the Logistics module only exposes the `shipments` truth.

---

## 7. `order_financials` and `order_cost_entries` — Expand, Don't Duplicate

Accounting pages extend the Order truth by writing into:

- `order_financials` — settlement status, revenue recognition, payment fee, FX summary
- `order_cost_entries` — cost line items keyed to `order_id` / `order_item_id`

These tables are in the Finance module. New financial dimensions (KOL commission, platform fee, return cost) are added as new `cost_type` enum values or new columns in these tables, never as new standalone order fields on `orders`.

---

## 8. Exchange Rate Contract

- A base currency is configured per tenant (e.g. CNY).
- Exchange rates are recorded at time of transaction, not recalculated retroactively.
- `order_financials.exchange_rate` and `order_cost_entries.exchange_rate` store the rate used at posting time.
- An `exchange_rates` reference table (future) provides daily rates; Accounting selects the rate for the transaction date.

---

## 9. Financial Completeness Statuses

Order-level completeness progresses through states owned by `order_financials`:

- `pending` — not yet costed
- `draft` — costs entered, not confirmed
- `confirmed` — accounting confirmed, locked for period
- `reconciled` — payment matched

No status is stored on `orders` itself; the Orders module is agnostic to accounting completeness.

---

## 10. Accounting UI Pages (Future — Not Implemented in PR #93)

| Page | Description |
|------|-------------|
| Dashboard | P&L summary, cost vs revenue KPIs |
| Order Costs | Per-order cost breakdown with completeness status |
| Product Costs | Current standard costs per product |
| Product Cost Versions | History of standard cost changes per product |
| Shipping Costs / Exceptions | Actual vs estimated shipping, flagged exceptions |
| KOL Commissions | Commission entries per order/KOL |
| Payment Fees | Platform and gateway fee entries |
| Expense Analysis | Existing `order_cost_entries` analysis (already live in Finance module) |
| P&L | Period P&L statement |

---

## 11. PR #93 Non-Goals (Explicitly Out of Scope)

- No `product_cost_versions` table — standard cost fields on `products` are sufficient for PR #93.
- No shipment accounting or cost allocation implementation.
- No P&L engine.
- No Accounting UI implementation.
- No `exchange_rates` table.
- No `order_item_cost_snapshots` table.

PR #93 delivers: Product master with accounting/logistics fields, Woo CSV ownership boundary, Product CRUD/import UI with Chinese labels.

---

*This document is frozen at OWNER_APPROVED status. Changes require explicit owner approval and a new version document.*

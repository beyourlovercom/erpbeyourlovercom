---
type: mvp-spec
project: BYL ERP
release: MVP1
version: 1.1
updated: 2026-08-24
---

# MVP1 — Tencent Docs Replacement

## Goal

Replace the Tencent Docs order/fulfillment handoff with the smallest useful ERP workflow while preserving one canonical Order truth.

Detailed current semantics are governed by `16_BYL_ERP_CANONICAL_ORDER_FINANCE_KOL_DATA_CONTRACT_V1.md`.

## Canonical rules

```text
CANONICAL_ORDER = orders.id
BUSINESS_ORDER_NUMBER = external_order_number
ERP_TECHNICAL_NUMBER = internal_order_number / generated / unique / hidden by default
ORDER_KIND = customer | kol_fulfillment
ORDER_SOURCE = independent from order kind
V1_SHIPMENT = 1 Order : 1 Shipment
```

Customer Orders and KOL fulfillment Orders are rows in the same canonical `orders` table. Affiliate-attributed consumer sales remain customer Orders plus attribution; they are not KOL fulfillment Orders.

## Workflow

```text
Manual order creation
→ choose/default customer or kol_fulfillment
→ canonical orders.id
→ pending shipment
→ logistics copies order information reliably
→ logistics records carrier / tracking / shipped time
→ mark shipped
→ existing manual website-sync pending behavior where applicable
→ operator updates website manually where applicable
→ mark manual sync complete
```

## Required screens

### Dashboard

- pending processing;
- pending shipment;
- shipped pending website sync;
- completed today.

### Orders

- one shared manual create implementation;
- customer/KOL fulfillment classification;
- human-entered business order number as the primary UI identifier;
- list with server-side pagination/search/filter/sort;
- scoped server-side authorization;
- details with bounded fulfillment workspace;
- unlimited practical item/SKU rows;
- address snapshot;
- independent order/shipment/sync status projection;
- notes;
- source/type;
- owner.

### Logistics

- one pending shipment queue for both customer and KOL fulfillment Orders;
- business order number + order-kind indicator;
- copy full shipment information with explicit success/failure handling;
- copy recipient/address/phone;
- record carrier;
- record tracking;
- record shipped time;
- mark shipped;
- preserve ShipmentService / Manual Sync / Audit behavior.

### Sync

- manual pending;
- manually completed today;
- needs confirmation;
- returned/failed;
- mark completed;
- reopen with audit.

### Finance / Accounting — Order Analysis

Finance uses the same `orders.id`; no second accounting-order truth.

- product/commercial revenue;
- shipping revenue separate from shipping cost;
- tax collected separate from tax cost/liability;
- discount/refund;
- product cost;
- warehouse/first-mile/logistics cost;
- recognized KOL/Affiliate commission where applicable;
- payment fee;
- other cost;
- total cost;
- profit;
- margin;
- completeness;
- USD base-currency analysis with preserved FX snapshot when needed;
- filters and export.

Advanced SKU-level allocation, AffiliateWP connector/KOL CRM and broad BI expansion remain later bounded work unless explicitly activated.

## Current remediation package

`ERP-ORDER-FULFILLMENT-M1-001` revalidates/remediates the remaining MVP1 Order/Fulfillment Human UAT findings:

```text
MVP1-UAT-DEFECT-002  create-order required-field contract
MVP1-UAT-DEFECT-003  business order number preserved / ERP number hidden
MVP1-UAT-DEFECT-004  reliable shipment copy + permission contract
MVP1-UAT-FINDING-005 bounded Order Detail fulfillment workspace
```

No finding is closed by documentation alone.

## Excluded from the current bounded implementation unless separately authorized

- automatic WooCommerce import;
- AffiliateWP connector implementation or AffiliateWP data clone;
- full KOL CRM/statistics/commission UI;
- webhook;
- automatic tracking write-back;
- batch external sync;
- Shopify;
- Product/SEO integrations;
- GA4/GSC/Cloudflare;
- social APIs;
- full inventory/procurement/accounting;
- split-shipment `1:N` implementation;
- remote deployment / Production access.

## Acceptance

- one complete manual customer Order can be created using the primary business order number;
- one complete KOL fulfillment Order can be created using the same canonical Order service/database;
- customer and KOL fulfillment projections do not leak unrelated scoped data;
- both order kinds enter the same pending Logistics queue;
- logistics copy behavior is browser-verified and cannot fail silently;
- logistics can ship the Order through the existing Shipment workflow;
- existing Manual Sync behavior remains compatible/no-regression;
- Audit identifies actors and timestamps;
- Order/Finance compatibility is preserved;
- large lists are bounded server-side, not fetch-all-to-Vue;
- MySQL migration/backfill behavior is verified on permitted local/disposable data only;
- Tier B US full verification + China exact-SHA full independent verification pass;
- Owner Local UAT passes before merge.

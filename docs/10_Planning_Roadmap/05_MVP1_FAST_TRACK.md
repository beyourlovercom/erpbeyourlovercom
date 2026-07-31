---
type: mvp-spec
project: BYL ERP
release: MVP1
version: 1.0
---

# MVP1 — Tencent Docs Replacement

## Goal

Replace the current Tencent Docs order/fulfillment handoff with the smallest useful ERP workflow.

## Workflow

```text
Manual order creation
→ pending shipment
→ logistics copies order information
→ logistics records shipment
→ manual website-sync pending
→ operator updates website manually
→ mark manual sync complete
```

## Required screens

### Dashboard

- pending processing;
- pending shipment;
- shipped pending website sync;
- completed today.

### Orders

- create;
- list;
- search/filter;
- details;
- items;
- address;
- status;
- notes;
- source/type;
- owner.

### Logistics

- pending shipment queue;
- copy full shipment information;
- copy recipient/address/phone;
- record carrier;
- record tracking;
- record shipped time;
- mark shipped.

### Sync

- manual pending;
- manually completed today;
- needs confirmation;
- returned/failed;
- mark completed;
- reopen with audit.

### Finance / Accounting — Order Analysis

- revenue;
- discount;
- product cost;
- warehouse cost;
- first mile;
- overseas handling;
- last mile;
- KOL commission;
- payment fee;
- other cost;
- total cost;
- contribution profit;
- margin;
- completeness;
- filters and export.

## Excluded

- automatic WooCommerce import;
- webhook;
- automatic tracking write-back;
- batch external sync;
- Shopify;
- Product/SEO integrations;
- GA4/GSC/Cloudflare;
- social APIs;
- full inventory/procurement/accounting.

## Acceptance

- one complete manual order can be created;
- logistics can copy and ship it;
- website-sync queue is created;
- operator can mark external update complete;
- audit identifies actors and timestamps;
- order profit is calculated from entered values;
- missing costs do not appear as confirmed profit;
- CSV export works;
- MySQL persistence and backup are verified.

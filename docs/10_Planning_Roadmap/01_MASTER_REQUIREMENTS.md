---
type: master-requirements
project: BYL ERP
version: 1.0
status: living
---

# BYL ERP Master Requirements

## 1. Project objective

Build an internal ERP that gradually replaces Tencent Docs and later unifies:

- manual and website orders;
- fulfillment and logistics;
- customer and lead management;
- KOL operations;
- products and media;
- inventory and procurement;
- SEO and content;
- external synchronization;
- order-level profitability;
- country, product, warehouse and KOL decisions.

## 2. Immediate MVP1 objective

Within approximately one month, replace the current Tencent Docs workflow:

```text
Website/KOL order
→ manually enter ERP
→ logistics copies shipment information
→ logistics records carrier/tracking/shipped time
→ order enters manual website-sync queue
→ operator manually updates website
→ operator marks website sync complete
```

## 3. User-facing primary menu

```text
仪表盘
同步
订单
客户
网红 / KOL
物流
产品
库存
外采
SEO
财务 / 会计
指南
Manager OS
```

## 4. Authentication

- guest `/` shows the actual login homepage;
- `/login` shows the same login page;
- authenticated `/` redirects to `/dashboard`;
- no public registration;
- account opening is administrator-controlled;
- web session authentication;
- inactive users cannot log in;
- login rate limiting;
- server-side permissions;
- audit login/logout/failure.

## 5. Dashboard

Foundation:

- system status;
- sync health;
- current user;
- pending-work framework;
- recent activity;
- quick actions;
- truthful empty states.

Real metrics after real data exists:

1. pending shipment;
2. shipped today;
3. risk orders;
4. urgent orders;
5. customer orders;
6. KOL orders;
7. inventory alerts;
8. overseas warehouse monitoring.

No mock numbers.

## 6. Orders

Unified core model for:

- manual website order;
- WooCommerce order;
- Shopify order;
- KOL order;
- sample order;
- replacement;
- reshipment;
- other manual order.

Do not create parallel `manual_orders`, `woocommerce_orders` or `kol_orders` truth tables.

Required concepts:

- order;
- order item;
- order address;
- external reference;
- shipment;
- audit;
- financial summary;
- cost entries.

## 7. MVP1 order workspace

- create manual order;
- source and order type;
- customer/recipient;
- phone;
- country/region/city/address/postcode;
- items/SKU/quantity;
- order notes;
- owner;
- status;
- search/filter;
- CSV export;
- copy full shipment information;
- copy address/phone;
- record carrier;
- record tracking number;
- record shipped time;
- mark shipped;
- manual website-sync queue;
- mark website sync complete;
- modification and completion audit.

## 8. Sync

User-facing name:

```text
同步
```

Backend module:

```text
sync
```

Route prefix:

```text
/sync
```

Provider classes:

- commerce;
- content;
- analytics;
- files;
- social.

Manual actions are first-class, but reliability later includes:

- webhook;
- scheduler;
- incremental polling;
- reconciliation;
- queue;
- retry;
- conflict;
- outbox.

## 9. WordPress/WooCommerce roadmap

- MVP1: manual order and manual website write-back;
- MVP1.1: one-click/incremental WooCommerce inbound;
- MVP1.2: single tracking write-back;
- MVP1.3: batch sync, retry, conflict and reconciliation.

All phases use the same final order, shipment, external-reference and sync/outbox structures.

## 10. Product OS

Synchronize from WordPress/WooCommerce:

- product detail;
- SKU;
- status;
- descriptions;
- price;
- categories/tags;
- stock status;
- featured image;
- gallery;
- external IDs;
- remote modification time.

Featured image:

- remote attachment ID;
- URL;
- MIME;
- dimensions;
- alt text;
- content hash;
- controlled local mirror;
- sync state.

## 11. SEO OS

- content inventory;
- WordPress Posts;
- WordPress Pages;
- featured images;
- revisions;
- canonical URLs;
- Google Docs/Drive import;
- preview;
- publish to WordPress Draft;
- direct publish by separate permission;
- GA4 page metrics;
- GSC search metrics;
- Cloudflare path metrics;
- content performance;
- publish history;
- sync health.

Metrics remain source-specific:

- GA4 = user behavior and page views;
- GSC = search clicks/impressions/CTR/position;
- Cloudflare = requests/bandwidth/cache/security/bots;
- WordPress statistics plugin = optional supplemental source.

Do not combine them into one ambiguous view count.

## 12. KOL social enrichment

KOL center accepts Instagram or X profile links.

KOL OS handles input/display/confirmation.

`sync.social` handles:

- URL parsing;
- provider credentials;
- API call;
- queue;
- retry;
- rate limits;
- raw snapshot;
- avatar ingestion;
- audit.

X:

- username;
- display name;
- avatar;
- followers;
- following;
- post count;
- verified/protected.

Instagram:

- professional accounts and officially permitted profiles;
- unsupported/private/permission-limited states;
- no unapproved scraping fallback.

Store follower snapshots and controlled avatar mirrors.

## 13. Finance / Accounting

Primary submenu:

```text
订单分析
```

Purpose:

Evaluate each shipped order using:

- net revenue;
- product cost;
- warehouse cost;
- first-mile allocation;
- overseas handling;
- last-mile cost;
- KOL commission;
- website discount;
- payment fee;
- customs/tax;
- refund/replacement loss;
- other cost;
- total cost;
- contribution profit;
- profit margin;
- cost completeness.

Subsequent analysis:

- country profitability;
- recommended country shipping fee;
- recommended product/country shipping fee;
- SKU/warehouse inventory recommendation using recent 90-day shipments;
- KOL cooperation value using actual profit.

Website discount must not be deducted twice when amount paid already reflects the discount.

Costs must distinguish:

```text
estimated
confirmed
adjusted
```

Multi-currency values require original amount, accounting currency, exchange-rate snapshot and converted amount.

## 14. Long-term modules

- Customer / Lead OS;
- Order OS;
- Logistics OS;
- KOL OS;
- Product OS;
- Inventory OS;
- Procurement OS;
- SEO OS;
- Finance / Accounting OS;
- Sync;
- Manager OS;
- Guide OS;
- Shopify;
- AffiliateWP/AffiliateBYL;
- logistics providers;
- WhatsApp;
- email;
- AI enrichment.

## 15. Non-functional requirements

- desktop primary;
- tablet usable;
- basic mobile;
- Chinese UI;
- English route/database/permission keys;
- server-side tables and forms;
- typed frontend contracts;
- queues for large work;
- idempotency for external operations;
- audit for meaningful mutations;
- secrets never returned to Vue;
- no secrets in logs;
- no duplicate source-of-truth documents.

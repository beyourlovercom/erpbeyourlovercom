---
type: module-spec
module: sync
project: BYL ERP
version: 1.0
---

# Sync Provider Plan

## Naming

```text
User-facing: 同步
Backend: sync
Route: /sync
Permissions: sync.*
```

## Provider classes

```text
commerce
- WooCommerce
- Shopify

content
- WordPress Posts
- WordPress Pages
- Google Docs

analytics
- GA4
- GSC
- Cloudflare

files
- Google Drive
- CSV/XLSX

social
- Instagram
- X
```

## Common runtime

```text
Connection
→ Sync Run
→ Sync Items
→ Provider
→ Queue
→ Raw Snapshot
→ Normalize
→ Domain
→ Audit
```

## Manual-first evolution

MVP1:

```text
sync_outbox
mode = manual
status = manual_pending/manual_completed
```

MVP1.2:

```text
same outbox
mode = automatic
status = queued/processing/succeeded/failed/conflict
```

## Reliability

- idempotency;
- retry/backoff;
- failed jobs;
- reconciliation;
- webhook inbox;
- secure credentials;
- no secret exposure;
- page closure does not interrupt tasks.

## WordPress ownership

WordPress initially owns:

- original order;
- customer input;
- items;
- prices;
- payments/refunds;
- source order status.

ERP owns:

- internal order;
- owner/team/warehouse;
- corrected address;
- shipment;
- tracking;
- internal flags and notes;
- write-back state.

First write-back scope:

- tracking number;
- carrier;
- shipped time;
- tracking URL;
- approved status mapping;
- ERP sync metadata.

Auto-complete remains off by default.

---
type: architecture
project: BYL ERP
version: 1.0
status: mandatory-baseline
---

# System Architecture

## Mandatory stack

```text
Laravel modular monolith
Inertia.js
Vue 3
TypeScript
Tailwind CSS
Vite
MySQL
Redis
Queue
Scheduler
Web session authentication
Server-side authorization
Audit logs
Single repository
Single deployment unit
No SSR
No independent JWT SPA
```

## Layering

```text
Experience
→ Application
→ Domain
→ Infrastructure
→ External Providers
```

## Domains

```text
Identity
Customer / Lead
Orders
Fulfillment / Logistics
Sync
KOL
Product / Media
Inventory
Procurement
Content / SEO
Finance / Accounting
Audit
```

## MVP principle

```text
Functionality can be reduced.
Architecture cannot be temporary.
```

MVP1 must use forward-compatible models:

```text
orders
order_items
order_addresses
order_external_references
shipments
sync_outbox
order_financials
order_cost_entries
audit_records
```

Do not create:

```text
manual_orders
woocommerce_orders
kol_orders
mvp_orders
temporary_shipping
```

## Application services

Manual and automated paths share actions:

```text
CreateOrderAction
UpsertExternalOrderAction
CreateShipmentAction
ConfirmShipmentAction
CompleteManualSyncAction
RecalculateOrderFinancialsAction
```

## Sync evolution

```text
MVP1 manual completion
→ MVP1.1 inbound adapter
→ MVP1.2 outbound adapter
→ MVP1.3 orchestration
```

Only adapters and orchestration expand. Core order and shipment truth does not change.

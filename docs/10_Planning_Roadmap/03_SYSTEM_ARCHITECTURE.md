---
type: architecture
project: BYL ERP
standard_id: BYL-ERP-ARCH-001
version: 1.1
status: mandatory-baseline
effective_date: 2026-08-03
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

## Architecture Standard History and Module Contract

`ARCHITECTURE_STANDARD_V1_0_FACT`: v1.0 required a Laravel modular monolith, the Experience → Application → Domain → Infrastructure → External Providers layering and the listed domains. v1.0 did not explicitly require `app/Modules/**`; the current boundary blocker is the absence of executable ownership, layer and dependency contracts, not the absence of a directory that v1.0 never named.

`ARCHITECTURE_STANDARD_V1_1_DECISION`: Owner approved `RECOMMEND_OPTION_A1`. From v1.1, `app/Modules/**` is the forward-effective physical implementation contract. B1 freezes the contract only; it creates no directory or migration. B2 through B5 must implement and verify it before physical compliance can be assessed.

## Physical Module Contract

```text
app/Modules/{Identity,Runtime,Audit,Sync,Shell,Dashboard}/
app/Modules/{Orders,Logistics}/ reserved for MVP1; not created in B1
app/Shared/
```

Actual modules create only needed `Experience`, `Application`, `Domain`, `Infrastructure` and `Providers` layers. Identity owns users, authentication, roles, permissions and authorization; Runtime is platform technology; Audit and Sync own their data; Shell and Dashboard are Experience/Application capabilities; Orders and Logistics are reserved.

Allowed direction is `Experience → Application → Domain`; Infrastructure implements approved Application/Domain ports; Providers compose bindings and routes. Domain cannot depend on framework, Eloquent, transport or other module internals. Cross-module calls use public Application Actions/Contracts or approved Events/Queries. Route closures cannot hold durable business logic.

`app/Shared/**` / `App\Shared\**` is limited to stable framework-free identifiers, clocks, small value objects, result contracts and base exception contracts. `App\Support` is a Legacy Compatibility Namespace, not Shared Kernel; Support classes require ownership review and must not become a God Layer.

Historical migrations remain unchanged in `database/migrations/**`; no move, rename, copy or schema rebuild. Existing table ownership is Identity (`users`, sessions, roles, permissions and pivots), Audit (`audit_records`), Sync (`sync_outbox`) and Runtime/Laravel infrastructure (cache/jobs tables).

During migration, old namespaces may expose tracked shims delegating to canonical module classes. Routes, names, permissions, tables and serialization contracts remain stable; shim removal belongs to B5. The existing `App\` → `app/` Composer mapping discovers `App\Modules` and `App\Shared`; no module-specific PSR-4 mapping is required by default.

## Boundary Verification Contract

B2 must add automated checks for forbidden Domain/Application dependencies, public cross-module contracts, provider rule isolation, Support registration, Shared purity, route ownership, unchanged migration paths, reserved Orders/Logistics scope and unchanged route/permission/menu contracts.

## Execution Order

`001F-B1` CURRENT / IMPLEMENTED / PENDING INDEPENDENT REVIEW → `001F-B2` through `001F-B5` PROPOSED / NOT AUTHORIZED → `001F-C` → `001F-D` → `001F-E` → `001F-F`. B1 does not authorize MVP1.

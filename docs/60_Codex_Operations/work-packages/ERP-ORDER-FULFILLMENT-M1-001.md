---
type: work-package
project: BYL ERP
work_package_id: ERP-ORDER-FULFILLMENT-M1-001
title: Unified Manual Order → Logistics / Fulfillment M1
status: CONTRACT FORMALIZED / DRAFT DELIVERY PR #90 OPEN / IMPLEMENTATION NOT AUTHORIZED
draft_pr: 90
execution_provider: CLAUDE_CODE
design_authority: GPT_WEB
test_provider: CODEX_CLI
baseline: main@6ac25f2168dea7e8cd9e8f4c49c10752642a44df
branch: feature/erp-order-fulfillment-m1-001
estimate: UNKNOWN WITH EVIDENCE / exact implementation estimate deferred until implementation preflight confirms migration/backfill and affected runtime surfaces
updated: 2026-08-24
---

# ERP-ORDER-FULFILLMENT-M1-001

## 1. Objective

Turn the already-existing MVP1 Order/Shipment foundation into the Owner-approved unified operational workflow:

```text
Manual Order
→ Customer or KOL Fulfillment classification
→ Canonical orders.id
→ Pending Logistics queue
→ Reliable copy / shipment entry
→ Mark shipped
→ Existing Manual Sync / Audit behavior preserved
→ Finance-compatible canonical data
```

The package must reuse the existing Order, Order Item, Address, Shipment, Manual Sync, Audit and Finance foundations. It must not create a second customer/KOL/logistics order database.

## 2. Frozen design authority

Implementation must conform to:

```text
docs/10_Planning_Roadmap/16_BYL_ERP_CANONICAL_ORDER_FINANCE_KOL_DATA_CONTRACT_V1.md
```

Key invariants:

```text
CANONICAL_ORDER = orders.id
PRIMARY_UI_ORDER_NUMBER = external_order_number
INTERNAL_TECHNICAL_NUMBER = internal_order_number / unique / hidden by default
ORDER_KIND = customer | kol_fulfillment
ORDER_SOURCE = independent from order kind
V1_SHIPMENT_CARDINALITY = 1 order : 1 shipment
BASE_CURRENCY = USD
```

Affiliate-attributed consumer sales remain `customer` Orders; `kol_fulfillment` means products/samples/gifts/replacements sent to a KOL.

## 3. Roadmap position

This package temporarily becomes the current product priority after ERP-INQUIRY-M1.1 Company is merged. ERP-INQUIRY-M1.2 Contact is PAUSED, not cancelled. After this package passes the required verification and Owner local UAT/merge gate, the default sequence resumes at ERP-INQUIRY-M1.2 Contact unless Owner changes priority again.

No remote release is authorized by this priority change.

## 4. Existing implementation to reuse

The baseline already contains:

- canonical `orders` table with internal/external numbers, source, owner and status;
- `order_items`, `order_addresses`, `order_external_references`;
- one-shipment-per-order `shipments` foundation;
- `ShipmentService` mark-shipped/reopen behavior;
- automatic Manual Sync pending creation after shipment;
- immutable Audit integration;
- Order-level Finance/Cost foundation;
- customer and KOL route surfaces that currently share OrderController behavior;
- Logistics pending-shipment queue and copy actions;
- existing MVP1 workflow tests.

This package is an additive/remediation evolution, not a rewrite.

## 5. Required product outcomes

### 5.1 Order identifiers

- manual `external_order_number` is required and preserved as entered except safe surrounding-whitespace handling;
- all normal Order/Logistics/KOL/Finance UI uses `external_order_number` as the primary displayed order number;
- `internal_order_number` remains generated, immutable, globally unique and hidden by default;
- technical/internal number may be exposed only through explicit advanced/system/audit context;
- manual duplicate business order numbers are rejected within the manual ERP connection scope.

### 5.2 Order kind

Introduce canonical business classification:

```text
customer
kol_fulfillment
```

Do not reuse `source_type` as business classification.

Customer order pages must scope to `customer`. KOL fulfillment order pages must scope to `kol_fulfillment`. Both remain rows in the same `orders` table.

### 5.3 Unified manual create

Use one shared Order Create implementation.

Entry behavior:

```text
Customer → New Order       defaults customer
KOL → New KOL Order        defaults kol_fulfillment
```

The same server-side create action/service owns validation and persistence. Do not fork duplicate CustomerOrder/KolOrder models or services.

### 5.4 Order items

Continue one line per item/SKU. No fixed Product-1/Product-N columns. Preserve existing Order Item truth and extend only where the frozen contract requires compatible snapshot/analysis fields.

### 5.5 Logistics

Both `customer` and `kol_fulfillment` pending shipments enter the same Logistics queue.

Queue UI must include the business order number and an order-kind indicator, while reusing the existing ShipmentService.

The copy action must be reliable in the actual local browser/runtime, with deterministic failure handling and an approved fallback when Clipboard API capability is unavailable. Silent copy failure is prohibited.

Mark-shipped behavior must preserve existing shipment, Order, Manual Sync and Audit semantics.

### 5.6 Order detail fulfillment projection

Provide the smallest useful fulfillment workspace in Order Detail without a broad page redesign. It must make address/items/current shipment/tracking/manual-sync status and permitted correction/actions easy to understand.

### 5.7 Pagination and query policy

Order customer/KOL lists and Logistics queues must use bounded server-side pagination, filtering, ordering and authorization. All-order `.get()`/client-side filtering patterns must not remain on user-facing large lists after this package.

Design check: 50,000 Orders normal; 500,000 Orders architecture/performance review target.

## 6. Authorization / data scope

The package must implement or reuse server-side authorization so operational users do not receive unrelated users' order data by default.

Required model:

```text
FUNCTION_PERMISSION + DATA_SCOPE + FIELD_PERMISSION
```

Minimum behavior:

- business operators see their permitted/owned Orders;
- KOL specialists see their permitted KOL fulfillment Orders;
- logistics can access Orders assigned/eligible for shipment without receiving unrestricted Finance-sensitive fields;
- Finance/Owner all-order projections are not implemented by sending unauthorized full datasets to Vue;
- Owner/Admin retains all-scope authority according to existing canonical permission conventions.

Do not invent a new role system if the existing permission foundation can express the approved behavior.

## 7. Schema / migration preflight gate

This package is schema-sensitive and therefore Tier B.

Before authoring the final migration/backfill behavior, Claude must perform a read-only implementation preflight and report actual legacy shapes for:

```text
orders.source_type = website | kol | manual_other
external_order_number population/duplicates
existing Order rows relevant to KOL/manual usage
current indexes and constraints
current role/permission catalog
```

Backfill policy must obey the frozen rule:

- deterministic legacy rows may be mapped automatically;
- ambiguous rows must not be guessed;
- no production database or real production dump may be used;
- migration execution remains local/test only until separately authorized by the package gates.

If `manual_other` cannot be classified safely, the implementation must use an explicit compatible migration/exception strategy rather than silently classifying it.

## 8. Historical MVP1 Human UAT findings in scope

This package must revalidate/remediate the four remaining legacy findings relevant to Orders/Fulfillment:

```text
MVP1-UAT-DEFECT-002
Create Order server-side required-field contract
→ current main already appears to contain server validation; re-test exact expected behavior before adding redundant rules.

MVP1-UAT-DEFECT-003
User business order number overwritten/generated
→ resolve through external_order_number primary-display/preservation contract and hidden internal number.

MVP1-UAT-DEFECT-004
Shipment copy ineffective + Admin permission concern
→ reproduce/classify exact root cause, implement reliable copy and verify server authorization.

MVP1-UAT-FINDING-005
Order Detail lacks fulfillment-workspace information architecture
→ implement bounded fulfillment detail projection and Owner UAT.
```

No finding is declared CLOSED until exact implementation evidence and Owner UAT support closure.

## 9. KOL navigation impact

The long-term approved KOL Level-2 target is:

```text
所有网红
网红订单
佣金明细
网红统计
关系维护
```

This Work Package may change KOL Level-2 runtime navigation only where necessary to expose the real `kol_fulfillment` order surface and only if the implementation preflight confirms no undeclared shared App-Shell dependency. Level-1 KOL governance is frozen and must not be renamed/reordered.

Full KOL CRM, AffiliateWP connector, commission detail/performance implementation and relationship-maintenance product work remain outside this package unless Owner explicitly expands scope.

## 10. Explicit exclusions

Not authorized by this Work Package contract alone:

```text
full AffiliateWP API connector
AffiliateWP Visits/Conversion clone
full KOL CRM implementation
advanced KOL statistics dashboard
full Payment/Refund module
advanced SKU financial allocation engine
WooCommerce inbound synchronization milestone
ERP-INQUIRY-M1.2 Contact or later Inquiry work
split-shipment 1:N implementation
remote deployment
Production access or migration
real credentials/tokens/cookies/customer exports in repository
new third-party dependency without explicit need/approval
broad App Shell redesign
```

## 11. Verification contract

Because this package changes schema semantics and authorization/data scope, verification tier is:

```text
TIER_B = FULL DUAL-HOST REQUIRED
```

US Mac / Claude Code:

- focused backend Order/Logistics/permission/migration tests;
- full PHPUnit;
- full Vitest;
- TypeScript/typecheck;
- build;
- Browser E2E including Customer and KOL fulfillment flow;
- local migration/backfill test on disposable/local test database only;
- no external/production network dependency;
- git diff check / clean evidence.

China Mac / Codex CLI:

- exact approved Head SHA;
- independent full verification required for this Tier B package;
- no source mutation, fix, diagnosis or architecture decisions;
- local `http://erp.b` real browser/UAT evidence as permitted;
- report raw PASS/FAIL evidence to GPT/Owner.

## 12. Required Browser E2E scenarios

At minimum:

```text
Customer manual order
→ business order number preserved
→ customer list scoped
→ pending Logistics
→ copy information behavior
→ mark shipped
→ shipment/manual-sync state visible

KOL fulfillment manual order
→ business order number preserved
→ KOL fulfillment list scoped
→ same pending Logistics
→ mark shipped

Authorization
→ operator cannot read unrelated scoped Orders through server routes

Pagination/filter
→ bounded server-side result contract
```

## 13. Acceptance gates

This Work Package is not complete until:

1. canonical Order identity and `order_kind` semantics match the frozen contract;
2. manual customer and KOL fulfillment Orders use the same canonical database/service;
3. business order number is the primary UI identifier and is never replaced by ERP technical number;
4. both order kinds flow through one Logistics/Shipment workflow;
5. reliable copy behavior is browser-verified;
6. server-side data scope/permissions are verified;
7. large-list query surfaces are paginated/indexed server-side;
8. existing Manual Sync/Audit behavior has no regression;
9. the four legacy Order/Fulfillment Human UAT findings are revalidated and only closed where supported;
10. US full verification PASS;
11. China exact-SHA full independent verification PASS;
12. Owner local UAT PASS;
13. Owner explicitly authorizes merge.

Remote deployment remains a separate milestone/release action.

## 14. Current authorization state

```text
CONTRACT: OWNER APPROVED DESIGN AUTHORITY
WORK_PACKAGE_FORMALIZATION: AUTHORIZED BY NEXT-STEP INSTRUCTION
PRODUCT_IMPLEMENTATION: NOT YET AUTHORIZED
MIGRATION_AUTHORING: NOT YET AUTHORIZED UNTIL IMPLEMENTATION GRANT/PREFLIGHT
MIGRATION_EXECUTION: NO
REMOTE_DEPLOYMENT: NO
MERGE: NO
```

The next gate after this Draft delivery PR is opened is explicit Owner authorization to begin the implementation preflight/implementation on the US Mac through Claude Code.

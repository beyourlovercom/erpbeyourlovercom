---
type: program-roadmap-reconciliation
project: BYL ERP
roadmap_id: ERP-PROGRAM-ROADMAP-RECONCILIATION-V1
status: OWNER DIRECTED / PROPOSED CANONICAL BASELINE
version: 1.0
updated: 2026-08-28
---

# ERP Program Roadmap Reconciliation V1

## Purpose

Remove stale execution-order conflicts after the rapid merge sequence through PR #110 and establish one current planning baseline before any new feature Work Package starts.

## Merged facts that old roadmaps must now recognize

- PR #93 Product Master / SKU — COMPLETE.
- PR #95 Order Create/Edit V2 — COMPLETE.
- PR #96 Inventory Foundation + Batch Sync M1 — COMPLETE.
- PR #101 Manual Fulfillment Integration — COMPLETE.
- PR #104 WooCommerce Inbound — COMPLETE.
- PR #106 Tracking Outbound — COMPLETE.
- PR #108 Sync Orchestration — COMPLETE.
- PR #103 KOL M1.0 Foundation — COMPLETE.
- PR #110 KOL M1.1 CRM — COMPLETE.

## Canonical truth ownership

```text
KOL = KOL master, CRM, Collaboration, Content, KOL finance projections
Product Master = products.id / SKU truth
Inventory = Warehouse + SKU×Warehouse stock truth
Order = orders.id demand/fulfillment order truth
Shipment = shipment/carrier/service/tracking fulfillment truth
Sync = remote synchronization/orchestration evidence
AffiliateWP = Affiliate / Referral / Payout provider truth
```

No module may create a second truth for another module's canonical entity.

## KOL / Fulfillment conflict resolution

KOL M1.2 is split into:

```text
M1.2A Collaboration
M1.2B Canonical Fulfillment Bridge
```

M1.2A owns formal cooperation facts. M1.2B only links a Collaboration to the existing canonical `orders.id` with `order_kind=kol_fulfillment`, then projects existing Shipment/Tracking state back to KOL. KOL does not own Reservation, allocation, packing, shipping cost, transport mode, carrier rules or WMS connectors.

## Current execution policy

After this reconciliation is merged, no product Work Package is implicitly CURRENT. Owner chooses the next bounded track explicitly.

Eligible next tracks are:
1. `ERP-KOL-M1.2A-COLLABORATION-001`;
2. `MVP2 Inventory Reservation Foundation`;
3. reactivation of `ERP-INQUIRY-M1.2 Contact`.

These are alternatives, not a parallel mandate.

## Draft PR classification

- PR #97 Entity Architecture V2 — retain as long-term docs/design reference; not current implementation authority.
- PR #98 Release 1.2–1.5 rebaseline — CLOSED / superseded by later merged facts and this reconciliation.
- PR #109 duplicate KOL CRM — CLOSED / superseded by merged PR #110.
- PR #81 old Dashboard pilot — legacy/stale implementation experiment; not roadmap authority and must not be merged without a fresh rebase/re-evaluation.

## Safety

This reconciliation changes planning/governance only. No product code, schema, migration, dependency, runtime, production data or deployment is authorized.

## Owner priority update — 2026-08-28

Owner selected **KOL V1 full completion and stabilization** as the next product program. After this reconciliation merges, the default execution order is M1.2A Collaboration → M1.2B Canonical Fulfillment Bridge → M1.3 Content → M1.4 Finance/Affiliate → KOL V1 Cross-phase Closure. MVP2 and Inquiry M1.2 remain paused unless Owner explicitly reprioritizes.

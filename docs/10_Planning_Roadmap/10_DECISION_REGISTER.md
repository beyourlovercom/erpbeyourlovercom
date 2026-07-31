---
type: decision-register
project: BYL ERP
version: 1.0
updated: 2026-07-31
---

# Decision Register

## D-001 Mandatory architecture

Laravel modular monolith + Inertia/Vue/TypeScript/Tailwind + MySQL/Redis/Queue/Scheduler + session auth + server authorization + audit.

## D-002 Dashboard

`/dashboard` is the only ERP homepage.

## D-003 Risk and urgent

Manual internal flags or controlled statuses; no invented SLA logic.

## D-004 Sync naming

User-facing `同步`; backend `sync`; route `/sync`; permissions `sync.*`.

## D-005 Sync is primary navigation

Sync is a high-frequency primary menu.

## D-006 Public registration

Disabled. Accounts are administrator-created.

## D-007 MVP1 target

Replace Tencent Docs manual order/fulfillment handoff in approximately one month.

## D-008 MVP1 architecture

Manual functionality uses final-compatible Order, Shipment, External Reference, Sync Outbox and Cost models. No temporary truth tables.

## D-009 MVP sequence

```text
MVP1 manual
→ MVP1.1 WooCommerce inbound
→ MVP1.2 single tracking outbound
→ MVP1.3 batch/retry/conflict
```

## D-010 Manual paths remain

Manual order entry and manual website-sync remain permanent fallback capabilities.

## D-011 Finance order analysis

Order-level profitability is included in MVP1 under Finance / Accounting.

## D-012 Cost status

Costs distinguish estimated, confirmed and adjusted.

## D-013 Discount

Do not deduct website discount twice when amount paid already reflects it.

## D-014 Product synchronization

WordPress product detail and featured image enter Product OS; first direction is inbound.

## D-015 SEO OS

WordPress content, Google Docs publishing and source-specific GA4/GSC/Cloudflare metrics enter SEO OS through sync.

## D-016 KOL social

KOL center can request Instagram/X enrichment; official APIs only by default; follower snapshots and local avatar mirror.

## D-017 Obsidian

Repository Markdown is the source of truth. Obsidian reads the repository; no duplicate Roadmap.

## D-018 Documentation branch

Planning foundation must not be mixed into PR #1.

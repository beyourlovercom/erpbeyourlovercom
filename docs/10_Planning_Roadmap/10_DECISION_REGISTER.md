---
type: decision-register
project: BYL ERP
version: 1.0
updated: 2026-08-01
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

## D-019 001C closure, 001D preparation and Operations paths

001C is accepted and merged. 001D becomes the current prepared architecture package, but implementation still requires separate Owner authorization. Global Architecture Gate remains FAIL; no Migration or deployment is authorized.

Formal Work Packages are stored under `docs/60_Codex_Operations/work-packages/` and named `<WORK-PACKAGE-ID>.md`, beginning with `ERP-ARCH-MIGRATION-001D`. Work Logs retain `docs/60_Codex_Operations/logs/YYYY-MM-DD_<WORK-PACKAGE-ID>.md`.

## D-020 001D scope freeze and ordered execution split

001D is split into `ERP-ARCH-MIGRATION-001D-A` Runtime Foundation, `ERP-ARCH-MIGRATION-001D-B` Audit Foundation and `ERP-ARCH-MIGRATION-001D-C` Sync Outbox Foundation, executed in that order. The split grants no implementation authorization; 001D-A remains prepared with authorization NO, while 001D-B and 001D-C remain waiting.

Redis is the Queue and Cache baseline. Current Session configuration is preserved without a forced Redis migration. Laravel Scheduler is the runtime scheduling foundation; production cron remains a documentation contract only. The existing 001B audit foundation must be preserved and extended additively, and Sync Outbox remains provider-independent integration intent rather than business truth. Migration file authoring may be authorized within a future subpackage, but Migration execution requires separate explicit authorization. External APIs, Sync UI, Order, Shipment and deployment remain excluded.

## D-021 Complete Program Roadmap and Roadmap Hours governance

Every formal task and final report must show the complete top-level Program Roadmap and a distinct complete current-track map. `ROADMAP HOURS` becomes mandatory between `PROJECT POSITION` and final `ESTIMATED CODEX HOURS`. Current-package estimates and program remaining estimates are distinct; unknown remaining estimates require evidence, and cumulative or overlapping values cannot be summed.

`MIGRATION` remains a mandatory umbrella field and cannot be replaced by authoring/execution subfields. Final reports require actual effective hours, breakdown, variance and variance reason. Repository-local standard version 1.1 applies prospectively and to open/unmerged work reviewed after merge; historical merged work is not retroactively invalidated. Open PR #10 must be updated before merge, and its unmerged transition D-021 must become D-022 after rebase. Architecture conflict: NONE. Application implementation, Migration and deployment authorization remain NO.

## D-022 001D-A completion and 001D-B preparation

PR #9 was accepted and squash merged. ERP-ARCH-MIGRATION-001D-A is MERGED / PASS, including the timeout-coherence remediation at accepted Head `3d8f92b701370db41bed0df707183207f6924dd5` and main commit `50803c3fcd51e9f8b3485f4fb57565154a284bf7`. Local validation passed, but live Redis PASS was not claimed.

ERP-ARCH-MIGRATION-001D-B becomes the current prepared Audit Foundation package with implementation authorization NO. Migration file authoring and Migration execution remain NO. ERP-ARCH-MIGRATION-001D-C remains WAITING, Global Architecture Gate remains FAIL, and deployment remains NO. Architecture conflict: NONE.

## D-023 001D-B Audit Foundation implementation authorization

The Owner authorized ERP-ARCH-MIGRATION-001D-B implementation from `main@0720a47ddc8825b9506ab28651fa07a8c1994e62`, including one Audit Service, explicit user and trusted-system actors, correlated structured metadata, recursive sensitive-field removal, immutable application writes, security-critical failure handling and separately authorized audit viewing.

The existing 001B `audit_records` schema contains all required fields, so the exact proposed schema delta is NONE. No Migration file was created or modified and no Migration was executed. ERP-ARCH-MIGRATION-001D-C remains WAITING, merge requires a separate Owner decision after GitHub independent review, Global Architecture Gate remains FAIL, and deployment remains NO. Architecture conflict: NONE.

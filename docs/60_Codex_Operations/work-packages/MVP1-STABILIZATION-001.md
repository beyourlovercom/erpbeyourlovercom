# MVP1-STABILIZATION-001

## Objective

Repair the seven Owner-reported stabilization findings before MVP2. Preserve existing canonical business truth and reuse validated MVP1.1–1.3 infrastructure rather than rebuilding it.

## Delivery Groups

1. Global regressions: STAB-001 VI readability, STAB-002 navigation aliases, STAB-003 self account management.
2. Operational workspaces: STAB-004 Sync IA, STAB-005 Carrier ledger, STAB-006 Orders Saved Views.
3. Knowledge base: STAB-007 Markdown Guides + release changelog.

## Non-Negotiable Contracts

- Customer/KOL order shortcuts must exist both under Orders and their owning business module.
- Self password change validates current password; administrator reset remains a separate path.
- Sync Run/Outbox stay the synchronization authority; no second queue.
- Carrier history reads canonical Shipment truth and may store provider/billing evidence without duplicating Shipment.
- Saved Views store query definitions, not copied orders.
- Guides store Markdown; rendered tutorial H2 headings generate the page TOC.
- Release notes are publishable records, not raw Git commit dumps.
- No MVP2 Reservation, allocation, packing, or carrier-rule automation in this package.

## Gate

Focused tests per finding → Architecture + Approved Menu/Navigation → migration smoke → typecheck/build → full PHPUnit/Vitest → isolated Owner UAT → Ready → explicit merge authorization.
# ERP Logistics Tracking Canonical Validation Hotfix 001

Status: implementation / PR gate

## Trigger
Production real-order UAT found shipment `007-0909-c40377` with a contaminated tracking value containing the carrier number plus recipient/repacking note text.

## Frozen rule
- Tracking stores the carrier tracking token only.
- Leading/trailing whitespace is trimmed.
- Internal whitespace, CJK text, and note text are rejected.
- Operator notes belong in `logistics_note`.
- Existing Production dirty rows are not auto-repaired by this hotfix.

## Scope
Centralize validation in `ShipmentService` so draft, mark-shipped, later tracking update, and overseas draft paths share the same rule.

## Verification
Focused Logistics gate: 6 tests / 29 assertions PASS on Windows isolated checkout.
Full `LogisticsOsV1ClosureTest`: new tracking tests pass; 8 inherited Inertia-response failures remain due the Windows frontend test environment and are unrelated to this change.

No Production data mutation is authorized by this work package.
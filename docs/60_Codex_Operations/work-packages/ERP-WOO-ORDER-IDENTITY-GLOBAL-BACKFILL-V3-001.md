# ERP-WOO-ORDER-IDENTITY-GLOBAL-BACKFILL-V3-001

Status: READY_FOR_REVIEW

## Goal
Safely backfill historical Woo customer ERP IDs to the frozen canonical format:
`007-MMDD-c{Woo Order Number}`.

## Scope
- Preserve existing single-day `--date` behavior.
- Add inclusive `--from-date` / `--to-date` range scope.
- Default to dry-run.
- Apply requires exact `--expect-change` match.
- Any blocked row or target identity collision fails closed before writes.
- Every applied identity change writes `order.erp_identity_repaired` Audit evidence.

## Safety
- No Production write is authorized by this work package.
- Production must run a read-only full-range preview after code deployment.
- Full-range Apply requires a separate Owner gate tied to the exact preview count.
- Manual customer and KOL identity rules are unchanged.

## Verification
- Focused Order/Woo/Repair regression: 19 tests / 108 assertions / PASS.
- Pint: PASS.
- `git diff --check`: PASS.

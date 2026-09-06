# ERP-INVENTORY-V2-AUTOMATIC-ALLOCATION-HARDENING-001

STATUS: IMPLEMENTED / VERIFICATION_PASS / PRODUCTION_HOTFIX_PENDING
BASELINE: 76deca4846d912009c182f4934e658643484c808
BRANCH: hotfix/erp-inventory-autoalloc-scheduler-hardening-001
EXECUTION_PROVIDER: GPT_WEB
VERIFICATION_TIER: HIGH_RISK

## Objective
Close Automatic Allocation V1 production hardening without broadening the frozen routing policy.

## Scope
- Apply scheduler catch-up `limit` only after filtering to the configured automatic-allocation country allowlist.
- Preserve exact `--order` inspection behavior for diagnostics/manual preview.
- Keep historical orders excluded and all eligibility/recommendation checks unchanged.
- After deployment, return Production catch-up limit from temporary `1000` mitigation to bounded `100`.

## Production evidence before patch
- DE-only automatic allocation enabled and hourly scheduler active.
- 9 automatic Reservations + 9 automatic Shipment Drafts created successfully.
- QOH remained 4913; Reserved became 9; non-DE automatic Shipments remained 0.
- Immediate replay mutated 0 rows.
- Temporary `CATCH_UP_LIMIT=1000` was used to avoid starvation under the old query ordering.

## Verification
- Starvation regression: older US pending Order + newer DE ready Order + `--limit=1` selects and allocates DE.
- Automatic Allocation + Assisted Fulfillment + Reservation + Logistics closure: 55 tests / 421 assertions PASS.
- `git diff --check`: PASS.

## Boundary note
`Reship` after an already committed first shipment has no second Inventory Reservation/QOH consumption contract in current Logistics. This is a downstream post-dispatch inventory-consumption concern and is not silently changed by this scheduler hardening package. It must be addressed by Automatic Fulfillment / Logistics before fully automatic re-dispatch.

## Production gate
The hotfix may be deployed as the exact branch head based on Production `76deca4`, avoiding later Accounting merges. Routing remains DE -> DE only.

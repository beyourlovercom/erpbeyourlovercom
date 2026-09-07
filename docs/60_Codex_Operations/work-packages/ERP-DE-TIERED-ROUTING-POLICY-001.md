# ERP-DE-TIERED-ROUTING-POLICY-001

## Scope
Freeze the Owner-approved three-tier Germany warehouse routing policy in the centralized routing configuration.

## Group A — DE preferred
DE, CZ, PL, SK, HU, SI, AT, NL, BE. DE must have Full Snapshot authority and whole-order stock. Only DE destination remains automatic; all other countries stay assisted-only.

## Group B — cost evidence gate
FR, ES, IT, PT, GR, EE. DE is recommended only when both real China historical shipping-cost evidence and real DE DHL quote evidence exist, and `DE DHL + EUR 1 handling` is strictly lower than China historical shipping cost. Missing/invalid evidence is fail-closed.

## Group C — default China
DK, FI, SE. Do not route to DE unless Owner changes policy later.

## Carrier / cost boundary
DE warehouse uses DHL only. Routing includes EUR 1 per-order handling. Storage cost is excluded from routing and remains Finance/Warehouse Billing Evidence scope.

## Safety
No split shipment. No cross-region fallback. No automatic enablement for non-DE countries. No Production data mutation or deployment in this work package.

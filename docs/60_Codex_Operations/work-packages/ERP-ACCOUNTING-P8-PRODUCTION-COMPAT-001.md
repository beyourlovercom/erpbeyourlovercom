# ERP-ACCOUNTING-P8-PRODUCTION-COMPAT-001

**Status:** CLOSED / PRODUCTION ACCEPTED

## Objective
Make P8 KOL commission runtime safe when Production has PR338 but has not yet deployed PR337 MorePlus / overseas-handling capability.

## Incident evidence — 2026-09-16
- PR #339 code-only overlay was cut over above Production PR338.
- P8A Referral Order Resolution Production Data Write Gate succeeded: 3,351 seen / 3,327 resolved / 24 unresolved / 0 ambiguous.
- P8A replay became 3,351 noop; customer Profit remained unchanged at 13,902 cost entries and one pre-existing `kol_commission` row.
- First Finance read-only dry-run failed before preview because `CostMaterializationService` required `OverseasHandlingEvidenceReader` from PR337.
- Production did not have a PR337 release or its migration/runtime dependency closure.
- P8 runtime was immediately rolled back to the prior PR338 release; P8A resolved Referral data intentionally remained.

## Frozen compatibility contract
- Do not deploy PR337 or its migration as an implicit P8 dependency.
- Overseas-handling is optional until its contract is installed and bound.
- If absent, general Finance preview and continuous delta skip overseas-handling instead of producing a container or relation failure.
- If present, existing PR337 overseas-handling behavior remains unchanged.
- P8 `kol_commission` evidence, FX bridge, strong-fact rules, dedicated Production apply gate and default-disabled continuous-delta activation remain unchanged.
## Implementation
- Remove hard constructor injection of `OverseasHandlingEvidenceReader` from Finance materialization.
- Resolve that capability only when the interface exists and the container has a binding.
- Skip the overseas-handling candidate and dedicated stage when the capability is unavailable.
- Guard the continuous-delta overseas relation query with the same capability test.

## Validation
- P8 / continuous-delta / PR337 P5 focused: 24 PASS / 160 assertions / 1 MySQL-only skip.
- Finance full regression: 97 PASS / 752 assertions / 1 MySQL-only skip.
- PR337 `InventoryMorePlusCostBridgeP5Test`: 4 PASS / 44 assertions.
- Architecture/navigation suite: hotfix 8 fail / 44 pass / 1,842 assertions; exact `main@89edd3a5` is identical, regression delta 0.
- `git diff --check` and PHP lint PASS.

## Production boundary
This hotfix performs no Production data writes. After merge, Production P8 must be rebuilt over the current PR338 runtime using PR #339 runtime plus this compatibility delta only. Do not activate `ERP_ACCOUNTING_CONTINUOUS_DELTA_KOL_COMMISSION_ENABLED` before the initial Finance KOL Commission Data Write Gate is accepted and post-verified.

## Production closure — 2026-09-17
- Compatibility PR #344 merged at `d99ab97ac44d999c34caa6cd803042431d30da26`.
- Production convergence verified the compatibility delta without requiring an implicit PR337 deployment.
- Runtime without `OverseasHandlingEvidenceReader` successfully executed KOL Finance dry-run and canary preview; no container/relation failure remained.
- Subsequent Production releases preserved the compatibility behavior while later warehouse/handling capability evolved independently.
- This compatibility package is closed.

# ERP-FULFILLMENT-COST-ENGINE-CN-DE-001

STATUS: APPROVED_FOR_IMPLEMENTATION
EXECUTION_PROVIDER: US_CODEX_CLI
OWNER_SCOPE_APPROVED: 2026-09-07
BASELINE: origin/main at worktree creation

## Goal
Implement read-only China Factory + Germany DHL routing-cost calculation for `FulfillmentPlanPreviewService`.
No Production deploy, no schema changes, no automatic split execution, no auto-ship.

## Existing boundary
- `FulfillmentPlanPreviewService` already generates single/factory/split plans.
- Current split execution remains HUMAN_REVIEW_ONLY.
- Existing manual `plan_costs[...]` evidence remains supported and must override automatic estimate when supplied.
- Packaging truth comes from approved current product packaging standards.
- Missing packaging/rate/FX must fail closed and return explicit reason, never guess.
## China canonical source
File: `仓库-物流-国内小包费用.xlsx`
Sheet: `SD经济挂号-带电`
Source formula: chargeable weight = max(actual weight, L*W*H/8000), rounded upward by gram unless a country-specific minimum/increment applies.
For aggregated multi-item Preview, use sum packaged weights and sum packaged volumes as an explicit estimate; mark provenance as estimated-consolidated packaging.

Normalize at least these unambiguous price rows into versioned repo data:
- US: 0-.1 131/kg+25; .101-.2 131+23; .201-.45 131+21; .451-.7 131+21; .701-1 131+14; 1.001-3 131+14; 3.001-6 131+14; 6.001-30 131+14 RMB. Minimum billing 0.05kg.
- CA: 0-.15 104+26; .151-.3 104+26; .301-.45 104+26; .451-.75 113+27; .751-1 113+27; 1.001-1.5 113+28; 1.501-2 120+28; 2.001-30 120+28. Minimum billing 0.05kg.
- MX: 0-.25 102+23; .251-.5 102+23; .501-1 102+23; 1.001-1.5 109+25; 1.501-2 109+25; 2.001-10 109+25.
- GB: 0-2 84+21; 2.001-15 81+21.
- DE: 0-.1 115+27; .101-.3 115+26; .301-2 115+27; 2.001-3 115+27; 3.001-30 120+27 RMB.
- FR: 0-.4 117+24; .401-30 117+28 RMB.
- IE: 0-20 125+28 RMB.
- IL: 0-1 159+26; 1.001-2 166+26; 2.001-8 180+26 RMB.
Do not auto-price Australia until postcode-zone resolution exists. Japan special billing increment conflicts with its visible weight rows; leave unsupported in V1 rather than infer.

EU27 surcharge source rule: for EU27, current effective policy precharges EUR 3 per declared Item; same parcel multiple declared items accumulate. For planner V1 treat unique product lines in a segment as declared Item count. Return this as a separate EUR component, not hidden in RMB.

US source exclusions are authoritative: no service to ZIP prefixes 006-009, 967-969, 995-999 and no APO/FPO military addresses. Therefore current canonical China channel cannot price AK/HI or military destinations; return `factory_channel_unserved_destination`. Do not override this even though business experience says China can sometimes be cheaper to AK/HI; that requires a future alternate China channel/rate source.
## Germany DHL canonical source
File: `2025-天运-德国-邮好运德国海外仓服务报价（生效时间：20250505）.xlsx`
Carrier: DHL only.
Owner routing handling: EUR 1.00 per parcel. Storage cost is Finance-only and excluded from routing.

DE domestic base rates EUR before fuel: 0-1kg 3.82; 1-2 3.97; 2-3 4.27; 3-5 4.55; 5-10 5.03; 10-15 5.53; 15-20 6.22; 20-25 6.81; 25-31.5 7.14. Domestic quote fuel = 3%; normal routing cost = base*1.03 + 1.00 handling. Weight uses actual weight rounded upward to whole kg for tier selection.

International formula from quote: billed kg = ceil(actual kg), minimum actual billing floor 0.1kg; transport EUR = billed_kg * continuation_rate + base_item_fee; quote already includes fuel/Toll; routing total = transport + EUR1 handling.
International rate pairs `country => continuation_rate, base_item_fee, max_kg`:
AT .2825,6.435,31.5; BE .2615,6.8445,30; CZ .566,4.8705,31.5; DK .2405,10.803,30; EE .482,8.682,30; ES .4295,8.2305,30; FI .4715,12.0735,30; FR .5345,8.0205,30; GR 1.4375,7.443,30; HU .3665,5.6895,30; IT .293,8.7975,30; LT .482,7.2435,31.5; LU .3245,6.603,30; NL .2195,6.561,30; PL .2195,6.0045,31.5; SE .335,12.9555,30; SI .293,5.826,30; SK .4085,5.6265,30; PT .4925,8.724,30.
Continue international rates: LV .482,8.1045,30; BG .7865,6.1515,31.5; MT 2.372,12.504,30; RO .545,7.674,31.5; HR .9965,4.7025,30; IE .965,6.729,30; CY 2.834,17.25,31.5; CH .2195,20.106,31.5; GB .881,12.0945,30; LI .2195,20.106,31.5; MC .5135,7.5585,30; SM .293,8.871,30; NO 1.6475,12.462,30.

Do not auto-apply optional DHL surcharges (return, address correction, peak, bulky, irregular) unless explicit order/package evidence says the trigger is true. Expose possible surcharge metadata separately.

## FX / comparison
Reuse `App\Modules\Finance\Application\EcbFxService` read-only reference conversion. Convert each ready segment component set to CNY for plan ranking; preserve raw components and ECB source/reference_date/stale. If ECB unavailable or a segment has missing rate/packaging, plan cost must be `missing_evidence` and not ranked.

## Packaging estimate
Use approved current product packaging standards. For a segment: actual_weight = sum(packaged_weight_kg*qty); volume_cm3 = sum(L*W*H*qty). China volumetric estimate = volume_cm3/8000. Missing any product packaging standard blocks automatic cost. Explicitly mark aggregation as an estimate for multi-item segments.

## Required implementation
Create versioned rate-data files and focused calculators/services. Integrate automatic cost into `FulfillmentPlanPreviewService`; manual `plan_costs` remains highest precedence. Keep existing plan keys stable. No UI redesign in this WP.
## Acceptance tests
At minimum cover:
1. China->DE 0.250kg, no volumetric override: RMB component = 0.25*115+26 = 54.75; EU surcharge = EUR3 for one declared product line.
2. China->FR 0.500kg: RMB component = 0.5*117+28 = 86.50 plus EUR3/item.
3. China channel blocks HI/AK and APO/FPO with explicit source-derived reason.
4. DE->DE 0.454kg: DHL base 3.82*1.03 + handling 1 = EUR4.9346.
5. DE->FR 0.454kg: 1*.5345+8.0205+1 = EUR9.5550.
6. DE international 9.6kg AT uses ceil=10: 10*.2825+6.435+1 = EUR10.2600.
7. Missing packaging/rate/FX fails closed.
8. Manual `plan_costs` overrides auto calculation.
9. Existing US/CA/DE routing/recommendation tests remain green.
10. No schema/migration; no Production mutation.

Run focused calculator/planner tests plus existing Logistics/Reservation regression used by PR #248. Commit and push branch, but do not merge or deploy; return exact test counts and head SHA to GPT.

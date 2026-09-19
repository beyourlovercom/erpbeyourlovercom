# ERP Accounting Evidence Completeness + KOL Immutable-FX Replay — 2026-09-19

## Scope

Bounded P1 Accounting completeness audit plus one code correction discovered by read-only Production evidence review. No Product Cost guessing, no historical cost back-propagation, no Production data write in this work package.

## Production read-only baseline

Operational customer Orders excluding `historical_logistics`: 6,303.

Revenue-ready customer Orders under the existing financial-evidence contract: 5,569.

Canonical Shipment coverage among those revenue-ready Orders:

- with Shipment: 1,851
- without Shipment: 3,718
- without Shipment by order year: 2025 = 2,433; 2026 = 1,285

Required cost coverage among revenue-ready Orders:

- all six required cost types present: 33
- at least five required cost types present: 942
- product_cost present: 292
- first_mile_cost present: 1,852
- overseas_handling_cost present: 1,851
- last_mile_cost present: 876
  - actual/confirmed: 142
  - estimated: 734
- payment_fee: already globally materialized under its frozen program
- customs_tax: existing owner-rule materializer replay is noop for all 6,303 operational customer Orders

The current aggregate `INCOMPLETE` population must not be interpreted as one homogeneous defect. It combines non-revenue orders, historical fulfillment evidence gaps, required-cost evidence gaps, and optional cost semantics.

## Product Cost evidence boundary

Production currently has 57 confirmed Product Cost source rows covering 55 Products. Revenue-ready Orders use 136 distinct Product IDs; 52 of those currently have any confirmed Product Cost source.

The existing Product Cost source contract deliberately uses `effective_from=2026-07-01`. GitHub documentation explicitly forbids back-propagating July cost into 2025 or earlier 2026 Orders without historical effective evidence.

For revenue-ready Orders on/after 2026-07-01:

- Orders: 1,125
- with product_cost: 291
- missing product_cost: 834

Largest post-July gaps include `gift`, `VA617A`, `VA617A-GREEN`, `VA617A-PINK`, `TL278A-Green`, and other Products whose ERP standard cost fields are also blank. The original `成本货本007组 7月进销存.xlsx` source file is not currently available in ChatGPT Library; only the prior 57-row reconciliation workbook is available. No fallback Product Cost may be fabricated.

## Materializer preview findings

Read-only Production previews:

- product_cost / first_mile: product apply=0; first-mile apply=0
- actual last-mile: apply=0
- warehouse cost: apply=0
- last-mile estimate: apply=3; these are refreshes, not blank-cost backfills
- KOL commission: apply=645; noop=1,772; blocked=3,886

Further inspection proved the 645 KOL `apply` rows had identical business amounts before/after. Currency totals were unchanged exactly:

- GBP: 105 Orders, old 1,396.68 = new 1,396.68
- EUR: 328 Orders, old 5,805.79 = new 5,805.79
- CAD: 124 Orders, old 4,564.21 = new 4,564.21
- AUD: 88 Orders, old 3,029.29 = new 3,029.29

Sample evidence showed identical AffiliateWP `source_reference`, identical `source_at`, amount, currency, provenance and status; only the current FX reference date had advanced. The generic disposition logic therefore treated immutable historical KOL postings as apply candidates.

## Code correction

`CostMaterializationService` now treats an AffiliateWP posting as the same immutable business-evidence snapshot only when all of the following remain identical:

- `source_reference` begins `affiliatewp-referrals:v1:` and matches the existing entry
- `source_at` matches
- amount matches
- currency matches
- provenance matches
- status matches

For that exact same evidence snapshot, current Settings FX/date drift is ignored and the already-posted amount remains frozen/noop. A real referral evidence change still becomes apply-eligible.

This is intentionally narrower than the existing P8.1 multi-shipment immutable-FX rule. Direct last-mile estimates remain refreshable when their underlying estimate evidence actually changes.

## Validation

- PHP syntax check on changed PHP files: PASS
- `git diff --check`: PASS
- KOL + Last-mile + Accounting Continuous Delta: 29 tests / 163 assertions, no failures
- Multi-shipment suite excluding one proven main-baseline Inertia-response failure: 10 tests / 82 assertions, no failures
- The excluded `order_profit_formula_route_renders_canonical_explanation_page` failure reproduces unchanged on clean `origin/main` (`c40cefdad7aca380c1cf4a2f124b229c94602f4b`) and is therefore not introduced by this patch.
- GitHub Actions remains subject to the account-side billing/runner blocker documented in the canonical roadmap; no CI-green claim is permitted unless a run actually starts and passes.

## Next evidence gates

1. Merge this bounded immutable-FX fix.
2. Run the patched KOL materializer against Production in dry-run only; expected semantic result is that the previous 645 false-positive apply candidates collapse to noop unless AffiliateWP evidence changed since the audit.
3. Keep the three last-mile refresh candidates separate for evidence-by-evidence review.
4. Product Cost completeness requires a new/expanded confirmed cost source file; historical pre-2026-07-01 coverage requires historical effective evidence, not date rewriting.
5. The 3,718 revenue-ready Orders without canonical Shipment remain a distinct historical fulfillment-evidence coverage project.

# ERP-ACCOUNTING-V11C-P8-KOL-COMMISSION-001

**Status:** CLOSED / PRODUCTION ACCEPTED / CONTINUOUS DELTA ACTIVE

## Objective
Materialize canonical AffiliateWP Referral commission into customer Order Profit without recreating AffiliateWP provider truth or reopening KOL identity history.

## Frozen provider / finance semantics
- AffiliateWP remains Referral / Commission / Payout provider truth.
- Only provider status `unpaid|paid` is recognized as customer `kol_commission` cost.
- `pending` is projected only; `rejected`, `draft`, and `failed` are not recognized cost.
- Referral must be `reconciliation_status=present` and exact-linked to one canonical Woo customer Order.
- Source commission currency/amount is preserved. AffiliateWP current Production commission source is USD.
- Finance provenance is `provider_import`; status is `confirmed`.
- Existing `actual`, `confirmed_manual`, `allocated_actual`, or different strong facts are never silently overwritten.
- No new scheduler; reuse Accounting continuous delta.

## Production Source Audit — 2026-09-16
- Referral mirror rows: 3,351.
- Current `order_resolution_status=unresolved`: 3,351.
- Read-only exact join to canonical Woo Order references: 3,327 exact / 24 missing / 0 ambiguous.
- Eligible `paid|unpaid + present` exact set: 2,417 Referral rows / 2,417 Orders.
- Eligible AffiliateWP source commission: USD 56,113.30.
- Eligible Order transaction currencies: USD 1,772; EUR 328; CAD 124; GBP 105; AUD 88.
- Therefore 645 eligible Orders require Accounting FX Snapshot Bridge; same-currency-only behavior is not acceptable.
- Existing manual `kol_commission`: exactly one `confirmed_manual` row on Order 1200; it is not in the eligible 2,417-Order set.

## P8A — ERP-local Referral Order re-resolution
Add a dry-run-first local resolver using only existing `kol_affiliate_referrals` and canonical `order_external_references`.
It must not call WordPress, WooCommerce or AffiliateWP and must not change Provider Referral facts.
Production apply requires process-local `ERP_KOL_REFERRAL_ORDER_RESOLUTION_PRODUCTION_APPLY_ENABLED=true` plus exact `seen/ready/unresolved/ambiguous/change` guards.
Only `order_id` and `order_resolution_status` may change. Missing/ambiguous references fail closed to unresolved.

## P8B — Multi-currency commission bridge
Finance reads exact eligible Referral rows, sums one source currency per Order, preserves `source_currency/source_amount/referral_ids`, and converts through the existing Accounting FX Snapshot Bridge when source currency differs from Order currency.
Mixed/missing Referral source currencies fail closed. Cross-currency posting freezes the existing transaction FX snapshot contract.

## P8C — Dedicated Finance gate
Provide `finance:materialize-kol-commission`, dry-run by default. Production apply requires process-local `ERP_ACCOUNTING_KOL_COMMISSION_PRODUCTION_APPLY_ENABLED=true` plus exact `seen/apply/noop/blocked/fx-freeze` guards.

## P8D — Continuous delta
Add `kol_commission` to the existing Accounting continuous-delta stages with independent activation `ERP_ACCOUNTING_CONTINUOUS_DELTA_KOL_COMMISSION_ENABLED=false` by default. While disabled, Referral mirror `updated_at` is not a delta candidate trigger and the `kol_commission` stage is not executed; therefore P8A Production re-resolution cannot indirectly trigger Finance writes. After the dedicated initial Finance KOL Commission Production Data Write Gate passes and becomes idempotent/noop, enable this stage so future present Referral mirror changes re-enter scope. No second scheduler is created.

## Data-write boundaries
- Code/test/PR work performs no Production Referral rewrite and no Production `kol_commission` write.
- After Production code-only deploy, first run local Referral resolution dry-run and enter a separate Referral Order Resolution Production Data Write Gate.
- Only after post-verify, run Finance commission dry-run and enter a separate Finance KOL Commission Production Data Write Gate.
- ERP Changelog remains manual-only and is not updated by this package unless Owner explicitly asks.

## Validation evidence
- P8 dedicated Referral resolution: 2 tests PASS.
- P8 dedicated KOL commission materialization / FX / Production guard: 3 tests PASS.
- Existing CostMaterialization regression remains green after preserving the existing mixed-currency blocker contract.
- Accounting continuous delta includes a dedicated Referral-change test and passes 14 tests / 80 assertions with one MySQL-only skip.
- Activation-race guard: default-disabled KOL commission continuous-delta stage prevents Referral-only P8A writes from re-entering Accounting; explicit activation restores Referral candidate + commission materialization behavior.
- Final focused gate after latest-main rebase: 20 PASS / 123 assertions / 1 MySQL-only skip.
- Final Finance regression: 95 PASS / 743 assertions / 1 MySQL-only skip.
- Final KOL regression: 295 PASS / 1,320 assertions.
- Governance suite on final P8 candidate: 8 fail / 44 pass / 1,842 assertions; the 8 failing test names exactly match exact latest `main@0c9b7975`, so Architecture regression delta = 0.
- Latest main advanced through HFR6 and Inventory MorePlus Cost Bridge P5. P5 did overlap Accounting continuous-delta/materialization files; P8 rebased cleanly onto `main@0c9b7975`, preserving `overseas_handling_actual` alongside gated `kol_commission`. P5 regression passes 4 tests / 44 assertions.

## Work Log — 2026-09-16
- Implemented P8A ERP-local Referral→Order exact re-resolution, dry-run first, Production process flag + exact guards, fail-close unresolved/ambiguous behavior, and one aggregate Audit event on mutation.
- Implemented P8B multi-currency KOL commission bridge using existing Accounting FX Snapshot Bridge while preserving source amount/currency/referral IDs and strong-fact protection.
- Implemented P8C dedicated `finance:materialize-kol-commission` dry-run/Production exact-count gate.
- Implemented P8D on the existing Accounting continuous-delta scheduler only; no second scheduler.
- Final safety review found and closed the activation race: P8D is independently default-disabled until initial Finance materialization post-verify/noop. Both stage execution and Referral candidate trigger are gated.
- No Production Referral rows, `order_cost_entries`, Provider facts, or runtime activation flags were written during code/test/PR work.
- ERP Changelog not updated because this package remains manual-only.
- Continuation implementation/verification effort in this execution window: approximately 1.5 effective hours; no pre-existing historical effort inferred beyond recorded evidence.


## Production closure — 2026-09-17 / reverified 2026-09-18
- PR #339 merged at `4b17c6d8943369f984d5764a6d2bff70708558d5`; compatibility hotfix PR #344 merged at `d99ab97ac44d999c34caa6cd803042431d30da26`.
- P8A exact Production resolution completed at 3,351 seen / 3,327 resolved / 24 unresolved / 0 ambiguous; current 2026-09-18 snapshot remains 3,327 resolved / 24 unresolved.
- Initial Finance materialization produced 2,417 `provider_import / confirmed` KOL cost rows. Current snapshot remains exactly **2,417 provider rows + 1 pre-existing confirmed_manual row**, across **2,418 distinct Orders**, with **0 duplicate KOL Orders**.
- Source commission for the 2,417 provider rows reconstructed to USD 56,113.30 at activation; cross-currency rows preserve frozen Accounting FX evidence.
- `ERP_ACCOUNTING_CONTINUOUS_DELTA_KOL_COMMISSION_ENABLED=true` remains persisted; `ERP_ACCOUNTING_KOL_COMMISSION_PRODUCTION_APPLY_ENABLED` remains unset. The existing Accounting scheduler remains the only scheduler.
- ERP Changelog intentionally remains unchanged because publication is manual-only.

## Closure decision
P8 KOL Commission is closed. Future AffiliateWP Referral changes are handled by the existing Accounting continuous-delta pipeline; no dedicated P8 scheduler or initial backfill remains.

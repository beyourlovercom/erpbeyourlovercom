# ERP-GATEWAY-ECONOMICS-V1-001

STATUS: OWNER_CONFIRMED / ACTIVE
EXECUTION_PROVIDER: GPT_WEB
VERIFICATION_TIER: HIGH_RISK
BASELINE: origin/main@eec2ac697d299499c73db92b491876177862d044
BRANCH: feature/erp-gateway-economics-v1-001
WORKTREE: /Users/a/AI/007/worktrees/erp-gateway-economics-v1-001
DEPENDENCIES: Payment Authority Gate V1; Stale Pending Reconciliation V1; Payment Observability V1 all MERGED
PARALLEL_SAFE: YES
FOUNDATION_CHANGE_REQUIRED: NO

## Goal

Build a read-only Gateway Economics evidence layer without pretending unavailable data exists.

V1 must answer, by payment gateway AND order currency:
- payment-order count;
- captured/paid evidence count;
- captured gross;
- refunds;
- actual gateway fee evidence coverage;
- actual observed gateway fees where supported;
- owner-rule 3.5% accounting fee estimate, clearly separated from actual fee;
- net after refund before fees;
- net after refund and actual fees only when actual fee coverage is complete.

No gateway winner/ranking is produced in V1.

## Audit evidence frozen 2026-09-24

Woo plugin code exposes order-level actual processor fee metadata:
- WooPayments: _wcpay_transaction_fee
- Checkout Plugins Stripe: _cpsw_stripe_fee
- Payment Plugins PayPal: _paypal_fee
- Airwallex: no equivalent canonical order-level processor-fee metadata found in the installed plugin.

Historical local Woo snapshot shows fee evidence is substantial. In the 2026-04-01+ currently processing/completed cohort:
- WooPayments: 861 paid orders; 861 distinct orders have fee evidence.
- Stripe Element: 88 paid orders; 88 distinct orders have fee evidence.
- PayPal wallet + card: 464 paid orders; 464 distinct orders have fee evidence.
- Airwallex fee evidence remains unavailable.

Fee metadata may have duplicate rows. Most duplicates contain the same value, but WooPayments has at least one historical order with conflicting distinct fee values. Therefore importer MUST NOT pick MAX/MIN arbitrarily.

Multi-currency is material: USD, EUR, GBP, CAD and AUD all occur. V1 MUST NOT sum money across currencies.

ERP currently materializes payment_fee using Owner rule provider_order_total × 3.5%. That is an accounting estimate, not gateway actual-fee evidence.

## P1.8.1 Actual fee evidence import

Add additive nullable fields to order_financials:
- actual_payment_fee DECIMAL(18,2) nullable
- actual_payment_fee_source VARCHAR(127) nullable
- actual_payment_fee_status VARCHAR(32) nullable

Woo normalizer reads only known payment-fee meta keys from Woo REST meta_data.

Supported mapping:
- payment_method starts with woocommerce_payments -> _wcpay_transaction_fee
- cpsw_stripe_element -> _cpsw_stripe_fee
- ppcp / ppcp_card -> _paypal_fee
- other gateways -> unavailable

Status contract:
- observed: supported key present; all numeric occurrences normalize to one identical monetary value
- missing: gateway supports actual-fee metadata but key is absent
- conflict: multiple distinct normalized numeric values exist
- invalid: key exists but contains unsupported/non-numeric fee value
- unavailable: installed gateway has no canonical order-level actual-fee field in V1
- null on legacy rows means not_imported_yet until refreshed/backfilled

For conflict/invalid/missing/unavailable:
- actual_payment_fee MUST remain null.
- actual_payment_fee_source may identify the known supported meta key for missing/conflict/invalid.
- never infer actual fee from 3.5%.

No historical Production backfill is authorized in this WP.

## P1.8.2 Economics preview

Add read-only command:
payments:economics-preview

Options:
- --json
- --as-of=<UTC timestamp>
- --days=<1..365>, default 30

Cohort basis:
- Woo customer orders by canonical order_date in window.
- "captured" means paid_at is present, regardless of later refund/hold state. This is economics evidence, not fulfillment authority.
- payment-order counts are NOT raw processor attempts.

Output rows are gateway × currency. Never cross-sum monetary amounts between currencies.

Per-row metrics:
- payment_orders
- captured_orders
- created_to_captured_rate (descriptive Woo-order conversion only; NOT authorization success rate)
- gross_evidence_orders
- captured_gross
- refund_evidence_orders
- refund_amount
- refund_amount_rate when gross > 0
- actual_fee_observed_orders
- actual_fee_evidence_gross
- actual_fee_total
- actual_fee_coverage = observed fee orders / captured orders
- actual_fee_effective_rate = actual_fee_total / gross of observed-fee orders
- accounting_fee_estimate_3_5pct = captured_gross × 3.5%, clearly labelled estimate
- net_after_refund_before_fee
- net_after_refund_and_actual_fee only when actual_fee_coverage is complete and captured gross/refund evidence is complete; otherwise null
- fee_evidence_status_counts

Coverage declaration:
- raw_provider_attempts: unavailable
- webhook_event_store: unavailable
- dispute_chargeback_cost: unavailable
- airwallex_actual_processor_fee: unavailable
- accounting_3_5pct_is_actual_gateway_fee: false
- cross_currency_amount_total: prohibited

No ranking, score, winner, or routing recommendation in V1.

## Safety / privacy

Output never includes:
- raw transaction id
- raw meta payload
- customer name/email/phone/address
- gateway credentials/secrets

Command/service performs zero DB writes.

## Explicit prohibitions

- No Production deploy.
- No Production migration execution.
- No historical Production backfill.
- No Woo mutation.
- No gateway config/routing changes.
- No refund/cancel/dispute action.
- No scheduler.
- No dashboard UI.
- No invented Airwallex fee.
- No cross-currency monetary total.
- No actual-fee fallback to 3.5%.

## Required tests

1. WooPayments fee metadata imports observed actual fee.
2. Stripe fee metadata imports observed actual fee.
3. PayPal wallet/card fee metadata imports observed actual fee.
4. Airwallex actual fee is unavailable/null.
5. duplicate identical fee meta values remain observed.
6. conflicting fee meta values => conflict/null.
7. invalid fee meta => invalid/null.
8. supported gateway missing key => missing/null.
9. existing inbound idempotency remains green.
10. gateway × currency rows remain separated.
11. captured uses paid_at even if current provider status later refunded/on-hold.
12. actual fee coverage and effective rate are correct.
13. owner 3.5% accounting estimate is separate from actual fee.
14. actual-fee-adjusted net is null for incomplete fee coverage.
15. complete fee coverage computes fee-adjusted net.
16. zero order cohort safe.
17. no raw transaction/meta/PII leakage.
18. preview performs zero DB writes.
19. output explicitly declares raw attempts/dispute/Airwallex fee coverage gaps.
20. P1.6/P1.7 regressions remain green.
21. fresh migration/schema test PASS.

## Delivery

Implement -> focused tests -> payment/Woo/Finance/Observability regression -> fresh migration -> Pint/diff check -> commit -> PR -> CI evidence -> merge gate.

Production remains unauthorized.

PROJECT POSITION
PROGRAM_ROADMAP: BYL Payment Reliability lane
CURRENT_TRACK_PHASE_MAP: P1.7 MERGED -> P1.8 Gateway Economics CURRENT -> P1.9 Payment Reliability Closure WAITING
CURRENT_PHASE: P1.8 Gateway Economics
CURRENT_WORK_PACKAGE: ERP-GATEWAY-ECONOMICS-V1-001
PACKAGE_STATUS: OWNER_CONFIRMED / ACTIVE
WAITING_PHASES: P1.9
BASELINE: origin/main@eec2ac697d299499c73db92b491876177862d044
GLOBAL_GATE: Local/Dev implementation authorized; Production unauthorized
PRODUCTION_DEPLOYMENT_STATUS: NO
EVIDENCE_AS_OF: 2026-09-24

ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: planning 3.0h; range 1.8-4.5h
ESTIMATE_CONFIDENCE: medium-high
ESTIMATE_METHOD: additive fee-evidence schema + Woo normalizer import + read-only economics preview + regression
ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE
MIGRATION: AUTHORING/LOCAL TEST INCLUDED; PRODUCTION EXECUTION EXCLUDED
DEPLOYMENT: EXCLUDED
OWNER_REVIEW: EXCLUDED

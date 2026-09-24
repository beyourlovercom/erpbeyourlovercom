# ERP-PAYMENT-RELIABILITY-CLOSURE-V1-001

STATUS: OWNER_CONFIRMED / EXECUTE_THROUGH_UAT
EXECUTION_PROVIDER: GPT_WEB
VERIFICATION_TIER: HIGH_RISK
BASELINE: origin/main@34ace327d934cb72f2ea9e6e1dd47d00a67535eb
BRANCH: feature/erp-payment-reliability-closure-v1-001
WORKTREE: /Users/a/AI/007/worktrees/erp-payment-reliability-closure-v1-001
DEPENDENCIES: P1.6.1 Payment Authority Gate; P1.6.2 Stale Pending Reconciliation; P1.7 Payment Observability; P1.8 Gateway Economics — all MERGED
PARALLEL_SAFE: YES
FOUNDATION_CHANGE_REQUIRED: NO
OWNER_AUTHORIZATION: continuous execution through UAT; Production remains unauthorized

## Goal

Close Payment Reliability P1 into one canonical, read-only closure surface and one frozen gateway ownership / Checkout handoff contract.

P1.9 does NOT change payment routing. It records and validates the result of P1.0-P1.8.

## Closure command

Add:
payments:reliability-closure

Options:
- --json
- --as-of=<UTC timestamp>
- --health-days=<1..30>, default 1
- --economics-days=<1..365>, default 30
- --limit=<safe anomaly detail limit>

The command composes:
- PaymentHealthPreviewService
- GatewayEconomicsPreviewService

No parallel alternative calculations are allowed.

## Final gateway ownership contract

This is operational ownership, not a ranking.

- primary_card:
  - owner: woocommerce_payments
  - normal_checkout: enabled
- apple_pay:
  - owner: woocommerce_payments
  - placement: cart_and_checkout_only
- google_pay:
  - owner: woocommerce_payments
  - placement: cart_and_checkout_only
- paypal_wallet:
  - owner: ppcp
  - normal_checkout: enabled
- klarna_bnpl:
  - owner: airwallex_klarna
  - normal_checkout: enabled
- local_payment_methods:
  - owner: woocommerce_payments
  - availability: capability_and_region_based
- card_hot_standby:
  - owner: cpsw_stripe_element
  - normal_checkout: hidden
  - purpose: controlled emergency fallback only
- paypal_card:
  - owner: ppcp_card
  - normal_checkout: hidden_candidate
- airwallex_card:
  - owner: airwallex_card
  - normal_checkout: hidden_candidate

Product page express payment remains disabled by Checkout Experience contract.
Cart / Checkout express payment remains capability-driven; do not hard-code device OS.

## Safety capabilities to declare

- Payment Authority Gate: implemented
- Stale Pending classifier / preview: implemented, read-only
- Payment Observability: implemented, read-only
- Gateway Economics: implemented, read-only
- actual gateway fee evidence:
  - WooPayments supported
  - Stripe supported
  - PayPal supported
  - Airwallex unavailable
- raw provider authorization-attempt stream: unavailable
- canonical webhook event store: unavailable
- dispute/chargeback cost evidence: unavailable

These gaps are explicit. They are not silently replaced by Woo order counts or the 3.5% accounting estimate.

## Closure runtime state

Derived from current read-only evidence:
- blocked:
  - fulfillment_payment_mismatch > 0; OR
  - duplicate transaction identity > 0; OR
  - Woo sync state failed/stale/missing; OR
  - either health/economics scan is truncated.
- review:
  - no blocked condition, but PaymentHealth state=warning or Woo sync partial.
- ready:
  - none of the above.

This runtime state is NOT a Production deploy authorization and NOT an election/ranking of gateways.

## Rollout gates

Expose:
- payment_authority_safety
- woo_inbound_sync
- stale_pending_review
- economics_evidence
- provider_telemetry_coverage
- production_schema
- production_backfill
- checkout_routing_change

production_schema:
- pending_external until explicit Production migration gate executes the additive migrations already merged from P1.6.1 and P1.8.

production_backfill:
- not_authorized / separate future gate.
- historical transaction id / actual fee refresh or backfill is not part of P1.9.

checkout_routing_change:
- contract_frozen / not_applied.
- Checkout Experience workstream consumes this contract separately.

## Checkout handoff contract

The closure output must expose:
- canonical gateway ownership matrix above;
- payment safety services are ERP-side source-of-truth gates;
- Woo remains payment capture/status surface;
- ERP fulfillment must never infer paid from fulfillment status;
- Checkout must not expose multiple normal card entry points;
- Stripe remains installed/available as hot standby; no auto retry after issuer decline;
- PayPal Card and Airwallex Card are not normal primary card UI;
- Apple Pay / Google Pay are express paths on Cart/Checkout, not Product page;
- all provider/capability decisions remain device/browser/provider-capability based.

## Production prerequisites

Static checklist only; P1.9 does not perform them:
1. backup + explicit Production schema gate for merged additive payment migrations;
2. Production code deploy gate;
3. read-only post-deploy health/closure preview;
4. separately approved historical evidence refresh/backfill if desired;
5. Checkout routing/config changes only under Checkout Experience Production gate;
6. no gateway removal until checkout regression + payment UAT are complete.

## Privacy / safety

Output must not expose:
- raw transaction id
- raw fee meta key/value payload
- customer name/email/phone/address
- gateway credentials
- provider secrets
- raw webhook payload

The service/command performs zero DB writes.

## Required tests

1. gateway ownership matrix exactly matches frozen contract.
2. closure composes health + economics using same as-of.
3. health critical => closure blocked.
4. sync stale/missing/failed => blocked.
5. scan truncation => blocked.
6. health warning/partial sync => review.
7. healthy evidence without truncation => ready.
8. known telemetry gaps do not become fabricated values.
9. actual fee coverage remains gateway × currency and no cross-currency total is created.
10. production schema/backfill/routing gates remain pending/not-applied, never implied complete.
11. JSON emits no transaction id, raw fee meta, or PII.
12. preview performs zero DB writes.
13. P1.6.1 / P1.6.2 / P1.7 / P1.8 regressions remain green.
14. command registration verified.
15. no schema/migration in P1.9.
16. no Production deploy/write/mutation.

## Delivery / UAT

Owner authorized continuous execution through UAT:
IMPLEMENT -> TEST -> COMMIT -> PUSH -> PR -> CI EVIDENCE -> MERGE WHEN SAFE/APPROVED BY THIS CONTINUOUS AUTHORIZATION -> DEV/OWNER UAT PREP

CI runner scheduling failure with runner_id=0 / steps=[] may use the already established infrastructure-exception pattern if local full validation and exact-head evidence are complete.

Stop at Owner UAT. Production is explicitly excluded.

PROJECT POSITION
PROGRAM_ROADMAP: BYL Payment Reliability lane
CURRENT_TRACK_PHASE_MAP: P1.6.1 MERGED -> P1.6.2 MERGED -> P1.7 MERGED -> P1.8 MERGED -> P1.9 Payment Reliability Closure CURRENT
CURRENT_PHASE: P1.9 Payment Reliability Closure
CURRENT_WORK_PACKAGE: ERP-PAYMENT-RELIABILITY-CLOSURE-V1-001
PACKAGE_STATUS: OWNER_CONFIRMED / EXECUTE_THROUGH_UAT
BASELINE: origin/main@34ace327d934cb72f2ea9e6e1dd47d00a67535eb
GLOBAL_GATE: continuous execution through UAT authorized; Production unauthorized
PRODUCTION_DEPLOYMENT_STATUS: NO
EVIDENCE_AS_OF: 2026-09-24

ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: planning 2.0h; range 1.2-3.2h
ESTIMATE_CONFIDENCE: high
ESTIMATE_METHOD: composition layer + frozen contract + read-only command + regression/UAT
ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE
MIGRATION: EXCLUDED
DEPLOYMENT: EXCLUDED
OWNER_REVIEW: UAT STOP

# ERP-PAYMENT-OBSERVABILITY-V1-001

STATUS: OWNER_CONFIRMED / ACTIVE
EXECUTION_PROVIDER: GPT_WEB
VERIFICATION_TIER: HIGH_RISK
BASELINE: origin/main@049172b46b71f5a235692e48c8512c6e78c32dbe
BRANCH: feature/erp-payment-observability-v1-001
WORKTREE: /Users/a/AI/007/worktrees/erp-payment-observability-v1-001
DEPENDENCIES: ERP-PAYMENT-AUTHORITY-GATE-V1-001 MERGED; ERP-STALE-PENDING-RECONCILIATION-V1-001 MERGED
PARALLEL_SAFE: YES, dedicated worktree
FOUNDATION_CHANGE_REQUIRED: NO

## Goal

Create a lightweight, read-only Payment Health Preview that lets operators answer:

> Is payment processing or payment-to-ERP state materially abnormal right now?

This is not a large dashboard and not a gateway ranking system.

## Evidence boundary

Canonical V1 evidence available in ERP:
- Woo customer order identity and order_date;
- order_financials.payment_method;
- order_financials.provider_order_status;
- order_financials.paid_at;
- order_financials.payment_transaction_id;
- order_financials.provider_modified_at;
- PaymentAuthorityService;
- StalePaymentReconciliationPreviewService;
- ERP order fulfillment state;
- Woo automatic inbound SyncRun / SyncRunItem evidence.

Not available canonically in ERP V1:
- provider SDK/payment-intent attempt stream;
- card-network authorization attempt log;
- provider webhook event store.

Therefore V1 MUST NOT label Woo order counts as raw payment attempts. Coverage gaps must be explicit in output.
## Metrics contract

### Window cohort metrics

For Woo customer orders whose canonical order_date falls within the requested day window:
- payment_orders;
- paid_orders: PaymentAuthorityState=paid;
- hard_failed_orders: provider status=failed;
- pending_orders: provider status=pending;
- hold_orders: provider status=on-hold;
- cancelled_orders;
- refunded_orders;
- unconfirmed_orders;
- current_paid_rate = paid_orders / payment_orders, descriptive only.

Break down by payment_method.

### Current backlog/review metrics

Reuse stale-payment canonical classification:
- stale_unpaid;
- recent_pending;
- pending_evidence_conflict;
- hold_review;
- missing_timestamp_review.

### Safety anomalies

1. fulfillment_payment_mismatch
- Woo customer order has material fulfillment progression: ERP status shipped/completed/completed_delivered, OR a FulfillmentAllocation, OR a Shipment, OR an active inventory reservation (reserved/committed);
- but current PaymentAuthorityState is not paid.
- This is CRITICAL.

2. duplicate_transaction_identity
- duplicate non-null payment_method + payment_transaction_id groups.
- This is CRITICAL.
- New schema should normally force zero, but observability keeps the invariant visible.

3. payment_evidence_gap
- Woo payment order has a nominal paid operational status (processing/completed) but PaymentAuthorityState is unconfirmed.
- This is WARNING.

### Woo inbound sync health

Use run_type=woocommerce_order_incremental, provider_key=woocommerce.

Expose:
- latest run id/status;
- started_at/completed_at;
- age_hours;
- failed_items/conflict_items;
- sync state: healthy / partial / failed / stale / missing.

Default stale threshold: 2.5 hours because the canonical scheduler runs hourly.

No raw SyncRun error message or secret-bearing payload is exposed.
## Overall health state

- critical:
  - fulfillment_payment_mismatch > 0; OR
  - duplicate_transaction_identity > 0; OR
  - Woo inbound sync is failed/stale/missing.
- warning:
  - no critical condition, but any pending_evidence_conflict, hold_review, missing_timestamp_review, payment_evidence_gap, or sync partial exists.
- ok:
  - none of the above.

stale_unpaid and hard_failed counts are operational/customer-payment signals and do not by themselves make overall health critical. They remain visible for monitoring.

## Read-only command

Add:
payments:health-preview

Options:
- --json
- --as-of=<UTC timestamp>
- --days=<1..30>, default 1
- --limit=<bounded anomaly detail count>

Human output should show:
- overall health;
- cohort totals;
- gateway breakdown;
- current backlog/review counts;
- sync health;
- anomaly details.

JSON output must expose only operator-safe identifiers/metadata:
- ERP order id/order number;
- Woo provider reference;
- gateway;
- provider status;
- payment authority;
- ERP fulfillment status;
- booleans for evidence;
- no customer PII;
- no raw transaction id;
- no gateway secret;
- no raw provider payload.

## Explicit coverage declaration

JSON/human output must include:
- raw_provider_attempts: unavailable
- webhook_event_store: unavailable
- payment_orders_are_not_raw_attempts: true

This prevents future operators from interpreting created Woo orders as true processor attempts.
## Explicit prohibitions

- No Production deploy.
- No Production write.
- No Woo mutation.
- No payment gateway mutation/routing change.
- No cancellation/refund.
- No new scheduler in V1.
- No schema/migration in V1.
- No dashboard UI in V1.
- No customer PII or raw transaction identity in output.
- No provider performance ranking/winner label.
- No automatic alert delivery yet.

## Required tests

1. paid Woo order contributes to cohort paid count.
2. failed/pending/on-hold/cancelled/refunded map to correct descriptive cohort metrics.
3. per-gateway breakdown is correct.
4. current_paid_rate is descriptive and handles zero safely.
5. stale preview classifications are incorporated without mutation.
6. shipped/progressed Woo order with non-paid authority => critical fulfillment_payment_mismatch.
7. pending_shipment unpaid Woo order is not fulfillment mismatch.
8. nominal processing/completed with missing paid_at => payment_evidence_gap.
9. duplicate non-null transaction identity is detected by read query/fixture-compatible test path without weakening schema.
10. latest completed Woo inbound sync within threshold => healthy.
11. partial sync => warning/partial.
12. failed/stale/missing sync => critical.
13. output declares provider-attempt/webhook coverage gaps.
14. JSON emits no raw transaction id or PII.
15. preview performs zero database writes.
16. P1.6.1 and P1.6.2 regressions remain green.

## Delivery

Implement -> focused tests -> P1.6.1/P1.6.2 + Woo inbound regression -> Pint/diff check -> commit -> push -> Draft PR -> CI evidence -> merge gate.

No Production action is included.

## Implementation / Verification Evidence — 2026-09-24

- Implementation head before closure evidence: 943c54fbc041ed0e2233f357089cadd1c2551ceb.
- Added read-only PaymentHealthPreviewService and payments:health-preview command.
- Added descriptive Woo payment-order cohort metrics by gateway. Output explicitly states Woo payment orders are not raw provider attempts.
- Added current stale-payment backlog integration from P1.6.2.
- Added critical fulfillment/payment mismatch detection across shipped/completed state, FulfillmentAllocation, Shipment, and active inventory reservation materialization.
- Added payment-evidence-gap visibility for processing/completed Woo states missing paid_at.
- Added duplicate non-null payment transaction identity invariant visibility without exposing raw transaction ids.
- Added Woo automatic inbound sync health: healthy / partial / failed / stale / missing with a 2.5-hour default stale threshold.
- Overall health is ok / warning / critical only; hard-failed and stale-unpaid counts remain descriptive signals and do not by themselves create a critical state.
- No schema, scheduler, dashboard UI, alert delivery, Woo mutation, payment routing change, Production write or deploy was added.

Validation:
- P1.7 focused suite: 14 tests / 57 assertions PASS.
- P1.7 + P1.6.1 + P1.6.2 + Woo inbound/sync + finance + SyncRun regression: 58 tests / 281 assertions PASS.
- Orders regression excluding inherited OrderSavedViews baseline failure: 29 tests / 96 assertions PASS.
- payments:health-preview command registration verified.
- JSON privacy test proves no raw transaction id, recipient name, phone, or email is emitted.
- Read-only test proves preview performs zero database writes.
- Duplicate-transaction detection positive path tested using a test-database-only temporary index drop; Production migration remains unchanged.
- New/owned files Pint PASS.
- OrdersServiceProvider Pint issue reproduces on origin/main and is inherited formatting debt; no broad unrelated formatting rewrite was applied.
- git diff --check PASS.
- Production migration/write/deploy: NOT performed.

Coverage limitations intentionally remain explicit:
- raw_provider_attempts = unavailable.
- webhook_event_store = unavailable.
- payment_orders_are_not_raw_attempts = true.

Residual risk:
- V1 cannot diagnose provider-level authorization attempts or webhook delivery failures because those event streams are not canonically stored in ERP.
- V1 is read-only; alert delivery and any provider telemetry ingestion are separate future packages.
- P1.8 Gateway Economics must not treat payment-order cohort counts as raw processor attempts.

PROJECT POSITION
PROGRAM_ROADMAP: BYL Payment Reliability lane
CURRENT_TRACK_PHASE_MAP: P1.6.1 MERGED -> P1.6.2 MERGED -> P1.7 Payment Observability CURRENT -> P1.8 Gateway Economics WAITING -> P1.9 Payment Reliability Closure WAITING
COMPLETED_PHASES: P1.0-P1.6.2
CURRENT_PHASE: P1.7 Payment Observability
CURRENT_WORK_PACKAGE: ERP-PAYMENT-OBSERVABILITY-V1-001
PACKAGE_STATUS: IMPLEMENTED / VERIFIED / CI_CHECK_PENDING
WAITING_PHASES: PR #420 merge gate; P1.8; P1.9
BASELINE: origin/main@049172b46b71f5a235692e48c8512c6e78c32dbe
GITHUB_STATE: PR #420 open; implementation head 943c54fbc041ed0e2233f357089cadd1c2551ceb
GLOBAL_GATE: LOCAL VERIFICATION PASS; Production unauthorized
PRODUCTION_DEPLOYMENT_STATUS: NO
NEXT_DEPENDENCY: PR #420 merge decision; P1.8 waits for merge
EVIDENCE_AS_OF: 2026-09-24

ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: planning 2.2h; range 1.2-3.5h
ARCHITECTURE_GATE_REMAINING: 0h
ESTIMATE_CONFIDENCE: medium-high
ESTIMATE_AS_OF: 2026-09-24
ESTIMATE_METHOD: read-only metrics service + sync health + bounded anomaly command + focused regressions
ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE — no accepted repository Work Log records productive actual hours; chat/tool elapsed time is not used as hours evidence
ACTUAL_HOURS_BREAKDOWN: UNKNOWN WITH EVIDENCE
VARIANCE: UNKNOWN WITH EVIDENCE — actual hours unavailable
VARIANCE_REASON: no accepted actual-hours ledger
MIGRATION: EXCLUDED
DEPLOYMENT: EXCLUDED
OWNER_REVIEW: EXCLUDED

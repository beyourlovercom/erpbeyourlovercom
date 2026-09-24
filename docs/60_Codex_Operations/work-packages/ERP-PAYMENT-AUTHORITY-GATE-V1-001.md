# ERP-PAYMENT-AUTHORITY-GATE-V1-001

STATUS: OWNER_CONFIRMED / ACTIVE
EXECUTION_PROVIDER: GPT_WEB
VERIFICATION_TIER: HIGH_RISK
BASELINE: origin/main@d35e01fb80845ad426dac9757a087e049fb9f96d
BRANCH: feature/erp-payment-authority-gate-v1-001
WORKTREE: /Users/a/AI/007/worktrees/erp-payment-authority-gate-v1-001
DEPENDENCIES: NONE
DEPENDENCY_TYPE: NONE
DEPENDENCY_STATUS: N/A
PARALLEL_SAFE: YES, dedicated worktree
FOUNDATION_CHANGE_REQUIRED: NO
PROVIDER_HANDOFF: US_CODEX_CLI attempted 2026-09-23 but exited before repository mutation due usage limit; serialized handoff to GPT_WEB + Remote Desktop Commander; worktree remained clean.

## Problem

WooCommerce inbound imports status=any. Current normalization maps processing, pending and on-hold into ERP pending_shipment, while fulfillment paths trust pending_shipment without a canonical payment-authority gate.

Audit evidence shows most stale pending/on-hold orders have neither date_paid nor transaction identity, while a paid PayPal order can later become on-hold due to dispute. Therefore paid_at alone is insufficient.

Safety invariant: a WooCommerce customer order must not allocate inventory, materialize fulfillment allocation, create/change shipment draft, or mark shipped unless current imported payment evidence authorizes fulfillment.

## Frozen architecture

1. Keep payment and fulfillment as separate axes. Do not redesign OrderStatus.
2. Add nullable payment_transaction_id to order_financials, populated from Woo REST transaction_id.
3. Add nullable-safe composite unique constraint on payment_method + payment_transaction_id. Existing rows are not backfilled.
4. Create one canonical payment-authority derivation with states:
   - not_required: non-Woo customer order
   - unconfirmed: Woo order lacks sufficient imported evidence
   - pending: Woo pending
   - hold: Woo on-hold, even if paid_at exists
   - failed: Woo failed
   - cancelled: Woo cancelled/trash
   - refunded: Woo refunded
   - paid: status is not blocked and paid_at exists
5. Blocked statuses take precedence over paid_at.
6. Free/zero-total Woo orders pass only when Woo has non-blocked paid/completed operational evidence and paid_at exists. No missing-evidence bypass.
7. Create one canonical FulfillmentPaymentGate in Logistics application scope. Woo customer orders require paid; non-Woo/manual/KOL/historical remain not_required.
8. Apply the same gate to all material fulfillment mutation paths, including automatic allocation, system/manual fulfillment submission, assisted recommendation adoption, shipment draft save/change, and mark-shipped. Any discovered shared ShipmentService bypass path must also be protected.
9. Do not duplicate payment logic across controllers/services.
10. Read-only list redesign is out of scope. P1.6.2 owns stale pending cleanup/list semantics.

## Prohibitions

- No Production deploy.
- No Production migration execution.
- No Production data write/backfill.
- No Woo write/sync mutation.
- No payment gateway configuration changes.
- No checkout UI work.
- No stale pending cleanup.
- No historical transaction backfill.
- No broad OrderStatus redesign.
- No unrelated refactor.

## Required tests

1. Woo pending without paid evidence blocks auto allocation.
2. Woo on-hold with paid_at blocks fulfillment.
3. Woo failed/cancelled/refunded blocks fulfillment.
4. Woo processing with paid_at allows fulfillment.
5. Woo completed with paid_at allows fulfillment when otherwise eligible.
6. Woo order missing financial evidence blocks fulfillment.
7. Non-Woo/manual/KOL order remains allowed.
8. Woo inbound persists payment_transaction_id.
9. Re-import same Woo order remains idempotent.
10. Duplicate non-null payment_method + payment_transaction_id cannot create two financial identities.
11. Direct shared shipment mutation cannot bypass the gate.
12. Existing fulfillment regression tests remain green.

## Verification

US Mac exact worktree only:
- fresh migration/schema test
- focused PHPUnit for Woo inbound, payment authority, automatic/manual fulfillment
- relevant Orders/Finance/Logistics regression subset
- repository PHP checks
- git diff --check
- clean tracked merge-candidate worktree

Migration authoring/testing only; never run against Production in this package.

## Delivery

Owner-confirmed continuous delivery applies:
IMPLEMENT -> TEST -> COMMIT -> PUSH -> PR -> REQUIRED CHECKS -> MERGE WHEN GREEN

This is backend/data-safety scope. No pre-development visual gate is required. If implementation materially changes an Owner-visible workflow beyond a bounded payment-block message, stop for Owner UAT.

Production release remains unauthorized.

## Implementation / Verification Evidence — 2026-09-24

- Provider handoff: US Codex CLI exited before repository mutation because its usage limit was exhausted; GPT Web + Remote Desktop Commander resumed the same dedicated worktree serially. No concurrent implementation provider existed.
- Implementation head: b15db3977e414f1334291a3fabde08001ad3dcc2.
- Added canonical PaymentAuthorityState / PaymentAuthorityService and one Logistics FulfillmentPaymentGate.
- Woo inbound now persists nullable payment_transaction_id; order_financials adds a nullable-safe composite unique index on payment_method + payment_transaction_id.
- Blocked payment states take precedence over historical paid_at, so paid-then-disputed/on-hold Woo orders remain blocked.
- The shared gate protects automatic allocation, recommended/manual fulfillment allocation, assisted reservation/draft flows, shared Shipment draft mutation, and mark-shipped. Non-Woo/manual/KOL/historical behavior remains not_required.
- Overseas warehouse fixtures that intentionally represented orders as Woo orders were updated to include paid Woo evidence; the gate was not weakened.

Local verification:
- Focused payment/fulfillment: 29 tests / 177 assertions PASS.
- Overseas warehouse regression: 31 tests / 320 assertions PASS.
- Broad affected regression: 266 tests / 5005 assertions PASS, 1 skipped.
- Temporary SQLite migrate:fresh PASS; payment_transaction_id and order_financials_payment_transaction_unique verified present.
- New/owned files Pint PASS. Four modified legacy files already fail Pint on origin/main; no unrelated formatting rewrite was introduced.
- git diff --check PASS.
- Production migration/data write/deploy NOT performed.

GitHub CI:
- ERP Production Stability V1 CI run 35941892159 on implementation head b15db3977e414f1334291a3fabde08001ad3dcc2 ended before runner assignment.
- sqlite-regression: runner_id=0, steps=[].
- mysql8-runtime: runner_id=0, steps=[].
- Both jobs ended within roughly two seconds without executing test steps; classify as CI infrastructure/scheduling failure, not code-test failure.
- No usable job log existed because no runner step executed.

Residual risk:
- No historical transaction-id backfill was executed; legacy financial rows remain nullable until refreshed or separately reconciled.
- Production migration is a separate explicit gate.
- P1.6.2 owns stale pending lifecycle cleanup.

PROJECT POSITION
PROGRAM_ROADMAP: BYL ERP canonical roadmap plus bounded Payment Reliability safety lane; this package does not replace other program tracks.
CURRENT_TRACK_PHASE_MAP: P1.6 Runtime Reliability Audit COMPLETE -> P1.6.1 Payment Authority Gate V1 CURRENT -> P1.6.2 Stale Pending Reconciliation WAITING -> P1.7 Payment Observability WAITING
COMPLETED_PHASES: P1.0-P1.6 audit/evidence phases
CURRENT_PHASE: P1.6.1 Payment Authority Gate V1
CURRENT_WORK_PACKAGE: ERP-PAYMENT-AUTHORITY-GATE-V1-001
PACKAGE_STATUS: IMPLEMENTED / VERIFIED / CI_INFRASTRUCTURE_EXCEPTION_EVIDENCED
WAITING_PHASES: PR #415 delivery/merge gate; P1.6.2; P1.7
BASELINE: origin/main@d35e01fb80845ad426dac9757a087e049fb9f96d
GITHUB_STATE: PR #415 open; implementation head b15db3977e414f1334291a3fabde08001ad3dcc2; Actions run 35941892159 failed before runner assignment
SOURCE_CONFLICTS: AI/CURRENT_CONTEXT.md is stale relative to current main and current Owner-confirmed work; GitHub main plus this package govern this bounded scope.
GLOBAL_GATE: LOCAL VERIFICATION PASS; GitHub CI infrastructure exception evidenced; Production unauthorized
PRODUCTION_DEPLOYMENT_STATUS: NO; explicitly excluded
NEXT_DEPENDENCY: PR #415 delivery/merge decision under evidenced CI infrastructure exception; P1.6.2 waits for merge
EVIDENCE_AS_OF: 2026-09-24

ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: planning 2.5h; range 1.5-4.0h
KNOWN_CURRENT_TRACK_REMAINING: P1.6.2 and P1.7 not yet hour-frozen
ARCHITECTURE_GATE_REMAINING: 0h for this frozen package
MVP1_TOTAL_ESTIMATE: historical value not recomputed here
MVP1_PLANNING_BASELINE: historical value not recomputed here
MVP1_MAXIMUM_ENVELOPE: historical value not recomputed here
MVP1_REMAINING: UNKNOWN WITH EVIDENCE; current package is outside stale historical arithmetic
POST_MVP_KNOWN_INCREMENT: UNKNOWN WITH EVIDENCE
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE; no accepted Work Log supplied
ESTIMATE_CONFIDENCE: medium
ESTIMATE_AS_OF: 2026-09-23
ESTIMATE_METHOD: bounded additive schema + central authority + fulfillment wiring + focused regressions

ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 0.3h planning; range 0.2-0.5h
CORE_EXECUTION: 1.2h planning; range 0.7-2.0h
TEST_AND_VALIDATION: 0.7h planning; range 0.4-1.0h
REMEDIATION_BUFFER: 0.3h planning; range 0.2-0.5h
ESTIMATED_EFFECTIVE_HOURS: 2.5h
ESTIMATE_RANGE: 1.5-4.0h
ESTIMATE_BASIS: frozen additive schema + central authority service + bounded fulfillment wiring + focused regressions
ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE — no accepted repository Work Log records productive actual hours for this package; chat/tool elapsed time is not used as hours evidence
ACTUAL_HOURS_BREAKDOWN: UNKNOWN WITH EVIDENCE — no accepted component-level Work Log exists
VARIANCE: UNKNOWN WITH EVIDENCE — actual effective hours are unavailable, so variance cannot be calculated safely
VARIANCE_REASON: accepted actual-hours evidence is unavailable; no value is fabricated from PR/chat/tool timestamps
OWNER_REVIEW: EXCLUDED
MERGE: INCLUDED
MIGRATION: INCLUDED
MIGRATION_FILE_AUTHORING: INCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: EXCLUDED unless implementation materially changes Owner-visible workflow
DEPLOYMENT: EXCLUDED

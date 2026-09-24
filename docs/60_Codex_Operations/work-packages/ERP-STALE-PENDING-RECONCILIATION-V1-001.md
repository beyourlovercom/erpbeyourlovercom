# ERP-STALE-PENDING-RECONCILIATION-V1-001

STATUS: OWNER_CONFIRMED / ACTIVE
EXECUTION_PROVIDER: GPT_WEB
VERIFICATION_TIER: HIGH_RISK
BASELINE: origin/main@8a1d276a33b686a9011ecee080cbe46c0f3c689d
BRANCH: feature/erp-stale-pending-reconciliation-v1-001
WORKTREE: /Users/a/AI/007/worktrees/erp-stale-pending-reconciliation-v1-001
DEPENDENCIES: ERP-PAYMENT-AUTHORITY-GATE-V1-001 MERGED
PARALLEL_SAFE: YES, dedicated worktree
FOUNDATION_CHANGE_REQUIRED: NO

## Problem

Woo order inbound intentionally imports status=any. Payment Authority Gate V1 now prevents unpaid/disputed Woo orders from entering fulfillment mutation, but stale payment orders remain visible and accumulate indefinitely unless they are classified and reconciled.

Historical Woo snapshot evidence for 2026-04-01 through 2026-08-28:
- 76 pending/on-hold orders total.
- 75 had neither date_paid nor transaction id.
- PayPal wallet: 34 pending + 1 on-hold.
- Airwallex Klarna: 21 pending.
- Airwallex Card: 9 pending.
- Stripe Element: 9 pending.
- one gateway-empty pending.
- Woo hold-stock setting is 60 minutes, yet many pending records are older than 30 days.

One PayPal on-hold record had paid evidence and was later put on hold after dispute. Therefore on-hold must never be equated with abandoned payment.

## Frozen V1 scope

This package implements read-only stale-payment classification and preview only.

No Woo order status mutation, no ERP business-state mutation, no batch cancellation and no Production data write are authorized.
## Canonical evidence

Classification uses the already-imported canonical Woo financial evidence:
- order source/kind;
- provider_order_status;
- payment_method;
- payment_transaction_id;
- paid_at;
- provider_modified_at.

The state age anchor is provider_modified_at. If provider_modified_at is missing, the record is review-only; do not infer age from ERP updated_at or fabricate a provider timestamp.

## Classification states

Only Woo customer orders are candidates.

1. recent_pending
- provider status pending;
- no paid_at;
- no transaction identity;
- age below configured gateway threshold.

2. stale_unpaid
- provider status pending;
- no paid_at;
- no transaction identity;
- age at or above configured gateway threshold.
- eligible for future live revalidation, not for mutation in this WP.

3. pending_evidence_conflict
- provider status pending;
- paid_at or transaction identity exists.
- always manual review; never auto-cancel.

4. hold_review
- provider status on-hold.
- always manual review regardless of age or evidence.
- include whether paid evidence exists.

5. missing_timestamp_review
- pending/on-hold candidate with no provider_modified_at.
- always manual review.

6. not_candidate
- non-Woo, non-customer, or provider status outside pending/on-hold.

## Thresholds

Configurable defaults:
- ppcp: 24h
- cpsw_stripe_element: 24h
- airwallex_card: 24h
- airwallex_klarna: 72h
- default/unknown: 24h

Thresholds are classification policy only. They do not trigger writes.

## Output contract

Add a read-only console command:
orders:preview-stale-payments

Options:
- --json
- --as-of=<UTC timestamp> for deterministic evidence/tests
- --limit=<n> bounded detail output

Safe output:
- aggregate counts by classification and payment method;
- candidate ERP order id / ERP order number;
- Woo provider reference;
- payment method;
- provider status;
- provider_modified_at;
- age hours;
- evidence booleans only (has_paid_at, has_transaction_id).

Never output customer PII, gateway secrets, raw transaction id, card/payment payloads or provider credentials.
## Explicit prohibitions

- No Production deploy.
- No Production migration.
- No Production data write.
- No Woo write.
- No cancel/refund/status change.
- No new scheduler in V1.
- No payment gateway configuration change.
- No attempt to resolve PayPal disputes automatically.
- No deletion of historical pending orders.
- No frontend redesign.

## Required tests

1. fresh pending <24h => recent_pending for default/card/PayPal.
2. pending >=24h without evidence => stale_unpaid for default/card/PayPal.
3. Klarna 48h pending => recent_pending.
4. Klarna >=72h pending => stale_unpaid.
5. pending with paid_at => pending_evidence_conflict.
6. pending with transaction identity => pending_evidence_conflict.
7. on-hold + paid evidence => hold_review.
8. on-hold without paid evidence => hold_review.
9. missing provider_modified_at => missing_timestamp_review.
10. non-Woo/manual/KOL/non-customer => not_candidate/excluded.
11. JSON command output contains no raw transaction id or PII.
12. deterministic --as-of classification.
13. command performs zero database writes.
14. Payment Authority Gate regressions remain green.

## Delivery

Implement -> focused tests -> affected Orders/Finance/Logistics regression -> diff check -> commit -> push -> Draft PR.

No Production action is included.

PROJECT POSITION
PROGRAM_ROADMAP: BYL Payment Reliability safety lane within BYL ERP
CURRENT_TRACK_PHASE_MAP: P1.6 Audit COMPLETE -> P1.6.1 Payment Authority Gate MERGED -> P1.6.2 Stale Pending Reconciliation CURRENT -> P1.7 Payment Observability WAITING
COMPLETED_PHASES: P1.0-P1.6; P1.6.1
CURRENT_PHASE: P1.6.2 Stale Pending Reconciliation
CURRENT_WORK_PACKAGE: ERP-STALE-PENDING-RECONCILIATION-V1-001
PACKAGE_STATUS: OWNER_CONFIRMED / ACTIVE
WAITING_PHASES: P1.7
BASELINE: origin/main@8a1d276a33b686a9011ecee080cbe46c0f3c689d
GITHUB_STATE: dedicated branch/worktree; Draft PR pending
SOURCE_CONFLICTS: NONE
GLOBAL_GATE: read-only local/dev implementation authorized; all real-data mutation unauthorized
PRODUCTION_DEPLOYMENT_STATUS: NO
NEXT_DEPENDENCY: none
EVIDENCE_AS_OF: 2026-09-24
ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: planning 1.8h; range 1.0-3.0h
KNOWN_CURRENT_TRACK_REMAINING: P1.7 not yet hour-frozen
ARCHITECTURE_GATE_REMAINING: 0h
MVP1_TOTAL_ESTIMATE: historical value not recomputed here
MVP1_PLANNING_BASELINE: historical value not recomputed here
MVP1_MAXIMUM_ENVELOPE: historical value not recomputed here
MVP1_REMAINING: UNKNOWN WITH EVIDENCE
POST_MVP_KNOWN_INCREMENT: UNKNOWN WITH EVIDENCE
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE
ESTIMATE_CONFIDENCE: medium-high
ESTIMATE_AS_OF: 2026-09-24
ESTIMATE_METHOD: bounded read-only classifier + command + focused regressions

ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 0.2h planning; range 0.1-0.4h
CORE_EXECUTION: 0.8h planning; range 0.4-1.4h
TEST_AND_VALIDATION: 0.6h planning; range 0.3-0.8h
REMEDIATION_BUFFER: 0.2h planning; range 0.2-0.4h
ESTIMATED_EFFECTIVE_HOURS: 1.8h
ESTIMATE_RANGE: 1.0-3.0h
ESTIMATE_BASIS: no schema; one classifier service; one read-only command; focused tests
OWNER_REVIEW: EXCLUDED
MERGE: INCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED

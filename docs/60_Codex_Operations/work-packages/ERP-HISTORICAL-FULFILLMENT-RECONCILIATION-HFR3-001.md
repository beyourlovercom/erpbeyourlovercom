# ERP-HISTORICAL-FULFILLMENT-RECONCILIATION-HFR3-001

STATUS: ACTIVE / FORMAL CONTRACT FROZEN / PRODUCTION WRITE GATED
EXECUTION_PROVIDER: GPT_WEB
VERIFICATION_TIER: HIGH_RISK
OWNER: Harr
SYSTEM: BYL ERP / Logistics OS
BASELINE: origin/main@9d178eec0f2eac904e9a6dbfaa37d6ee5691ef83
BRANCH: feature/erp-historical-fulfillment-reconciliation-hfr3-001
WORKTREE: /Users/a/AI/007/worktrees/erp-historical-fulfillment-reconciliation-hfr3-001
DEPENDENCIES: HFR-0 PR #317, HFR-1 PR #319, HFR-2 PR #322 merged
DEPENDENCY_TYPE: MERGED_PR
DEPENDENCY_STATUS: SATISFIED
PARALLEL_SAFE: YES — HFR3 service/command/tests/docs only

## Purpose
Implement Customer Shipment Backfill V2 as the guarded mutation boundary for HFR normalized candidates. HFR3 must never replay the Tianyun 27-row V1 source and must not modify the existing CustomerHistoricalShipmentBackfillService.

## Frozen input contract
HFR3 accepts a canonical JSON document containing `source_scan_sha256` plus HFR normalized candidates. It re-runs `HistoricalFulfillmentReconciliationClassifier`; it never trusts caller-supplied ready labels.

## Mandatory apply rechecks
For every Class A candidate, preview and apply recheck exact Order identity, customer kind, shipped-compatible status, no conflicting Shipment, no ShipmentAttempt, no tracking collision in Shipment/ShipmentAttempt, active exact warehouse, non-empty carrier/service, and source-backed shipped_at. Apply repeats those checks under database locks.

## Mutation scope
Allowed on apply only:
- create canonical `shipments` rows for final Class A candidates;
- create HFR3 audit records.

Forbidden:
- Order mutation;
- ShipmentAttempt mutation;
- Woo mutation;
- Inventory quantity/reservation mutation;
- Accounting/Finance/P6 mutation;
- schema/UI/scheduler/changelog changes;
- Tianyun V1 replay.

## Production gate
Default is dry-run. Production `--apply` requires a process-local enable flag plus exact source SHA, plan SHA, ready/review/skip counts. `ready=0` must hard-block apply as a no-op gate. No Production apply is authorized by this WP.

## Verification outcome
Local implementation gates PASS. New HFR3 focused behavior, legacy Customer Historical Shipment Backfill V1, and Historical Import regression were validated with zero test failures after local asset build.

## Current boundary
CODE READY FOR GIT DELIVERY. Production remains read-only. The next runtime action after merge is a fresh Production dry-run against a newly generated normalized Woo candidate source. Stop before any Production apply and present the exact write gate.

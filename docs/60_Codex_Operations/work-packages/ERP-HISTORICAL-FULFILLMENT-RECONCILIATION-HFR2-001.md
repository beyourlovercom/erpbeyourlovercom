# ERP-HISTORICAL-FULFILLMENT-RECONCILIATION-HFR2-001

STATUS: COMPLETE / PRODUCTION READ-ONLY / HFR-3 READY
EXECUTION_PROVIDER: GPT_WEB
VERIFICATION_TIER: HIGH_RISK
OWNER: Harr
SYSTEM: BYL ERP / Logistics OS
BASELINE: origin/main@8bd822aaee84467efb97ca5a63ed2b9b3f186fd9
BRANCH: feature/erp-historical-fulfillment-reconciliation-hfr2-001
WORKTREE: /Users/a/AI/007/worktrees/erp-historical-fulfillment-reconciliation-hfr2-001
DEPENDENCIES: HFR-0 PR #317 and HFR-1 PR #319 merged
DEPENDENCY_TYPE: MERGED_PR
DEPENDENCY_STATUS: SATISFIED
PARALLEL_SAFE: YES — new HFR Woo adapter/tests/docs only

## Purpose
Implement the first HFR-2 provider adapter for the highest-yield exact evidence source proven by HFR-0: WooCommerce historical tracking metadata. The adapter converts Woo source facts into the HFR-1 normalized candidate contract without database/network/provider mutation and without changing core classifier semantics.

## Frozen source authority
Woo may supply exact provider Order ID, tracking number, provider slug, package line-item bindings and source ship date. Woo does NOT supply authoritative historical warehouse or service in the audited HFR-0 metadata population. Present-day routing policy, destination, SKU or carrier must never be used to invent those missing facts.

## Scope
- Add `WooHistoricalFulfillmentEvidenceAdapter` only as the first provider adapter.
- Preserve exact Woo Order identity and package tracking/provider/ship-date/line-item bindings.
- Map carrier slug only through explicit Carrier Master aliases; do not mark carrier/service authority complete when service is absent.
- Treat missing multi-package canonical target as Missing Evidence, not ambiguity.
- Treat a real multi-target ambiguity or duplicate tracking as fail-closed ambiguity.
- Never attach one existing Shipment to a different package of the same multi-package Order.
- Correct HFR-1 existing-Shipment agreement so `shipped_at` variance alone does not create class E; class A still requires source-backed shipped_at.

## Explicit prohibitions
- No Production data mutation, schema, UI, scheduler or changelog.
- No current routing-policy inference for historical warehouse/service.
- No Tianyun 27-row replay and no KOL-to-customer promotion.
- No Accounting/Finance/P6 changes.
- No Production deployment is required by this WP.

## Closure evidence
- Production read-only scan SHA: `133557bbd82e039b5a319b612667a1c9ecec2f2d74bf805decd788d70b9d0b3e`.
- Refined Order matrix: Ready 0 / Already Canonical 23 / Missing Evidence 5052 / Conflict 3 / Duplicate-Ambiguous 32 / Orphan 0.
- Candidate matrix: Ready 0 / Review 5328 / Skip 26.
- Focused verification: 24 tests / 74 assertions PASS; Pint PASS; diff-check required before delivery.
- Production mutation count: zero.

# ERP-KOL-M1.4E-COMMISSION-PAYOUT-WORKFLOW-001

Status: LIVE_UAT_PASS_DEPENDENCY_OPEN
Base: `main@264fe20b79b7f145f3b976d939ddce0a07c57d8d`
Branch: `feature/erp-kol-m1-4e-commission-payout-workflow-001`

## Objective

Build the ERP-side commission payout workflow on top of M1.4D Referral provider truth, without turning AffiliateWP into the ERP approval ledger.

## Frozen Entity Boundary

- `kol_affiliate_payouts`: read-only mirror of AffiliateWP payout provider facts.
- `kol_payout_requests`: ERP-owned payout approval/execution ledger.
- `kol_payout_request_items`: immutable Referral membership of each ERP payout request.
- Provider payout identity is `(connection_key, payout_id)`.
- ERP payout request identity is the local positive request ID sent as `erp_payout_request_id`.
- A Provider payout may historically have zero Referral rows; mirror it without inventing links.
- An ERP-created payout request must contain at least one eligible Referral.

## ERP Request Eligibility

Every selected Referral must be exact provider truth for the same connection, Affiliate, and currency, with:

- `reconciliation_status = present`
- `provider_status = unpaid`
- `payout_id = null`
- positive commission amount
- exact Affiliate → KOL binding already resolved

Referral rows are locked inside the create transaction. Existing active payout requests are checked while those rows are locked so two concurrent requests cannot claim the same Referral.

## Approval / Execution States

`pending_approval -> approved -> provider_processing -> paid`

Alternate terminal/retry states:

- `rejected`
- `provider_error`
- `readback_mismatch`

A rejected request never calls the Provider. Provider retries reuse the same persisted idempotency key and immutable payload fingerprint.

## Segregation of Duties

- `kol.payouts.request`: create payout requests from eligible Referral rows.
- `kol.payouts.manage`: approve/reject and complete an approved payout.
- KOL Manager receives request permission only.
- Finance receives manage permission only.
- Approver must be different from requester.
- Administrator inherits both through the global permission catalog.

## Connector Write Contract

`POST /payouts/complete` is invoked only after ERP approval and requires exactly:

- `erp_payout_request_id`
- `affiliate_id`
- `referral_ids`
- `amount`
- `currency`
- `payout_method = paypal_manual`
- `paypal_transaction_id`
- `paid_at` (`YYYY-MM-DD`)

ERP requires exact read-back before marking a request `paid`.
## Frozen PayPal Recipient Evidence

A new request requires the resolved KOL to be active, approved, configured with `payout_method = paypal`, and have a non-empty payout account.

At request creation ERP freezes an encrypted PayPal recipient snapshot on the payout request. Later KOL profile edits do not mutate the approved payment destination. The PayPal transaction ID is also encrypted at rest and is never emitted in list payloads or logs.

## Real Provider Read-Only Baseline

Local AffiliateWP was audited read-only before implementation:

- Payout rows: 1,550
- Provider status: 1,550 `paid`
- Provider method: 1,550 `manual`
- Distinct Affiliates: 112
- Referral rows carrying payout IDs: 1,549
- Distinct referenced payout IDs: 1,549
- Dangling Referral → payout links: 0
- Historical payouts with zero Referrals: 1

No payout write-back was performed during this audit.## Final Local Gate Evidence

- Fresh migration rehearsal: PASS; all three M1.4E tables created.
- Focused payout backend: 8 scenarios / 37 assertions / exit 0.
- Final payout + Referral + Connector + Identity focused gate: 95 warning-marked scenarios / 304 assertions / exit 0.
- Frontend Commissions + Payouts: 6 / 6 PASS.
- Typecheck: PASS.
- Production build: PASS; existing >500 kB chunk warning only.
- Architecture: 1 failed / 1 risky / 6 passed / 493 assertions.
- Architecture failure is the existing 12 non-KOL APP-001 baseline violations; M1.4E KOL APP-001 violations = 0.
- `git diff --check`: PASS.
- Sensitive literal scan: PASS; no secrets, keys, `.env`, or UAT temp artifacts present.
- `origin/main` drift before checkpoint: 0 behind / 0 ahead from base `264fe20b79b7f145f3b976d939ddce0a07c57d8d`.
- Dedicated workflow: `.github/workflows/erp-kol-m1-4e-commission-payout-workflow.yml`; YAML parse PASS.

## Live Provider Gate

Next allowed live action is read-only `GET /snapshot/payouts` against Local WordPress and parity comparison with the Provider database baseline. `POST /payouts/complete` remains prohibited because it creates real payout/referral financial side effects.

Merge, Production deploy, tag, and release remain unauthorized.## Live Provider Read-Only UAT — PASS

The signed `GET /snapshot/payouts` gate against `localdev.beyourlover.com` was compared to direct read-only AffiliateWP database truth.

Initial UAT exposed a Connector anomaly: historical payout #948 has a stale raw relation to deleted Referral #1617. Connector output first exposed `[null]`, then the raw stale ID when canonicalization preferred the payout object's raw relation.

The fix is isolated in YBY-Core PR #29 (`ANDY-CORE-ERP-PAYOUT-EMPTY-REFERRAL-SNAPSHOT-001`): canonical payout Referral IDs prefer AffiliateWP's hydrated helper, filter missing Referral entities, and use raw relation IDs only as fallback.

Final parity with PR #29 exact source:
- 1,550 payouts / 1,550 Provider DB payouts
- 112 distinct Affiliates
- 1,549 Referral links / 1,549 Provider DB entity links
- exactly 1 zero-Referral payout
- payout #948 `referral_ids = []`
- 0 null Referral IDs
- status `paid = 1550`
- method `manual = 1550`
- payout ID range 1..1550

`M1.4E_LIVE_PROVIDER_READ_ONLY_UAT = PASS`.
# B2B Trade Finance S3-G4 — independent Accounting manual settlement

Date: 2026-10-22. **Draft / isolated synthetic verification only; no Production merge/deploy/money movements.**

## Decision and source audit
Owner approved **independent B2B Trade Order architecture**, previously implemented as S3-G3 Draft PR #480. BYL `order_financials` remains the Woo/Consumer Order Finance source, with `orders.order_kind` limited to customer/KOL/derived. No trusted B2B order linkage to it exists. Therefore **never merge/force-map Trade Orders into Woo OrderFinancial or C-end Customers**. New Finance-controlled `finance_b2b_trade_cash_events` is the single Finance source for independent B2B Trade receipts and refunds; it must not be loaded again as Woo cash into existing dashboards without a reconciliation contract.

## Verified implementation boundary
- One Finance-owned immutable event table, `finance_b2b_trade_cash_events`, references a real `b2b_trade_orders` record, a reviewed `b2b_trade_receipt_evidence` (uniquely) for positive receipts, a corresponding existing Finance receipt event for refunds; globally unique `(source,external_reference)` prohibits cross-order and cross-refund transaction replay.
- `accounting.view` governs reading the Accounting B2B workspace. **Both** `accounting.view` and `finance.b2b_cash_confirm` are required to confirm a transaction. This distinct permission is seeded on the **administrator preset** only; no default B2B seller, B2C customer, KOL or logistics operator inherits finance confirmation. Service independently validates active Finance actor permission.
- Receipt confirmation requires: trade order independently approved, business evidence reviewed by a second person, finance user distinct from evidence claimant and business reviewer, attestation matching exact submitted currency, source, amount and **external reference**, approved external statement method, value date and written reconciliation basis. Only then is the Finance event appended; business-side `reviewed` status alone never counts as money.
- Refund requires a different Finance confirmer than the original receipt, its own globally unique external bank/provider reference and evidence basis, same order/currency; cumulative refunds cannot exceed original confirmed receipt.
- Accounting net-cash summary comes **only** from confirmed Finance cash events. Distinguishes trade approval pending, unpaid, partial, paid, overpaid, needs review; supports refunds. Not booked to Woo's `OrderFinancial`, never creates Woo `orders` or payment API operations. All amounts use integer micro-units with validation at the PHP boundary; input text uses ordinary 2-decimal currency.
- The Finance manual attestation is an **authorized human assertion**, not an independent technical query against a bank/provider API. The fact of bank settlement must be confirmed by the finance officer using statements outside this application. **Do not advertise automatic bank verification** or externally proven payments.
- Finance display route: `/accounting/b2b-trade-cash`, details and authorized confirm/refund actions; B2B trade detail displays only aggregated Finance-confirmed result, not statement notes or private financial event identities.
- Branch only; default all Finance states empty until explicit authorized Finance confirmation. Money recognized for Finance **manual settlement tracking**, not auto-imported into P&L, tax returns, cost/revenue dashboards or the existing Woo profit model.

## Test/Dev Owner gate
- Synthetic tests: reject seller/B2C and reviewer confirmation, require signed source+reference matching, reject mismatched amount, duplicate transaction, duplicate evidence and replay, partial receipt/refund, refund > receipt, refund confirmed by original confirmer, cross-order resource mismatch.
- Isolated SQLite apply/rollback/reapply, PHP lint, TypeScript and Vite build, targeted PHPUnit, PC1280 and mobile390 Vue rendering with synthetic props. Previous UAT must remain separate; no real bank or customer data.
- **Still HOLD**: in-browser multi-account signed-in PC UAT, physical iPhone 14 Owner UAT, source bank account authority and actual external settlement verification, automated bank/provider reconciliation, FX conversion policy, refund/chargeback feed, P&L aggregation and Finance Controller signoff. GitHub Actions billing restriction and inherited baseline drift #474 remain separate.
- No main merge, real database migrations, production deployment, actual money operations, Woo changes or READY device restarts.

## G4 late security UAT hardening
- Finance receipts and refunds also check provider transaction references against existing Woo `order_financials.payment_transaction_id` and `provider_reference` in READ-ONLY mode. A confirmed Woo platform transaction **cannot be claimed again** by the independent B2B Finance record, even when the business-supplied document and other metadata match.
- Eloquent `FinanceB2BTradeCashEvent` disallows update/delete of confirmed Finance events; correcting cash requires an appended refund event. This is an **application-level guard** (not a claim of cryptographic or database-trigger immutability).
- Cross-system negative test inserts one synthetic Woo OrderFinancial row into SQLite memory and confirms a matching B2B Finance event is blocked; no live Woo records were accessed.
- PC/M14 responsive Chrome proof is compiled local Vue pages with **synthetic Inertia props only**, not real authorized Finance login or physical iPhone. No automatic bank/provider reconciliation is claimed.

# BYL B2B Sales OS S3-G1 — finance source-of-truth read-only gate

Status: **Draft S3-G1 read-only contract + pure projector only**. Actual OrderFinancial↔B2B binding is NOT implemented or approved. Do NOT characterize as full S3 payment integration, a cash ledger or verified revenue.

## Verified source facts
- Source **YBY** `app/Modules/Sales/Application/PaymentReceiptService.php` maintains manually entered receipts with confirmation/void audit; `ReceivableStatusService.php` derives `pending_receivable / unpaid / partial / paid / overpaid` from confirmed same-currency receipts, **not** from Won.
- Target **BYL** `app/Modules/Finance/Infrastructure/Models/OrderFinancial.php` already holds `amount_paid`, `refund_amount`, `currency`, `paid_at`, `payment_transaction_id`, `provider_reference`, and reconciliation fields. These correspond to Woo/Order finance facts but have **no authenticated B2B Opportunity foreign key** or evidence that a random B2B opportunity belongs to the Woo consumer order.
- Porting YBY's manual receipt creation directly would invent a competing money ledger and risk double counting. A B2B 'Won', a quote draft, and an expected Opportunity value are **not payment evidence**.

## G1 implemented technical contract (safe, pure and non-transactional)
`app/Modules/B2B/Sales/B2BSalesFinanceTruthProjection.php` accepts only previously approved, validated `byl_order_financial` order/finance linkage plus an explicitly confirmed receivable and immutable payment/refund facts. This is **pure in-memory math**; it neither queries Orders nor writes SQL, communicates with processors, nor mutates Accounting.

- Without an attested link (source, Order id, financial record id, approver, verified time), returns `unlinked`, **not** `unpaid`, `paid`, or a false zero-revenue assertion.
- Without explicitly confirmed receivable baseline, returns `pending_receivable`; Won status and draft Quote cannot set this baseline.
- With matching attested evidence: compute `verified_cash_micros = sum(paid_micros - refunded_micros)`, requiring exact same Order ID, finance ID, currency, paid timestamp, transaction reference, nonnegative amounts and refund <= paid.
- Duplicate transaction references, mismatched source IDs or currency, excessive refunds or unconfirmed receivable go to `review_required` and **do not emit a cash value**. No automatic FX conversion.
- Derive `unpaid / partial / paid / overpaid` ONLY from this verified net cash and receivable. The returned `actual_revenue_recorded=false` is always false because the read-only projection has **no accounting posting authority**.

These fields are a **future adapter contract**, not evidence that a real Woo B2B linkage has been accepted. No API route or UI claiming actual paid revenue was added.

## G2 missing inputs before real finance integration — hard HOLD
1. **Business ownership**: Determine whether standalone wholesale orders and Woo B2C orders should share any link at all. Cannot assume matching customer email/name, quote amount, or product implies the same purchase.
2. **Proof of link**: Explicit one-to-one/one-to-many Opportunity→authenticated Order/OrderFinancial attribution with reviewer ID/time, provider reference, no duplicate claim between two Opportunities, and seller/warehouse identity. A read-only adapter must reject cross-owner/cross-company finance exposure.
3. **Currency/FX**: OrderFinancial stores decimals while Sales Quote uses microunits. Specify decimal-safe conversions, charge/refund currency, FX source/date and precise handling of multi-currency receipts without netting incompatible currencies.
4. **Reconciliation**: `paid_at`, provider transaction identity, status/chargeback/refunds, multiple payments and voids; do not double count `amount_paid` across order lines or separate marketing/affiliate ledger.
5. **Authoritative approval**: Confirm BYL Finance/Accounting owner and permission for manual distributor bank evidence, final receipt confirmation/void and B2B visibility; consider separate B2B-only ledger only if existing finance cannot represent the transaction.
6. **Audit / rollout**: synthetic source-reference collisions, unauthorized direct access, refunded/partial/overpaid tests; PC/M14 owner review; explicit approved Dev-only schema/API scope before writes.

## Evidence and dependencies
- S0–S1 Draft PR #476, S2 Draft PR #477, missing parity Issue #475.
- Isolated test `tests/Unit/B2B/B2BSalesFinanceTruthProjectionTest.php`: unlinked, no approval, pending receivable, unpaid, partial refund, paid/overpaid, duplicate reference, mismatched currency/order/refund; uses synthetic events only.
- This S3-G1 branch is stacked against current S2 HEAD and intentionally leaves C-end Customers/Orders/Finance modules, migrations, payment routes, live financial records, mail, main, UAT and Production **unchanged**.

**Release verdict:** S3-G1 gate can PASS read-only tests, but complete S3 and YBY parity remain HOLD. **Do not claim recognized revenue or received money from Won/Quote.**

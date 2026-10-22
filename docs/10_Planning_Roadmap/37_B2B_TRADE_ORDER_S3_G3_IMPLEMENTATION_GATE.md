# Independent B2B Trade Order S3-G3 — BYL ERP

Status: **ISOLATED FEATURE CANDIDATE / DEV+OWNER UAT HOLD / NOT ACTUAL FINANCIAL INTEGRATION**.

## Owner architecture decision
Owner explicitly selected a **standalone B2B Trade Order architecture** instead of reusing or falsely attributing Consumer Woo Orders. This work is stacked atop #479 (S3-G2), #478 (G1), #477 (S2), #476 (S1), and #473 (B2B Core). Owner requested implementation progress, not permission to merge main or modify production.

## Canonical B2B data contract
- `b2b_trade_orders`: immutable-looking generated trade order number, scoped B2B company, Sales Opportunity and exactly one quote version (unique quote_id), seller owner, snapshot of quote price/line items in integer micro-units, internal draft/approved state and second-person approval evidence.
- `b2b_trade_receipt_evidence`: B2B Trade Order only, submitted source+transaction reference with unique constraint, claimed amount/currency, pending/reviewed/rejected evidence **documents**. It is **not a cash ledger**, does not collect money, and “reviewed” explicitly means documentation inspected, **not actual received cash**.
- `b2b_trade_audit_events`: append-only internal evidence of draft, order approval, claimed receipt submission and review; foreign keys restrict deletion of the record chain.
- B2C `customers`, canonical `orders`, `order_financials`, Finance/Accounting journal and WooCommerce unchanged. Never copy or merge consumer customer_id into B2B organization_id.

## Authorization and invariants
1. B2B viewer gets only owner-scoped list/details; B2C-only actors forbidden from routes. B2B seller `b2b.view+b2b.operate` creates a *draft* from a quote that belongs to their own visible Sales Opportunity. Same quote cannot create a second Order. Quote values and currency are snapshotted, not modified by receipt actions.
2. **Order approval requires** `b2b.view+b2b.view_all+b2b.trade.approve`, an Opportunity with status `won`, and a different user from the draft creator. Approval is an internal order signoff, **not** verified buyer acceptance, bank settlement or a recognized Accounting transaction.
3. Claiming payment evidence requires an approved Trade Order, a positive amount in the same currency, an allowed source and globally unique source+reference. Claimants do not generate canonical OrderFinancial rows or gateway calls.
4. Evidence review requires `b2b.view+b2b.view_all+b2b.receipts.review`, a second person distinct from claimant, mandatory reason, and an unreviewed record; cannot review twice. Reviewed evidence **must not** be included in actual cash or P&L before a separately approved Finance settlement linkage exists.
5. Admin preset migration seeds the two new explicit B2B approval permissions; no consumer role receives them by default. Separate test cases exercise direct URL denial and seller/approver separation.

## UI and test evidence
- New nested B2B menu `贸易订单`, listing/form, details, status, evidence and audit panels. Main China Mac UAT remains on the previous approved baseline: this S3-G3 feature is tested in a **separate** feature checkout, not deployed to it.
- Actual `npm run typecheck` PASS and Vite build PASS.
- **91 PHPUnit tests / 910 assertions PASS** across B2B/Shell/ModuleProvider/S3 finance checks, including 7 new Trade Order workflow tests.
- Isolated dedicated SQLite `migrate:fresh`, one-step rollback and reapply each PASS. Three new B2B tables start empty, as do `order_financials` and `customers` in that test database.
- Local headless Chrome rendered actual Vue list and details components at **1280×800 PC** and **390×844 mobile** — 4 checks, no JS errors or page horizontal overflow. The responsive Chrome preview injects **synthetic Inertia page props**; not actual authorized logged-in backend UAT or a physical M14.
- Mobile card layout and business-readable decimal entry were retained. Backend stores exact integer micros. No production credentials or data were read.

## Remaining stage gates
- **G4 Finance posting adapter — NOT AUTHORIZED / NOT IMPLEMENTED:** real payment-gateway/transfer identity, merchant confirmation, FX and refunds/chargebacks, existing Accounting ledger association, one-transaction-one-claim uniqueness and Finance owner approval. No actual cash receipt, approved-invoice dispatch, Accounting journal write or payment execution.
- **S4 Owner UAT — HOLD:** authenticated cross-role seller+independent approver+receipt reviewer tests with synthetic records, genuine PC and physical M14 signoff, GitHub CI restriction and inherited menu/architecture baseline issues (#474).
- Do not merge staged Draft PRs as independent production changes or run live migrations. Existing main, previous China Mac UAT, US Mac ERP and Production must remain unchanged.

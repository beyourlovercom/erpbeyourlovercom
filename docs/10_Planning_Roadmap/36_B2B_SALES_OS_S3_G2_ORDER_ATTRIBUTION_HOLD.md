# BYL B2B Sales S3-G2 — Order ownership, exact money conversion, hard hold

Status: **S3-G2 read-only fail-closed policy and tests only**. No actual payment integration, no recognized revenue, no login UI, no migrations, no Owner M14 UAT PASS.

## Verified architecture facts (BYL main source)
- `app/Modules/Orders/Application/OrderKind.php` currently has exactly: `customer`, `kol_fulfillment`, `fulfillment_derived`. **There is NO B2B standalone order kind** and no canonical `b2b_organization_id` / `b2b_opportunity_id` on `orders` or `order_financials`.
- `app/Modules/Orders/Infrastructure/Models/Order.php` has generic `customer_id` (consumer/B2C), owner, external reference and finance relationship, **not** a B2B customer/Company link.
- `app/Modules/Finance/Infrastructure/Models/OrderFinancial.php` persists `amount_paid`, `refund_amount` with Laravel `decimal:2` casts, `currency`, `paid_at`, `payment_transaction_id`, `provider_reference`, and reconciliation metadata. It is for the existing Orders accounting truth, not standalone distributor payments.
- The target YBY-origin `B2BSalesOpportunity` has `b2b_organizations`, `b2b_contacts`, `b2b_cases` and no verified Order/OrderFinancial relation. **Matching by product, price, email, country, or merchant reference is unsupported and unsafe**.

## G2 implementation and safety rationale
1. `B2BSalesOrderAttributionGate` rejects **all existing OrderKind values**, even if a caller provides plausible order/company/Opportunity IDs. They represent customer or fulfillment workflows; neither is validated B2B wholesale. It also rejects fabricated `b2b_trade` until a separately reviewed order kind is actually registered. Adding such a kind in the future alone will **not** make the gate pass; a verified finance-authorized attestation layer must be designed first.
2. `B2BSalesExactMoney` converts the existing BYL `decimal:2` payment/refund strings into integer micro-units for parity with Sales Quote amounts — never PHP floats. Rejects negatives, exponent notation, comma notation, more than two cents, oversized quantities, and refunds exceeding paid.
3. These are **pure deterministic PHP classes**. No SQL query, bank API, payment mutation, customer lookup, or accounting posting is performed. The no-approval result is a **stop**, not an “unpaid” assertion.
4. Test `B2BSalesOrderAttributionGateTest` exercises all current kinds, forged future type, invalid IDs, noncanonical guesses, precise cents conversion, malformed values and refund overflow. It prevents silent authorization if legacy OrderKinds change.

## S3 still needs separate authorized product/Finance decision
- Wholesale direct invoicing/receipts must have a distinct B2B Trade order/payment ledger with Owner-approved source and fraud controls **or** an explicitly attested link between a B2B Opportunity and an existing canonical order, with uniqueness and finance approval. Never overload B2C `customer_id`.
- Bind receipt/funding event `provider_reference` and gateway `payment_transaction_id` to the right merchant account, verify chargebacks and partial refunds, and specify multi-currency FX handling.
- Ensure a canonical payment can be linked only once, record user, timestamp and reason, enforce per-salesperson B2B visibility and Finance permission separately.
- Only after that contract can read-only Woo/Finance evidence be fetched for a specific confirmed Opportunity. Only the Finance/Accounting owner may record recognized revenue; this B2B module must not.
- Run synthetic role-negative end-to-end tests and Owner real PC/physical M14 before exposing a final Sales receipt UX.

## Release / change fence
S3-G2 stacked on S3-G1 Draft #478 → S2 #477 → S1 #476 → B2B foundation #473. No main merge, Production/Dev deploy, live order/receipt write, or UAT instance overwrite. Current successful unit tests establish only the **rejection policy**, not an actual B2B trade payment integration.

---
type: architecture-data-contract
project: BYL ERP
contract_id: BYL-ERP-CANONICAL-ORDER-FINANCE-KOL-DATA-V1
status: OWNER APPROVED / FROZEN FOR IMPLEMENTATION PLANNING
version: 1.0
updated: 2026-08-24
---

# BYL ERP Canonical Order / Finance / KOL Data Contract V1

## 1. Authority and purpose

This contract freezes the Owner-approved business semantics for Order, Fulfillment, Finance, KOL CRM and AffiliateWP integration. It is the design authority for `ERP-ORDER-FULFILLMENT-M1-001` and later compatible slices unless a later Owner-approved contract supersedes it.

This contract does not itself authorize product implementation, Migration execution, production access, remote deployment, external credentials, or merge.

## 2. Canonical Order truth

```text
CANONICAL_ORDER_TRUTH = orders.id
```

Customer, KOL fulfillment, Logistics, Finance/Accounting, Sync and external integration projections all reference the same canonical Order. No module may create a second order truth such as customer_orders, kol_orders, logistics_orders or accounting_orders.

Order transaction data is anchored by `order_id`. Master data such as User, Product, Company, Contact and KOL owns its own canonical ID and is referenced from the Order or its child rows.

## 3. Order identity

### 3.1 Business order number

```text
external_order_number
```

- primary user-visible order number;
- manual orders: required, entered by the operator, preserved without semantic rewriting;
- WooCommerce: mapped from the provider's human/business order number;
- shown by default in Order, Logistics, KOL and Finance projections;
- not globally unique across unrelated providers.

Manual orders must reject duplicate business order numbers within the manual ERP connection scope.

### 3.2 ERP technical identity

```text
internal_order_number
```

- system-generated;
- globally unique;
- immutable;
- hidden by default from normal UI;
- used for logs, Audit, API, reconciliation, support and unique technical location.

`orders.id` remains the database primary key and canonical relational anchor.

## 4. Order kind versus source

These are independent dimensions.

```text
order_kind = customer | kol_fulfillment
```

- `customer`: a real customer purchase, whether manual, WooCommerce, Alibaba or another source;
- `kol_fulfillment`: product/sample/gift/replacement intentionally sent to a KOL for collaboration.

A consumer sale attributed to a KOL remains `order_kind=customer`; it receives an Affiliate/KOL attribution relation and must never be converted into `kol_fulfillment`.

```text
source_type = manual | woocommerce | alibaba | shopify | email | whatsapp | api | other
```

`order_kind` answers what business kind the Order is. `source_type` answers where it came from.

## 5. Canonical Order core

Target semantic fields:

```text
orders
- id
- internal_order_number
- external_order_number
- order_kind
- source_type
- order_date
- order_status
- owner_id
- company_id nullable
- contact_id nullable
- kol_id nullable
- currency
- order_note nullable
- created_by
- lock_version
- created_at / updated_at
```

`owner_id` is the business owner. It is not the logistics assignee or handler.

`lock_version` provides optimistic concurrency protection so stale edits do not silently overwrite newer Order-core changes.

## 6. Order items

Products are normalized as one line per SKU/item, not flattened `Product 1 ... Product N` columns.

```text
order_items
- id
- order_id
- product_id nullable
- sku_snapshot
- product_name_snapshot
- quantity
- unit_price
- line_revenue
- currency
- created_at / updated_at
```

There is no fixed practical item-count limit per order. `product_id` enables long-term product analysis; SKU/name snapshots preserve historical order evidence even when Product master data changes later.

## 7. Order address snapshot

Historical order addresses must not change when a current Contact/KOL profile changes.

```text
order_addresses
- id
- order_id
- address_type
- recipient_name
- company_name nullable
- phone
- email nullable
- country_code
- country_name_snapshot nullable
- province_state_code nullable
- province_state_name_snapshot nullable
- city
- address_line_1
- address_line_2 nullable
- postal_code nullable
- created_at / updated_at
```

V1 requires shipping address behavior; billing may be added when needed.

Country/region analysis uses normalized codes such as ISO country code, not free-text country names as the analytical key.

## 8. External references and idempotency

```text
order_external_references
- id
- order_id
- provider
- connection_key
- reference
- created_at / updated_at
```

Uniqueness contract:

```text
UNIQUE(provider, connection_key, reference)
```

Provider references are used for idempotent matching and integration reconciliation. The same human business order number may exist in different external providers.

For the verified AffiliateWP/WooCommerce pattern:

```text
AffiliateWP referral.context = woocommerce
AffiliateWP referral.reference = WooCommerce provider order id
→ order_external_references
→ orders.id
```

No fuzzy order matching by customer name, similar amount or approximate date is permitted. Unmatched external records remain explicit integration exceptions.

## 9. Independent workflow states

A single `order_status` must not encode every workflow.

Target semantic separation:

```text
Order:     open | completed | cancelled
Shipment:  pending | shipped | exception | returned
Finance:   incomplete | complete | reconciled
Sync:      pending | completed | failed | returned
```

The same Order may legitimately be `open`, `shipped`, `finance=incomplete` and `sync=pending` at the same time.

## 10. Fulfillment / Logistics

V1 keeps one shipment per order:

```text
1 Order → 1 Shipment
```

Target shipment semantics:

```text
shipments
- id
- order_id
- shipment_status
- carrier
- tracking_number
- shipped_at
- logistics_note nullable
- assigned_to nullable
- handled_by nullable
- created_at / updated_at
```

- `assigned_to`: current logistics assignee;
- `handled_by`: user who actually completed shipment;
- `orders.owner_id`: business owner and must not be overwritten by logistics assignment.

Future split shipment support may widen the relation to `1 Order → N Shipments` without replacing the canonical Order model.

## 11. Authorization and data scope

Authorization has three independent layers:

```text
FUNCTION_PERMISSION
+ DATA_SCOPE
+ FIELD_PERMISSION
```

Typical functional permissions include `orders.*`, `logistics.*`, `kol.*` and `finance.*` according to the repository's canonical permission catalog.

Data scope vocabulary:

```text
own | assigned | team | all
```

Default operational behavior is own-scope where appropriate. Server-side query authorization is mandatory; loading all rows and hiding unauthorized data in Vue is forbidden.

Field permissions/projections limit sensitive fields even when a role may access the Order for another workflow. Logistics does not need hidden financial cost/profit fields; Finance does not receive unrestricted mutation authority over shipping/customer business fields.

## 12. Finance truth

Finance uses the canonical Order; no separate accounting-order truth is allowed.

`order_cost_entries` remains the cost-fact ledger. `order_financials` is a calculated/reconciled order-level summary.

Required semantic distinction:

```text
shipping_revenue != shipping_cost
tax_collected != tax_cost / tax_liability
```

Operational analysis:

```text
Commercial Revenue
= Product Revenue
+ Shipping Revenue
- Discounts
- Refunds

Total Cost
= Product Cost
+ Warehouse Cost
+ First Mile
+ Shipping Cost
+ Payment Fee
+ Recognized Commission
+ Tax Cost Borne By Business
+ Other Variable Cost

Profit = Commercial Revenue - Total Cost
Margin = Profit / Commercial Revenue
```

`tax_collected` is preserved separately and is not automatically treated as ordinary sales revenue.

## 13. Base currency and FX

ERP analytical base currency is frozen as:

```text
USD
```

Each foreign-currency Order keeps its original currency. Financial calculations that normalize into USD preserve an FX snapshot including rate and rate date so historical profit does not change merely because the current market FX rate changes.

## 14. Item-level profitability

Order-level profit is required, but product strategy also requires SKU-level profitability.

Target semantic projection:

```text
order_item_financials
- order_id
- order_item_id
- product_cost
- allocated_shipping_cost
- allocated_payment_fee
- allocated_commission
- allocated_tax_cost
- allocated_other_cost
- net_revenue
- total_cost
- profit
- margin
- allocation_method / allocation_version
- calculated_at
```

Allocation rules:

1. use real item-level cost where available;
2. product cost is direct to the SKU;
3. shipping may use weight allocation when trustworthy weight data exists;
4. without trustworthy item weight, shared shipping/payment/commission/tax/other costs default to sales-value proportion;
5. real line-level commission/tax data overrides allocation;
6. the allocation method must remain traceable.

## 15. KOL master and relationship CRM

ERP owns long-term KOL relationship truth.

Target KOL semantics include:

```text
kols
- id
- display_name
- legal_name nullable
- country_code nullable
- language nullable
- status
- owner_id
- relationship_stage
- cooperation_type
- joined_at nullable
- archived_at nullable
- created_at / updated_at
```

KOL specialist ownership uses `kols.owner_id`. Relationship history and next follow-up belong to ERP and are not delegated to AffiliateWP.

Target relationship stages:

```text
lead | contacted | negotiating | sample_sent | active | paused | inactive | rejected
```

Target cooperation types:

```text
affiliate | product_seeding | paid_campaign | hybrid | other
```

Social accounts are separate child records and may later use official-provider enrichment.

## 16. AffiliateWP integration boundary

```text
AffiliateWP = Affiliate / Referral / Payout source of truth
ERP = KOL CRM + canonical Order + Finance + analytics + cross-system binding
```

ERP V1 must not clone AffiliateWP's Visits, Conversion, aggregate Earnings history or its full Referral/Payout administration.

ERP stores only the minimum durable facts required to bind KOL and Order and support Finance/analysis.

Minimal account binding:

```text
kol_affiliate_accounts
- kol_id
- provider
- connection_key
- external_affiliate_id
- optional current display/cache fields
- last_synced_at
```

Minimal order attribution projection:

```text
order_affiliate_attributions
- order_id
- kol_id
- provider
- connection_key
- external_affiliate_id
- external_referral_id
- external_order_reference
- commission_amount
- commission_currency
- referral_status
- referred_at
- external_payout_id nullable
- last_synced_at
```

Affiliate/KOL matching uses external Affiliate ID. Email, name or username must not be used as the authoritative identity key.

## 17. Affiliate commission recognition

Owner-approved mapping:

```text
pending  = projected commission only
unpaid   = recognized/payable commission cost
paid     = recognized commission cost + paid cash state
rejected = zero recognized commission cost
```

Transition `unpaid → paid` must never double-count commission expense.

For V1, AffiliateWP `paid` is accepted as actually paid. No additional ERP Finance-paid confirmation is required.

## 18. KOL performance analytics

Affiliate-attributed customer sales remain canonical customer Orders. KOL performance is derived through:

```text
KOL
→ order_affiliate_attributions
→ orders.id
→ order_items / address / finance
```

This supports analysis by:

- orders and valid orders;
- revenue, cost, profit and margin;
- commission;
- SKU / product;
- country / region;
- time period;
- specialist/owner.

No duplicate `kol_sales` order truth is permitted.

## 19. KOL navigation target

The approved long-term KOL Level-2 product map is:

```text
所有网红
网红订单
佣金明细
网红统计
关系维护
```

`网红订单` means `order_kind=kol_fulfillment`. Affiliate-attributed consumer purchases remain customer Orders and appear through commission/performance projections.

Runtime menu mutation is not authorized merely by this contract; implementation must occur inside an approved Work Package and preserve Level-1 governance.

## 20. Query and scale policy

Design check target:

```text
50,000 Orders = normal operating size
500,000 Orders = architecture/performance design check
```

Mandatory policy:

- server-side pagination;
- server-side search/filter/sort;
- indexed predicates;
- server-side authorization;
- no all-order dataset sent to Vue for client filtering.

Typical pages default to bounded pagination such as 50 rows/page.

## 21. Audit and evidence

Material Order, Shipment, Finance and integration-attribution mutations must use the existing immutable Audit foundation. Raw external payloads may be retained only through an explicitly approved integration/raw-payload mechanism and must not contain secrets in Audit metadata.

Historical data that cannot be classified reliably must never be guessed during migration. Deterministic rows may be migrated automatically; ambiguous `order_kind` or integration mappings become explicit migration/integration exceptions for review.

## 22. Implementation sequencing

This contract is broader than the first implementation slice.

`ERP-ORDER-FULFILLMENT-M1-001` is limited to the bounded manual Order → Logistics → Shipment workflow, order identifiers, `order_kind`, authorization/data scope, pagination/indexing, order-detail fulfillment projection and the relevant legacy UAT remediation/revalidation.

The first slice does **not** automatically authorize full AffiliateWP connector development, KOL CRM, advanced Payments/Refunds, full item-profit allocation engine or remote deployment. Those are activated only by later bounded scope or an explicit Owner-approved expansion.

## 23. Owner-approved gates frozen on 2026-08-24

```text
1. KOL order semantics = kol_fulfillment
2. Manual business order number contract = approved
3. Commission recognition pending/unpaid/paid/rejected = approved
4. AffiliateWP paid = actually paid for V1
5. Base currency = USD
6. V1 shipment model = 1 Order : 1 Shipment
7. Shared item-cost allocation default = sales-value proportion; real item data wins; shipping may use real weight
```

## 24. Change control

Any change to Order truth, identifier semantics, order kind/source distinction, commission recognition, base currency, shipment cardinality, default cost-allocation rule or AffiliateWP source-of-truth boundary requires an explicit Owner-approved contract revision and corresponding Decision Register / Change Log / Roadmap synchronization.

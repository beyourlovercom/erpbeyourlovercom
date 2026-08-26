---
type: module-spec
module: finance-order-analysis
project: BYL ERP
version: 1.1
updated: 2026-08-24
---

# Finance / Accounting — Order Analysis

## Purpose

Use the canonical `orders.id` as the smallest transaction anchor and support both Order-level and SKU-level profitability without creating a second accounting-order truth.

The detailed frozen semantics are governed by `16_BYL_ERP_CANONICAL_ORDER_FINANCE_KOL_DATA_CONTRACT_V1.md`.

## Canonical rules

```text
ORDER_TRUTH = orders.id
BASE_CURRENCY = USD
BUSINESS_ORDER_NUMBER = external_order_number
ERP_TECHNICAL_NUMBER = internal_order_number / hidden by default
```

Finance reads the same Order used by Customer, KOL fulfillment and Logistics projections.

## Revenue model

```text
Commercial Revenue
= Product Revenue
+ Shipping Revenue
- Discounts
- Refunds
```

`tax_collected` is preserved separately and is not automatically treated as ordinary sales revenue.

## Cost model

```text
Total Cost
= Product Cost
+ Warehouse Cost
+ First Mile
+ Shipping Cost
+ Payment Fee
+ Recognized Affiliate/KOL Commission
+ Tax Cost Borne By Business
+ Other Variable Cost
```

```text
Profit
= Commercial Revenue
- Total Cost
```

```text
Profit Margin
= Profit / Commercial Revenue
```

## Shipping and tax gap

The following pairs must remain separate:

```text
shipping_revenue != shipping_cost
tax_collected != tax_cost / tax_liability
```

This permits Finance to identify countries/regions where customer-facing shipping or tax collection is too low relative to real business cost.

## Discount rule

When amount actually paid already reflects a website discount, the discount is analytical metadata and must not be deducted twice.

Store or derive, as available:

- merchandise/product revenue;
- discount amount;
- shipping revenue;
- refund amount;
- tax collected;
- original transaction currency;
- normalized USD values from a preserved FX snapshot.

## Cost entries

Each cost fact stores or can identify:

- canonical order;
- optional item;
- optional shipment;
- optional KOL;
- type;
- original amount/currency;
- exchange-rate snapshot when normalization is required;
- USD/base-currency amount;
- source;
- allocation basis/method;
- status;
- note;
- creator/confirmer.

Statuses remain compatible with:

```text
estimated
confirmed
adjusted
```

## Order financial summary

At minimum:

- product/commercial revenue;
- shipping revenue;
- discounts/refunds;
- tax collected;
- total cost and cost components;
- shipping cost and tax cost/liability;
- recognized commission cost;
- profit;
- margin;
- finance/cost completeness status;
- calculated time;
- original currency + USD FX snapshot where applicable.

## Item-level profitability

Finance must eventually support one financial projection per Order Item so Product/SKU strategy is not inferred only from whole-order profit.

Target dimensions:

```text
order_item_id
order_id
product_cost
allocated_shipping_cost
allocated_payment_fee
allocated_commission
allocated_tax_cost
allocated_other_cost
net_revenue
total_cost
profit
margin
allocation_method / allocation_version
```

Allocation rules are frozen as:

1. real item-level facts win;
2. direct product cost stays with the SKU;
3. shipping may use trustworthy weight when available;
4. otherwise shared shipping/payment/commission/tax/other costs default to sales-value proportion;
5. real line-level commission/tax overrides allocation;
6. allocation method remains traceable.

Advanced SKU allocation implementation is not automatically required by `ERP-ORDER-FULFILLMENT-M1-001`; that Work Package only preserves compatibility with this contract.

## Affiliate commission

AffiliateWP remains Referral/Payout source of truth. ERP stores only the minimum order-attribution/commission facts needed for Finance and analytics.

Recognition:

```text
pending  → projected commission only
unpaid   → recognized/payable commission cost
paid     → recognized cost + paid state
rejected → zero recognized commission cost
```

`unpaid → paid` never recognizes the same commission expense twice. Owner approved AffiliateWP `paid` as actually paid for V1.

## Analytical dimensions

The Finance model must support aggregation by:

- date/time;
- country / region;
- product / SKU / category;
- KOL / Affiliate attribution;
- business owner;
- source/provider;
- carrier/shipping zone where available;
- profit/loss;
- incomplete cost.

## Country decisions

Target outputs include:

- order count;
- revenue/profit/margin;
- average/median/P90 shipping cost where evidence exists;
- shipping revenue vs actual shipping cost gap;
- tax collected vs tax cost/liability gap;
- refund/failure rate;
- data-supported shipping/tax pricing adjustments.

## Product decisions

Target outputs include:

- SKU/product/category orders and units;
- revenue;
- item/allocated cost;
- profit;
- margin;
- country/region profitability;
- shipping/commission burden.

## KOL decisions

Affiliate-attributed consumer sales remain canonical customer Orders. KOL profitability is derived through Order attribution, not a duplicate KOL sales table.

Target outputs include:

- attributed orders;
- revenue;
- product cost;
- logistics;
- recognized/paid commission;
- refund/replacement effects;
- profit;
- margin;
- top products;
- country/region performance.

Follower count and AffiliateWP Visit/Conversion data are supporting context, not profitability truth.

## Inventory

Future inventory suggestions may use SKU + warehouse + recent shipped quantity, but Inventory remains a separate master/operational domain and must reference canonical Product/Order data rather than duplicate it.

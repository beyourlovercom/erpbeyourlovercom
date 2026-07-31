---
type: module-spec
module: finance-order-analysis
project: BYL ERP
version: 1.0
---

# Finance / Accounting — Order Analysis

## Purpose

Use each shipped order as the smallest profitability unit.

## Financial model

```text
Net Revenue
= Amount Paid
- Refund Amount
```

```text
Total Cost
= Product Cost
+ Warehouse Cost
+ First Mile
+ Overseas Handling
+ Last Mile
+ KOL Commission
+ Payment Fee
+ Customs/Tax
+ Refund/Replacement Loss
+ Other Variable Cost
```

```text
Contribution Profit
= Net Revenue
- Total Cost
```

```text
Profit Margin
= Contribution Profit / Net Revenue
```

## Discount rule

When `amount_paid` already reflects website discount, discount is analytical metadata and must not be deducted again.

Store:

- merchandise list amount;
- discount amount;
- shipping revenue;
- amount paid;
- refund amount.

## Cost entries

Each cost stores:

- order;
- optional item;
- optional shipment;
- optional KOL;
- type;
- original amount/currency;
- exchange-rate snapshot;
- accounting-currency amount;
- source;
- allocation basis;
- status;
- note;
- creator/confirmer.

Statuses:

```text
estimated
confirmed
adjusted
```

## Order financial summary

- net revenue;
- total cost;
- contribution profit;
- margin;
- cost completeness;
- profit status;
- calculated time.

## MVP1 table

- order number;
- source;
- type;
- country;
- warehouse;
- KOL;
- revenue;
- discount;
- cost components;
- total cost;
- profit;
- margin;
- completeness.

Filters:

- date;
- country;
- product/SKU;
- warehouse;
- KOL;
- profit/loss;
- incomplete cost.

## Future decisions

### Country

- order count;
- average and median cost;
- P90 shipping cost;
- profit;
- margin;
- refund/failure rate;
- recommended shipping charge.

### Product

- product/country/warehouse profitability;
- recommended shipping charge.

### Inventory

Use SKU + warehouse + recent 90-day shipped quantity:

- 90-day shipped total;
- monthly average;
- daily average;
- current stock;
- days of cover;
- later safety stock and reorder recommendation.

### KOL

Use actual revenue and full cost:

- orders;
- revenue;
- product cost;
- logistics;
- commission;
- sample cost;
- refund/replacement;
- profit;
- margin.

Follower count is supporting context, not the profitability truth.

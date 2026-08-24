---
type: module-spec
module: kol
project: BYL ERP
version: 1.1
updated: 2026-08-24
---

# KOL / Influencer Management

## Purpose

ERP is the long-term KOL relationship and performance-management system. AffiliateWP remains the source of truth for Affiliate/Referral/Payout data; ERP does not rebuild or clone AffiliateWP.

The detailed frozen semantics are governed by `16_BYL_ERP_CANONICAL_ORDER_FINANCE_KOL_DATA_CONTRACT_V1.md`.

## KOL master

ERP owns canonical KOL identity and specialist ownership.

Target semantics:

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

`kols.owner_id` is the KOL specialist responsible for the relationship. Default data scope is the permitted/owned KOL set; specialists must not receive unrelated specialists' KOL data through server queries.

## Relationship CRM

ERP owns relationship history, not AffiliateWP.

Target activity semantics:

```text
kol_relationship_activities
- id
- kol_id
- activity_type
- channel
- subject nullable
- note
- relationship_stage_before nullable
- relationship_stage_after nullable
- contacted_at
- next_followup_at nullable
- created_by
- created_at
```

Relationship stages:

```text
lead
contacted
negotiating
sample_sent
active
paused
inactive
rejected
```

Cooperation types:

```text
affiliate
product_seeding
paid_campaign
hybrid
other
```

## KOL fulfillment Orders

Products/samples/gifts/replacements sent to a KOL use the same canonical Order database:

```text
order_kind = kol_fulfillment
orders.kol_id → kols.id
```

This is not a separate KOL order database.

Affiliate-attributed consumer purchases are different:

```text
order_kind = customer
+ order_affiliate_attribution
```

A consumer sale must never become a `kol_fulfillment` Order merely because AffiliateWP attributes it to a KOL.

## AffiliateWP boundary

```text
AffiliateWP = Affiliate / Referral / Payout source of truth
ERP = KOL master + relationship CRM + canonical Order + Finance + cross-system attribution
```

ERP V1 does not duplicate AffiliateWP aggregate Visits, Conversion, Earnings or full Referral/Payout administration.

Minimal account binding:

```text
kol_affiliate_accounts
- kol_id
- provider
- connection_key
- external_affiliate_id
- optional display/cache fields
- last_synced_at
```

Minimal order attribution:

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

KOL matching uses external Affiliate ID, not fuzzy name/email/username matching.

Verified integration pattern for WooCommerce referrals:

```text
AffiliateWP context = woocommerce
AffiliateWP reference = provider WooCommerce order id
→ order_external_references
→ canonical orders.id
```

Unmatched referrals remain explicit integration exceptions. Do not guess by amount/date/customer similarity.

## Commission semantics

```text
pending  = projected commission
unpaid   = recognized/payable commission cost
paid     = recognized cost + actually paid for V1
rejected = zero recognized commission cost
```

`unpaid → paid` must not double-count expense.

## KOL performance

Performance derives from canonical customer Orders linked by attribution:

```text
KOL
→ order_affiliate_attributions
→ orders.id
→ order_items / address / finance
```

This supports:

- attributed orders;
- revenue;
- recognized/paid commission;
- total cost;
- profit and margin;
- top SKU/product;
- country/region mix;
- time trend;
- specialist/owner performance.

AffiliateWP Visits/Conversion may be read from AffiliateWP as supporting context but are not ERP profitability truth and are not permanently cloned in V1.

## Level-2 navigation target

Owner-approved target:

```text
所有网红
网红订单
佣金明细
网红统计
关系维护
```

Semantics:

- `所有网红`: KOL master;
- `网红订单`: `kol_fulfillment` Orders;
- `佣金明细`: AffiliateWP commission/referral projection against canonical customer Orders;
- `网红统计`: canonical Order revenue/cost/profit/KOL analysis;
- `关系维护`: ERP CRM activity/follow-up workspace.

Runtime navigation changes require an approved implementation Work Package; Level-1 menu governance remains unchanged.

## Social enrichment

Social enrichment remains a later compatible capability.

User flow:

```text
Paste Instagram/X profile link
→ parse platform/handle
→ create social sync run
→ official provider
→ profile/followers/avatar
→ local avatar mirror
→ follower snapshot
→ KOL profile
```

Official APIs only by default; no unapproved scraping fallback.

Target social account semantics:

```text
kol_social_accounts
- KOL
- platform
- profile URL
- handle
- platform user ID nullable
- display name nullable
- bio nullable
- account type nullable
- verification/privacy nullable
- avatar asset nullable
- provider/method
- status and timestamps
```

Follower/social snapshots may be added later if API quota/cost justifies them. They are separate from AffiliateWP performance facts and separate from Order/Finance truth.

## Current implementation boundary

`ERP-ORDER-FULFILLMENT-M1-001` does not automatically implement full KOL CRM, AffiliateWP connector, commission dashboard or social-provider enrichment. It may implement only the KOL fulfillment Order surface required by its bounded scope unless Owner explicitly expands the package.

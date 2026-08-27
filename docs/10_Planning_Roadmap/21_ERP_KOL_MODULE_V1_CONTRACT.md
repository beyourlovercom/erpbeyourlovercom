---
type: architecture-data-ui-contract
project: BYL ERP
contract_id: ERP-KOL-MODULE-V1-CONTRACT
status: OWNER APPROVED / FROZEN FOR IMPLEMENTATION
version: 1.0
updated: 2026-08-27
---

# ERP KOL Module V1 Contract

## 1. Authority

This contract freezes the Owner-approved KOL module structure, saved-view model, Owner/data-scope rules, publication-evidence rules and phased implementation boundary.

It complements `16_BYL_ERP_CANONICAL_ORDER_FINANCE_KOL_DATA_CONTRACT_V1.md` and supersedes only that contract's former long-term KOL Level-2 navigation target. All existing canonical Order, KOL, AffiliateWP, Finance, Shipment and Audit truth boundaries remain unchanged.

```text
KOL_MASTER_TRUTH = kols.id
ORDER_TRUTH = orders.id
PRODUCT_TRUTH = products.id
SHIPMENT_TRUTH = shipments.id
AFFILIATEWP = Affiliate / Referral / Payout source of truth
ERP = KOL CRM + Order binding + Finance/analytics + durable relationship history
```

No KOL page may create a second Order, Shipment, Product, payout or affiliate-referral truth.

## 2. Frozen Level-1 / Level-2 navigation

Level-1:

```text
KOL
```

Approved Level-2 order:

```text
KOL 总览
红人库
合作机会
合作记录
沟通跟进
样品与发货
内容与发布
佣金与结算
优惠码与 Affiliate
```

This Owner decision replaces the former Level-2 target `所有网红 / 网红订单 / 佣金明细 / 网红统计 / 关系维护` for future KOL module implementation.

Existing `/kol/orders` and `/kol/orders/create` Order capabilities remain valid product capabilities but are no longer required to remain Level-2 KOL navigation items once the new approved KOL navigation is implemented. KOL fulfillment Orders continue to use canonical `orders.id` with `order_kind=kol_fulfillment`.

## 3. KOL Master

Target KOL master semantics:

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
- notes nullable
- created_at / updated_at
```

Each active KOL has one primary Owner through `kols.owner_id -> users.id`.

Primary Owner is responsibility authority, not a copied name string. Optional future collaborators may use a separate relation; they must not weaken the meaning of `owner_id`.

## 4. Social accounts and followers

A KOL may own multiple social accounts.

```text
kol_social_accounts
- id
- kol_id
- platform
- handle nullable
- profile_url
- is_active
- created_at / updated_at
```

Followers are measurable facts that change over time and must not be stored only as display strings.

```text
kol_social_metrics
- id
- social_account_id
- followers nullable
- raw_followers_value nullable
- measured_at
- created_at / updated_at
```

Examples such as `318.2K`, `8.6万` or `1.2M` may preserve the raw import value while normalized integer followers support filtering and analytics.

The `100K+` system view matches a KOL when at least one active social account has the latest normalized followers value `>= 100000`.

## 5. CRM entities

KOL relationship management must not flatten recurring events into the KOL master row.

### Opportunities

```text
kol_opportunities
- id
- kol_id
- owner_id
- stage
- proposed_cooperation_type nullable
- quoted_amount nullable
- currency nullable
- next_follow_up_at nullable
- status
- notes nullable
- created_at / updated_at
```

### Communications

```text
kol_communications
- id
- kol_id
- opportunity_id nullable
- collaboration_id nullable
- owner_id
- channel
- occurred_at
- result nullable
- notes nullable
- next_follow_up_at nullable
- created_at / updated_at
```

### Collaborations

One KOL may have many collaborations.

```text
kol_collaborations
- id
- kol_id
- owner_id
- cooperation_type
- status
- started_at nullable
- completed_at nullable
- agreed_amount nullable
- currency nullable
- commission_rule nullable
- notes nullable
- created_at / updated_at
```

Product/sample facts must use relations to canonical Products and Orders rather than free-text duplicates where canonical entities exist.

## 6. Sample and shipment boundary

`样品与发货` is a KOL projection over existing fulfillment truth.

```text
KOL / Collaboration
    -> optional canonical Order (order_kind=kol_fulfillment)
    -> Order Items / Product
    -> canonical Shipment
    -> Warehouse / Carrier / Tracking
```

KOL does not own a duplicate tracking number, Warehouse assignment or Shipment status.

A collaboration may exist without a Shipment when no physical fulfillment is required.

## 7. Content and publication evidence

Published content is a separate child fact, not the same as `已合作`.

```text
kol_contents
- id
- kol_id
- collaboration_id nullable
- platform
- content_type
- publication_status
- post_url nullable
- published_at nullable
- created_at / updated_at
```

Publication screenshots/evidence are attachments related to the content record.

### Published evidence rule

A content record has publication evidence when at least one of these exists:

```text
post_url IS NOT NULL
OR
published screenshot / attachment exists
```

Publication evidence categories:

```text
none
url
screenshot
url_and_screenshot
```

`已合作 != 已发布`.

The system must support filtering:

```text
Has Published Content = yes/no
Publication Evidence = none/url/screenshot/url_and_screenshot
```

The Owner-frozen default top-view lists do not require `已发布` as a fixed system tab in V1. Admin/Operations may save the published filter as a custom top View.

## 8. Commission and settlement boundary

KOL commission history must preserve individual records and must not be represented only by a lifetime total.

AffiliateWP remains the source of truth for AffiliateWP Referral/Payout facts under Contract 16.

ERP may store durable KOL commission/settlement facts required for CRM, analysis or non-AffiliateWP collaboration settlements while preserving the existing AffiliateWP binding and commission-recognition rules.

Target projection supports:

```text
KOL
- lifetime recognized commission
- paid commission
- pending/payable commission
- settlement history
```

No implementation in KOL Foundation may silently clone AffiliateWP Visits, Referrals, Payouts or aggregate Earnings history.

## 9. Discount code and Affiliate boundary

KOL module may own durable relationship metadata such as assigned promotion codes and affiliate bindings.

Affiliate account identity continues to use external Affiliate ID as authoritative matching evidence where AffiliateWP is the provider. Name/email/handle are not authoritative AffiliateWP identity keys.

## 10. Top View model

A top View is a saved server-side query definition. It never copies KOL data and never changes KOL ownership.

```text
VIEW = saved filters + saved sort + display metadata
```

Views are split into:

```text
system
custom
```

### Administrator / Operations default top menu

```text
我的红人 | 全部红人 | 待跟进 | 沟通中 | 已合作 | 长期合作 | 100K+ | <custom views...> | + 新建视图
```

Examples of custom views:

```text
Emma清单
Aries清单
美国KOL
待付款佣金
已发布
```

### KOL Specialist top menu

```text
我的红人 | 待跟进 | 沟通中 | 已合作 | 长期合作
```

KOL Specialist cannot create, edit, delete, reorder or pin custom Views.

## 11. System View semantics

### 我的红人

```text
kols.owner_id = CURRENT_USER.id
```

### 全部红人

Available only to users with all-KOL data scope.

### 待跟进

Matches records with an actionable follow-up due now or overdue. Exact implementation may project the next pending follow-up from CRM facts, but must remain server-side and indexed.

For own-scope users, `owner_id = CURRENT_USER.id` is always applied by authorization, not merely by UI filter.

### 沟通中

Represents active relationship/opportunity communication stages such as contacted, negotiating and other Owner-approved active communication states. It is a composed server-side View and need not map to one enum value.

### 已合作

A KOL has at least one formal Collaboration in an accepted active/completed collaboration state.

### 长期合作

A KOL is explicitly classified as a long-term/repeat relationship. It must use structured relationship/cooperation data, not note-text inference.

### 100K+

At least one active social account's latest normalized follower metric is `>= 100000`.

## 12. Custom Views

Only Administrator and Operations may create/manage Views in V1.

Creating a View means:

```text
set current filters
-> + 新建视图
-> name View
-> save current filters/sort
-> choose visibility
-> choose pin-to-top
-> save
```

V1 visibility:

```text
private = creator only
shared = Administrator / Operations shared
```

Recommended persistent contract:

```text
saved_views
- id
- module
- name
- view_type
- created_by
- visibility
- filters_json
- sort_json nullable
- is_pinned
- is_default
- sort_order
- created_at / updated_at
```

For this module:

```text
module = kol
```

The implementation should keep the schema reusable for later Orders/Products/Inventory/Shipments/Inquiry Saved Views when this does not create cross-module coupling.

## 13. Owner filter and data scope

View filters and authorization are different concepts.

```text
VIEW_FILTER != DATA_PERMISSION
```

### Administrator / Operations

May read all active KOL Owners and filter by:

```text
All
Emma
Aries
Hiko
...
```

Owner options are data-driven from authorized users/roles and must not be hard-coded names.

### KOL Specialist

Server-side effective scope:

```text
kols.owner_id = CURRENT_USER.id
```

The UI may show `Owner: <current user>` but cannot expose other Owner choices.

Changing URL/query/API parameters must never widen a Specialist's scope. Server-side authorization must reject or constrain cross-owner access.

## 14. Functional permissions

V1 must separate at least:

```text
kol.view
kol.operate
kol.views.manage
```

Role intent:

```text
Administrator: all KOL permissions + all data scope
Operations: kol.view + kol.operate + kol.views.manage + all KOL data scope
KOL Specialist: kol.view + kol.operate + own KOL data scope; NO kol.views.manage
```

Owner reassignment must remain an Administrator/Operations-authorized action and must not be available to ordinary KOL Specialists unless a later explicit permission is introduced.

## 15. Filtering contract

KOL list filtering is server-side. V1 foundation should reserve/filter on canonical facts including:

```text
Owner
KOL status
relationship stage/type
platform
country
language
followers min/max
has collaboration
has published content
publication evidence
pending follow-up
last communication date
next follow-up date
pending commission
created date
tag (when tags are implemented)
```

No full KOL dataset may be sent to Vue for client-side authorization/filtering.

Server-side pagination/search/sort is mandatory.

## 16. KOL detail aggregation

A KOL detail page must be able to combine, without duplicating truth:

```text
Master profile
Social accounts + latest metrics
Owner
Opportunities
Communications
Collaborations
Products / linked KOL fulfillment Orders
Shipment projection
Published content/evidence
Promotion/Affiliate metadata
Commission/settlement projection
Audit history where applicable
```

## 17. Audit

Material KOL mutations must use the existing Audit foundation, including at minimum:

```text
KOL create/update/archive
Owner reassignment
Opportunity state changes
Collaboration state changes
Communication logging where required
Content publication/evidence mutation
Commission/settlement mutation
Saved View create/update/delete when shared/admin-visible
```

## 18. Menu governance

The Owner explicitly authorized the KOL Level-2 menu change in the 2026-08-27 KOL design session.

Implementation must update together:

```text
menu/APPROVED_MENU.json
config/erp_shell_navigation.php
corresponding menu/navigation tests
active Work Package evidence
```

Partial runtime/baseline updates are prohibited.

## 19. Implementation sequence

The complete module is intentionally split into bounded delivery packages:

```text
M1.0 ERP-KOL-M1-FOUNDATION-001
  KOL Master + social accounts/metrics + Owner/data-scope + Saved Views
  + KOL Overview / 红人库 + approved menu baseline

M1.1 ERP-KOL-M1-CRM-001
  合作机会 + 沟通跟进

M1.2 ERP-KOL-M1-COLLABORATION-001
  合作记录 + canonical Product/Order/Shipment links + 样品与发货

M1.3 ERP-KOL-M1-CONTENT-001
  内容与发布 + publication URL/screenshot evidence + published filters

M1.4 ERP-KOL-M1-FINANCE-AFFILIATE-001
  佣金与结算 + 优惠码与 Affiliate bindings/projections
```

Each package uses one Work Package / one branch / one Draft delivery PR.

## 20. Foundation package dependency

At contract issuance, PR #102 `ERP-MVP1-CLOSURE-NAVIGATION-REPAIR-001` is open and already touches first-level/KOL Overview navigation.

Foundation implementation must not silently race that shared navigation foundation.

```text
DEPENDENCY = PR #102
DEPENDENCY_TYPE = MERGED_PR_REQUIRED_BEFORE_PRODUCT_IMPLEMENTATION
```

The KOL contract and Work Package may be formalized now. Product implementation starts after PR #102 is merged to `main`, or after a later explicit Owner authorization changes the dependency strategy.

## 21. Explicit exclusions from KOL M1 Foundation

M1.0 does not implement:

```text
AffiliateWP live connector/sync
social-provider API ingestion
automatic influencer discovery
AI outreach
automatic commission payout
automatic social metrics refresh
full campaign engine
Warehouse/WMS connector
WooCommerce automation
Reservation / automatic warehouse allocation
Production deployment
```

## 22. Acceptance direction

The KOL module is considered progressively usable when:

1. KOL Master is canonical and owner-scoped;
2. Administrator/Operations can see all Owners and create saved top Views;
3. KOL Specialist sees only own KOLs and cannot create Views;
4. social-account/follower facts are normalized and filterable;
5. later CRM/Collaboration/Content/Finance packages attach to the same `kols.id` without duplicate truths;
6. KOL fulfillment continues to reuse canonical Orders/Shipments;
7. published content is evidence-backed by URL or screenshot;
8. AffiliateWP source-of-truth boundary remains intact.

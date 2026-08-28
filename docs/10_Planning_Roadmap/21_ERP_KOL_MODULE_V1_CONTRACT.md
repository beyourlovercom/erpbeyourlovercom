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

## Owner UAT Amendment — 2026-08-27

Status: OWNER_APPROVED / M1.0 REMEDIATION REQUIRED BEFORE ACCEPTANCE.

The KOL Create/Edit surfaces MUST comply with `18_BYL_ERP_FORM_DESIGN_STANDARD_V1.md` and the existing ERP V2 form VI already used by canonical Order/Product forms. Legacy `mvp-card`/page-local generic form styling is not an approved exception.

KOL Master V1 must additionally expose structured contact, shipping and payout facts on Create/Edit:
- contact email;
- contact phone;
- optional tax identifier;
- payout method (`paypal`, `bank`, `other`) and payout account identifier/details;
- shipping recipient and structured shipping address (country, province/state, city, postal code, address lines).

These facts remain KOL-domain data. Payment/tax identifiers are sensitive business data and must not be shown as default list columns; mutation remains audited. This amendment is part of M1.0 Owner UAT remediation, not M1.1 CRM scope.

## Owner UI Upgrade — KOL_M1_FOUNDATION_UI_UPGRADE_V1

Status: OWNER_APPROVED / FROZEN FOR M1.0 REMEDIATION.

This named upgrade remains inside `ERP-KOL-M1-FOUNDATION-001` / PR #103; it is not a second Work Package and does not create a second branch/PR.

### Dashboard target

`/kol` 总览 upgrades from simple count cards to an operational dashboard. It should include, where backed by canonical data: total KOLs, monthly new KOLs, collaborated KOLs, long-term KOLs, pending publication count, pending commission amount; monthly GMV distribution; KOL level distribution; collaboration-stage funnel; content-format distribution; category/platform/city distribution; and Owner/负责人 ranking. Later-package metrics must show zero/not-yet-connected rather than fabricated facts until M1.1-M1.4 sources exist.

Dashboard filters: date range, Owner, platform, country/region, KOL level and collaboration stage. Server-side Owner scope remains authoritative.

### KOL detail target

Detail/Create/Edit must use the frozen ERP V2 Form VI and present Chinese labels. Required sections: 基本信息, 业务画像, 联系方式, 收件地址, 税务与收款, 社交账号, 内部运营信息, 更多资料, 备注.

Required detail/business-profile facts include: KOL level, city, categories, products, monthly GMV band, average selling price, content format, product quantity, store count, internal contact, collaboration stage, MCN agency/name and MCN-managed flag where applicable.

联系方式 fields are frozen as: 联系电话, Email, Telegram, WhatsApp, 其他联系方式, 首选联系方式. WeChat/微信号 and Douyin/抖音 ID are explicitly excluded. Preferred-contact choices are Phone, Email, Telegram, WhatsApp, Other.

收件地址 fields: recipient, phone, country/region, province/state, city, postal code, address line 1, address line 2.

税务与收款 fields: optional tax identifier; payout method (`paypal`, `bank`, `other`); PayPal account or structured bank receiving details as applicable. Sensitive payout/tax facts must not be default list columns or unrestricted Audit payloads.

### 红人库 list target

The list follows the Owner reference direction: dense relational table, Chinese labels, multi-value/tag presentation, server-side filters/sort/pagination and Saved Views. Candidate columns include 红人名称, 等级, 城市, 主营品类, 主推产品, 月度GMV, 平均售价, 内容形式, 产品数量, 店铺数量, 内部联系人, 合作阶段, MCN机构, 负责人, 粉丝数, 最近沟通, 是否已发布 and 待结算佣金 when the owning source exists.

Search/filter may cover name/handle/contact, Owner, platform, country/region, city, level, categories, products, GMV band, content format, collaboration stage, publication state, MCN state and follower range. KOL Specialist own-only scope remains server-side and cannot be widened by filters or Saved Views.

## Owner UI Upgrade Micro-Adjustment — 2026-08-27

Status: OWNER_APPROVED / OVERRIDES PRIOR CONTACT + GMV DETAIL WORDING FOR `KOL_M1_FOUNDATION_UI_UPGRADE_V1`.

Foreign-KOL contact scope is simplified. Remove WeChat/微信号, Douyin/抖音 ID and Telegram from KOL contact fields and filters. Keep `phone`, `email`, `whatsapp`, optional `other_contact`. `preferred_contact_method` remains structured, with Email as the default/preferred business contact for new KOL records; Phone/WhatsApp/Other may remain selectable only when explicitly required by the implementation.

Business-profile semantics are also revised: remove `monthly_gmv_band` and `average_selling_price` from the required KOL foundation UI/data target. Replace them with cumulative historical sales-volume facts. The product meaning is: each attributable selling/cooperation event records an incremental order count (e.g. A, then B, then C), while the KOL detail/list/dashboard may display the derived cumulative historical order count `A + B + C`. The total must not be maintained as an opaque manually overwritten lifetime number when durable per-event facts are available.

Recommended V1 fact model is additive/history-preserving: `kol_sales_performance` (or equivalent child facts) with at least `kol_id`, optional collaboration/content/source reference, `order_count`, `recorded_at`, optional note/source. `historical_order_count` is a derived aggregate across authorized facts. This does not create a second canonical customer Order truth; when canonical Order attribution exists later it should be referenced/reconciled rather than duplicated.
## M1.0 Scope Closure — 2026-08-27

Owner decision: `KOL_M1_FOUNDATION_UI_UPGRADE_V1` is the final M1.0 scope. No additional intelligence, public-platform ingestion, audience analytics, OAuth, scoring model, or new business-engine scope may be added before M1.0 Owner UAT is completed.

M1.0 now closes around the already frozen foundation/UI upgrade: KOL master, owner/data scope, Saved Views, ERP V2 Form VI, foreign-contact fields, shipping/tax/payout data, business profile, social accounts/followers, additive historical sales quantity, KOL Dashboard, 红人库 list, and Chinese detail surfaces.

Execution order after M1.0 is fixed as:
`Owner UAT PASS -> M1.1 CRM -> M1.2 Collaboration/Fulfillment -> M1.3 Content -> M1.4 Finance/Affiliate`.

KOL Intelligence (public profile/content ingestion, automated performance metrics, Audience Analytics screenshots/authorization, evaluation scoring) is explicitly deferred until after the existing M1.1–M1.4 operating track unless the Owner later reprioritizes it.
## Unified execution roadmap authority — 2026-08-28

Owner decision: future KOL execution order and phase boundaries are consolidated in `23_ERP_KOL_MODULE_V1_UNIFIED_ROADMAP.md`. This contract remains the architectural/data authority where not overridden by that unified execution roadmap.

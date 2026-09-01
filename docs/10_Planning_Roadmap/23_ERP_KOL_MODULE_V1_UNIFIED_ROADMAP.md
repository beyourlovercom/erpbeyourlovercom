---
type: unified-execution-roadmap
project: BYL ERP
roadmap_id: ERP-KOL-MODULE-V1-UNIFIED-ROADMAP
status: OWNER APPROVED / CANONICAL EXECUTION ROADMAP
version: 3.0
updated: 2026-08-29
---

# ERP KOL Module V1 — Unified Roadmap V3

## 1. Authority

This roadmap unifies the previously frozen KOL Module V1 contract, the M1.0 Foundation UI upgrades, the M1.1 Detail visual baseline, and the latest Owner-approved M1.1 closure additions into one execution sequence.

For future KOL implementation order and scope boundaries, this file is the canonical execution roadmap. Earlier contracts remain valid architectural/evidence sources where they do not conflict with this roadmap.

Canonical truth boundaries remain unchanged:

```text
KOL_MASTER_TRUTH = kols.id
PRODUCT_TRUTH = products.id
ORDER_TRUTH = orders.id
SHIPMENT_TRUTH = shipments.id
AFFILIATEWP = Affiliate / Referral / Payout provider truth
```

KOL must not create duplicate Product, Order, Shipment, payout, referral or tracking truth.
## 2. Unified delivery sequence

```text
M1.0 Foundation
  -> M1.1 CRM
  -> M1.2A Collaboration
  -> M1.2B Canonical Fulfillment Bridge
  -> M1.3 Content
  -> M1.4A AffiliateWP Connector Foundation
  -> M1.4B Influencer Sync + KOL Approval + Provisioning
  -> M1.4C Coupon Sync + Provisioning
  -> M1.4D Referral + Commission Sync
  -> M1.4E Commission Payout Workflow
  -> M1.4F Manager Approval Center + Weekly/Monthly Dashboard
  -> Cross-phase Closure
```

Each phase remains one bounded Work Package / one branch / one PR unless the Owner explicitly changes delivery strategy.

## 3. M1.0 — Foundation baseline

M1.0 owns the durable KOL master and operational foundation:
- KOL master and Owner/data scope;
- Saved Views and 红人库;
- ERP V2 Form VI Create/Edit/Detail foundation;
- structured contact, shipping, tax and payout facts;
- social accounts and normalized follower history;
- business profile;
- additive historical sales quantity;
- KOL Overview/Dashboard;
- approved KOL navigation.

M1.0 remains closed. New work must not reopen it except for explicitly identified foundation gap fixes required by later phases.
### M1.0 gap fix carried by M1.1 — Avatar

Avatar storage is classified as an M1.0 Foundation gap fix but is delivered inside the current M1.1 branch because the M1.1 Detail Profile Hero requires a real avatar source.

Frozen behavior:
- KOL DB stores avatar path/source/update timestamp;
- Edit supports upload/replace;
- click avatar -> show `粘贴图片即可 Ctrl+V / ⌘V`;
- clipboard paste is supported;
- drag/drop and file picker remain fallback paths;
- image is stored in ERP-owned Storage;
- Detail Profile Hero reads the stored avatar;
- Instagram/X automatic avatar APIs are explicitly deferred.

The avatar editor belongs inside the `网红业务画像` section, not as a separate top-of-page card.

## 4. Current business-profile contract

`网红业务画像` contains only the current Owner-approved business-profile fields:
- 网红分级: 头部 / 腰部 / 尾部 / 素人;
- 性别: 男 / 女 / 女同 / 男同 / 其他;
- 主营品类: Lifestyle / Beauty / Fitness / 漫画 / 家居 / 色情 / 其他;
- 内容形式: 图文 / 短视频 / 直播 / 图文+视频 / 混合;
- 主推产品: choose from canonical Product Master SKU;
- 产品数量.

Obsolete business-profile-only `city` and `store_count` fields are removed from this contract. Other KOL-domain sections such as shipping, tax/payout and internal operations remain separate and are not removed.
## 5. M1.1 — CRM

M1.1 owns CRM facts and the operational KOL Detail experience:
- cooperation opportunities;
- communication follow-up;
- next follow-up;
- stage timeline;
- quote/intention facts;
- Owner scope enforcement;
- Detail current opportunity / recent communications / historical opportunities.

M1.1 does not own fulfillment, content publication, commission settlement or social-provider ingestion.

### Opportunity management

Reuse `kol_opportunities` and provide real operator CRUD/state management. Minimum maintained facts should cover the existing CRM contract: title, stage, cooperation type/intention, target SKU(s) as proposal references, estimated/quoted amount and currency where used, next follow-up, next action, Owner, status and notes/date facts required by the Detail card.

Behavior:
- active opportunity -> Detail `当前合作机会`;
- completed/failed/cancelled/paused historical opportunity -> Detail `历史合作机会`;
- no active record -> explicit empty state;
- no production fixture/fabricated data.

An opportunity is a CRM object only. It does not become a Shipment, Order or formal Collaboration merely because its status changes.
### Communication management

Reuse `kol_communications` and provide real operator CRUD. Minimum maintained facts:
- occurred/contacted date-time;
- channel: Email / Instagram / X / WhatsApp / Phone / Other as supported;
- direction where required: outbound / inbound;
- summary/content;
- recorder/Owner;
- related opportunity when applicable;
- next action;
- next follow-up date-time.

Detail `最近沟通` reads the latest real records. No records -> explicit empty state.

### Detail visual contract

The frozen Detail composition remains:
1. Profile Hero;
2. left Basic Information;
3. left Social Media Data;
4. right Current Opportunity + stage timeline;
5. right Recent Communications;
6. full-width Historical Opportunities.

Missing future metrics render `—`. Empty CRM collections render explicit empty states. Fabricated production values are forbidden.
### M1.1 real-data closure

M1.1 is not complete until the Detail page is fed by maintainable real backend data:

```text
Avatar -> ERP Storage
Social followers -> specialist manual maintenance in M1.1
Current opportunity -> kol_opportunities
Recent communications -> kol_communications
Historical opportunities -> kol_opportunities history
```

Manual social follower maintenance is accepted for V1. Instagram/X automatic sync is not a blocker for M1.1.

### M1.1 Region G — visual/responsive closure

Before Owner UAT:
- one-to-one visual calibration against `KOL_DETAIL_PAGE_VISUAL_BASELINE_V1`;
- desktop/mobile responsive pass;
- empty states;
- long-text and high-record-count boundaries;
- Edit <-> Detail navigation calibration;
- archive/reactivate and permission visibility regression checks.

### M1.1 Region H — acceptance gate

- focused and relevant backend tests;
- TypeScript/typecheck;
- Vite build;
- `git diff --check`;
- Local UAT on US machine;
- Owner UAT;
- only after Owner UAT PASS: commit / push / PR delivery;
- Merge remains a separate explicit Owner authorization.
## 6. M1.2 — Collaboration + Canonical Fulfillment Bridge

### M1.2A — Collaboration

M1.2A owns formal KOL cooperation facts only after an Opportunity becomes a confirmed business collaboration:
- formal Collaboration record;
- related Opportunity and KOL;
- agreed products as canonical `products.id` / SKU references;
- agreed quantity, cooperation type, commercial terms, dates, Owner, status and notes;
- durable collaboration history.

M1.2A does not create Warehouse, Inventory, Order, Shipment or Tracking truth.

### M1.2B — Canonical Fulfillment Bridge

M1.2B exists only when a Collaboration requires physical sample/product fulfillment. It is an integration/projection bridge, not a second fulfillment engine.

Frozen bridge contract:

```text
KOL Collaboration
  -> canonical Order (`orders.id`, `order_kind=kol_fulfillment`)
  -> canonical Order Items (`product_id` / SKU)
  -> existing Inventory / Warehouse truth
  -> existing Shipment
  -> existing Tracking / Sync
  -> projected fulfillment status back into KOL Collaboration/Detail
```

Rules:
- one canonical Order truth; KOL stores only the relation/projection needed for the Collaboration;
- one canonical Product truth from Product Master;
- Inventory and Warehouse decisions remain owned by Inventory/Fulfillment modules;
- Shipment and Tracking remain canonical Shipment/Sync truth;
- KOL may deep-link to Order/Shipment and display read projections;
- KOL must not implement Reservation, warehouse allocation, shipping-cost, transport-mode, carrier-rule or WMS-connector engines;
- those engines remain in the global MVP2–MVP4 Fulfillment roadmap.

`M1.1 historical opportunity != M1.2 collaboration history`.

## 7. M1.3 — Content

M1.3 owns content and publication facts:
- platform and content type;
- Post URL;
- screenshot/attachment evidence;
- planned/actual publish time;
- publication status;
- evidence classification;
- published-state filters.

Frozen rule: `已合作 != 已发布`. Publication requires URL and/or screenshot evidence according to the V1 contract.

## 8. M1.4 — AffiliateWP Operations + Finance

Owner expanded M1.4 on 2026-08-29 so KOL Specialists and KOL Managers can perform normal AffiliateWP operations entirely from ERP without logging into WordPress. WordPress/AffiliateWP remains the external provider truth; ERP becomes the operating, approval, audit and finance workspace. Synchronized mirrors/projections are allowed only where required to operate and reconcile against provider truth.

### M1.4A — AffiliateWP Connector Foundation
- ERP-side signed connector client and provider contract;
- connection descriptor from environment/config, not database-stored plaintext secrets;
- HMAC request signing, timestamp, nonce, replay-resistant contract and idempotency key;
- health/probe and version compatibility;
- deterministic error/sync state contract;
- Andy Core v1.5.3 WordPress Connector is the external execution-side dependency.

### M1.4B — Influencer Sync + KOL Approval + Provisioning
- sync every AffiliateWP Affiliate into ERP AffiliateWP Influencer workspace;
- exact `connection_key + affiliate_id` is the authoritative external identity; no fuzzy name/email matching;
- unbound Affiliate may be explicitly bound to an existing KOL or explicitly converted to a new KOL;
- KOL Specialist-created KOL follows the configured approval policy;
- Manager/Admin-created KOL is immediately approved;
- approved KOL provisions/enables WordPress User and creates/approves an Active AffiliateWP Affiliate idempotently.

KOL approval policy has exactly three modes:
1. `ALL`: every Specialist-created KOL requires Manager approval;
2. `THRESHOLD`: configured platform thresholds determine whether manual approval is required; reaching any configured exemption threshold auto-approves, while all configured values below threshold or missing reliable data requires approval;
3. `NONE`: Specialist-created KOL is immediately approved.

Policy changes are prospective only. Each KOL approval decision preserves policy snapshot and reason.

### M1.4C — Coupon Sync + Provisioning
- sync all WooCommerce coupons into ERP, including non-Affiliate coupons;
- Coupon mode is part of `/kol/affiliatewp`;
- normalized Coupon Code uses trim + case-insensitive comparison;
- one Coupon Code may belong to at most one Affiliate/KOL, while one KOL may own multiple Coupons;
- ERP creation checks local mirror plus live WordPress/WooCommerce conflict before remote create;
- conflicts are hard-blocked and surfaced through the ERP notification/island UX with existing owner details;
- successful create must read back and verify the WooCommerce Coupon + Affiliate binding.

### M1.4D — Referral + Commission Sync
- sync AffiliateWP Referral facts and bind them to canonical customer Orders;
- `pending` = projected only; `unpaid` = recognized/payable; `paid` = recognized + paid; `rejected` = zero recognized;
- `unpaid -> paid` never double-counts canonical `kol_commission` cost;
- AffiliateWP Referral commission cannot be manually recreated as a duplicate ERP commission;
- ERP manual settlements remain only for non-AffiliateWP fixed fee, bonus, content fee, reimbursement or other approved business costs.

### M1.4E — Commission Payout Workflow
- Specialist selects eligible `unpaid` Referral groups/KOLs and submits one payout request batch;
- exact Referral IDs are snapshotted and locked against concurrent active payout requests;
- Manager approval means approved-for-payment, not paid;
- payment is initially manual PayPal; Manager records PayPal transaction ID, amount, currency, paid date and optional evidence;
- only after real payment is recorded does ERP ask the Connector to create/complete the AffiliateWP Payout and mark exact Referrals paid;
- payment success + AffiliateWP sync failure becomes `PAID_SYNC_PENDING`; only sync retries are allowed, never a second payment.

### M1.4F — Manager Approval Center + Weekly/Monthly Dashboard
- Manager-only Approval Center tabs: KOL approval, paid-collaboration approval, commission payout approval;
- paid Collaboration created by a Specialist requires Manager approval before it becomes an approved payable cooperation;
- Manager dashboard supports This Week / This Month and canonical filters;
- metrics include new/approved/rejected KOLs, cooperation records, publication records, Affiliate sales/commission and payout workflow counts;
- Specialist dashboard is owner-scoped and exposes own KOL/cooperation/publication/eligible-commission/application state.

### Provider-truth boundary
AffiliateWP remains Affiliate / Referral / Payout provider truth; WooCommerce remains Coupon and customer Order provider truth where applicable; WordPress remains WordPress User truth. ERP stores explicit synchronized mirror/projection, approval, payout-request, audit and canonical Finance facts required for the operating workflow. Provider mismatch is surfaced as sync/conflict state and must never be silently overwritten.
## 9. Cross-phase Closure

Final KOL V1 closure occurs only after M1.0-M1.4 are complete and includes:
- regression across all KOL phases;
- permission and Owner-scope verification;
- migration rehearsal;
- data-boundary checks against Product/Order/Shipment/Affiliate truth;
- full UAT;
- final KOL V1 closure decision.

## 10. Explicitly deferred intelligence / automation

The following are not required for the current M1.1-M1.4 operating track unless the Owner later reprioritizes them:
- Instagram/X automatic avatar ingestion;
- automatic follower refresh;
- public profile/content scraping;
- social OAuth ingestion;
- influencer discovery;
- audience analytics ingestion;
- AI outreach;
- automated scoring/evaluation.

These may later be introduced as a separate KOL Intelligence track without changing the core KOL/CRM truth model.

## 11. Immediate next execution target

Owner priority as of 2026-08-28: complete and stabilize the entire KOL V1 track before moving to another product roadmap.

Canonical execution order:

```text
ERP-KOL-M1.2A-COLLABORATION-001
→ ERP-KOL-M1.2B-FULFILLMENT-BRIDGE-001
→ ERP-KOL-M1.3-CONTENT-001
→ ERP-KOL-M1.4A-AFFILIATEWP-CONNECTOR-FOUNDATION-001
→ ERP-KOL-M1.4B-INFLUENCER-APPROVAL-PROVISIONING-001
→ ERP-KOL-M1.4C-COUPON-SYNC-PROVISIONING-001
→ ERP-KOL-M1.4D-REFERRAL-COMMISSION-SYNC-001
→ ERP-KOL-M1.4E-COMMISSION-PAYOUT-001
→ ERP-KOL-M1.4F-APPROVAL-DASHBOARD-001
→ ERP-KOL-V1-CROSS-PHASE-CLOSURE-001
```

Each implementation package remains bounded and independently tested. M1.2B must only bridge to canonical Order/Inventory/Shipment/Tracking truth. Final KOL V1 closure requires full cross-phase regression, permissions/Owner-scope checks, migration rehearsal, build/typecheck, local UAT and Owner UAT. Merge remains separately authorized per PR.


## 12. Execution status — 2026-09-01

- M1.2A Collaboration: MERGED.
- M1.2B Canonical Fulfillment Bridge: MERGED.
- M1.3 Content: MERGED.
- M1.4A AffiliateWP Connector Foundation: MERGED.
- M1.4B Influencer Approval / Provisioning: MERGED.
- M1.4C Coupon Sync / Provisioning: MERGED.
- M1.4D Referral / Commission Sync: MERGED.
- M1.4E Commission Payout Workflow: MERGED.
- M1.4F Manager Approval Center / Dashboard: MERGED by PR #141 at `e2099498429fc6df939900ee029a0e42887ba7c1`.
- `ERP-KOL-V1-CROSS-PHASE-CLOSURE-001`: OWNER UAT PASS / PR #143 / READY FOR REVIEW.

Cross-phase Closure remains verification/UAT only unless a regression proves a bounded compatibility defect. The deferred intelligence/automation list in section 10, including influencer discovery and Instagram/X automation, remains deferred and is not activated by V1 closure.

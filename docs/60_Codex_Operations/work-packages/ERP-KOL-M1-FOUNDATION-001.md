---
type: work-package
project: BYL ERP
work_package_id: ERP-KOL-M1-FOUNDATION-001
title: KOL Master / Owner Scope / Saved Views Foundation
status: FORMALIZED / OWNER AUTHORIZED / WAITING FOR PR #102 DEPENDENCY
updated: 2026-08-27
execution_provider: CLAUDE_CODE
---

# ERP-KOL-M1-FOUNDATION-001 — KOL Foundation

## 1. Authorization and baseline

```text
OWNER_PRODUCT_DIRECTION: APPROVED 2026-08-27
OWNER_IMPLEMENTATION_AUTHORIZATION: GRANTED FOR THIS FROZEN PACKAGE
EXECUTION_PROVIDER: CLAUDE_CODE
BASE_BRANCH: main
BASE_SHA_AT_FORMALIZATION: c0a09baf43397454f16026041c8507b16a53dd26
SOURCE_OF_TRUTH: GitHub / beyourlovercom/erpbeyourlovercom
PRIMARY_IMPLEMENTATION_HOST: US Mac
PRIMARY_UAT_HOST: US Mac / http://erp.b
CHINA_VERIFICATION: NOT REQUIRED UNDER OWNER STANDING OVERRIDE
REMOTE_DEPLOYMENT: NO
PRODUCTION_DATABASE: NO
MERGE_AUTHORIZATION: NOT YET GRANTED
```

Design authority:

```text
docs/10_Planning_Roadmap/21_ERP_KOL_MODULE_V1_CONTRACT.md
```

Existing canonical authority preserved:

```text
docs/10_Planning_Roadmap/16_BYL_ERP_CANONICAL_ORDER_FINANCE_KOL_DATA_CONTRACT_V1.md
```

## 2. Dependency and parallel-work declaration

PR #102 `ERP-MVP1-CLOSURE-NAVIGATION-REPAIR-001` is open at formalization and modifies shared first-level/KOL Overview navigation foundation.

```text
DEPENDENCIES: PR #102
DEPENDENCY_TYPE: MERGED_PR
DEPENDENCY_STATUS: OPEN / MUST MERGE BEFORE PRODUCT IMPLEMENTATION
PARALLEL_SAFE: GOVERNANCE/DESIGN YES; PRODUCT IMPLEMENTATION NO UNTIL DEPENDENCY MERGES
```

This branch/PR may carry the frozen KOL contract and Work Package now. Claude product implementation must begin only after PR #102 is merged to `main` and this branch is aligned to that merged main, unless Owner explicitly authorizes a different stacked dependency strategy.

## 3. Objective

Build the first real KOL module foundation on one canonical `kols.id` so the ERP can manage KOL identity, responsibility, social accounts/follower facts, server-side owner-scoped listing and reusable saved top Views without duplicating Order, Shipment, Product or AffiliateWP truth.

This package delivers the data and UI foundation required by all later KOL CRM / Collaboration / Content / Commission packages.

## 4. Frozen Level-1 / Level-2 menu change

Owner explicitly approved this KOL navigation baseline:

```text
KOL
├── KOL（/kol 即总览）
├── 红人库
├── 新增网红
├── 合作机会
├── 合作记录
├── 沟通跟进
├── 样品与发货
├── 内容与发布
├── 佣金与结算
└── 优惠码与 Affiliate
```

Because this changes governed Level-2 menu values, implementation must atomically update:

```text
menu/APPROVED_MENU.json
config/erp_shell_navigation.php
menu/navigation automated tests
this Work Package evidence
```

For M1.0, `/kol` 总览 and `红人库` are real working surfaces. The remaining approved Level-2 entries may be registered as bounded module routes/surfaces only if they do not pretend their later M1.1–M1.4 business engines are already implemented. Their status/progress in the approved menu baseline must remain truthful.

Existing KOL fulfillment Order routes remain canonical capabilities and must continue to function even if they are no longer Level-2 KOL menu items.

## 5. KOL Master schema

Author migrations for a canonical KOL master with at least:

```text
kols
- id
- display_name
- legal_name nullable
- country_code nullable
- language nullable
- status
- owner_id nullable FK -> users.id
- relationship_stage
- cooperation_type
- joined_at nullable
- archived_at nullable
- notes nullable
- timestamps
```

Required rules:

- one canonical KOL identity per row;
- `owner_id` is a User FK, never a copied owner-name string;
- archive is not destructive delete;
- normal list/search excludes archived by default but supports authorized archive filters;
- schema/indexes support owner/status/stage/country server-side filters.

## 6. Social account / follower foundation

Author canonical child tables for multiple social profiles and measurable follower history:

```text
kol_social_accounts
- id
- kol_id
- platform
- handle nullable
- profile_url
- is_active
- timestamps

kol_social_metrics
- id
- social_account_id
- followers nullable integer
- raw_followers_value nullable
- measured_at
- timestamps
```

Requirements:

- multiple platforms per KOL;
- normalized integer followers support numeric filtering;
- raw imported display value may be preserved;
- `100K+` is derived from the latest metric for at least one active account;
- no automatic social-provider API fetch in this package.

## 7. Functional permission / data scope contract

At minimum add/activate:

```text
kol.view
kol.operate
kol.views.manage
```

Role intent:

```text
Administrator
- all KOL permissions
- all KOL data scope

Operations
- kol.view
- kol.operate
- kol.views.manage
- all KOL data scope

KOL Specialist
- kol.view
- kol.operate
- own KOL data scope only
- NO kol.views.manage
```

Server-side data scope is mandatory.

A KOL Specialist must not read another Owner's KOL by changing URL, query parameters, saved View IDs or API payload.

Owner reassignment is restricted to Administrator/Operations-authorized behavior; Specialist cannot reassign a KOL to another Owner.

## 8. Owner filter

Administrator / Operations see a data-driven Owner selector:

```text
All
<authorized KOL owners from users/roles>
```

No hard-coded Emma/Aries/Hiko identities.

KOL Specialist sees only own effective Owner context and cannot enumerate/select other Owners.

## 9. Saved Views

Implement reusable persistent Saved Views for `module=kol`.

Target contract:

```text
saved_views
- id
- module
- name
- view_type = system|custom
- created_by
- visibility = private|shared
- filters_json
- sort_json nullable
- is_pinned
- is_default
- sort_order
- timestamps
```

The Saved View foundation should remain reusable by future modules without creating a cross-module business dependency.

Only Administrator / Operations may create/edit/delete/reorder/pin custom KOL Views in V1.

KOL Specialist cannot create or manage Views.

Shared custom Views are visible to Administrator/Operations; private custom Views are visible only to the creator.

## 10. Frozen top-view behavior

### Administrator / Operations

Default system top Views:

```text
我的红人 | 全部红人 | 待跟进 | 沟通中 | 已合作 | 长期合作 | 100K+
```

Then pinned custom Views, for example:

```text
Emma清单 | Aries清单 | ...
```

Then:

```text
+ 新建视图
```

### KOL Specialist

```text
我的红人 | 待跟进 | 沟通中 | 已合作 | 长期合作
```

No `全部红人`, no `100K+` fixed tab requirement, no other-owner custom View exposure, and no `+ 新建视图`.

## 11. System View semantics in M1.0

M1.0 must implement truthful server-side behavior for the system Views that can be derived from M1.0 facts.

```text
我的红人 = owner_id = current user
全部红人 = all-scope only
100K+ = latest followers >= 100000 on any active account
```

For CRM-dependent system Views (`待跟进`, `沟通中`, `已合作`, `长期合作`), M1.0 must either:

1. implement only the minimum structured fields needed to support their frozen semantic truth without duplicating later CRM/Collaboration facts; or
2. keep the UI contract present but explicitly empty/not-yet-populated until the owning later package is delivered.

Claude must not invent duplicate temporary CRM tables merely to make a tab appear populated.

## 12. KOL Overview

Deliver a real KOL Overview surface with bounded canonical counts derived from authorized data, such as:

```text
KOL total
mine
relationship/status breakdown where backed by current facts
100K+ count
```

Do not fabricate commission/published/collaboration KPI values before their owning packages exist.

## 13. 红人库 list

Deliver a real server-side KOL list with:

```text
search
Owner filter when authorized
platform
country
followers min/max
status / relationship stage where available
system/custom View application
server-side pagination
server-side sort
```

List projection should include the useful M1.0 facts:

```text
KOL display name
social platform/profile summary
latest followers
Owner
current status/stage
```

Later-package columns such as latest collaboration, published content and pending commission may remain absent until their canonical sources exist.

## 14. Create / edit KOL

M1.0 includes bounded KOL create/edit capability following `18_BYL_ERP_FORM_DESIGN_STANDARD_V1.md`.

Required create/edit facts:

```text
display name
legal name optional
country
language
status
relationship stage
cooperation type
Owner where authorized
notes
social accounts
follower metric entry/update
```

Specialist can create/edit only within own authorized scope and cannot assign another Owner.

No hard delete in V1; use archive/re-activate where authorized.

## 15. Audit

Use the existing immutable Audit foundation for material actions at least:

```text
kol.created
kol.updated
kol.archived
kol.reactivated
kol.owner_reassigned
kol.saved_view.created
kol.saved_view.updated
kol.saved_view.deleted
```

Audit must not log secrets or raw unrestricted payloads.

## 16. Existing canonical boundaries to preserve

```text
Order truth = orders.id
KOL fulfillment = order_kind=kol_fulfillment
Product truth = products.id
Shipment truth = shipments.id
Warehouse/Inventory truth remains Inventory
AffiliateWP truth remains Affiliate/Referral/Payout for AffiliateWP provider
```

No `kol_orders`, duplicate shipment, duplicate product or duplicate AffiliateWP referral/payout tables are permitted.

## 17. Explicit exclusions

```text
合作机会 business implementation beyond M1.0 minimum support = NO
沟通记录 engine = NO
full collaboration history engine = NO
sample/shipping duplicate engine = NO
content/publication evidence engine = NO
commission/settlement engine = NO
promotion-code engine = NO
AffiliateWP live sync = NO
social-provider live APIs = NO
automatic KOL discovery = NO
AI outreach = NO
automatic payouts = NO
campaign engine = NO
WooCommerce automation = NO
Reservation / auto warehouse = NO
Production deployment = NO
```

These belong to M1.1–M1.4 or later packages.

## 18. Migration safety

Migration file authoring and local disposable-database execution are authorized.

Required local migration rehearsal:

```text
fresh migration
rollback applicable KOL migration batch
reapply
schema/index/FK assertions
existing local data compatibility check
```

No Production database access or migration is authorized.

## 19. Validation contract

```text
VERIFICATION_TIER = OWNER_OVERRIDE
RATIONALE = schema + authorization would normally be high risk, but Owner standing policy explicitly uses US Mac as sufficient and does not require China verification unless specially requested or a concrete cross-machine defect exists
```

Required US verification on merge-candidate revision:

```text
focused KOL PHP tests
focused Identity/permission/data-scope tests
menu approved-baseline tests
Architecture tests
migration fresh/rollback/reapply on disposable local DB
full PHPUnit
full Vitest
npm run typecheck
npm run build
git diff --check
bounded browser/local UAT at http://erp.b
```

Owner-facing local/UAT credential:

```text
admin / password
```

Local/testing only.

## 20. Owner UAT acceptance

Owner UAT should prove at minimum:

1. Administrator can open KOL Overview and 红人库.
2. Administrator/Operations can select All or a specific KOL Owner.
3. Administrator/Operations can create a custom View from current filters and pin it to top navigation.
4. Custom View restores the same server-side filters.
5. KOL Specialist defaults to `我的红人` and sees only own KOLs.
6. Specialist cannot enumerate another Owner or create/manage Views.
7. KOL create/edit/social account/follower facts persist correctly.
8. `100K+` is numerically correct from normalized follower metrics.
9. Existing `/kol/orders` KOL fulfillment capability still works.
10. Menu contains the new Owner-approved Level-2 KOL structure without breaking other modules.

## 21. Merge / deployment gate

```text
MERGE: requires explicit Owner authorization after verification/UAT
PRODUCTION_DEPLOYMENT: NOT AUTHORIZED
REMOTE_MIGRATION: NOT AUTHORIZED
```

# PROJECT POSITION
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate [COMPLETE] -> MVP1 Existing Operations / Tencent Docs Replacement [PR #101 MERGED; closure navigation PR #102 open] -> KOL M1 [OWNER PRIORITY OVERRIDE CURRENT] -> ERP-INQUIRY-M1 / remaining roadmap packages preserved unless Owner reprioritizes again -> MVP1.1 WooCommerce Inbound -> MVP1.2 Single Tracking Outbound -> MVP1.3 Sync Orchestration -> Product / Media -> WordPress Product and Image Sync -> Content / SEO -> Posts / Pages -> GA4 / GSC / Cloudflare -> Google Docs Publishing -> Full Order Workspace -> Logistics OS -> KOL Social Providers / expansion -> Inventory and Procurement -> Accounting / ROI Expansion -> Shopify and Additional Integrations -> Production Hardening / Expansion
CURRENT_TRACK_PHASE_MAP: KOL M1.0 FOUNDATION [CURRENT / DEPENDENCY WAIT] -> KOL M1.1 CRM -> KOL M1.2 COLLABORATION -> KOL M1.3 CONTENT -> KOL M1.4 FINANCE/AFFILIATE
COMPLETED_PHASES: KOL V1 product/design contract frozen by Owner
CURRENT_PHASE: ERP-KOL-M1-FOUNDATION-001 FORMALIZATION / DEPENDENCY GATE
CURRENT_WORK_PACKAGE: ERP-KOL-M1-FOUNDATION-001
PACKAGE_STATUS: FORMALIZED / OWNER AUTHORIZED / WAITING FOR PR #102 MERGE BEFORE PRODUCT IMPLEMENTATION
WAITING_PHASES: M1.1 CRM; M1.2 Collaboration; M1.3 Content; M1.4 Finance/Affiliate
BASELINE: main@c0a09baf43397454f16026041c8507b16a53dd26
GITHUB_STATE: PR #101 merged; PR #102 open Draft; KOL delivery branch formalized
SOURCE_CONFLICTS: AI/CURRENT_CONTEXT.md still describes pre-#101 manual-fulfillment priority and is stale against GitHub; Contract 16 Section 19 old KOL navigation is explicitly superseded by Owner-approved Contract 21; PR #102 is an unmerged shared-navigation dependency
GLOBAL_GATE: PASS
PRODUCTION_DEPLOYMENT_STATUS: NO
NEXT_DEPENDENCY: PR #102 merge to main, then align KOL branch and start US Claude implementation
EVIDENCE_AS_OF: 2026-08-27

# ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 18h planning value; 14-22h range
KNOWN_CURRENT_TRACK_REMAINING: M1.1-M1.4 not yet individually issued/estimated; UNKNOWN WITH EVIDENCE
ARCHITECTURE_GATE_REMAINING: 0h for global gate; package-specific validation remains included below
MVP1_TOTAL_ESTIMATE: historical MVP1 estimate not used as KOL M1 estimate
MVP1_PLANNING_BASELINE: historical value retained in prior governance; not recalculated here
MVP1_MAXIMUM_ENVELOPE: historical value retained in prior governance; not recalculated here
MVP1_REMAINING: UNKNOWN WITH EVIDENCE; MVP1 operational implementation is merged but closure/navigation UAT remains open through PR #102
POST_MVP_KNOWN_INCREMENT: KOL M1.0 issued separately at 14-22h; later KOL packages not yet issued
LONG_TERM_CUMULATIVE_RANGE: preserve canonical Roadmap document 13 horizons; no new arithmetic performed here
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE
ESTIMATE_CONFIDENCE: MEDIUM for M1.0 only
ESTIMATE_AS_OF: 2026-08-27
ESTIMATE_METHOD: bounded schema + authorization + server-side list/views + UI + regression + local UAT; later KOL engines excluded

# ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 2.0h
CORE_EXECUTION: 10.0h
TEST_AND_VALIDATION: 4.0h
REMEDIATION_BUFFER: 2.0h
ESTIMATED_EFFECTIVE_HOURS: 18.0h
ESTIMATE_RANGE: 14-22h
ESTIMATE_BASIS: KOL master/social schema, permissions/data scope, Saved Views, approved navigation, Overview/List/Create/Edit, migration rehearsal, regression and US Local UAT
OWNER_REVIEW: INCLUDED
MERGE: EXCLUDED
MIGRATION: INCLUDED
MIGRATION_FILE_AUTHORING: INCLUDED
MIGRATION_EXECUTION: INCLUDED / LOCAL DISPOSABLE DATABASE ONLY
UAT: INCLUDED / US LOCAL ONLY
DEPLOYMENT: EXCLUDED

## Owner UAT Remediation Amendment — 2026-08-27

OWNER_UAT_RESULT: FAIL / REMEDIATION REQUIRED.

Required before M1.0 acceptance:
1. Rebuild KOL Create/Edit to the frozen ERP Form Design Standard V1 / existing ERP V2 form VI; remove legacy generic `mvp-card` form layout and page-local generic input styling.
2. Use centered `erp-form-page` / `erp-form-content`, `erp-form-card`, 2-column desktop field composition, standard labels/inputs/errors/action bar, and responsive single-column collapse.
3. Add structured KOL contact/shipping/payout fields: email, phone, optional tax ID, payout method/account, recipient and structured shipping address.
4. Preserve server-side authorization/scope and audit; do not expose tax/payment identifiers as default KOL list columns.
5. Add focused frontend VI contract tests and backend persistence/validation tests, then rerun full verification and US Local UAT.

This amendment is Owner-authorized M1.0 remediation on PR #103. M1.1+ remains excluded. MERGE remains unauthorized until Owner UAT PASS.

## Named Owner Upgrade Scope — KOL_M1_FOUNDATION_UI_UPGRADE_V1

Status: OWNER APPROVED / FROZEN / SAME WP + SAME BRANCH + SAME PR #103.

This is the formal name for the current M1.0 Owner-UAT remediation. It does not open a new WP/branch/PR.

Implementation scope:
1. Upgrade `/kol` 总览 to the approved operational dashboard direction with canonical-only metrics and filters; no fabricated M1.1-M1.4 data.
2. Rebuild KOL Create/Edit/Detail on the existing ERP V2 Form VI (`erp-form-page`, `erp-form-content`, `erp-form-card`, `erp-form-grid`, `erp-form-field`, `erp-input`, standard error/action states).
3. Expand KOL detail/business profile with Chinese labels and the Owner-approved reference fields: level, city, categories, products, GMV band, average price, content format, product/store counts, internal contact, collaboration stage and MCN facts.
4. Add structured contact/address/tax/payout persistence and validation.
5. Upgrade 红人库 to the approved dense tag/table direction while preserving Saved Views and server-side filtering/scope.

Frozen contact fields:
- 联系电话 / `phone`
- Email / `email`
- Telegram / `telegram`
- WhatsApp / `whatsapp`
- 其他联系方式 / `other_contact`
- 首选联系方式 / `preferred_contact_method` = Phone|Email|Telegram|WhatsApp|Other

Explicitly excluded from this KOL contact contract: 微信号 / WeChat ID and 抖音 ID / Douyin ID.

Acceptance addition: Owner UAT must verify dashboard structure, Chinese detail sections, ERP V2 Form VI compliance, persistence of contact/address/tax/payout facts, redaction/non-default exposure of sensitive payout/tax data, upgraded 红人库 tags/filters/Saved Views, and preservation of Specialist own-only server scope.

MERGE remains unauthorized until this upgrade passes Owner UAT. Production deployment/migration remains unauthorized.

## KOL_M1_FOUNDATION_UI_UPGRADE_V1 Micro-Adjustment — Owner Approved

This amendment supersedes the earlier contact/GMV field wording inside the same M1.0 remediation package.

Contact fields for foreign KOLs: keep Email, Phone, WhatsApp, optional Other Contact. Remove WeChat, Douyin ID and Telegram entirely from contact UI/data/filter requirements. New KOL records default `preferred_contact_method` to Email.

Business profile: remove required 月度 GMV and 平均售价 fields. Replace with 历史带货数量 / historical attributable order count. Preserve additive history: each selling/cooperation event contributes an incremental order count; the displayed lifetime total is the sum of those event facts. Example: A + B + C = historical order count. Do not store only a manually overwritten lifetime total when the contributing facts can be preserved.

Implementation should use a bounded child performance/history fact model or equivalent, without creating duplicate canonical Orders. Later canonical attribution may link/reconcile these facts. Dashboard/list/detail should use the derived historical order count and may show event history where appropriate.
## Final M1.0 Scope Freeze — 2026-08-27

Owner has closed further M1.0 expansion. `KOL_M1_FOUNDATION_UI_UPGRADE_V1` is the final remediation scope for PR #103.

Do not add KOL Intelligence, public social-platform ingestion, Audience Analytics extraction, OAuth/authorization flows, automated evaluation/scoring, or additional M1.1+ business engines before Owner UAT.

Required execution order is:
`finish current M1.0 implementation -> full verification -> US Local Owner UAT -> explicit acceptance/merge gate -> M1.1 -> M1.2 -> M1.3 -> M1.4`.

Implementation must stop at the M1.0 Owner UAT gate after preparing the verified UAT build. It must not automatically begin M1.1.
## Roadmap Re-estimate — 2026-08-27

Status: OWNER-APPROVED PLANNING BASELINE / supersedes the earlier 14–22h M1.0 planning range as a remaining-work estimate.

Based on the current implemented foundation and preserved uncommitted remediation, remaining effective development estimates are:

- M1.0 `KOL_M1_FOUNDATION_UI_UPGRADE_V1`: 6–9h remaining.
- M1.1 CRM: 3–5h.
- M1.2 Collaboration / Fulfillment: 4–6h.
- M1.3 Content: 3–5h.
- M1.4 Finance / Affiliate: 4–6h.
- KOL V1 cross-phase closure / regression / Owner UAT remediation buffer: 2–3h.

Current KOL V1 operating-track remainder: 22–34h effective engineering time, planning baseline ≈ 28h. Waiting time, Claude quota resets, passive CI time and Owner review latency are not counted as engineering hours.

KOL Intelligence remains outside this operating-track estimate and is deferred until after M1.1–M1.4 unless Owner reprioritizes it.

## M1.0 Owner UAT Acceptance — 2026-08-28

OWNER_UAT_RESULT: PASS.

Owner-approved final UAT adjustments inside the same M1.0 package:
- `/kol` is the canonical KOL overview; `/kol/overview` is compatibility redirect only.
- KOL Level-2 navigation removes standalone `KOL 总览` and adds `新增网红`.
- Create/Edit first-screen information architecture prioritizes basic facts, shipping/contact facts and social accounts.
- Legal name is removed from the Create/Edit UI while existing persisted data remains compatible.
- Cooperation type is constrained to `免费发帖 | 长期合作 | 付费发帖`, default `免费发帖`.
- New records default joined date to today and Owner to the creating account.
- Shipping/contact card is two columns × four rows: Phone/Email, Recipient/Country, State/City, Postal Code/Detailed Address; Address Line 2 is removed from the UI.
- Primary social rows default to Instagram and X/Twitter.
- Country/region uses searchable canonical options with `US` default and `OTHER` fallback; language uses fixed options with `en` default and `other` fallback.
- Create/Edit actions are sticky at the upper-right; bottom action bar is removed.

Final verification after Owner UAT remediation:
- PHP: 342 passed / 3507 assertions / 0 failures (2 skipped, 1 deprecated, 1 risky).
- Vitest: 32 files / 294 tests PASS.
- Typecheck: PASS.
- Vite production build: PASS.
- `git diff --check`: PASS.

M1.0 is accepted/frozen. M1.1 remains excluded until PR #103 is merged. Production deployment/migration remains unauthorized.

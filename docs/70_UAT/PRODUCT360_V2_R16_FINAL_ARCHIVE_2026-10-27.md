# Product 360 V2 — R13–R16 final archive (2026-10-27)

| Field | Frozen value |
|---|---|
| Project | BYL ERP / Product OS / Product 360 V2 |
| Scope | Restore Owner-approved V2 information architecture and 2A/2B packaging integration; preserve SKU Alias CRUD, Order / Logistics / Inventory behavior |
| Business decision | Product 360 V2 is the final approved scope; **do not start V3 or redesign** |
| Overall disposition | **R13–R16 CLOSED — Owner acceptance: PASS, explicitly confirmed 2026-10-27** |
| Canonical source | \`beyourlovercom/erpbeyourlovercom\`, merged PR [#525](https://github.com/beyourlovercom/erpbeyourlovercom/pull/525) |
| Source PR HEAD | \`d3a9a177420cfa9fbad5e89484ac8a414058db99\` |
| Merged main commit | \`359ec6f280ce57465eae0f6356d2e911d7f078be\` |
| Approved historical source | PR #459 Product 360 V2 and PR #462 2A/2B packaging; both **closed as superseded, without merging their stale branches** |
| Original V2 release | \`product360-v2-pr525-20261027-d3a9a177\` |
| Production at final readback | \`order-trash-pr523-preserved-20261027-680f0def\`, deployed after V2 and retaining Product 360 code byte-for-byte |
| Independent P1 follow-up | [#526 — durable ERP private media storage](https://github.com/beyourlovercom/erpbeyourlovercom/issues/526), **OPEN; not part of this sign-off** |

## R13 — information architecture / Owner visual acceptance

- Five first-level tabs: 概览 / 产品参数 / 销售与合作 / 库存与物流 / 关联与记录.
- Product parameters subtab **包装与配件** embeds the previously accepted packaging editor (including 2A new example / 2B legacy comparison) without taking the operator outside the Product 360 page.
- Product parameters subtab **渠道与 SKU** preserves SKU Alias create, status, delete, OMS verification and \`?tab=aliases\` deep links; main branch retains all four alias-management routes.
- Isolated Dev employee browser performed login, tab navigation, stored packaging version / scenario / units-carton readback, SKU Alias management controls and no browser JavaScript errors: **PASS**.
- The Owner expressly replied **"pass，进入技术的归档"** after being given the final links and acceptance report, on 2026-10-27: **Owner acceptance = PASS**. This is a user sign-off, not a claim of a separately recorded authenticated production browser trace.
- Production item \`/products/1320\` was the Owner's visible example. It has **no existing packaging profile**; absence of packaging media for 1320 is not a missing-image regression.

## R14 — packaging historical evidence & parameter permissions

- Real saved packaging profile is **product ID 1336, profile ID 1**, with **three DB media records (IDs 1, 4, 5)**.
- First production read found media records present but original image files missing from the **active version-linked storage target**. Exact original PNGs were found in multiple historical release directories, cross-checked by SHA-256 against two historical copies, then copied safely (no overwrites) into the active storage path. Media sizes agreed with \`size_bytes\` and PHP \`Storage::disk('local')\` found **3/3** original files. The current subsequent production release retains these three files.
- Product parameter workflow: two additive migrations applied, four tables available. At closure, no real factory task or submission had been created; no fake production task/submission used for acceptance.
- Permission \`products.parameters.submit\` exists in \`PermissionCatalog\` and is created in the database when actually assigned; the raw permission DB row was not yet present at closure because no such role grant exists. Factory submission requires authorization plus an **enabled task explicitly assigned to this user and product**. Manager review requires \`products.operate\`, and self-review is rejected.
- The active ERP \`storage\` symlink still points at a versioned release directory. Long-term shared storage consolidation is **deferred to independent P1 #526** with its own backup/Owner authorization. Do **not** delete historical releases containing source image bytes.

## R15 — no cross-module regression

- GitHub checks on exact PR #525 head: **6/6 PASS**, including Unified SKU Alias V1, KOL and ERP Production Stability CI.
- Corrected MySQL-focused tests: **55 PASS / 828 assertions**; Vue Product 360 suites: **43 PASS**; TypeScript and Vite build: PASS.
- Broad Product OS suite: **233 PASS / six existing failures**; under the same conditions the prior baseline had **195 PASS / nine failures**. No new failure identifiers. This is **not** a claim that the full suite was green.
- One early V2 application cutover briefly overrode the four newer SKU Alias detail routes; it was **immediately rolled back**. The corrected Git three-way merge restored those routes and CRUD before the final controlled redeployment. Historic incident and remediation are recorded in PR #525.
- Four SKU Alias routes and five factory-parameter routes present after corrected cutover. Core OrderService, single- and multi-warehouse atomic replan services, Logistics FulfillmentDerivedOrderService, Inventory Reservation Service and Orders/Show.vue were verified byte-for-byte identical with the preceding stable release.
- Authenticated user pages redirect anonymous requests normally (HTTP 302 rather than server errors). Public ERP homepage HTTP 200. Product 360 V2 files retained through the later PR #523 order-trash release; current main and production Show.vue SHA-256 match.

## R16 — source-of-truth, backup and rollback

- Merge PR #525 is canonical; a 36-file comparison of the V2 merge against the corresponding production files found **0 differences** before the later production order update.
- Production originally deployed \`product360-v2-pr525-20261027-d3a9a177\`; the subsequent \`order-trash-pr523-preserved-20261027-680f0def\` still includes the same Product 360 Show.vue, ProductController, Product routes and PackagingEditor.
- Pre-release complete MySQL backup: \`product360-v2-before-r11-20261027.sql.gz\`, SHA-256 \`4a78475aace5a2d3081985f88a88bb2c64c3a992319a5f0d26a259fe8f92746d\`. It was gzip-integrity checked. The prior application release \`order-amendment-v1-pr16-20261027-56874d65\` is retained for audit and rollback history.
- No destructive migration, Woo writeback, real order adjustment, inventory mutation or financial data rewrite was performed for this UI restoration.
- A technical rollback, **if later required**, must consider the **then-current** production baseline and subsequent migrations / business changes. Do not blindly switch to the 2026-10-27 pre-V2 release or restore its whole DB after newer approved changes; reconcile with the current deploy owner and actual backups.
- Subsequent storage hardening, retention policy, cross-release image persistence and authenticated media URL readback are independently tracked in **#526**. Its OPEN state must remain unchanged by the V2 sign-off.

## Final governance decision

**Product 360 V2 R13–R16 = CLOSED / ARCHIVED.**
**Owner final visual sign-off = PASS (2026-10-27).**
**Product 360 V3 / redesign = NOT AUTHORIZED, NOT PLANNED.**
**Durable storage #526 = OPEN / separate follow-up.**

This file is documentation-only; no runtime, database, permissions or production release is changed by archiving.

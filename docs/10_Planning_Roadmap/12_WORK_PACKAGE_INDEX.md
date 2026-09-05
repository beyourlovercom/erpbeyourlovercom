---
type: work-package-index
project: BYL ERP
version: 1.0
updated: 2026-08-28
---

# Work Package Index

## Active

```text
ERP-GOV-ROADMAP-RECONCILIATION-001
Full ERP Roadmap conflict cleanup through PR #110
Status: CURRENT / DOCS-ONLY / OWNER DIRECTED / MERGE NOT AUTHORIZED
Baseline: main@8c3a24d0a202d3180106d1ac7befbd848539494b
Branch: docs/erp-roadmap-reconciliation-001
Work Package: docs/60_Codex_Operations/work-packages/ERP-GOV-ROADMAP-RECONCILIATION-001.md
Product Implementation: NO
Next Product Package: NONE / OWNER SELECTION REQUIRED AFTER RECONCILIATION

ERP-ORDER-FULFILLMENT-M1-001
Unified Manual Order → Logistics / Fulfillment M1
Status: COMPLETE / OWNER UAT PASS / MERGED BY PR #90
PR: #90 / MERGED
Baseline: main@6ac25f2168dea7e8cd9e8f4c49c10752642a44df
Branch: feature/erp-order-fulfillment-m1-001
Work Package: docs/60_Codex_Operations/work-packages/ERP-ORDER-FULFILLMENT-M1-001.md
Work Log: docs/60_Codex_Operations/logs/2026-08-24_ERP-ORDER-FULFILLMENT-M1-001.md
Design Contract: docs/10_Planning_Roadmap/16_BYL_ERP_CANONICAL_ORDER_FINANCE_KOL_DATA_CONTRACT_V1.md
Verification: US verification accepted; China closure verification waived by Owner
Product Implementation: COMPLETE
Migration Execution: NO
Remote Deployment: NO
Merge: COMPLETE

ERP-PRODUCT-MASTER-M1-001
Product Master / canonical SKU
Status: COMPLETE / OWNER UAT PASS / MERGED BY PR #93
PR: #93; Main merge included in current main lineage

ERP-ORDER-EDIT-V2-001
Order Create/Edit V2
Status: COMPLETE / OWNER UAT PASS / MERGED BY PR #95
PR: #95; Merge SHA: 3d35916a9634b7303470cad9cce07f76185ea4bb

ERP-INVENTORY-BATCH-SYNC-M1-001
Inventory Foundation + Inventory Batch Sync M1
Status: COMPLETE / OWNER UAT PASS / MERGED BY PR #96
PR: #96; Main Merge SHA: f5247a2f9d18e8912dd64d7e728a30ec6bef9a9b
Work Package: docs/60_Codex_Operations/work-packages/ERP-INVENTORY-BATCH-SYNC-M1-001.md
Verification: US full verification/UAT accepted; China verification not required by Owner default

ERP-GOV-INVENTORY-M1-CLOSURE-SYNC-001
Post-merge canonical Inventory closure synchronization
Status: COMPLETE / DOCS-ONLY / OWNER AUTHORIZED
Work Package: docs/60_Codex_Operations/work-packages/ERP-GOV-INVENTORY-M1-CLOSURE-SYNC-001.md
Next: HISTORICAL MARKER SUPERSEDED / see D-040 roadmap reconciliation

ERP-INQUIRY-M1
B2B Inquiry Foundation
Status: ACTIVE MILESTONE / EXECUTION PAUSED AT M1.2 / NOT CANCELLED
Children: M1.0 COMPLETE; M1.1 Company COMPLETE / MERGED BY PR #89; M1.2 Contact PAUSED; M1.3 Case WAITING; M1.4 Inbound Message WAITING; M1.5 Inquiry Inbox WAITING; M1.6 Email Intake WAITING; M1.7 WhatsApp Intake WAITING; M1.8 Yesterday/Waiting Dashboard WAITING; M1.9 Local Milestone UAT WAITING
Resume Default: PAUSED / explicit Owner reactivation required

ERP-ARCH-MIGRATION-001E
Shared UI / Browser E2E
Status: COMPLETE / PASS

ERP-ARCH-MIGRATION-001E-B
Browser E2E Foundation
Status: MERGED / PASS / CLOSED
PR: #21; Main SHA: 4b9fbb0deacdfc02439fb947398b87c148602c75
Work Package: docs/60_Codex_Operations/work-packages/ERP-ARCH-MIGRATION-001E-B.md

ERP-ARCH-MIGRATION-001E-C
Authentication / Permission / App Shell E2E
Status: MERGED / PASS / CLOSED
PR: #22; Accepted Head: 2844d8f2f9214a3fb058e546eb92751e49cbe5af
Main SHA: 3218473b1cfc93f098a2e9607aabe9adb16bcf0d

ERP-ARCH-MIGRATION-001F
Architecture Re-audit
Status: COMPLETE / PASS / CLOSED

ERP-ARCH-MIGRATION-001F-A
Governance Status Correction & Gate Scope Freeze
Status: COMPLETE / CLOSED
PR: #23
Accepted Head: f327910941dc0b6107bfaf40f5803b1448544ce4
Main SHA: 70b492772eedc993e5790d563c9c261cb0b1e0b7
DEDICATED_WORK_PACKAGE_FILE: NOT CREATED / OUTSIDE AUTHORIZED FILE BOUNDARY

ERP-ARCH-MIGRATION-001F-B
Modular Boundary Decision / Remediation
Status: COMPLETE / REVIEWED / OPTION A1 APPROVED

ERP-ARCH-MIGRATION-001F-B1
Module Architecture Contract & Decision Freeze
Status: COMPLETE / PASS / CLOSED
PR: #24 (contract); closure PR #25
Accepted Head: f26b510b499ffef95e1723eb19d12bac84c93718
Main Squash: b3ac6268488b9ea96ceebaf7e7dcb23e8b5f2c9f
Work Package: docs/60_Codex_Operations/work-packages/ERP-ARCH-MIGRATION-001F-B1.md
Work Log: docs/60_Codex_Operations/logs/2026-08-03_ERP-ARCH-MIGRATION-001F-B1.md

ERP-ARCH-MIGRATION-001F-B2
Module Skeleton & Automated Boundary Enforcement
Status: COMPLETE / PASS / CLOSED
Work Package PR: #26
Work Package: docs/60_Codex_Operations/work-packages/ERP-ARCH-MIGRATION-001F-B2.md
Work Log: docs/60_Codex_Operations/logs/2026-08-04_ERP-ARCH-MIGRATION-001F-B2.md
B2 Work Package PR: #26
B2 Implementation PR: #27
Accepted Head: fe6512f655944e7668c1786899f06880af049f29
Main Squash: 6df005f8e0e59c877d95fcf41fb5c507c507378f
Closure PR: #28; Main Closure SHA: b2bc2d6e25699bc93b6ef352bc20f593c1cacd56

ERP-ARCH-MIGRATION-001F-B3
Identity & Access Boundary Migration
Status: COMPLETE / PASS / CLOSED
Work Package: docs/60_Codex_Operations/work-packages/ERP-ARCH-MIGRATION-001F-B3.md
Work Log: docs/60_Codex_Operations/logs/2026-08-05_ERP-ARCH-MIGRATION-001F-B3.md
Work Package PR: #29
Implementation PR: #30
Accepted Head: 90b18665e5f797eb2b8f0cd91d3b3baeb8073595
Main Squash: 906fea71b1d598de3b38ae89b34a33142cec0982
Closure PR: #31; Main Closure SHA: 0af914ebf8fc9f94b18e63e008fd78739bba6811

ERP-ARCH-MIGRATION-001F-B4
Runtime / Audit / Sync Boundary Migration
Status: COMPLETE / PASS / CLOSED
Work Package: docs/60_Codex_Operations/work-packages/ERP-ARCH-MIGRATION-001F-B4.md
Work Log: docs/60_Codex_Operations/logs/2026-08-05_ERP-ARCH-MIGRATION-001F-B4.md
Work Package PR: #32; Main Squash: 2a445ca6daffda8b79347b663218bf9f7c3e8d1b
Implementation PR: #33; Accepted Head: 403152de84b85ead62d48d2a9c2135e759f007e1; Main Squash: ee9908c57cabbe4ac93b3628d28154601d96a4c1
Closure PR: #34; Main Closure SHA: 26e22150f4f729aaf321a71e052a3602df9aec61

ERP-ARCH-MIGRATION-001F-B5
Shell / Dashboard Boundary & Compatibility Cleanup
Status: COMPLETE / PASS / CLOSED
Work Package: docs/60_Codex_Operations/work-packages/ERP-ARCH-MIGRATION-001F-B5.md
Work Log: docs/60_Codex_Operations/logs/2026-08-05_ERP-ARCH-MIGRATION-001F-B5.md
Work Package PR: #35; Main Squash: 330f23ecc145d6205b786edfbfb57b5979ac074a
Implementation PR: #36; Accepted Head: 010b62d8601843d406acc322a93a4a7f4e290bd9; Main Squash: 1e876120285e988a46123742928c4307bcde934b
Closure PR: #37; Main Closure SHA: a5035cbe0e8d4aed0bf1683c1081b4417f8936be

ERP-ARCH-MIGRATION-001F-C
MySQL Two-Connection Concurrency Validation
Status: COMPLETE / PASS / CLOSED
Work Package: docs/60_Codex_Operations/work-packages/ERP-ARCH-MIGRATION-001F-C.md
Work Log: docs/60_Codex_Operations/logs/2026-08-05_ERP-ARCH-MIGRATION-001F-C.md
Work Package PR: #38; Main Squash: 1cd76e0f178c3a95e71bb48d4762a198858b5524
Implementation PR: #39; Accepted Head: 8a11ae851743db5c3d9835ce2be2ddfb6036e0f9; Main Squash: f939c15f9e84adcfa45aefce28a09e1026f00dca
Closure PR: #40; Main Closure SHA: 63d1d2d3923d531b032d092eeb87d792f82eabaa

ERP-ARCH-MIGRATION-001F-D
Redis / Queue / Scheduler Runtime Validation
Status: IMPLEMENTATION COMPLETE / PASS / MERGED / POST-MERGE CLOSURE IN PROGRESS
Work Package: docs/60_Codex_Operations/work-packages/ERP-ARCH-MIGRATION-001F-D.md
Work Log: docs/60_Codex_Operations/logs/2026-08-05_ERP-ARCH-MIGRATION-001F-D.md
Work Package PR: #41; Main Squash: e760e032cb6854e086bc0189bc76f0134266deba
Implementation PR: #42; Accepted Head: 35bf66da9e98455074fc24ba5f9cd4081cdb9f81; Main Squash: 6fbd4b751a057ab2060a3f191df7ddf2dc0ab5a7

ERP-ARCH-MIGRATION-001F-E
Local Development Migration Validation
Status: COMPLETE / PASS / CLOSED
Target: http://erp.b / /Users/a/AI/007/erpbeyourlovercom
Remote Dev, Staging, UAT and Production: NOT ACCESSED
Work Package: docs/60_Codex_Operations/work-packages/ERP-ARCH-MIGRATION-001F-E.md
Work Log: docs/60_Codex_Operations/logs/2026-08-05_ERP-ARCH-MIGRATION-001F-E.md
Work Package PR: #45; Implementation PR: #46; Closure PR: #47; Main Closure SHA: d56bc76fb1d86b990a4999ebc20d26cd48365c94

ERP-ARCH-MIGRATION-001F-F
Status: COMPLETE / PASS / CLOSED
Work Package PR: #48; Implementation PR: #49; Closure PR: #50; final SHA record PR: #51; Main SHA: a13becbefe1272ffb59cb2d78369df544e68ca97
Work Package: docs/60_Codex_Operations/work-packages/ERP-ARCH-MIGRATION-001F-F.md
Work Log: docs/60_Codex_Operations/logs/2026-08-05_ERP-ARCH-MIGRATION-001F-F.md
```

## Completed architecture packages

```text
ERP-ARCH-MIGRATION-001C
Application Shell
Status: MERGED / PASS
PR: #5
Main SHA: ba1638277bac5d333ee693d627a41ca9c950c565

ERP-ARCH-MIGRATION-001D-A
Runtime Foundation
Status: MERGED / PASS
PR: #9
Accepted Head: 3d8f92b701370db41bed0df707183207f6924dd5
Main SHA: 50803c3fcd51e9f8b3485f4fb57565154a284bf7

ERP-ARCH-MIGRATION-001D-B
Audit Foundation
Status: MERGED / PASS
PR: #12
Accepted Head: 953d1b07d48f975a5f1098069927ae9a680ebc7d
Main SHA: e2247fcaaae690c94ef07d45c8807ce749de1b46

ERP-ARCH-MIGRATION-001D-C
Sync Outbox Foundation
Status: MERGED / PASS
PR: #13
Accepted Head: e4bc57b7a3f0edbe84ccfde63d02d9135a4875e3
Main SHA: 548d53f1eae2ec14eea7261d3a101c1f2fda0dcc

ERP-ARCH-MIGRATION-001D
Runtime / Audit / Sync Foundation
Status: COMPLETE / PASS

ERP-ARCH-MIGRATION-001E-A
Shared UI and Design Tokens
Status: MERGED / PASS
PR: #17
Accepted Head: e46fb54a1b41a1efa620b4af3646e104ff7bcbbc
Main SHA: 442a3a872628764fecb7209049d2daf752624e9a
```

## Completed supporting packages

```text
ERP-LOCAL-ADMIN-BYPASS-001
Status: MERGED / PASS
PR: #16
Accepted Head: 6b63229b7d776104a5736334de639d9b20fb69f3
Main SHA: 55ba7320a7046deb04a03abd99053ccd7b7ed11e

ERP-SHELL-ACCOUNT-DOCK-001
Status: MERGED / PASS / OWNER VISUAL ACCEPTANCE PASS
PR: #15
Accepted Head: 0cac340443d47591b7e242ebe051fb4407d0a986
Main SHA: 32d7244b9b1c6d1fb0cf8863cb9af694e7acc10f

ERP-MENU-BASELINE-001
Status: MERGED / PASS
PR: #14
Accepted Head: 6d5595e879778895105215dc98b1f740521b6f1a
Main SHA: 493f32ce7bf1be4368a7e9f97de9ecf9f9c9c9d8
```

## Planned fast-track packages

```text
MVP1-FOUNDATION-001
Bounded application/runtime foundation
Status: COMPLETE / PASS / CLOSED
Work Package: docs/60_Codex_Operations/work-packages/MVP1-FOUNDATION-001.md
Work Log: docs/60_Codex_Operations/logs/2026-08-05_MVP1-FOUNDATION-001.md
Work Package PR: #54; Implementation PR: #55; Closure PR: #56; Main Closure SHA: 6ebddab0ab3204044d83df0fea11e4e28b9ec3f5

MVP1-ORDER-CORE-001
Order, item, address and external references
Status: COMPLETE / PASS / CLOSED
Work Package: docs/60_Codex_Operations/work-packages/MVP1-ORDER-CORE-001.md
Work Log: docs/60_Codex_Operations/logs/2026-08-05_MVP1-ORDER-CORE-001.md
Implementation PR: #59; Accepted Head: 3956f206e63dc77142f0d353f2ad3cede3816a19; Main Squash: 5ce98ad34b4adac0e931e348bfce0961e3d4bfd6
Closure PR: #60; Main Closure SHA: fb58cb14c3aa2c998abc1a4d4b2aab2e3634db26

MVP1-FULFILLMENT-001
Shipment and logistics manual workflow
Status: COMPLETE / PASS / CLOSED
Work Package: docs/60_Codex_Operations/work-packages/MVP1-FULFILLMENT-001.md
Work Log: docs/60_Codex_Operations/logs/2026-08-05_MVP1-FULFILLMENT-001.md
Work Package PR: #62; Main: f7c131433f3ed042b7f0a2f9dab1928742f9b347
Implementation PR: #63; Accepted Head: 22ded98dc9cd056ac4456aadd62d819de7a47290; Main: 65345c03bfed3c8b30dec56dec3444ab8b175b20
Closure PR: #64; Main Closure SHA: 518de20b91c401354ecca66f20a17dc95f5e1cf8
Owner Authorization: BYL-ERP-001F-TO-MVP1-LOCAL-UAT-20260805

MVP1-SYNC-MANUAL-001
Manual website-sync outbox and UI
Status: COMPLETE / PASS / CLOSED
Work Package: docs/60_Codex_Operations/work-packages/MVP1-SYNC-MANUAL-001.md
Work Log: docs/60_Codex_Operations/logs/2026-08-05_MVP1-SYNC-MANUAL-001.md
Work Package PR: #66; Implementation PR: #67; Closure PR: #69
Owner Authorization: BYL-ERP-001F-TO-MVP1-LOCAL-UAT-20260805

MVP1-FINANCE-ORDER-ANALYSIS-001
Order-level cost and profit
Status: COMPLETE / PASS / CLOSED
Closure PR: #68; implementation evidence inherited from PR #63

MVP1-UAT-RELEASE-001
Tests, migration, backup, UAT and release
Status: AUTOMATED READINESS PASS / HUMAN UAT CHANGES REQUIRED / 4 LEGACY FINDINGS OPEN
Readiness PRs: #70, #71, #72; Human UAT findings migrated by PR #80; DEFECT-001 closed by PR #87 + Owner UAT

ERP-GOV-INQUIRY-ROADMAP-REVISION-001
Roadmap revision promoting B2B Inquiry Foundation to current business milestone
Status: COMPLETE / PASS / MERGED
PR: #83; Main SHA: c5fb481b5e8c97eca709b12371b644013eee46ec
Work Package: docs/60_Codex_Operations/work-packages/ERP-GOV-INQUIRY-ROADMAP-REVISION-001.md
Work Log: docs/60_Codex_Operations/logs/2026-08-23_ERP-GOV-INQUIRY-ROADMAP-REVISION-001.md

ERP-GOV-EXECUTION-ROLE-ALIGNMENT-001
Role/entrypoint alignment for GPT + Claude + Codex operating model
Status: COMPLETE / PASS / MERGED
PR: #84; Main SHA: 655655dffd0cd71d5d2dec2872cf597aedf1c104
Work Package: docs/60_Codex_Operations/work-packages/ERP-GOV-EXECUTION-ROLE-ALIGNMENT-001.md
Work Log: docs/60_Codex_Operations/logs/2026-08-23_ERP-GOV-EXECUTION-ROLE-ALIGNMENT-001.md

ERP-INQUIRY-M1.0-CONTRACT-001
Inquiry architecture/data/API contract
Status: COMPLETE / OWNER APPROVED / MERGED
PR: #85; Main SHA: 02ce87dba5ede14d791b646b7cbe976a12064cae
Work Package: docs/60_Codex_Operations/work-packages/ERP-INQUIRY-M1.0-CONTRACT-001.md
Work Log: docs/60_Codex_Operations/logs/2026-08-23_ERP-INQUIRY-M1.0-CONTRACT-001.md

ERP-GOV-INQUIRY-M1.0-ACCEPTANCE-SYNC-001
Post-merge canonical status/estimate synchronization for Owner-approved M1.0
Status: COMPLETE / PASS / MERGED
PR: #86; Main SHA: 7308f81af3660acff3bcec5d843dbe83cfbf14a6
Work Package: docs/60_Codex_Operations/work-packages/ERP-GOV-INQUIRY-M1.0-ACCEPTANCE-SYNC-001.md
Work Log: docs/60_Codex_Operations/logs/2026-08-23_ERP-GOV-INQUIRY-M1.0-ACCEPTANCE-SYNC-001.md

ERP-INQUIRY-M1.1-COMPANY-001
Company Master Data Foundation
Status: COMPLETE / MERGED / OWNER UAT PASS
PR: #89; Main Merge SHA: 6ac25f2168dea7e8cd9e8f4c49c10752642a44df
Work Package: docs/60_Codex_Operations/work-packages/ERP-INQUIRY-M1.1-COMPANY-001.md
Work Log: docs/60_Codex_Operations/logs/2026-08-23_ERP-INQUIRY-M1.1-COMPANY-001.md
```


MVP1-MANUAL-FULFILLMENT-INTEGRATION-001
Status: COMPLETE / MERGED BY PR #101

MVP1.1-WOOCOMMERCE-INBOUND-001
Status: COMPLETE / MERGED BY PR #104

MVP1.2-WOOCOMMERCE-TRACKING-OUTBOUND-001
Status: COMPLETE / MERGED BY PR #106

MVP1.3-SYNC-ORCHESTRATION-001
Status: COMPLETE / MERGED BY PR #108

ERP-KOL-M1-FOUNDATION-001
Status: COMPLETE / MERGED BY PR #103

ERP-KOL-M1.1-CRM-001
Status: COMPLETE / MERGED BY PR #110

## Post-current planned milestones

```text
ERP-KOL-M1.2A-COLLABORATION-001
Status: ELIGIBLE NEXT / NOT ACTIVATED

MVP2-INVENTORY-RESERVATION-FOUNDATION
Status: ELIGIBLE NEXT / NOT ACTIVATED

ERP-INQUIRY-M1.2-CONTACT
Status: PAUSED / NOT CANCELLED / explicit Owner reactivation required
```

No product package is activated merely by appearing in this index. After `ERP-GOV-ROADMAP-RECONCILIATION-001`, the Owner must explicitly select the next bounded feature track.

## Owner-selected next KOL track

```text
ERP-KOL-M1.2A-COLLABORATION-001
Status: NEXT / OWNER SELECTED / NOT YET IMPLEMENTED
Followed by: ERP-KOL-M1.2B-FULFILLMENT-BRIDGE-001 → ERP-KOL-M1.3-CONTENT-001 → ERP-KOL-M1.4-FINANCE-AFFILIATE-001 → ERP-KOL-V1-CROSS-PHASE-CLOSURE-001
Boundary: reuse canonical Product / Order / Inventory / Shipment / Tracking truth; no duplicate fulfillment engine
```

## Current KOL M1.4 execution — Owner revision 2026-08-29

```text
ERP-KOL-M1.2A-COLLABORATION-001 = COMPLETE / MERGED PR #113
ERP-KOL-M1.2B-FULFILLMENT-BRIDGE-001 = COMPLETE / MERGED PR #114
ERP-KOL-M1.3-CONTENT-001 = COMPLETE / MERGED PR #116

ERP-KOL-M1.4A-AFFILIATEWP-CONNECTOR-FOUNDATION-001
Status: COMPLETE / MERGED PR #117
Merge SHA: 1a7347f94938be2db29cbcd1fad6822aedcd9052

ERP-KOL-M1.4B-INFLUENCER-APPROVAL-PROVISIONING-001
Status: CURRENT / OWNER AUTHORIZED / IMPLEMENTATION READY
Branch: feature/erp-kol-m1-4b-influencer-provisioning-001
Baseline: main@1a7347f94938be2db29cbcd1fad6822aedcd9052
External dependency: Andy Core v1.5.3 WordPress Connector (separate repository/chat)

Followed by:
→ ERP-KOL-M1.4C-COUPON-SYNC-PROVISIONING-001
→ ERP-KOL-M1.4D-REFERRAL-COMMISSION-SYNC-001
→ ERP-KOL-M1.4E-COMMISSION-PAYOUT-001
→ ERP-KOL-M1.4F-APPROVAL-DASHBOARD-001
→ ERP-KOL-V1-CROSS-PHASE-CLOSURE-001

MVP2 and ERP-INQUIRY-M1.2 remain paused until KOL V1 closes or Owner explicitly reprioritizes.
```


## KOL V1 Cross-phase Closure — current 2026-09-01

```text
ERP-KOL-M1.2A-COLLABORATION-001 = COMPLETE / MERGED
ERP-KOL-M1.2B-FULFILLMENT-BRIDGE-001 = COMPLETE / MERGED
ERP-KOL-M1.3-CONTENT-001 = COMPLETE / MERGED
ERP-KOL-M1.4A-AFFILIATEWP-CONNECTOR-FOUNDATION-001 = COMPLETE / MERGED
ERP-KOL-M1.4B-INFLUENCER-APPROVAL-PROVISIONING-001 = COMPLETE / MERGED
ERP-KOL-M1.4C-COUPON-SYNC-PROVISIONING-001 = COMPLETE / MERGED
ERP-KOL-M1.4D-REFERRAL-COMMISSION-SYNC-001 = COMPLETE / MERGED
ERP-KOL-M1.4E-COMMISSION-PAYOUT-WORKFLOW-001 = COMPLETE / MERGED
ERP-KOL-M1.4F-APPROVAL-DASHBOARD-001 = COMPLETE / MERGED PR #141
ERP-KOL-V1-CROSS-PHASE-CLOSURE-001 = OWNER UAT PASS / PR #143 / READY FOR REVIEW
```

Boundary: closure performs full regression, responsibility-scope verification, migration rehearsal, Product/Order/Shipment/Affiliate truth checks and Owner UAT. KOL Intelligence / influencer discovery remains deferred and is not part of this package.


## ERP-ROADMAP-PRODUCTION-RECONCILIATION-V2-001
Status: IMPLEMENTATION COMPLETE / FOCUSED VERIFICATION PASS / PREEXISTING ARCH BASELINE DRIFT RECORDED
Scope: governance/menu progress/main/Closure/Production truth reconciliation; no Production mutation.
Authority: `docs/10_Planning_Roadmap/27_ERP_ROADMAP_PRODUCTION_RECONCILIATION_V2.md`.

---
type: work-package-planning-roadmap
project: BYL ERP
title: B2B Business OS V1 — selective YBY ERP port and independent B2C boundary
status: PROPOSED / OWNER-CONFIRMED BUSINESS DIRECTION / ENGINEERING ESTIMATE / NO EXECUTION
date: 2026-10-22
issue: https://github.com/beyourlovercom/erpbeyourlovercom/issues/466
design_pr: https://github.com/beyourlovercom/erpbeyourlovercom/pull/467
related_email: https://github.com/beyourlovercom/erpbeyourlovercom/issues/458
source_repo: beyourlovercom/erpybyworldcom
target_repo: beyourlovercom/erpbeyourlovercom
---

# B2B Business OS V1 — next roadmap and effective engineering hours

## 0. Final Owner direction and baselines
**Business architecture approved:** separate level-1 menus and logical modules: C-end `Customer OS` remains `app/Modules/Customers`, `/customers`, `customers.*`, Woo consumers/subscribers/consumer service; B-end becomes `app/Modules/B2B`, `/b2b`, `b2b.*`, companies/contacts/inquiries/quotes/distributors. Source-port vetted YBY Customers/Inquiries behaviors only; never transfer YBY Production/customer data. Avoid direct cross-module writes and any second canonical Product/Order/Company truths. BYL remains one Laravel deployable, so modular releases use scoped enablement and shared-app regression rather than claiming physical independent binaries.

**Latest branch status at authoring:** M0 architecture addendum and source-port analysis are docs in Draft PR #467, not merged; no executable B2B module or Dev/Production release. Source YBY role-leak test tracker #93 remains open; do not treat upstream row-scope tests as already accepted. BYL source issue #458 requires metadata/index-first mailbox ingestion; no bulk email body or attachments copied into ERP SQL tables.

## 1. M0 remaining design/UAT (separate estimate)
| WP | Scope | Effective hours | Done criterion |
|---|---|---:|---|
| M0-F | Freeze Owner-reviewed navigation/roles, compact desktop + M14 wireframe, exact schema interface/legacy Company adapter and old-contract exception | 2–4h remaining (excluded from MVP totals) | Owner approves visual+data ownership and implementation contract; frozen documents kept versioned |

## 2. Wave A — manual, operational B2B CRM MVP
| WP | Scope | Effective hours | Acceptance |
|---|---|---:|---|
| M1 | Dedicated level-1 B2B menu, `app/Modules/B2B`, route `/b2b`, `b2b.*` permission registration, B2C unchanged | 4–7h | PC/M14 menu preview, negative `customers.*` vs `b2b.*` cross-access |
| M2 | Selective YBY company/contact behaviors, verified identity resolution and adapter to existing BYL Company; no data or schema overwrite | 5–8h | Synthetic create/link/search; existing Woo Customer and Company remain intact |
| M3 | Port YBY inquiry list concepts, Case core, source dedupe, stage/owner/company link, server pagination and global grid, source-index-only contract | 10–16h | Source replay creates exactly 1 Case; owner-scope + unauthorized direct path/export regression PASS |
| M4 | Complete new BYL manual Case create, follow-up tasks, notes/timeline, owner assignment, due dates, contacted/quoted/won/lost, inquiry KPI from actual Case rows | 10–18h | End-to-end synthetic 100-unit prospective wholesale lead and a different international B2B opportunity, without personal info or mail sending |
| M5 | Scoped schema rehearsal/rollback, test suite, B2C non-regression, PC + M14 Owner functional and visual UAT | 6–9h | UAT PASS, no Production writes, C-end customer/subscribe/order metrics unchanged |
| **Wave A total** | **First useful B2B CRM MVP** | **35–58h** | **May stop here and use manually after separately approved release** |

Wave A checkpoints: M1 early UI gate; M5 final functioning CRM gate. Design freeze must preserve current C-end customer navigation. This functional MVP explicitly does NOT include live business mailboxes, WhatsApp API, website intake, quote PDFs or commercial price policy automation.

## 3. Wave B — optional follow-on business functionality
| WP | Scope | Effective hours | Hard dependency |
|---|---|---:|---|
| M6 | Quotations / versioning / export draft, four-tier distributor interest vs authorization, editable MOQ/price rules, pricing permission and approvals | 10–18h | Wave A, commercial signoff, no automatic exclusivity |
| M7 | Signed and idempotent BYL website-form → ERP intake with source/page/UTM tracking, fail-closed retries, existing WordPress forms preserved | 6–12h | Approved WordPress source contract and scoped Dev tests |
| M8 | Gmail / enterprise-mail read-only message **index-first** link to Case, bounded consented canary | 8–16h | #458 credential owner/admin interactive authorization and compliant provider scopes |
| M9 | Official WhatsApp Business Platform signed webhook/index integration | 4–8h | Approved Meta Business API account and callback setup; personal chats remain manual |
| M10 | B2B funnel dashboard, response SLA, quote/win/loss by owner/source/country/product without Ads-click-as-lead inflation | 4–7h | Canonical verified inquiry/quote/order references |
| M11 | Host and Production readiness, dry-run/rollback/backup verification, negative-role UAT, scoped formal release | 5–8h | Separate Owner Production deployment authorization; real data import is NOT included |
| **Wave B incremental** | **Advanced/connected B2B features** | **37–69h** | **Not a blocker for Wave A** |

## 4. Non-overlapping total and historical estimate
- Wave A basic operational CRM: **35–58 effective engineering hours**.
- Wave B incremental only: **37–69h**.
- Waves A+B combined: **72–127h**.
- Remaining M0 interface/visual review: **2–4h additional**; full new planned remainder **74–131h** including M0. Existing M0 documentation work is completed, not counted again.
- These are *planning effort ranges*, not actual hours or elapsed calendar durations. Owner review waiting, credential/Meta approval delays, CI idle time, and hosting contingencies are excluded. Material external API surprises require re-estimation, not silent overrun.
- `docs/10_Planning_Roadmap/13_PROGRAM_ROADMAP_AND_HOURS.md` carries historical frozen ERP-INQUIRY-M1 **86–150h** estimate (M1.1 Company already completed, old scope/protocol). This Business OS roadmap is a **replacement scoping/forecast** with cross-repo reuse; **DO NOT ADD** the older 86–150h to the new 72–127h and do not count past Company work as new effort. This proposed rebaseline does not silently overwrite document 13 until formal reconciliation.
- Scope control: importer for confidential historical data, outbound email/WhatsApp campaign messaging, subscriptions, billing/payment, and multi-ERP live synchronization are excluded. Requirements outside identified phases need bounded amendments.

## 5. Delivery order and no-duplicate-effort policy
1. Finish M0-F role/menu/data contract + screenshot/wireframe acceptance without rebuilding previously completed docs.
2. Implement M1→M2→M3→M4 sequentially in bounded, dedicated implementation worktrees/branches, using existing YBY source logic as reference and matching BYL app governance.
3. Gate M5 with PC/M14 owner UAT once; routine bug fixes within same WP; do not repeatedly ask for tiny approvals.
4. Only after Wave A passes, activate the next highest-value Wave B package; M6 Quote/Distributor and M7 Website-form intake are higher priority than full mailbox and WhatsApp API.
5. Keep code/data in GitHub canonical, B2B-specific unit/feature/regression harness and shared BYL release safeguards; no direct Production edits.
6. No YBY/ BYL secret/data sync; no READY device restart; no real emails, marketing broadcasts, real orders or pay events; no Production release under this planning-only approval.

## 6. Decision / go-no-go record
- Approved: independent B/C modules and menu; reuse YBY source code selectively.
- Pending product design Owner gate: exact desktop/M14 presentation and B2B permission/Company ownership adapter.
- Pending execution availability: Remote Desktop Commander last returned monthly-usage exhausted; do not retry/reconnect under that service's guidance. Repository planning work can continue but do not claim local CI/UAT completed.
- Deliverables this turn: roadmap document and scope issue update only, not actual B2B implementation or environment deployment.

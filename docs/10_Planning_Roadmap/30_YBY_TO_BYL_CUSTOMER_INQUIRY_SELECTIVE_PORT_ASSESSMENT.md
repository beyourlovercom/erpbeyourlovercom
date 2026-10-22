---
type: cross-repository-port-assessment
project: BYL ERP
work_package: CUSTOMER-INQUIRY-OS-V1-SOURCE-PORT
parent_issue: https://github.com/beyourlovercom/erpbeyourlovercom/issues/466
status: DRAFT / READ-ONLY SOURCE AUDIT / NO RUNTIME DEPLOYMENT
source_repo: beyourlovercom/erpybyworldcom@main
target_repo: beyourlovercom/erpbeyourlovercom@main
date: 2026-10-22
---

# Customer & Inquiry OS — YBY → BYL selective source port

## Owner intent
Reuse proven YBY ERP Customer/Inquiry application behavior in BYL ERP while retaining BYL Customer/Order canonical identities. This is a **code/function port**, **not** copying YBY clients, employees, emails, prices, shipments, orders or Production databases. Do not overwrite BYL Customer module, migrate data, share accounts or create cross-project read connections.

## Code-level facts verified from source and target
Both source and target are PHP 8.3, Laravel 13.8, Inertia 3.2, Vue 3, Vite, TypeScript modular monoliths; architecture is broadly compatible but route, UI, role and schema contracts differ.

### Source ERP `beyourlovercom/erpybyworldcom`
- `app/Modules/Inquiries/{Application,Experience,Infrastructure,Providers}` exists.
- `resources/js/Pages/Inquiries/Index.vue` has list/KPIs, status/stage/source filters, contact identity display, salesperson owner reassignment, Company linking and project navigation.
- `InquiryCaseService::indexPayload` and `EloquentInquiryCaseRepository` provide project-scoped lists and counts (list hard limit 200; no server-side pagination).
- `InquiryIntakeService` supports source values `website`, `whatsapp`, `xiaoman`, `manual`, stable external source identity, payload hashing and deduplication; **it also currently stores source_payload JSON**, which must NOT be inherited for future email/WhatsApp provider content under BYL's index-first rule.
- `InquiryWorkflowService` currently handles assignee and company linking with audit and optional YBY project customer relation refresh. Current source web routes expose list, owner assignment and link-company; this is NOT a complete case create/notes/quote/follow-up pipeline.
- `ProjectCustomerController` renders project-specific customer relationships, verification, unresolved inquiry counts and received amounts. Depends on `BusinessProjects`, `BusinessProjectCompany`, `CustomerReceivedTruthService`, `SalespersonOwnerScope` and a YBY-only project branding configuration.
- YBY migrations introduce `cases`, project foreign key, contact snapshots, source IDs, `business_project_companies`, `company_contacts`, company-identity review and attribution relations; several use project FK and cascading deletes.
- YBY-specific `SyncLocalWordPressInquiriesCommand` reads YBY WP DB `yby_leads` via local connection. NEVER port this connection/command, production credentials or site mapping to BYL.
- Source safety tracker `https://github.com/beyourlovercom/erpybyworldcom/issues/93` is **OPEN** (legacy salesperson read-scope risk). Current source controller/service visibly inject actor-based SalespersonOwnerScope, but Issue is not closed nor a proven full cross-owner/cross-project negative UAT PASS. No reliance on source behavior as sufficient BYL permission security.

### Target ERP `beyourlovercom/erpbeyourlovercom`
- Existing Customers module and `/customers`, `/customers/library`, `/customers/groups`, `/customers/companies` routes / canonical Customer and Company models.
- `resources/js/Pages/Customers/Dashboard.vue` shows existing Customer, Woo Order, Marketing Subscriber and Support metrics; do not erase/replace.
- `cases`, new `contact_identities` and new `channel_messages` not implemented on current target main per issue #458 and code inspection.
- The frozen `15_ERP_INQUIRY_M1_ARCHITECTURE_DATA_API_CONTRACT.md` requires Contact/Case separation, a unified /customers inquiry workspace and explicit exact-match dedup. Follow the index-first proposed amendment `29_CUSTOMER_INQUIRY_OS_V1_INDEX_FIRST_AMENDMENT.md`, subject to the separate owner review.
- BYL ERP Global Grid VI 1.1 and ERP Form Design Standard must govern list/form composition (not copy YBY visual CSS wholesale).

## Selective port decision matrix

| Source component | BYL disposition | Reason |
|---|---|---|
| Inquiries List screen concept and KPI states | **PORT / RESTYLE** | Reuse structure and queries; replace project routing with `/customers/inquiries`; use shared Grid VI; add pagination |
| Source Case stage and handling semantics | **PORT / RECONCILE** | Map into BYL frozen Case status/stage/handling axes, not YBY-only keys |
| Inquiry source dedupe hash & replay guarding | **PORT CONCEPT, REIMPLEMENT** | Provider identity, exactly-once and conflict quarantine; avoid raw source_payload and YBY case-number prefixes |
| Company linking / identity-review interaction | **PORT CONCEPT, REIMPLEMENT** | Existing BYL `companies` and new Contact identity contract; avoid YBY business_project_company tables |
| YBY ProjectCustomerController/BusinessProjects | **DO NOT PORT** | YBY-only brand/project graph, per-project sales/receipt projections |
| YBY Company and Customer schema/migrations | **DO NOT OVERWRITE** | Existing BYL Customer/Company and Woo Order linkage must remain canonical |
| YBY SalespersonOwnerScope | **REVIEW/REDESIGN** | Source Issue #93 unresolved; explicitly enforce BYL row scope in backend queries/actions, not UI |
| YBY WP local read command + source DB config | **DO NOT PORT** | Source-only website and local DB connector; BYL WP handoff via signed scoped API in a future stage |
| YBY CaseAttribution and Sales/Traffic integration | **DEFER** | Dependencies specific to YBY Traffic and Sales modules, need BYL attribution adapter |
| Full follow-up timeline, manual Create Case, Quote/Distributor state | **NEW BYL FEATURES** | Not established as completed source functionality; separate bounded work packages |

## Proposed BYL destination architecture
```text
BYL ERP
  /customers                     EXISTING overview preserved
  /customers/library             EXISTING Customer library preserved
  /customers/companies           EXISTING Company registry preserved
  /customers/inquiries           NEW Cases inbox
  /customers/inquiries/create    NEW operator entered inquiry
  /customers/cases/{case}        NEW detail / follow-up / activity
  /customers/orders              EXISTING canonical Order relation preserved
```
No new top-level navigation, duplicate CRM database, second Company truth, or YBY brand/project dropdown. Implement source-aware distinct Case IDs and brand/source attributes rather than binding existing Customer tables to YBY `business_project_id`.

## Phase plan and acceptance tests (planning ranges, not timesheets)
**P0 — compatibility mapping / ACL and frozen amendment (3–5 engineering hours)**
- [ ] Compare exact target model/route/schema and YBY interfaces; preserve target table names.
- [ ] Resolve index-first vs legacy frozen message-body conflict under owner acceptance.
- [ ] Role matrix salesperson owns, manager scope, admin scope; direct URL/export scope verified with synthetic data.

**P1 — Companies/identity selective port (5–8h)**
- [ ] Reuse existing BYL Company, Customer data; add Contact and explicit company membership only through a separate migration.
- [ ] Adopt source customer identity verification behavior without project-scoped FK or fake company merge.
- [ ] Synthetic fixture create/find/update; no real data mutation.

**P2 — Inquiry inbox / Case core (10–16h)**
- [ ] New BYL Case model, idempotent inbound metadata-only intake and assignment/company-link workflows; append-only audit.
- [ ] Projectless routes for BYL `/customers/inquiries`; existing Customer Overview unaffected.
- [ ] Migrate source screen concepts into canonical Grid VI, server pagination, filters, mobile responsiveness and zero fake stats.
- [ ] UAT sample fixture cases in two countries (no personal details): direct customer and potential distributor.
- [ ] CI: source replay twice = one Case; owner A cannot read owner B case via list/query/direct/exports; original Woo orders unaffected.

**P3 — regression, owner PC + M14 UAT, handoff (5–8h)**
- [ ] Migration dry-run and rollback in isolated dev DB; no production SQL.
- [ ] Permission, localization, list performance, Customer/Company/Order/KOL/Support regressions.
- [ ] Review exact UI on PC and M14 with Owner. No website/production deploy.

**Additional new BYL functionality**: manual Case entry, follow-up tasks, notes/timeline and customer conversation references (estimated separately **10–18h**, subject to schema agreement). Bulk email, official WhatsApp, price-tier authority, Quote/PI, historical data imports and Production rollout are additional gated packages, **NOT included** in the 23–37h basic source-port range.

## Hard guardrails and known blocker
1. SOURCE_CODE_ONLY. Never export/sync/copy YBY actual customer rows, employee identities, Sales data, credentials, message bodies or Production database into BYL without separate explicit user authorization and privacy migration contract.
2. Avoid YBY `business_project_id` cascading FK and broad YBY `companies` schema migrations in BYL.
3. No source/target Production changes, site/plugin changes, mail send, real inquiry writes, migration execution or GitHub main merge from this review. Local implementation follows its separately activated bounded package and CI/UAT gate.
4. Remote Desktop Commander is unavailable due this month's service usage limit (tool stated no retry/reconnect). Source/target GitHub read-only evidence and this Draft contract therefore do not constitute local build/CI/runtime UAT.
5. The source Issue #93 remained open at assessment time; negative authorization tests are mandatory before relying on the port.

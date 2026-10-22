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
Reuse proven YBY ERP Customer/Inquiry application behavior in a new **BYL B2B Business OS** while keeping BYL **B2C Customers** and canonical Orders untouched. Owner confirmed two independent first-level modules on 2026-10-22. This is a **code/function port**, **not** copying YBY clients, employees, emails, prices, shipments, orders or Production databases. Do not overwrite BYL Customer module, migrate data, share accounts or create cross-project read connections.

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
- The frozen `15_ERP_INQUIRY_M1_ARCHITECTURE_DATA_API_CONTRACT.md` originally coupled inquiries to Customers and `/customers`, but a later Owner decision replaces that navigation/module boundary with independent **B2B** and **B2C** top-level business areas. See the versioned amendment `29_CUSTOMER_INQUIRY_OS_V1_INDEX_FIRST_AMENDMENT.md` for this exception and the separate index-first mail storage constraint.
- BYL ERP Global Grid VI 1.1 and ERP Form Design Standard must govern list/form composition (not copy YBY visual CSS wholesale).

## Selective port decision matrix

| Source component | BYL disposition | Reason |
|---|---|---|
| Inquiries List screen concept and KPI states | **PORT / RESTYLE** | Reuse structure and queries under the new B2B menu `/b2b/inquiries`; shared Grid VI, server pagination, no effect on B2C Customers |
| Source Case stage and handling semantics | **PORT / RECONCILE** | Map into BYL frozen Case status/stage/handling axes, not YBY-only keys |
| Inquiry source dedupe hash & replay guarding | **PORT CONCEPT, REIMPLEMENT** | Provider identity, exactly-once and conflict quarantine; avoid raw source_payload and YBY case-number prefixes |
| Company linking / identity-review interaction | **PORT CONCEPT, REIMPLEMENT** | Preserve existing BYL `companies` and an authorized Company Application port; B2B Case/Contact can link to canonical Company without duplicating or overwriting B2C Customer |
| YBY ProjectCustomerController/BusinessProjects | **DO NOT PORT** | YBY-only brand/project graph, per-project sales/receipt projections |
| YBY Company and Customer schema/migrations | **DO NOT OVERWRITE** | Existing BYL Customer/Company and Woo Order linkage must remain canonical |
| YBY SalespersonOwnerScope | **REVIEW/REDESIGN** | Source Issue #93 unresolved; explicitly enforce BYL row scope in backend queries/actions, not UI |
| YBY WP local read command + source DB config | **DO NOT PORT** | Source-only website and local DB connector; BYL WP handoff via signed scoped API in a future stage |
| YBY CaseAttribution and Sales/Traffic integration | **DEFER** | Dependencies specific to YBY Traffic and Sales modules, need BYL attribution adapter |
| Full follow-up timeline, manual Create Case, Quote/Distributor state | **NEW BYL FEATURES** | Not established as completed source functionality; separate bounded work packages |

## Proposed BYL destination architecture
```text
BYL ERP — independent first-level functional areas
  [B2C] app/Modules/Customers
    /customers                   EXISTING consumer customer overview preserved
    /customers/library           EXISTING Woo consumer customer records preserved
    /customers/orders            EXISTING canonical consumer Order projection preserved
  [B2B] app/Modules/B2B          NEW business-owned bounded context
    /b2b                         NEW B2B summary
    /b2b/inquiries               NEW B2B Case inbox
    /b2b/inquiries/create        NEW operator inquiry creation
    /b2b/cases/{case}            NEW detail / follow-up / activity
    /b2b/companies               NEW B2B business-oriented Company projection, no duplicate Company truth
```
**New dedicated top-level `B2B 业务` navigation** with `b2b.*` permissions, separate from the existing B2C `客户` menu with `customers.*`. No duplicate CRM database, second Company truth or YBY brand/project dropdown. Existing physical Company models/tables currently under Customers are a compatibility constraint: re-home only after explicit mapping/migration and no regression. Cross-module operations use narrow authorized query/command DTO ports, not direct database mutations of other modules. Implement source-aware distinct Case IDs and brand/source attributes rather than binding existing Customer tables to YBY `business_project_id`.

## Phase plan and acceptance tests (planning ranges, not timesheets)
**P0 — compatibility mapping / ACL, frozen contract and new B2B menu (4–7 engineering hours)**
- [ ] Compare exact target model/route/schema and YBY interfaces; preserve target B2C tables and `/customers` route, add separate `app/Modules/B2B` and `/b2b` menu contract.
- [ ] Resolve index-first vs legacy frozen message-body conflict under owner acceptance.
- [ ] Role matrix salesperson owns, manager scope, admin scope; direct URL/export scope verified with synthetic data.

**P1 — Companies/identity selective port (5–8h)**
- [ ] Reuse existing BYL Company, Customer data; add Contact and explicit company membership only through a separate migration.
- [ ] Adopt source customer identity verification behavior without project-scoped FK or fake company merge.
- [ ] Synthetic fixture create/find/update; no real data mutation.

**P2 — Dedicated B2B Inquiry inbox / Case core (10–16h)**
- [ ] New BYL Case model, idempotent inbound metadata-only intake and assignment/company-link workflows; append-only audit.
- [ ] Projectless B2B routes `/b2b/inquiries`, `/b2b/cases/{case}` with `b2b.*` server-side ACL; existing B2C Customer Overview and permissions unaffected.
- [ ] Migrate source screen concepts into canonical Grid VI, server pagination, filters, mobile responsiveness and zero fake stats.
- [ ] UAT sample fixture cases in two countries (no personal details): direct customer and potential distributor.
- [ ] CI: source replay twice = one Case; owner A cannot read owner B case via list/query/direct/exports; B2B salesperson cannot see B2C subscriber/Customer API/export; B2C operator cannot see B2B Case API/export; original Woo orders unaffected.

**P3 — B2B/B2C module boundary regression, PC + M14 UAT, handoff (6–9h)**
- [ ] Migration dry-run and rollback in isolated dev DB; no production SQL.
- [ ] Permission, localization, list performance, Customer/Company/Order/KOL/Support regressions; isolated deployments and rollback, no B2B module side-effects on C-side.
- [ ] Review exact UI on PC and M14 with Owner. No website/production deploy.

**Additional new BYL functionality**: manual Case entry, follow-up tasks, notes/timeline and customer conversation references (estimated separately **10–18h**, subject to schema agreement). Bulk email, official WhatsApp, price-tier authority, Quote/PI, historical data imports and Production rollout are additional gated packages, **NOT included** in the 25–40h estimated basic source-port range (updated for new first-level B2B navigation, permission and regression boundaries).

## Hard guardrails and known blocker
1. SOURCE_CODE_ONLY. Never export/sync/copy YBY actual customer rows, employee identities, Sales data, credentials, message bodies or Production database into BYL without separate explicit user authorization and privacy migration contract.
2. Avoid YBY `business_project_id` cascading FK and broad YBY `companies` schema migrations in BYL. Do not install YBY Customers module over the existing BYL B2C Customers module. All B2B code under its own physical module; preserve existing consumer data, subscription permissions and orders.
3. No source/target Production changes, site/plugin changes, mail send, real inquiry writes, migration execution or GitHub main merge from this review. Local implementation follows its separately activated bounded package and CI/UAT gate.
4. Remote Desktop Commander is unavailable due this month's service usage limit (tool stated no retry/reconnect). Source/target GitHub read-only evidence and this Draft contract therefore do not constitute local build/CI/runtime UAT.
5. The source Issue #93 remained open at assessment time; negative authorization tests are mandatory before relying on the port.

## Cross-ERP release independence (Owner-confirmed)

- YBY ERP and BYL ERP each keep their own repository, runtime, database, secrets, staff/roles, integrations and deployment pipeline. Selective source import is a **copy/adaptation of a reviewed code contract**, not a runtime submodule shared over the network or automatic upstream upgrade.
- Within BYL ERP, B2B (`app/Modules/B2B`) and B2C (`app/Modules/Customers`) are independently owned Work Packages and provider registrations. Shared Identity, Product, Order, Finance and UI can expose versioned application DTO/API ports; no direct cross-module Infrastructure imports or writes. **BYL is one Laravel deployment unit**: isolated module delivery means scoped feature activation, explicit migration compatibility, regression gates and rollback; it does not imply physically separate B2B/B2C hosting or binary deployments.
- No subscription consent transfer from B2C to B2B. A B2B organization contact can optionally be linked to an existing consumer identity only after verified identity and access review; status, consent and commercial terms remain separate.
- B2B releases require isolation/negative permission tests, B2C non-regression and rollback. B2C releases reciprocally test their own functionality and B2B boundary but do not trigger automatic B2B publication. Emergency shared-kernel changes require explicit cross-module test matrices.

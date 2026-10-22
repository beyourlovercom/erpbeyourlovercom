---
type: proposed-amendment
project: BYL ERP
feature: B2B Business OS / Inquiry CRM
version: 1.0-proposed
status: DRAFT / NON-PRODUCTION / OWNER-UAT-PENDING
parent_issue: https://github.com/beyourlovercom/erpbeyourlovercom/issues/466
amends: docs/10_Planning_Roadmap/15_ERP_INQUIRY_M1_ARCHITECTURE_DATA_API_CONTRACT.md
related: https://github.com/beyourlovercom/erpbeyourlovercom/issues/458
execution_provider: GPT_WEB
verification_tier: HIGH_RISK_FOR_FUTURE_SCHEMA_IMPLEMENTATION
---

# Customer & Inquiry OS V1 — M0 scope and index-first amendment

## 0. Intent, non-authority and guardrails
This is a PROPOSED ADDENDUM, not a silent rewrite of the Owner-approved frozen ERP-INQUIRY-M1 contract. It documents the original M0 inquiry plan and the Owner's newer 2026-10-22 decision: B2C Customer OS and B2B Business OS must have separate first-level menus, backend module ownership, permissions and independent development/release scopes. The separate B2B module shall selectively reuse YBY ERP code rather than copy its database or overwrite the existing B2C Customers module. Until an explicit scope and visual UAT review occurs, this document grants **no** permission to deploy, execute schema migrations against real databases, read live mailboxes, import customer records, register WhatsApp callbacks, or send quotations/messages. Production orders, invoices, payments, KOL, Support, Woo B2C records and website forms are unchanged.

**Single truth by bounded context:** The new B2B module owns B2B Contact/Inquiry Case/assignment/quote and distributor pipeline. Existing B2C Customers module remains the authority for Woo consumer and newsletter-facing Customer projections. Current legacy `Company` implementation resides in Customers and MUST NOT be overwritten or physically moved in M0; agree a versioned B2B Company port and data ownership before migrations. Existing Orders retains canonical Order truth; B2B and B2C use explicit application contracts rather than cross-module model writes. Existing Order authority remains Orders, and approved quotation workflow will reference canonical order/quote identity.

## 1. Existing repository facts
- Laravel modular monolith, Inertia/Vue 3/TypeScript, permission-based routes and Audit.
- Existing Customers entry is `GET /customers` / `shell.customers`; existing Customer Overview, Library, Groups, Company and Customer Import routes already run.
- No `cases`, `contact_identities`, `channel_messages` feature implementation on main is evidenced. The frozen ERP-INQUIRY-M1 document contains architectural intentions, not deployment confirmation.
- `/customers` and existing customer pages remain the B2C entry. The Owner has approved a separate first-level B2B menu: `/b2b` (overview), `/b2b/inquiries` (inbox), `/b2b/cases/{case}` (detail). A new B2B module/nav permission namespace is required; do NOT repurpose the B2C `/customers` overview or legacy routes.
- Shared data grid contract: `26_BYL_ERP_GLOBAL_DATA_GRID_VI_V1_1.md`; shared form standard `18_BYL_ERP_FORM_DESIGN_STANDARD_V1.md`.
- #458's later, privacy-safe **index-first** directive explicitly conflicts with legacy M1 sections 11/12/13/14/20 storing message `body_text`, `body_html` or raw payload in MySQL.

## 2. Approved-contract reconciliation table
| Area | Frozen M1 position | Proposed V1 resolution | Approval needed |
|---|---|---|---|
| Company, Contact, identity, Case | Separate canonical entities | Reuse model; preserve exact-match identity and no fuzzy merge | Schema review |
| Case state | case_status / case_stage / handling_state | Preserve all three axes; add optional priority and `next_follow_up_at` via reviewed migration | Schema review |
| Case Activity | Append-only notes/timeline | Preserve; manual notes allowed under CRM privacy controls and retention | ACL/retention |
| Email messages | Full bodies, HTML and raw payload columns | **Prohibit bulk provider body/raw-payload replication**; store provider/thread/message indexes and minimal preview metadata only | Explicit frozen-contract amendment |
| Attachments | Body-derived file copies | Provider-only pointer/metadata first; fetch on-demand only with approved policy | Separate phase |
| External identity | Exact email/WhatsApp normalization | Preserve; duplicate case detection by provider ID and thread; conflict review before linking Company | Unit tests |
| Menu/workspace | Original frozen contract had one Customers menu and one Inbox landing | **Owner-confirmed business separation**: keep B2C `/customers` unchanged; add new first-level B2B `/b2b`, inbox `/b2b/inquiries`, cases `/b2b/cases/{case}` | Versioned shell/menu amendment and scoped UAT |
| Site source | Later source-aware ingestion | Signed server-to-server connection only after source accepted/persisted; never count clicks as leads | Integration gate |
| Mailbox authorization | Gmail API/IMAP | Read-only scoped provider where technically enforceable; no ChatGPT connector tokens, no personal account scraping | Account owner/admin consent |
| WhatsApp | Meta Cloud webhook | Official Business Platform, signed verified events; no personal-chat scraping or browser automation | Separate account approval |

## 3. Minimal canonical inquiry fields
Case core (new columns gated by migrations):
- Existing frozen keys: case_number, company_id?, owner_id?, title?, source_channel, source_connection_key?, brand_key?, source_site_key?, case_status, case_stage, handling_state, identity_resolution_state, first_inbound_at, last_inbound_at, attention_since_at?, closed_at?, loss_reason_code?, attribution?.
- Proposed V1 additive fields: `inquiry_kind` (retail, wholesale, authorized_distributor_interest, exclusive_distributor_interest, other), `country_code` (ISO alpha-2 only when verified), `preferred_language`, `priority`, `next_follow_up_at`, `requested_quantity` (positive nullable), `requested_quantity_unit` (normally pieces), `estimated_value_minor` (nullable), `estimated_currency` (ISO-4217 nullable), `assigned_team_id` if a canonical Team identity exists; else do NOT invent it; optional product references live in normalized CaseProduct relation, not free text as Product truth.
- `source_origin`: verified_system vs manual_claim; verification must derive from trusted signed system input, not user-selected menu option.
- `brand_key` and source-site canonical registry must enforce BYL tenant scope, not merely display a string.
- Explicit `case_order_links` / `case_quote_links` to external canonical IDs when later approved; **do not** copy money/fulfillment truth into Case to shortcut joins.

Contact:
- contact_identities exact-normalized email/phone/WhatsApp keys, source verification, primary status; optional Company. No synthetic Company creation from email domain. No automatic Woo conversion.
- If a Company has several Contacts and several Cases, keep their identities/ownership independent until reviewed.

Channel message INDEX only:
- connection_key, provider, provider_message_key, provider_thread_key?, case_id?, sender/recipient minimal indexed metadata, limited subject, received_at, dedupe_key, direction, content_available_at_provider indicator, classification, ingestion status and allowed attribution fields. `body_text/body_html/raw_payload` must remain absent from V1 storage for synced email/WhatsApp messages. User-entered case notes remain ordinary CRM activities with separate access/retention policy.
- Unique `dedupe_key = sha256(channel + NUL + connection_key + NUL + provider_message_key)`; exact idempotence even under retries.

Follow-up and assignment:
- Actions to assign, start processing, mark waiting on customer, add note, create dated follow-up, stage transition, mark Won/Lost/Spam. Append activity and audited mutations; no deletion of history.

## 4. Source taxonomy / truth
Channels: `manual`, `website_form`, `email`, `whatsapp_business`, `social`, `trade_show`, `referral`, `csv_import`, `other`. Each requires `source_origin`, occurrence timestamp and identifying key.
- Website: source_site_key, form submission stable ID, page, source preset; UTM/GCLID only if valid; qualified case only after successful ERP Case storage.
- Email: provider stable message/thread key and authorized mailbox alias. No mail marked read, moved, deleted or sent from indexer.
- WhatsApp: Meta Business Platform signature + message id; personal WhatsApp requires manually registered Case only.
- CSV: preview/validate/owner-map/consent retention, then approved import. No blind historical bulk upsert.
- Business analytics are based on unique persisted Case IDs; clicks, WhatsApp outbound clicks and ads self-reported conversions remain separately labeled *source events*.

## 5. Customer/deal workflow and privacy
```text
Receive verified inquiry or operator-entered inquiry
 -> identity exact-match and ambiguous-match review
 -> create/open one Case; keep other opportunities distinct
 -> assign salesperson by allowed role, never cross-owner visibility
 -> contacted -> qualified -> quoted -> negotiating
 -> won (link canonical Order) / lost (reason) / spam
 -> CRM audit and dashboard metrics
```
Case status, stage and attention handling remain separate. Terminal case is not silently reopened. Returning source message marks the open case as waiting unless workflow rules prohibit continuation.

Permissions: B2C `customers.view`/`customers.operate` remain unchanged for consumer workflows and MUST NOT automatically grant B2B rights. The new B2B namespace uses separately registered/gated permissions such as `b2b.view`, `b2b.operate`, `b2b.view_all`, `b2b.assign`, `b2b.export`, `b2b.quotes_manage`, `b2b.channels_manage`, subject to reviewed RBAC/row-scope rules. Ordinary salesperson sees only their owned/assigned leads; sales manager sees authorized team scope; admin scope explicit. Hard fail-closed for direct-route/API/export access. Index-only mail headers and subject can be personal data; need access control, retention and export audit.

## 6. Distributor classification (not legal authorization)
Lead intent may specify one of: Retail / Wholesale / Authorized Distributor / Exclusive Distributor. It is a request category, not proof of authorized status.
Canonical distributor status requires independently verified company identity, channel evidence, executed agreement, geographic scope, effective dates, annual commitment and authorized approver. Do not auto-grant exclusive rights on a 500-piece purchase or a form checkbox.
Tier/MOQ/price policies should be configurable access-controlled ERP business records (future Quote/Distributor work package). Do not publish confidential prices in source files, fixtures, public docs or GitHub issues.

## 7. UX outline (no fabricated counters)
- `/customers`: retain the existing B2C Customer overview, subscriber/order metrics and consumer navigation unchanged; do not insert B2B-only controls by default.
- `/b2b/inquiries`: data grid default columns Case ID / Received / Company-or-Contact / Country / Channel / Product(s) / Quantity / Stage / Owner / Next follow-up / Last activity; right-side row actions. Compact saved-column preferences, server pagination, mobile-safe filter / search.
- `/b2b/cases/{case}`: context header, company/contact cards, opportunity summary, activity and provider-message timeline, next-step task, safe quote/order references, immutable audit trail.
- `/b2b/inquiries/create`: responsive centered form per ERP Form Design Standard; two columns on desktop, single column on M14. Operators can enter manual lead without inventing source/provider IDs.
- Metric views: waiting now, waiting >24h, overdue follow-up, new/qualified/quoted/won/lost, channel/country/product segmentation; avoid counting same Case twice across multiple messages.

## 8. Milestone acceptance and staged rollout
- **M0:** Contract reconciliation, data dictionary, owner/team scope matrix, desktop/M14 UX proposal; safe synthetic fixtures; review before executable schema design.
- **M1:** Manual Case/Contact create/edit/query; stages, assignment, timeline, follow-up and real persisted metrics, server-side ACL, pagination, atomic idempotence; isolated migration rehearsal/rollback; no Production writes.
- **M2:** WordPress signed source handoff after source persistence; canary, idempotency, source attribution, no duplicate leads.
- **M3:** #458 read-only index-first Gmail and enterprise mail on explicit consent, then official WhatsApp Business Platform; bounded historical preview.
- **M4:** Quote approval, order link, distributor authorization, revenue/lead conversion dashboards.

Tests: permission and cross-owner leakage, duplicate provider/replay, returning customer separate opportunities, exact identity linking, no body/raw-message retention, timezones, SLA clock, form validation, no unverified success conversion, large lists, M14 layout. No mail send, real order, production migration, main merge, READY device restart or live import as part of this documentation PR.

## 9. Owner visual decision gates
1. Approval of inbox and case-detail layout (M14/desktop).
2. Approval of sensitive customer owner/team visibility policy.
3. Approval of index-only messages versus original frozen body retention.
4. Separate credential/connection consent and Production release gates.

## 10. Separate B2B/B2C module architecture (Owner decision 2026-10-22)

- **Physical ownership**: Keep existing `app/Modules/Customers` for B2C. Add `app/Modules/B2B` as the B2B CRM bounded context with its own Experience/Routes, Application, Domain, Infrastructure and Providers. B2B UI components/pages live in a separate `resources/js/Pages/B2B` tree. Shared UI primitives may be reused but must not import business mutations into the other module.
- **Menu/versioning**: add a distinct level-1 `B2B 业务` entry and `b2b.*` permissions through a reviewed, versioned exception/amendment to the frozen Shell/menu contract. No CSS-only hiding, no incidental exposure of B2B data from B2C pages.
- **Tables**: do not create duplicate B2C customer, Woo order, Marketing Subscriber, Product or Finance canonical tables. B2B-specific Case/Contact/Message-index/Company relation may have their own logical tables in the same ERP database with foreign-key/API bridges where approved. Separate physical databases are NOT a prerequisite for module isolation.
- **Legacy Company migration**: target currently has `app/Modules/Customers/Infrastructure/Models/Company.php`; avoid renaming/moving/deleting it or existing `companies` rows before a separately reviewed compatibility strategy. The new B2B module can initially read Company through a narrow authorized Application contract. One Company identity, not a second independent Company database.
- **Cross-module bridge**: read-only or explicit authorized command/query DTO interfaces; no direct importing another module's Infrastructure models, direct writes to another module's tables, or sharing raw subscriber consent, Woo order or customer support records with B2B roles.
- **Independent development with accurate runtime boundary**: one bounded Work Package/branch/PR per module; B2B changes have B2B-focused CI and cross-module non-regression checks. BYL is still a **single Laravel deployable**, so B2B/B2C cannot literally deploy separately as two physical apps; use separately gated feature enablement, backward-compatible migrations, scoped canary and module-specific rollback plans within the shared release. YBY ERP and BYL ERP are fully separate repos/runtimes/DBs/deployments, with **selective source-code port only** and no automatic live cross-ERP synchronization.
- **Role truth**: B2C customer permission never implies B2B prospect permission; B2B salesperson never gains C-end customer exports; admin cross-module access requires explicit assignment. Default-deny for unauthorized APIs, search, export and direct deep links.
- **Acceptance**: a synthetic B2B case shall neither create/modify a B2C Customer or newsletter consent nor modify an Order; B2B Case changes cannot alter Customers UI/metrics; independent menu/permission checks pass on desktop and M14.

## 11. Task record
M0 planning tracked at #466 and mail-specific integration at #458. This file intentionally has no real client records and no price amounts. It is safe for eventual public repository review.

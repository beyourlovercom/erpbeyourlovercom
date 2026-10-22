---
type: proposed-amendment
project: BYL ERP
feature: Customer & Inquiry OS
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
This is a PROPOSED ADDENDUM, not a silent rewrite of the Owner-approved frozen ERP-INQUIRY-M1 contract. It documents the new Owner request to consolidate B2B and channel inquiry operations within the existing BYL ERP Customer OS. Until an explicit scope and visual UAT review occurs, this document grants **no** permission to deploy, execute schema migrations against real databases, read live mailboxes, import customer records, register WhatsApp callbacks, or send quotations/messages. Production orders, invoices, payments, KOL, Support, Woo B2C records and website forms are unchanged.

**Single truth:** Customers module owns Contact, Company, Inquiry Case, assignment, quote relationship and lead pipeline. Existing Customer records remain Customer authority for orders; new Contact is not automatically a Woo customer. Existing Order authority remains Orders, and approved quotation workflow will reference canonical order/quote identity.

## 1. Existing repository facts
- Laravel modular monolith, Inertia/Vue 3/TypeScript, permission-based routes and Audit.
- Existing Customers entry is `GET /customers` / `shell.customers`; existing Customer Overview, Library, Groups, Company and Customer Import routes already run.
- No `cases`, `contact_identities`, `channel_messages` feature implementation on main is evidenced. The frozen ERP-INQUIRY-M1 document contains architectural intentions, not deployment confirmation.
- `/customers` should remain the overview entry in V1; `/customers/inquiries` is the new functional list, `/customers/cases/{case}` the case details. No new top-level sidebar.
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
| Customers dashboard | Proposed replace with Inbox landing | Preserve existing Customer overview at `/customers`; Inbox at `/customers/inquiries` | Owner visual UAT |
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

Permissions: existing `customers.view` read and `customers.operate` mutation; add reviewed permissions such as `inquiries.view_all`, `inquiries.assign`, `inquiries.export`, `inquiries.quotes_manage`, `inquiries.channels_manage` only after RBAC/row-scope review. Ordinary salesperson sees only their owned/assigned leads; sales manager sees authorized team scope; admin scope explicit. Hard fail-closed for direct-route/API/export access. Index-only mail headers and subject can be personal data; need access control, retention and export audit.

## 6. Distributor classification (not legal authorization)
Lead intent may specify one of: Retail / Wholesale / Authorized Distributor / Exclusive Distributor. It is a request category, not proof of authorized status.
Canonical distributor status requires independently verified company identity, channel evidence, executed agreement, geographic scope, effective dates, annual commitment and authorized approver. Do not auto-grant exclusive rights on a 500-piece purchase or a form checkbox.
Tier/MOQ/price policies should be configurable access-controlled ERP business records (future Quote/Distributor work package). Do not publish confidential prices in source files, fixtures, public docs or GitHub issues.

## 7. UX outline (no fabricated counters)
- `/customers`: keep Customer KPI summary; append Inquiry summary after real backend exists.
- `/customers/inquiries`: data grid default columns Case ID / Received / Company-or-Contact / Country / Channel / Product(s) / Quantity / Stage / Owner / Next follow-up / Last activity; right-side row actions. Compact saved-column preferences, server pagination, mobile-safe filter / search.
- `/customers/cases/{case}`: context header, company/contact cards, opportunity summary, activity and provider-message timeline, next-step task, safe quote/order references, immutable audit trail.
- `/customers/inquiries/create`: responsive centered form per ERP Form Design Standard; two columns on desktop, single column on M14. Operators can enter manual lead without inventing source/provider IDs.
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

## 10. Task record
M0 planning tracked at #466 and mail-specific integration at #458. This file intentionally has no real client records and no price amounts. It is safe for eventual public repository review.

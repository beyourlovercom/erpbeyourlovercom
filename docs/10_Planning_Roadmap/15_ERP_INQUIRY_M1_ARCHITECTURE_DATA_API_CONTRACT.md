---
type: architecture-data-api-contract
project: BYL ERP
work_package_id: ERP-INQUIRY-M1.0-CONTRACT-001
milestone: ERP-INQUIRY-M1
version: 1.0
status: OWNER_APPROVED / FROZEN
baseline: main@655655dffd0cd71d5d2dec2872cf597aedf1c104
execution_provider: GPT_WEB
---

# ERP-INQUIRY-M1.0 Architecture / Data / API Contract

## 1. Objective

Freeze the business and technical contract for the B2B Inquiry milestone before Claude implementation begins.

Primary outcome:

```text
Email / WhatsApp inbound
        ↓
normalized exactly-once intake
        ↓
Contact identity resolution
        ↓
Company association when evidence exists
        ↓
Case resolution / creation
        ↓
immutable message + Case timeline
        ↓
Inquiry Inbox
        ↓
Yesterday / Waiting dashboard
```

This contract is design-only. It authorizes no application implementation, Migration execution, external mailbox connection, WhatsApp webhook registration, remote deployment or production access.

## 2. Repository-grounded constraints

The current repository already mandates a Laravel modular monolith with Inertia.js, Vue 3, TypeScript, Tailwind, MySQL, Redis, Queue, Scheduler, server-side authorization and Audit. The forward physical module contract is `app/Modules/**` with Experience → Application → Domain and Infrastructure/Providers boundaries.

The approved level-1 menu already contains `customers` at `/customers`, route `shell.customers`, permission `customers.view`. This M1.0 contract does not add, rename or reorder a level-1/level-2 menu item.

Existing Orders are an independent truth with no Company / Contact / Case foreign keys. ERP-INQUIRY-M1 does not mutate the existing Order schema unless a later separately authorized Work Package explicitly adds Case→Order linkage.

The existing Sync Outbox demonstrates the repository idempotency pattern; Inquiry intake uses its own inbound idempotency contract and does not write inbound messages into `sync_outbox`.

## 3. Module ownership

Canonical business module for this milestone:

```text
app/Modules/Customers/
```

`Customers` owns:

- Company;
- Contact and Contact Identity;
- Case;
- Case Contact membership;
- Case Activity / timeline;
- Inquiry Channel Connection metadata;
- Channel Message and attachments;
- Inquiry Inbox queries and handling actions;
- normalized inbound-message ingestion contract.

Other module responsibilities:

```text
Identity   → users, permissions and assignment actors
Runtime    → Queue / Scheduler execution infrastructure
Audit      → immutable system audit records
Dashboard  → consumes Customers Application query contracts only
Shell      → preserves the approved customers menu contract
Sync       → remains external-sync infrastructure; it does not become Customer/Case truth
```

Cross-module access uses Application Actions / Contracts / Queries. New Domain code must not import another module's Infrastructure model.

## 4. Canonical business model

```text
Company 0..1 ← Contact 1..N
                 │
                 ├──────────────┐
                 ↓              │
               Case N..N ← CaseContact
                 │
                 ├── CaseActivity (append-only)
                 │
                 └── ChannelMessage (source evidence)
                        └── ChannelMessageAttachment
```

A Company is optional at first intake. The system must not fabricate a Company merely because an email or WhatsApp message arrived.

A Contact is the deterministic communication identity owner. A Case is the business opportunity / inquiry. A message is evidence, not the opportunity itself.

## 5. Company contract

Table: `companies`

Required fields:

```text
id BIGINT PK
name VARCHAR(255) NOT NULL
legal_name VARCHAR(255) NULL
website_url VARCHAR(500) NULL
website_domain_normalized VARCHAR(191) NULL
country_code CHAR(2) NULL
registration_number VARCHAR(191) NULL
industry VARCHAR(191) NULL
owner_id BIGINT NULL → users
status VARCHAR(32) NOT NULL DEFAULT 'prospect'
archived_at TIMESTAMP NULL
created_at / updated_at
```

Indexes:

- `website_domain_normalized`;
- `(country_code, registration_number)`;
- `owner_id`;
- `status`.

Company resolution rules:

1. Existing Contact→Company relationship is authoritative when present.
2. Explicit trusted source metadata may identify an existing Company by an exact stored identifier.
3. Email-domain or fuzzy-name similarity alone must not silently merge two Company records.
4. Ambiguous matches remain `needs_review`; no guessed Company is created.

## 6. Contact and identity contract

Table: `contacts`

```text
id BIGINT PK
company_id BIGINT NULL → companies
name VARCHAR(255) NULL
title VARCHAR(191) NULL
country_code CHAR(2) NULL
language_code VARCHAR(16) NULL
owner_id BIGINT NULL → users
status VARCHAR(32) NOT NULL DEFAULT 'active'
archived_at TIMESTAMP NULL
created_at / updated_at
```

Table: `contact_identities`

```text
id BIGINT PK
contact_id BIGINT NOT NULL → contacts
identity_type VARCHAR(32) NOT NULL   // email | phone | whatsapp
raw_value VARCHAR(500) NOT NULL
normalized_value VARCHAR(500) NOT NULL
is_primary BOOLEAN NOT NULL DEFAULT false
verified_at TIMESTAMP NULL
created_at / updated_at
UNIQUE(identity_type, normalized_value)
UNIQUE(contact_id, identity_type, normalized_value)
```

Normalization:

- email: trim + lowercase; do not strip `+tag` automatically;
- WhatsApp / phone: E.164 when provider supplies a valid international identity;
- unsupported or invalid identities are quarantined instead of guessed.

Exact normalized identity matching is the only automatic Contact merge authority in M1.

Cross-channel fuzzy matching is prohibited. Operator-driven merge/split may be added in a later bounded package.

## 7. Case contract

Table: `cases`

```text
id BIGINT PK
case_number VARCHAR(64) UNIQUE NOT NULL
company_id BIGINT NULL → companies
owner_id BIGINT NULL → users
title VARCHAR(500) NULL
source_channel VARCHAR(32) NOT NULL
source_connection_key VARCHAR(127) NULL
brand_key VARCHAR(64) NULL
source_site_key VARCHAR(127) NULL
case_status VARCHAR(32) NOT NULL DEFAULT 'open'
case_stage VARCHAR(32) NOT NULL DEFAULT 'new'
handling_state VARCHAR(32) NOT NULL DEFAULT 'waiting'
identity_resolution_state VARCHAR(32) NOT NULL DEFAULT 'partial'
first_inbound_at TIMESTAMP NOT NULL
last_inbound_at TIMESTAMP NOT NULL
first_action_at TIMESTAMP NULL
last_action_at TIMESTAMP NULL
attention_since_at TIMESTAMP NULL
closed_at TIMESTAMP NULL
loss_reason_code VARCHAR(64) NULL
attribution JSON NULL
created_at / updated_at
```

Human Case number:

```text
CASE-YYYYMMDD-<database-id padded to at least 6 digits>
```

The date portion uses `first_inbound_at` in the configured ERP business timezone. Database `id` remains the internal primary key.

### 7.1 Case status axes

Do not overload one status field with both business progress and operational attention.

`case_status`:

```text
open | won | lost | spam
```

`case_stage` while open:

```text
new | contacted | qualified | quoted | negotiating
```

`handling_state`:

```text
waiting | in_progress | waiting_customer | none
```

New valid Case:

```text
case_status = open
case_stage = new
handling_state = waiting
attention_since_at = first_inbound_at
first_action_at = NULL
```

Terminal Case (`won`, `lost`, `spam`) uses `handling_state = none` and `closed_at`.

### 7.2 Human handling semantics

`Start Processing`:

- sets `handling_state = in_progress`;
- sets `first_action_at` only if currently NULL;
- updates `last_action_at`;
- clears `attention_since_at`.

`Waiting Customer`:

- sets `handling_state = waiting_customer`;
- updates `last_action_at`.

A new inbound message on an open Case:

- sets `handling_state = waiting`;
- sets `attention_since_at = message.received_at`;
- updates `last_inbound_at`;
- does not erase `first_action_at`.

This makes both new inquiries and returning customer replies visible as work needing attention.

## 8. Case Contact membership

Table: `case_contacts`

```text
id BIGINT PK
case_id BIGINT NOT NULL → cases
contact_id BIGINT NOT NULL → contacts
role VARCHAR(64) NULL
is_primary BOOLEAN NOT NULL DEFAULT false
created_at / updated_at
UNIQUE(case_id, contact_id)
```

A Case may involve multiple Contacts. Exactly one primary Contact is preferred but not required while identity resolution is incomplete.

## 9. Case Activity / timeline contract

Table: `case_activities`

Append-only business timeline.

```text
id BIGINT PK
case_id BIGINT NOT NULL → cases
activity_type VARCHAR(64) NOT NULL
actor_type VARCHAR(32) NOT NULL      // system | user
actor_user_id BIGINT NULL → users
related_message_id BIGINT NULL
body_text TEXT NULL
metadata JSON NULL
occurred_at TIMESTAMP NOT NULL
created_at TIMESTAMP NOT NULL
```

Initial activity types:

```text
case_created
message_received
processing_started
owner_assigned
status_changed
stage_changed
waiting_customer
note_added
message_rebound
case_closed
```

Application code must not update or delete existing Case Activity rows.

System Audit remains separate: Case Activity is business chronology; Audit is mutation/security evidence.

## 10. Channel connection contract

Table: `inquiry_channel_connections`

```text
id BIGINT PK
connection_key VARCHAR(127) UNIQUE NOT NULL
channel VARCHAR(32) NOT NULL          // email | whatsapp
provider VARCHAR(64) NOT NULL         // gmail_api | imap | meta_whatsapp | ...
display_name VARCHAR(191) NOT NULL
address_identifier VARCHAR(255) NULL
status VARCHAR(32) NOT NULL DEFAULT 'disabled'
config JSON NULL                      // non-secret provider metadata only
credentials_encrypted LONGTEXT NULL   // server-side encrypted cast only
sync_cursor TEXT NULL
last_synced_at TIMESTAMP NULL
last_error_code VARCHAR(100) NULL
last_error_at TIMESTAMP NULL
created_at / updated_at
```

Secrets:

- never returned to Vue;
- never included in logs / PR evidence;
- never stored in GitHub;
- encrypted at rest when DB persistence is used;
- provider secrets are accessed only in Infrastructure adapters.

Provider-specific schema may use `config` for non-secret IDs instead of adding provider columns to the business tables.

## 11. Channel Message contract

Table: `channel_messages`

The schema is future-compatible with outbound messages, but ERP-INQUIRY-M1 writes inbound messages only.

```text
id BIGINT PK
case_id BIGINT NULL → cases
contact_id BIGINT NULL → contacts
channel VARCHAR(32) NOT NULL
connection_key VARCHAR(127) NOT NULL
direction VARCHAR(16) NOT NULL DEFAULT 'inbound'
provider VARCHAR(64) NOT NULL
provider_message_key VARCHAR(191) NOT NULL
provider_thread_key VARCHAR(191) NULL
message_id_header VARCHAR(500) NULL
in_reply_to_key VARCHAR(500) NULL
dedupe_key CHAR(64) UNIQUE NOT NULL
sender_address VARCHAR(500) NOT NULL
sender_display_name VARCHAR(255) NULL
recipient_addresses JSON NULL
cc_addresses JSON NULL
subject VARCHAR(1000) NULL
body_text LONGTEXT NULL
body_html LONGTEXT NULL
received_at TIMESTAMP NOT NULL
ingestion_status VARCHAR(32) NOT NULL DEFAULT 'accepted'
raw_payload JSON NULL
normalization_version VARCHAR(16) NOT NULL DEFAULT '1.0'
created_at / updated_at
```

`dedupe_key`:

```text
SHA256(channel + NUL + connection_key + NUL + provider_message_key)
```

The same provider message may be delivered repeatedly; only one canonical row may exist.

Raw provider payload may be persisted only after credential/token redaction. Message bodies and allowed source headers are preserved as business evidence; transport secrets are not.

HTML is stored as evidence but must never be rendered unsanitized.

## 12. Attachments

Table: `channel_message_attachments`

```text
id BIGINT PK
message_id BIGINT NOT NULL → channel_messages
provider_attachment_key VARCHAR(191) NULL
filename VARCHAR(500) NULL
mime_type VARCHAR(191) NULL
byte_size BIGINT NULL
storage_disk VARCHAR(64) NULL
storage_path VARCHAR(1000) NULL
sha256 CHAR(64) NULL
fetched_at TIMESTAMP NULL
created_at / updated_at
UNIQUE(message_id, provider_attachment_key)
```

M1 may defer binary download for unsupported providers, but attachment metadata must not be lost.

## 13. Normalized inbound envelope

All channel adapters convert provider payloads into one Application DTO before business mutation.

```json
{
  "schema_version": "1.0",
  "channel": "email|whatsapp",
  "connection_key": "sales-primary",
  "provider": "gmail_api|imap|meta_whatsapp",
  "provider_message_key": "provider-stable-id",
  "provider_thread_key": "optional-provider-thread-id",
  "received_at": "2026-08-23T04:00:00Z",
  "sender": {
    "identity_type": "email|whatsapp|phone",
    "value": "provider-original-value",
    "display_name": "optional"
  },
  "recipients": [],
  "cc": [],
  "subject": null,
  "body_text": null,
  "body_html": null,
  "attachments": [],
  "provider_metadata": {},
  "attribution": {}
}
```

Provider metadata must not contain credentials after normalization.

## 14. Canonical intake action

Application contract:

```text
IngestInboundMessageAction(InboundMessageEnvelope) → InboundMessageResult
```

Transaction order:

1. validate envelope and provider identity;
2. derive `dedupe_key`;
3. return the existing canonical result when already ingested;
4. resolve/create Contact from exact normalized identity;
5. resolve Company only from explicit evidence / existing Contact relationship;
6. resolve existing Case or create a new Case under the rules below;
7. persist Channel Message;
8. update Case inbound/attention timestamps;
9. append Case Activity;
10. write system Audit metadata using IDs/error codes, not message content;
11. commit atomically.

A duplicate delivery must not create a second Contact, Case, Message or Activity.

## 15. Case resolution rules

### Email

Reuse an existing open Case when, in order:

1. `provider_thread_key` exactly matches a known message bound to that Case; or
2. `In-Reply-To` / `References` identifies a known message bound to that Case.

Otherwise create a new Case.

A terminal Case is never automatically reopened. A later message in a terminal thread creates a new Case and records the previous Case reference in activity metadata.

### WhatsApp

A WhatsApp chat identity is not treated as one permanent business opportunity.

Reuse an existing Case only when:

1. the Contact has exactly one open Case eligible for continuation; and
2. its last inbound/action activity is within configurable `INQUIRY_WHATSAPP_CONTINUATION_HOURS`; and
3. there is no explicit Case reference pointing elsewhere.

Proposed default:

```text
INQUIRY_WHATSAPP_CONTINUATION_HOURS = 72
```

When zero or multiple eligible open Cases exist, create a new Case with `identity_resolution_state = needs_review` rather than silently contaminating an unrelated Case.

The Owner may change the default window before M1.7 implementation without changing the surrounding architecture.

## 16. Inbox contract

The approved level-1 Customers menu remains unchanged.

Target route ownership after M1.5:

```text
GET /customers
route name: shell.customers
permission: customers.view
```

`/customers` becomes the Customer / Inquiry workspace landing page and defaults to Inquiry Inbox. The existing menu contract is preserved; no new level-1/level-2 menu item is required.

Tabs / internal pages are outside the governed menu levels and may include:

```text
Inbox
Companies
Contacts
Cases
```

Suggested internal routes:

```text
/customers/inquiries
/customers/companies
/customers/companies/{company}
/customers/contacts/{contact}
/customers/cases/{case}
```

Existing `/customers/orders` remains unchanged.

## 17. Permissions

Existing:

```text
customers.view
```

New proposed permissions:

```text
customers.operate   // start handling, assign, status/stage, notes, rebinding
channels.manage     // configure Email / WhatsApp connections
```

Read and mutation authorization remains server-side.

M1 does not introduce new row-level data-scope policy. Owner/assignment fields are stored for filtering and later scope expansion.

## 18. Operational actions

M1.5 minimum actions:

```text
StartProcessingCaseAction
AssignCaseOwnerAction
SetCaseStageAction
SetCaseStatusAction
SetWaitingCustomerAction
AddCaseNoteAction
RebindMessageToCaseAction
MarkCaseSpamAction
```

Every meaningful mutation appends business Activity and system Audit evidence.

## 19. Dashboard metric contract

Store timestamps in UTC. Day boundaries use a configured ERP business timezone; no metric hardcodes the client browser timezone.

Required metrics:

`yesterday_new_cases`

```text
case first_inbound_at within yesterday business-date window
AND case_status != spam
```

`yesterday_inbound_messages`

```text
accepted inbound channel_messages received within yesterday business-date window
```

`current_waiting_cases`

```text
case_status = open
AND handling_state = waiting
```

`waiting_over_24h`

```text
case_status = open
AND handling_state = waiting
AND attention_since_at <= now - 24 hours
```

Breakdown:

- source channel;
- owner/unassigned;
- first-time unhandled (`first_action_at IS NULL`) versus returning waiting work.

Dashboard obtains these values through a Customers Application query such as `InquiryMetricsQuery`; Dashboard must not read Customers Infrastructure models directly.

## 20. Email intake contract

M1.6 implements one provider adapter against the normalized envelope.

Core architecture remains provider-neutral. Provider selection is a pre-implementation gate based on the actual mailbox:

```text
Gmail / Google Workspace → Gmail API preferred
Other standards-compliant mailbox → IMAP adapter permitted
```

Required identity keys:

- Gmail API: Gmail stable message ID + thread ID;
- IMAP: connection key + mailbox UIDVALIDITY + UID as provider message key; RFC `Message-ID` stored separately when present.

Scheduler / Queue may poll incrementally. Provider cursor state is stored on the Channel Connection.

No mailbox password/token may appear in Vue props, logs, test artifacts or GitHub.

## 21. WhatsApp intake contract

M1.7 uses the official WhatsApp Business / Meta Cloud API path only.

Proposed endpoints:

```text
GET  /api/webhooks/whatsapp/{connectionKey}   // verification challenge
POST /api/webhooks/whatsapp/{connectionKey}  // signed event delivery
```

Hard requirements:

- verify provider signature before normalization;
- dedupe by WhatsApp message ID through the canonical `dedupe_key`;
- normalize `wa_id` to E.164 Contact identity;
- store text and attachment metadata;
- unsupported delivery/status events must not create Inquiry Cases;
- no unofficial scraping / browser automation fallback.

Because Meta requires a public HTTPS callback, China-local M1.7/M1.9 validates webhook logic with signed fixtures/replay. Real Meta callback registration is a milestone remote-release smoke gate, not a reason to turn `erp.beyourlover.com` into a daily development environment.

## 22. Future website/YBY Core compatibility

ERP-INQUIRY-M1 does not implement a website intake endpoint, but the normalized `InboundMessageEnvelope` is the future shared intake boundary.

A future authenticated website adapter may map YBY Core payloads into the same Action without creating a parallel Company/Contact/Case truth.

No public generic intake route is authorized in M1.0.

## 23. Security / evidence rules

- external signature/credential validation happens before business mutation;
- provider secrets remain server-only;
- logs contain IDs, provider keys after redaction, status/error codes and timings, not message bodies or credentials;
- message bodies/attachments are business data and require authenticated authorization;
- HTML is sanitized on render;
- duplicate delivery is exactly-once at the canonical message boundary;
- Case Activities are append-only;
- system Audit remains independent from business timeline;
- malformed/unsupported payloads fail closed and preserve bounded error evidence without creating guessed business records.

## 24. Migration sequence

```text
M1.1 Company
  → companies

M1.2 Contact
  → contacts
  → contact_identities

M1.3 Case
  → cases
  → case_contacts
  → case_activities

M1.4 Inbound Message Foundation
  → inquiry_channel_connections
  → channel_messages
  → channel_message_attachments
  → InboundMessageEnvelope
  → IngestInboundMessageAction

M1.5 Inquiry Inbox
  → Customers route takeover for /customers
  → server queries / filters / operational actions

M1.6 Email Intake
  → selected provider adapter
  → scheduler/queue incremental sync

M1.7 WhatsApp Intake
  → Meta webhook adapter

M1.8 Yesterday / Waiting Dashboard
  → InquiryMetricsQuery integration

M1.9 Local Milestone UAT
  → exact GitHub revision on http://erp.b
```

Migration authoring belongs to the corresponding implementation Work Package. This design PR runs no Migration.

## 25. Existing Order boundary

During ERP-INQUIRY-M1:

```text
orders remains current Order truth
cases remains Inquiry opportunity truth
```

No direct Order foreign key is added by this contract.

A later Case→Order linkage package may add nullable references without replacing either truth. It must account for the five still-open MVP1 Human UAT findings and must be independently authorized.

## 26. Work Package dependency map

Default serial path:

```text
M1.1 → M1.2 → M1.3 → M1.4 → M1.5 → M1.6 → M1.7 → M1.8 → M1.9
```

M1.6 and M1.7 may only run in parallel if their final Work Packages prove no shared provider/config/registry file overlap and pass `PARALLEL-WORK-EXEC-001`. Default is serial execution.

## 27. Planning estimate after contract freeze

Implementation estimate for M1.1–M1.9:

| Package | Scope | Range |
|---|---|---:|
| M1.1 | Company | 6–10h |
| M1.2 | Contact + identities | 8–14h |
| M1.3 | Case + state model + timeline | 12–20h |
| M1.4 | Inbound Message + intake foundation | 12–20h |
| M1.5 | Inquiry Inbox + handling actions | 10–18h |
| M1.6 | Email provider adapter | 12–24h |
| M1.7 | WhatsApp Meta adapter | 12–20h |
| M1.8 | Yesterday / Waiting Dashboard | 6–10h |
| M1.9 | Local regression / milestone UAT support | 8–14h |
| **Total** | **ERP-INQUIRY-M1 implementation** | **86–150h** |

Planning value: `116h`.

Confidence: `MEDIUM-LOW` until the actual Email provider/auth method is selected. The architecture/data contract is not conditional on that provider choice.

## 28. Acceptance criteria for M1.0

M1.0 is ready for Owner approval when all are true:

1. one canonical Customers-domain truth is defined;
2. Company can be absent without fabricating data;
3. Contact identity resolution is deterministic;
4. Message and Case are separate concepts;
5. exact inbound dedupe is defined;
6. Case continuation/new-Case rules are explicit for Email and WhatsApp;
7. operational waiting state is separated from business Case stage/status;
8. yesterday/waiting metrics have exact formulas;
9. Email/WhatsApp adapters share one normalized intake action;
10. menu baseline is preserved;
11. existing Orders remain untouched;
12. local-first / milestone-only remote release remains intact;
13. implementation packages and estimate are bounded.

## 29. Owner decision gates before implementation

Owner review of this contract must explicitly accept or change:

```text
A. Case number format: CASE-YYYYMMDD-<id>
B. WhatsApp continuation default: 72 hours
C. /customers as the default Inquiry Inbox landing page
D. New permissions: customers.operate and channels.manage
E. M1.1–M1.9 implementation sequence
F. 86–150h / 116h planning estimate classification
```

Email provider selection is a later M1.6 preflight gate and does not block approval of the core contract.

## 30. Current verdict

```text
ERP_INQUIRY_M1_0_CONTRACT = OWNER APPROVED / FROZEN
OWNER_ACCEPTED_GATES = A,B,C,D,E,F
PR_85_MERGE_SHA = 02ce87dba5ede14d791b646b7cbe976a12064cae
PRODUCT_IMPLEMENTATION = NOT AUTHORIZED UNTIL BOUNDED CHILD PACKAGE APPROVAL
MIGRATION = NOT AUTHORIZED / NOT RUN
EMAIL_CONNECTION = NOT AUTHORIZED
WHATSAPP_CONNECTION = NOT AUTHORIZED
REMOTE_DEPLOYMENT = NOT AUTHORIZED
NEXT_PRODUCT_WORK_PACKAGE = ERP-INQUIRY-M1.1-COMPANY-001
```

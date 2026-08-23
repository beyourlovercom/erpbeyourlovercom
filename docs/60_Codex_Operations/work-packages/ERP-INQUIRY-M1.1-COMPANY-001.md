---
type: work-package
project: BYL ERP
work_package_id: ERP-INQUIRY-M1.1-COMPANY-001
title: ERP Inquiry M1.1 — Company Master Data Foundation
status: US IMPLEMENTATION + US VERIFICATION COMPLETE (INCLUDING BOUNDED RESTORE UAT FOLLOW-UP) / AWAITING CHINA VERIFICATION AND OWNER RE-UAT
execution_provider: CLAUDE_CODE
design_authority: GPT_WEB
test_provider: CODEX_CLI
milestone: ERP-INQUIRY-M1
baseline: main@7308f81af3660acff3bcec5d843dbe83cfbf14a6
branch: feature/erp-inquiry-m1-1-company-001
estimate: 6–10h; planning value 8h
updated: 2026-08-23
---

# ERP-INQUIRY-M1.1-COMPANY-001

## 1. Objective

Implement the first bounded product slice of the Owner-approved `ERP-INQUIRY-M1` contract: canonical Company master data inside the new `Customers` module.

Primary outcome:

```text
Customers module foundation
        ↓
companies table + Company model/repository/application actions
        ↓
Company list / create / detail / edit / archive
        ↓
server-side permissions + immutable Audit evidence
        ↓
US full verification
        ↓
China exact-SHA verification tier
        ↓
Owner UAT / merge gate
```

This Work Package does **not** implement Contact, Contact Identity, Case, Inbound Message, Inquiry Inbox, Email, WhatsApp, Dashboard inquiry metrics, Orders linkage, or remote deployment.

## 2. Frozen authority

This package implements only the Company portion of the Owner-approved M1.0 contract merged through PR #85 and acceptance sync merged through PR #86.

Frozen Company schema contract:

```text
companies
- id BIGINT PK
- name VARCHAR(255) NOT NULL
- legal_name VARCHAR(255) NULL
- website_url VARCHAR(500) NULL
- website_domain_normalized VARCHAR(191) NULL
- country_code CHAR(2) NULL
- registration_number VARCHAR(191) NULL
- industry VARCHAR(191) NULL
- owner_id BIGINT NULL → users
- status VARCHAR(32) NOT NULL DEFAULT 'prospect'
- archived_at TIMESTAMP NULL
- created_at / updated_at

Indexes:
- website_domain_normalized
- (country_code, registration_number)
- owner_id
- status
```

M1.0 Company resolution invariants remain binding:

1. An existing Contact→Company relationship is authoritative when present.
2. Trusted explicit source metadata may identify an existing Company by an exact stored identifier.
3. Email-domain or fuzzy-name similarity alone must never silently merge Company rows.
4. Ambiguous matches remain review work; no guessed Company is created.
5. M1.1 itself performs no Contact-driven or inbound-message-driven Company resolution because those entities do not yet exist.

## 3. Module / architecture boundary

Create the canonical module root:

```text
app/Modules/Customers/
```

Use the repository's existing modular-monolith conventions:

```text
Customers/
  Application/
  Domain/
  Infrastructure/
  Experience/
  Providers/
```

`Customers` owns Company business truth. Cross-module access must use application contracts/actions/queries rather than importing another module's Infrastructure model.

`ModuleManifest.php` may be updated only to register the new Customers provider. Architecture baseline/hash/file-set tests may be updated only as required by the authorized new module/files.

## 4. Company behavior

Minimum capabilities:

```text
ListCompanies
GetCompany
CreateCompany
UpdateCompany
ArchiveCompany
```

Rules:

- `name` is required.
- `owner_id` is nullable and must reference an existing user when supplied.
- deleting an Owner must never delete a Company; follow existing repository FK safety conventions.
- `status` defaults to `prospect`; do not invent additional workflow semantics beyond the frozen M1.0 contract in this package.
- archive uses `archived_at`; no hard delete of Company rows in M1.1.
- archived rows are excluded from the default Company list unless an explicit archived filter is selected.
- no automatic merge, dedupe, split, or fuzzy matching UI/action is implemented.
- database indexes from the frozen contract are indexes, not new uniqueness rules. Do not add uniqueness constraints not approved by M1.0.

### Website normalization

For M1.1, `website_domain_normalized` is derived only from a valid supplied `website_url`:

```text
parse host → lowercase → remove one trailing dot → store exact host
```

Do not strip arbitrary subdomains and do not use fuzzy domain matching. Invalid non-empty URLs fail validation instead of being guessed/corrected.

### Country / registration

- `country_code`, when supplied, is normalized to uppercase two-character form.
- `registration_number` is stored as operator-entered business evidence after surrounding-whitespace trim; M1.1 adds no country-specific semantic normalization.
- the `(country_code, registration_number)` index supports later exact resolution/review but is not a silent merge authority.

## 5. Permissions

Existing read authority:

```text
customers.view
```

M1.1 introduces the already Owner-approved permission:

```text
customers.operate
```

Authorization contract:

```text
customers.view     → Company index/show
customers.operate  → Company create/update/archive
```

All mutation authorization is server-side. `channels.manage` is not introduced by this package unless required only as a governance catalog placeholder with zero runtime use; preferred sequence is to defer it to the channel package that first needs it.

## 6. Audit

Reuse the existing Audit module. Company creation, material update and archive must produce immutable audit evidence using existing repository conventions.

Audit evidence must identify at minimum:

```text
entity_type = company
entity_id
operation = create | update | archive
actor user id when available
changed field names / safe metadata
```

Do not place secrets or unrelated customer-message content into Audit metadata.

## 7. Internal HTTP / UI surface

M1.1 internal routes:

```text
GET    /customers/companies
GET    /customers/companies/create
POST   /customers/companies
GET    /customers/companies/{company}
GET    /customers/companies/{company}/edit
PUT|PATCH /customers/companies/{company}
POST   /customers/companies/{company}/archive
```

Route names should follow existing repository conventions under `customers.companies.*`.

UI requirements:

```text
Company List
- search/filter sufficient for name, website domain, country/registration and owner
- active/default list excludes archived
- explicit archived filter/view
- create entry visible only when authorized

Company Create/Edit
- name
- legal name
- website URL
- country code
- registration number
- industry
- owner
- status field may remain conservative/defaulted; do not invent business-stage semantics

Company Detail
- all canonical Company fields
- created/updated/archive state
- edit/archive controls only when authorized
```

`/customers` itself is **not** changed to Inquiry Inbox in M1.1; that landing-page change remains M1.5. No governed Level-1/Level-2 menu item is added, renamed, removed or reordered in this package. Owner UAT may enter M1.1 via `/customers/companies`.

## 8. Explicit exclusions

Forbidden in M1.1 unless a later Owner-approved bounded revision says otherwise:

```text
Contact / Contact Identity
Case / Case Activity / Case Contact
Channel Message / Attachment
Inquiry Inbox and handling-state UI
Email provider/auth/IMAP/Gmail work
WhatsApp / Meta Cloud API
Inquiry Dashboard metrics
Orders schema or Company→Order linkage
Logistics / Finance business changes
App Shell menu taxonomy/order/labels
fuzzy Company matching or auto-merge
production/remote database access
remote deployment to erp.beyourlover.com
real credentials, tokens, mailbox data or production dumps
new third-party dependencies without separate approval
```

## 9. Allowed implementation surface

Claude may modify only what is necessary inside these bounded areas:

```text
app/Modules/Customers/**                         NEW
app/Modules/ModuleManifest.php                   provider registration only
database/migrations/*companies*                  Company schema only
resources/js/Pages/Customers/Companies/**        Company UI only
Identity permission catalog/seed/config files    customers.operate only
Audit application integration call sites         Company audit integration only
routes only through Customers module/provider conventions
tests for Customers / permission / architecture / browser E2E
Architecture hash/file-set assertions             bounded sync for authorized files
docs/60_Codex_Operations/...                     this package/work log evidence
```

If implementation requires a product file outside these categories, Claude must stop and report the exact required file/reason. GPT Web decides whether to widen scope.

## 10. Acceptance criteria

Product:

- Companies migration matches the frozen M1.0 field/index contract.
- Customers module is registered through existing module architecture.
- authorized user can list, create, view, edit and archive Company records.
- read-only user can list/view but cannot mutate.
- unauthorized access is rejected server-side.
- website and country normalization follow Section 4 exactly.
- no unapproved uniqueness or fuzzy merge behavior exists.
- default listing excludes archived rows; archived state remains queryable.
- Company create/update/archive emits Audit evidence.
- `/customers` landing behavior and approved menu hierarchy remain unchanged.

Regression:

- Orders, Shell/navigation, Dashboard, Identity/Auth, Audit and existing modules remain green.
- no migration touches existing business tables other than creating Company-related M1.1 schema.
- no remote environment mutation.

## 11. Required US implementation-host verification

Before push/hand-off, US Claude runs the applicable full local suite:

```text
focused Customers backend tests
focused Company frontend/Vitest tests
Architecture suite
permission/auth regression
full php artisan test
full npm test
npm run typecheck
npm run build
npm run typecheck:e2e
Company browser E2E on US local runtime
full repository Browser E2E when feasible under repository policy
git diff --check
git status --short
```

The Work Log must record exact commands, exits, counts, skipped/risky/deprecated facts and raw failures. Claude may fix only failures whose root cause is inside this authorized package; ambiguous/out-of-scope failures return to GPT Web for diagnosis.

## 12. China independent verification

After US implementation verification PASS and push, China Codex checks the exact GitHub revision under the current asymmetric verification policy.

Default China scope:

```text
exact SHA checkout confirmation
working-tree cleanliness
migration/schema smoke on safe local test DB
Customers focused tests
permission/auth smoke
Company route/runtime smoke on http://erp.b
critical Company create → view → update → archive smoke
no tracked mutation by Codex
git diff --check
```

GPT Web may escalate China to the full regression tier if US evidence is incomplete, scope widens, risk rises, or smoke evidence conflicts.

Codex is TEST-ONLY: no product fixes, commits, pushes, merges or deployment.

## 13. Owner UAT

After automated verification passes, Owner UAT on `http://erp.b` must cover:

```text
open /customers/companies
create Company
required-name validation
website/country normalization visible after save
view Company detail
edit Company
archive Company
archived Company disappears from default list
read-only/unauthorized mutation behavior
no regression to existing 客户 → 订单 navigation
```

Owner `PASS` is required before Merge Gate.

## 14. Delivery / merge / deployment gates

```text
BRANCH = feature/erp-inquiry-m1-1-company-001
PR = single lifecycle Draft PR (#89)
CLAUDE_IMPLEMENTATION = COMPLETE (initial scope authorized via Owner comment on PR #89, 2026-08-23; restore/取消归档
                          UAT follow-up authorized via Owner comment "OWNER UAT FOLLOW-UP — COMPANY RESTORE / 取消归档
                          AUTHORIZED (2026-08-23)"; see work log)
US_VERIFICATION = PASS (initial scope and restore follow-up; see
                          docs/60_Codex_Operations/logs/2026-08-23_ERP-INQUIRY-M1.1-COMPANY-001.md)
CHINA_VERIFICATION = NOT RUN
EXISTING_ARCHIVE_OWNER_UAT = PASS
RESTORE_OWNER_RE_UAT = PENDING
MERGE = NOT AUTHORIZED
DEPLOYMENT = NOT AUTHORIZED
```

The same Draft PR is used from Work Package definition through implementation, verification, Owner UAT and Merge Gate. Do not create a second implementation PR for this Work Package.

## 14a. Owner UAT follow-up — Company restore / 取消归档 (bounded remediation)

The Owner's first UAT on `http://erp.b` reported `EXISTING_ARCHIVE_OWNER_UAT = PASS` for the archive flow defined in Section 13, then identified a missing lifecycle gap: an archived Company could not be restored/unarchived. This was authorized as a bounded UAT remediation inside this same lifecycle PR #89 via the Owner comment `OWNER UAT FOLLOW-UP — COMPANY RESTORE / 取消归档 AUTHORIZED (2026-08-23)`, posted at commit `902cc4b153c7daff9bbac8e43d5c2f3046f26c0e`.

Added within the frozen M1.1 scope, no schema change:

```text
POST /customers/companies/{company}/restore   (route: customers.companies.restore, permission: customers.operate)
CompanyService::restore()                      mirrors archive(); clears archived_at; idempotent no-op (no mutation,
                                                 no audit record) when the Company is already active
Audit event company.restored                   before/after evidence, reason code 'company_restored'
UI 取消归档 action                              Archived-only Company list rows and archived Company detail page;
                                                 hidden for active Companies
```

This remediation does not mark Owner UAT PASS for restore. Re-UAT of restore by the Owner remains outstanding.

## 15. Estimate

```text
M1.1 COMPANY = 6–10h
PLANNING VALUE = 8h
CLASSIFICATION = bounded implementation estimate from Owner-approved M1.0 contract
OWNER REVIEW/UAT = gate work, not authorization to deploy
```

## 16. Current verdict

```text
M1.0 CONTRACT = OWNER APPROVED / FROZEN / MERGED
M1.0 ACCEPTANCE SYNC = MERGED BY PR #86
CURRENT MAIN BASELINE = 7308f81af3660acff3bcec5d843dbe83cfbf14a6
M1.1 WORK PACKAGE = DEFINED
M1.1 DRAFT DELIVERY BRANCH = CREATED
CLAUDE IMPLEMENTATION = COMPLETE (bounded M1.1 Company scope; authorized via Owner comment on PR #89;
                          restore/取消归档 UAT follow-up authorized and implemented per Section 14a)
US VERIFICATION = PASS (initial scope and restore follow-up)
CHINA TEST = NOT RUN
EXISTING_ARCHIVE_OWNER_UAT = PASS
RESTORE_OWNER_RE_UAT = PENDING
MERGE = NOT AUTHORIZED
DEPLOYMENT = NOT AUTHORIZED
```

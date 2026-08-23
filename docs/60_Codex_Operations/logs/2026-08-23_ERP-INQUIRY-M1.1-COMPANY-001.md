---
type: work-log
project: BYL ERP
work_package_id: ERP-INQUIRY-M1.1-COMPANY-001
status: PRE-IMPLEMENTATION / OWNER AUTHORIZATION REQUIRED
baseline: main@7308f81af3660acff3bcec5d843dbe83cfbf14a6
branch: feature/erp-inquiry-m1-1-company-001
updated: 2026-08-23
---

# ERP-INQUIRY-M1.1-COMPANY-001 Work Log

## Activation

```text
M1.0_CONTRACT = OWNER APPROVED / FROZEN / MERGED
M1.0_ACCEPTANCE_SYNC_PR = #86 / MERGED
MAIN_BASELINE = 7308f81af3660acff3bcec5d843dbe83cfbf14a6
WORK_PACKAGE = ERP-INQUIRY-M1.1-COMPANY-001
DELIVERY_BRANCH = feature/erp-inquiry-m1-1-company-001
IMPLEMENTATION_PROVIDER = US MAC / CLAUDE CODE
TEST_PROVIDER = CHINA MAC / CODEX CLI
DESIGN_DIAGNOSIS_AUTHORITY = GPT WEB + OWNER
IMPLEMENTATION_AUTHORIZED = NO
MERGE_AUTHORIZED = NO
DEPLOYMENT_AUTHORIZED = NO
```

## Frozen implementation objective

Company master data only: `companies` schema, Customers module foundation, Company list/create/detail/edit/archive, `customers.view`/`customers.operate`, Audit integration, focused/full US validation, China exact-SHA verification and Owner UAT.

Explicitly excluded: Contact, Case, inbound messages, Inquiry Inbox, Email, WhatsApp, Dashboard inquiry metrics, Orders linkage, menu redesign and remote deployment.

## Pre-implementation evidence

```text
CUSTOMERS_MODULE_EXISTS_ON_BASELINE = NO
MODULE_MANIFEST_EXISTS = YES
CUSTOMERS_LEVEL1_MENU_EXISTS = YES
CUSTOMERS_LEVEL1_ROUTE = shell.customers
CUSTOMERS_LEVEL1_PERMISSION = customers.view
M1_1_ESTIMATE = 6–10h / planning 8h
```

## Implementation evidence

Pending explicit Owner authorization.

## Verification evidence

Pending implementation.

## Owner UAT

Pending implementation and automated verification.

## Final gate

```text
CLAUDE_IMPLEMENTATION = NOT AUTHORIZED
US_VERIFICATION = NOT RUN
CHINA_VERIFICATION = NOT RUN
OWNER_UAT = NOT RUN
MERGE = NOT AUTHORIZED
DEPLOYMENT = NOT AUTHORIZED
```

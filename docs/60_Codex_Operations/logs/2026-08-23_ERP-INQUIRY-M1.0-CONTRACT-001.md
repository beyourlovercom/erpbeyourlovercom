---
type: work-log
project: BYL ERP
work_package_id: ERP-INQUIRY-M1.0-CONTRACT-001
status: COMPLETE / OWNER APPROVED / MERGED
execution_provider: GPT_WEB
baseline: main@655655dffd0cd71d5d2dec2872cf597aedf1c104
---

# 2026-08-23 — ERP-INQUIRY-M1.0-CONTRACT-001

## Evidence read from GitHub

- PR #84 merged role-alignment governance at `655655dffd0cd71d5d2dec2872cf597aedf1c104`.
- `AI/CURRENT_CONTEXT.md`: ERP-INQUIRY-M1 is current; M1.0 is next.
- `docs/10_Planning_Roadmap/03_SYSTEM_ARCHITECTURE.md`: modular monolith, `app/Modules/**`, layer/dependency rules.
- `docs/10_Planning_Roadmap/01_MASTER_REQUIREMENTS.md`: Customer/Lead, WhatsApp, Email, idempotency, audit, server-side permissions.
- `menu/README.md` + `menu/APPROVED_MENU.json`: Customers level-1 menu is already approved; level-3/tabs are not governed.
- `database/migrations/2026_08_05_000000_create_orders_tables.php`: current Order truth has no Company/Contact/Case linkage.
- `app/Modules/Orders/Infrastructure/Models/Order.php`: existing Orders integrations remain independent.
- `app/Modules/Identity/Application/AuthorizationKeys.php`: `customers.view` already exists.
- `database/migrations/2026_08_01_000000_create_sync_outbox_table.php`: existing unique idempotency pattern confirmed.

## Design result

Created `docs/10_Planning_Roadmap/15_ERP_INQUIRY_M1_ARCHITECTURE_DATA_API_CONTRACT.md`.

Key decisions proposed for Owner review:

```text
MODULE: Customers
COMPANY: optional at first intake; never fabricated
CONTACT_IDENTITY: exact normalized email / phone / WhatsApp
CASE: business opportunity, separate from Message
CASE_STATUS_AXES: business status/stage separated from handling_state
MESSAGE_DEDUPE: SHA256(channel + NUL + connection_key + NUL + provider_message_key)
EMAIL_CASE_REUSE: exact provider thread / reply chain only
WHATSAPP_CASE_REUSE: exactly one eligible open Case within proposed 72h window
INBOX_ROOT: existing /customers route, preserving menu baseline
DASHBOARD: exact yesterday / waiting / >24h formulas
EMAIL: provider-neutral core; provider selection deferred to M1.6 preflight
WHATSAPP: official Meta Cloud API only
LOCAL_TEST: http://erp.b
REMOTE: milestone-only erp.beyourlover.com
```

## Scope evidence

```text
APPLICATION_CODE_CHANGED: NO
MIGRATION_AUTHORED: NO
MIGRATION_RUN: NO
MENU_BASELINE_CHANGED: NO
DEPENDENCY_CHANGED: NO
EMAIL_CONNECTED: NO
WHATSAPP_CONNECTED: NO
REMOTE_ACCESSED: NO
DEPLOYMENT: NO
```

## Estimate result

```text
M1.1–M1.9 IMPLEMENTATION_RANGE: 86–150h
PLANNING_VALUE: 116h
CONFIDENCE: MEDIUM-LOW until actual Email provider/auth method selected
```

No actual-hours value is fabricated from tool/chat elapsed time.

## Current verdict

```text
CONTRACT_DESIGN: COMPLETE
OWNER_REVIEW_REQUIRED: NO
OWNER_DECISION: APPROVED ALL SIX GATES
PR_85_MERGE_SHA: 02ce87dba5ede14d791b646b7cbe976a12064cae
NEXT: PREPARE ERP-INQUIRY-M1.1-COMPANY-001; IMPLEMENTATION STILL REQUIRES SEPARATE OWNER AUTHORIZATION
CLAUDE_IMPLEMENTATION_AUTHORIZED: NO
CODEX_TEST_REQUIRED: NO / design-only package
NEXT: open Draft delivery PR and request Owner contract decision
```

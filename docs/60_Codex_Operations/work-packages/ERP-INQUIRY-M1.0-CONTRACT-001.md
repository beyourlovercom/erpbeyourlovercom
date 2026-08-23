---
type: work-package
project: BYL ERP
work_package_id: ERP-INQUIRY-M1.0-CONTRACT-001
status: COMPLETE / OWNER APPROVED / MERGED
standard_id: LARGE-PROJECT-EXEC-001
standard_version: 1.2
execution_provider: GPT_WEB
baseline: main@655655dffd0cd71d5d2dec2872cf597aedf1c104
---

# ERP-INQUIRY-M1.0-CONTRACT-001

## Objective

Freeze the architecture, data, API, identity, dedupe, Case-state, channel and metric contracts for `ERP-INQUIRY-M1` before any Claude implementation begins.

## Authority

```text
OWNER_DIRECTION: proceed to GPT-authored ERP-INQUIRY-M1.0 after PR #84 role-alignment approval
DESIGN_AUTHORITY: GPT Web + Owner
EXECUTION_PROVIDER: GPT_WEB
IMPLEMENTATION_PROVIDER: NONE IN THIS PACKAGE
TEST_PROVIDER: NONE IN THIS PACKAGE
```

## Baseline evidence

- PR #84 merged to `main` as `655655dffd0cd71d5d2dec2872cf597aedf1c104`.
- Current architecture is Laravel modular monolith / `app/Modules/**`.
- Current approved Customers menu root is `/customers`, route `shell.customers`, permission `customers.view`.
- Existing Orders truth has no Company / Contact / Case foreign keys.
- Existing Sync Outbox demonstrates repository idempotency patterns but is not inbound-message truth.

## Authorized actions

- read current GitHub `main` and merged evidence;
- define the M1.0 architecture/data/API contract;
- create/update design/governance Markdown on this branch;
- open one Draft delivery PR for Owner review;
- propose implementation sequence and planning estimate.

## Prohibited actions

- no application code;
- no Migration authoring or execution;
- no database mutation;
- no Email mailbox/API connection;
- no WhatsApp webhook registration;
- no dependency changes;
- no menu baseline mutation;
- no remote access or deployment;
- no Production access;
- no Claude/Codex design participation.

## Deliverable

`docs/10_Planning_Roadmap/15_ERP_INQUIRY_M1_ARCHITECTURE_DATA_API_CONTRACT.md`

The contract freezes:

- `Customers` module ownership;
- Company / Contact / Contact Identity / Case / Case Contact / Case Activity;
- Inquiry Channel Connection / Channel Message / attachment schema;
- normalized `InboundMessageEnvelope`;
- `IngestInboundMessageAction` exactly-once behavior;
- Email thread and WhatsApp Case resolution rules;
- separate Case business status/stage and operational handling state;
- Inquiry Inbox route/permission contract;
- exact Yesterday / Waiting metric formulas;
- Email/WhatsApp provider boundaries;
- local-first / milestone-only remote release;
- M1.1–M1.9 dependency sequence and implementation estimate.

## Owner review gates

Owner must explicitly accept or revise:

1. `CASE-YYYYMMDD-<id>` human Case number format;
2. 72-hour default WhatsApp Case continuation window;
3. `/customers` as default Inquiry Inbox landing page;
4. new permissions `customers.operate` and `channels.manage`;
5. serial M1.1→M1.9 default sequence;
6. implementation estimate `86–150h`, planning value `116h`.

Email provider selection remains an M1.6 preflight gate.

## Validation

```text
MENU_IMPACT: NO
APPROVED_MENU_CHECK: PASS / design preserves existing level-1 Customers entry
PRODUCT_CODE_CHANGED: NO
MIGRATION: NO
DEPENDENCY_CHANGE: NO
REMOTE_ACCESS: NO
DEPLOYMENT: NO
```

## Acceptance criteria

- one canonical business truth is defined;
- no placeholder/fabricated Company is required;
- Contact identity resolution is deterministic;
- Message and Case remain distinct;
- inbound dedupe is exactly-once;
- Email/WhatsApp Case continuation rules are explicit;
- waiting-work semantics include returning customer replies;
- dashboard formulas are unambiguous;
- existing Orders remain untouched;
- no menu baseline change is required;
- child implementation packages are bounded before activation.

## PROJECT POSITION

```text
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate [COMPLETE] → MVP1 Existing Operations [IMPLEMENTATION COMPLETE; HUMAN UAT REMEDIATION OPEN] → ERP-INQUIRY-M1 B2B Inquiry Foundation [CURRENT] → ERP-INQUIRY-M1 Local Milestone UAT → Milestone-gated Remote Release to erp.beyourlover.com → MVP1.1 WooCommerce Inbound → MVP1.2 Single Tracking Outbound → MVP1.3 Sync Orchestration → Product / Media → WordPress Product and Image Sync → Content / SEO → Posts / Pages → GA4 / GSC / Cloudflare → Google Docs Publishing → Full Order Workspace → Logistics OS → KOL OS and Social Providers → Inventory and Procurement → Accounting / ROI Expansion → Shopify and Additional Integrations → Production Hardening / Expansion
CURRENT_TRACK_PHASE_MAP: → M1.0 Architecture/Data/API Contract → M1.1 Company → M1.2 Contact → M1.3 Case → M1.4 Inbound Message → M1.5 Inquiry Inbox → M1.6 Email Intake → M1.7 WhatsApp Intake → M1.8 Yesterday/Waiting Dashboard → M1.9 Local Milestone UAT → Owner Milestone PASS → Remote Release
COMPLETED_PHASES: Architecture Foundation; MVP1 implementation; Local Baseline Reconciliation; Roadmap Revision; Execution Role Alignment
CURRENT_PHASE: ERP-INQUIRY-M1.0 ARCHITECTURE / DATA / API CONTRACT
CURRENT_WORK_PACKAGE: ERP-INQUIRY-M1.0-CONTRACT-001
PACKAGE_STATUS: COMPLETE / OWNER APPROVED / MERGED THROUGH PR #85
WAITING_PHASES: M1.1–M1.9 and remote milestone release
BASELINE: main@655655dffd0cd71d5d2dec2872cf597aedf1c104
GITHUB_STATE: PR #84 merged; design branch docs/erp-inquiry-m1-contract-001 active; delivery PR not yet merged
SOURCE_CONFLICTS: NONE WITH EVIDENCE; post-merge canonical synchronization is tracked by ERP-GOV-INQUIRY-M1.0-ACCEPTANCE-SYNC-001
GLOBAL_GATE: PASS
PRODUCTION_DEPLOYMENT_STATUS: NO
NEXT_DEPENDENCY: ERP-INQUIRY-M1.1-COMPANY-001 Work Package definition and separate Owner implementation authorization
EVIDENCE_AS_OF: 2026-08-23
```

## ROADMAP HOURS

```text
CURRENT_PACKAGE_ESTIMATE: 4–8h; planning value 6h; design/governance only
KNOWN_CURRENT_TRACK_REMAINING: M1.1–M1.9 implementation 86–150h; planning value 116h after contract approval; Email provider uncertainty retained
ARCHITECTURE_GATE_REMAINING: 0h / complete
MVP1_TOTAL_ESTIMATE: 122–190h total historical estimate
MVP1_PLANNING_BASELINE: 166h historical total planning value
MVP1_MAXIMUM_ENVELOPE: 192h historical maximum total envelope
MVP1_REMAINING: UNKNOWN WITH EVIDENCE / actual-hours and overlap ledger incomplete
POST_MVP_KNOWN_INCREMENT: approximately 68–120h beyond MVP1 for prior WooCommerce inbound + single outbound estimate
LONG_TERM_CUMULATIVE_RANGE: retained in Program Roadmap source; not summed here
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE
ESTIMATE_CONFIDENCE: M1.0 MEDIUM-HIGH; M1.1–M1.9 MEDIUM-LOW until Email provider selected
ESTIMATE_AS_OF: 2026-08-23
ESTIMATE_METHOD: bounded schema/actions/UI/provider/test packages; no subtraction from incomplete actual ledger
```

## ESTIMATED CODEX HOURS

```text
PREFLIGHT_AND_SCOPE: 1–2h; planning value 1.5h
CORE_EXECUTION: 2–4h; planning value 3h
TEST_AND_VALIDATION: 0.5–1h; planning value 0.75h
REMEDIATION_BUFFER: 0.5–1h; planning value 0.75h
ESTIMATED_EFFECTIVE_HOURS: 6h
ESTIMATE_RANGE: 4–8h
ESTIMATE_BASIS: GPT-authored repository-grounded design package; legacy footer label retained by standard v1.2; actual execution provider is GPT_WEB
OWNER_REVIEW: EXCLUDED
MERGE: EXCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
```
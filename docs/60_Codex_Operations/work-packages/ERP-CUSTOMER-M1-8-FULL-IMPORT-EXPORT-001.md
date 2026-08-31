---
type: work-package
project: BYL ERP
work_package_id: ERP-CUSTOMER-M1-8-FULL-IMPORT-EXPORT-001
status: MERGE_AUTHORIZED
execution_provider: US_CODEX_CLI
verification_tier: HIGH_RISK
baseline: origin/main@63c2fa628815d14bdea1f41063da64c77610b608
owner_uat_required: YES
git_delivery_authorized: NO
merge_authorized: NO
production_deployment_authorized: NO
---

# Customer M1.8 — Full Import / Export

## Dependency
Customer M1.1–M1.7A are merged. PR #131 merged to canonical main at `63c2fa628815d14bdea1f41063da64c77610b608`. This package starts from that exact baseline.

## Owner direction
Owner explicitly directed `推进 Customer M1.8 — Full Import / Export` on 2026-08-31. This authorizes implementation of the frozen M1.8 scope in its isolated worktree. Git delivery, merge and production deployment remain separate gates.

## Objective
Replace the M1.1 reserved 501 Customer import/export endpoints and disabled UI with a real administrator-only CSV/XLSX round-trip engine for canonical Customer master truth, with preview-before-mutation, row-level evidence, atomic confirm and exactly-once confirm semantics.

## Canonical truth in scope
Import/export may write only Customer-owned editable truth:
- Customer identity/master: `name`, `email`, `phone`, `country_code`, `language_code`, `source_type`, `owner_id` through portable `owner_email`, `notes`;
- archived/active state;
- Billing Address;
- Shipping Address;
- M1.7 manual group memberships only.

Read-only projections MUST NOT become import truth: orders, order count, revenue/AOV, latest order, shipments, system/computed segments, Subscriber facts, Support facts, audit history, created/updated timestamps.

## File contract
- Import: `.csv` and `.xlsx` only; first XLSX worksheet only.
- Export: CSV and XLSX using one canonical column schema.
- Provide downloadable empty CSV and XLSX templates from the Import workspace.
- Stable canonical headers are machine-friendly snake_case; parser also accepts bounded Chinese/common aliases for the same canonical fields.
- Unknown columns are ignored with preview warning, never silently interpreted as another field.
- Duplicate columns after alias normalization are blockers.
- CSV must support UTF-8 and UTF-8 BOM; export CSV emits UTF-8 BOM for Excel compatibility.
- XLSX reader/writer must use built-in Zip/XML primitives already compatible with repository policy; do not add a third-party spreadsheet dependency.
- File limit: 10 MiB. Row limit: 5,000 non-empty data rows per batch.

## Canonical columns
Identity/master:
`customer_number`, `name`, `email`, `phone`, `country_code`, `language_code`, `source_type`, `owner_email`, `notes`, `status`, `manual_groups`.

Billing:
`billing_name`, `billing_company`, `billing_phone`, `billing_country_code`, `billing_province_state`, `billing_city`, `billing_address_line_1`, `billing_address_line_2`, `billing_postal_code`.

Shipping:
`shipping_name`, `shipping_company`, `shipping_phone`, `shipping_country_code`, `shipping_province_state`, `shipping_city`, `shipping_address_line_1`, `shipping_address_line_2`, `shipping_postal_code`.

`customer_number` is a stable ERP identity hint and must never directly assign/change the database primary key.
`manual_groups` canonical export value is a JSON array of manual group names; import may additionally accept a simple semicolon-separated list for convenience.

## Partial-update semantics
- Header absent = preserve that field on existing Customer.
- Header present + blank = clear a nullable field.
- `name` is required for CREATE; an existing Customer may preserve name if the column is absent, but a present blank name is a blocker.
- Address subfields follow the same presence rule. No address headers = preserve the whole address. Present fields update/clear only those fields; if the resulting address is entirely blank, delete that address record.
- `manual_groups` absent = preserve memberships. Present = exact replacement of manual memberships; blank = clear all manual memberships. System groups are never imported.
- `status` absent = preserve existing / active on CREATE. Accepted values include active/archived and bounded Chinese/boolean aliases. Import uses the existing archive/restore semantics.
- `owner_email` absent = preserve existing / unassigned on CREATE. Present blank = unassign. Nonblank must identify exactly one active User or block.
- `source_type` absent = preserve existing; CREATE defaults to `csv_import` for either CSV or XLSX bulk file import. Existing source keys remain authoritative; do not create new source enum/schema.

## Identity resolution / idempotency contract
Resolve each row deterministically in this order:
1. valid `customer_number` -> exact existing Customer;
2. normalized nonblank email -> exact unique Customer;
3. when neither above resolves, trimmed nonblank phone -> exact phone match only if exactly one Customer has it;
4. no match -> CREATE, but CREATE must have at least Email or Phone.

Rules:
- No fuzzy name/phone/email matching.
- If provided identifiers resolve to different existing Customers, row is a blocker.
- Ambiguous phone match is a blocker.
- Duplicate stable identities within the same file are blockers.
- Existing email uniqueness remains canonical.
- Same confirmed batch cannot be confirmed twice; second confirm returns 422.
- Re-previewing the same file may create a new batch, but deterministic identity resolution must prevent duplicate creation when the first import created customers with stable Email/Phone.

## Persistent import evidence
Add Customer-owned migration/schema:
`customer_import_batches`:
- id, source_type (`csv|xlsx`), original_filename, checksum;
- preview_total_rows, preview_create_rows, preview_update_rows, preview_unchanged_rows, preview_warning_rows, preview_error_rows;
- status (`preview|confirmed|abandoned`);
- creator_user_id, confirmer_user_id, confirmed_at, timestamps.

`customer_import_rows`:
- id, import_batch_id, source_row_no;
- raw_data JSON, normalized_data JSON, present_fields JSON;
- target_customer_id nullable;
- action (`create|update|unchanged`);
- validation_status (`ok|warning|error`), validation_message nullable;
- timestamps.

No other schema changes are authorized.

## Preview / Confirm contract
Preview:
- upload file -> parse -> normalize -> resolve identities/owners/groups -> validate -> persist batch + rows;
- preview performs NO Customer/address/group mutation;
- response/UI shows totals and row summaries with action + blocker/warning;
- any error row disables Confirm.

Confirm:
- lock batch and require `status=preview`;
- reject when preview_error_rows > 0;
- apply the complete batch in one DB transaction;
- use existing CustomerService create/update/archive/restore truth and Customer-owned address/group semantics;
- on success set status=confirmed, confirmer_user_id, confirmed_at;
- second/replayed confirm on same batch = HTTP 422;
- unexpected row failure rolls back all Customer mutations and leaves no partially confirmed batch.

## Export contract
- Existing `/customers/library/export` becomes real and keeps strict admin export gate.
- Supports `format=csv|xlsx`; default `xlsx`.
- Uses the exact same Customer filter/group/archive semantics as Customer Library (`search`, `country_code`, `source_type`, `archived`, `group`) with no pagination truncation.
- Exports canonical Customer master/address/manual-group fields only; never exports derived projections as if they were importable truth.
- Manual groups export as names; system segment membership is not exported as Customer truth.
- Customer Number is exported for safe round-trip updates.
- Dashboard export means all active Customers; Customer Library export preserves current filters.

## UX contract
Customer Library and Customer Dashboard:
- authorized Import/Export controls become enabled;
- unauthorized users continue to see no controls.
- Import navigates to a dedicated Customer Import workspace.
- Export offers XLSX and CSV without leaving the list.

Customer Import workspace:
- clear CSV/XLSX drop/file area;
- CSV/XLSX template download actions;
- Step 1 Preview button;
- preview summary: total/create/update/unchanged/warning/error;
- compact row table showing source row, identity, action, status, message;
- Step 2 Confirm button disabled until preview exists and has zero blockers;
- confirmed state is explicit and prevents re-confirm;
- recent import batch history (bounded latest 20) with filename, source, counts, status, creator/confirmer/time;
- desktop-first BYL ERP visual standard; no global shell redesign.

## Authorization contract
Import requires BOTH:
- `customers.import`
- `system.permissions.manage`

Export requires BOTH:
- `customers.export`
- `system.permissions.manage`

Do not add new permissions. Existing Customer-order export gate remains unchanged.

## Explicit exclusions
- No WooCommerce automatic Customer sync/backfill.
- No fuzzy/heuristic identity merge.
- No Order import/export redesign.
- No Company import/export.
- No system-segment membership import.
- No Subscriber/Support/Shipment/Marketing import.
- No deletion of Customers through import.
- No remote/provider API calls.
- No production deployment.
- No M1.9 Customer360 closure work.

## Required backend tests
- permissions: hidden/403 without BOTH gates; allowed with both;
- CSV and XLSX parsing of canonical headers and accepted aliases;
- BOM CSV and first-worksheet XLSX behavior;
- max file/row protections and malformed file rejection;
- preview has no business mutation;
- create, update, unchanged classification;
- exact customer_number/email/unique-phone resolution and conflict blockers;
- duplicate file identities block;
- partial update preserves absent fields; present blank clears nullable fields;
- new row requires stable Email or Phone and name;
- owner lookup and unknown/inactive owner blocker;
- country/source/status validation;
- Billing/Shipping create/update/clear/delete semantics;
- manual_groups exact replace, clear, unknown-group blocker, system groups untouched;
- archive/restore behavior;
- confirm atomicity and second confirm 422;
- re-preview/re-confirm stable-key file does not duplicate Customer;
- CSV and XLSX export round-trip through import preview;
- export uses same filters including system/manual groups/archive/search and is not page-limited;
- export excludes derived facts from canonical import schema.

## Required frontend tests
- Library/Dashboard authorized controls enabled; unauthorized remain absent;
- export format actions preserve current filters;
- Import workspace file selection, Preview, summary, blocker-disabled Confirm, successful Confirm, already-confirmed state;
- template buttons and import history visible;
- existing M1.7A Groups UI and Customer Library regressions remain green.

## Required verification — HIGH_RISK
- focused Customer backend/import-export suites;
- Customer M1.1–M1.7A regression suite;
- focused frontend tests + existing Customer Index/Dashboard/Show tests;
- `npm run typecheck`;
- fresh `npm run build`;
- Customer/provider/architecture tests; classify only proven baseline-unrelated drift separately;
- PHP syntax for touched PHP files;
- disposable SQLite `migrate:fresh`, verify new tables/indexes/FKs, rollback latest step, reapply;
- `git diff --check`;
- `public/hot` absent;
- automatically authenticated US-local Owner UAT with bypass disabled, including real CSV and real XLSX preview/confirm/export checks.

## Delivery gates
OWNER_SCOPE_DIRECTION: PASS — Owner explicitly directed Customer M1.8 on 2026-08-31.
IMPLEMENTATION_AUTHORIZATION: GRANTED — this frozen deterministic package may be implemented by US Codex CLI.
GIT_DELIVERY_AUTHORIZATION: GRANTED — Owner explicitly authorized M1.8 commit / push / PR creation on 2026-08-31. Delivery completed in PR #132.
MERGE_AUTHORIZATION: GRANTED — Owner explicitly authorized merge PR #132 on 2026-08-31. Exact-head merge only; production deployment remains separately unauthorized.
PRODUCTION_DEPLOYMENT_AUTHORIZATION: NOT GRANTED
HIGH_RISK_VERIFICATION: PASS — Customer regression 891 assertions; focused frontend 24/24; typecheck/build/diff/public-hot PASS; disposable SQLite fresh/FK/index/rollback/reapply PASS; Architecture exact-main comparison proves the same 3 unrelated baseline failures.
DELIVERY_GATE: PASS — post-UAT delivery verification passed with Customer/Nav/Provider backend 2525 assertions, Customer frontend 34/34, typecheck, production build, git diff check, and public/hot absence. Menu baseline/hash includes the Owner-approved dual Customer/Sync import entries.
DELIVERY_RECEIPT: PR #132 created from business delivery commit `9b65cccb9e1620f7aabc1400b4ba8c8d38c7cad8`; this receipt update is documentation-only and does not change verified product behavior.
OWNER_UAT_STATUS: PASS — Owner explicitly accepted Customer M1.8 Full Import / Export on 2026-08-31 12:35 (+08:00), including the dual Customer/Sync import navigation tweak. Authenticated US-local UAT used bypass=false, isolated SQLite data, the shared `Customers/Import` workspace, and real CSV preview evidence of 2 total / 1 create / 1 update / 0 warning / 0 error.
OWNER_UAT_TWEAK: PASS — Owner directed a duplicate `客户导入` level-2 entry under both Customer and Sync. Runtime navigation now exposes `/customers/import` and `/sync/customers/import` to the same workspace, with the same `customers.import + system.permissions.manage` all-of visibility/server gate and no duplicated import engine. Focused AppShell navigation, approved-menu baseline, and import/export suites PASS.

NEXT_GATE: separate Owner Git delivery authorization.

PROJECT POSITION
PROGRAM_ROADMAP: Customer M1.1–M1.7A completed/merged → Customer M1.8 Full Import/Export [CURRENT] → Customer M1.9 Customer360 Closure [WAITING].
CURRENT_TRACK_PHASE_MAP: Customer M1.1–M1.7A [MERGED] → M1.8 Full Import/Export [CURRENT] → M1.9 Customer360 Closure [WAITING].
COMPLETED_PHASES: Customer M1.1 through M1.7A merged; PR #131 merge commit `63c2fa628815d14bdea1f41063da64c77610b608`.
CURRENT_PHASE: Customer M1.8 Full Import/Export — PR #132 merge authorized; exact-head merge pending.
CURRENT_WORK_PACKAGE: ERP-CUSTOMER-M1-8-FULL-IMPORT-EXPORT-001
PACKAGE_STATUS: IMPLEMENTATION_COMPLETE / HIGH_RISK_VERIFICATION_PASS / OWNER_UAT_PASS / GIT_DELIVERED / PR_132_OPEN / MERGE_AUTHORIZED / DEPLOYMENT_NOT_AUTHORIZED.
WAITING_PHASES: PR #132 exact-head merge; then M1.9 Customer360 Closure.
BASELINE: origin/main@63c2fa628815d14bdea1f41063da64c77610b608
GITHUB_STATE: M1.8 delivery commit `9b65cccb9e1620f7aabc1400b4ba8c8d38c7cad8` plus docs-only delivery/merge receipts pushed; PR #132 merge authorized and awaiting final exact-head CLEAN/MERGEABLE verification.
SOURCE_CONFLICTS: Historical roadmap/current-context/WP documents may still call M1.7A CURRENT or M1.8 WAITING. Current Owner directive plus canonical merged Git main govern; no scope conflict blocks M1.8.
GLOBAL_GATE: HIGH_RISK US-local verification PASS; Owner UAT PASS; Git delivery complete in PR #132; Owner merge authorization granted. Production deployment remains separately gated.
PRODUCTION_DEPLOYMENT_STATUS: NO — no M1.8 deployment/tag/release authorized.
NEXT_DEPENDENCY: commit/push this docs-only merge authorization → verify exact PR head remains CLEAN/MERGEABLE → exact-head merge PR #132 → sync canonical main. No deployment.
EVIDENCE_AS_OF: 2026-08-31; canonical main `63c2fa628815d14bdea1f41063da64c77610b608`, M1.1 reserved import/export contract, current Customer schema/services, Inventory Batch Sync preview/confirm precedent.

ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 14–22h — full CSV/XLSX round-trip, persistent preview/confirm batch evidence, addresses/manual memberships, UI, tests and HIGH_RISK migration verification.
KNOWN_CURRENT_TRACK_REMAINING: M1.8 14–22h + M1.9 UNKNOWN WITH EVIDENCE.
ARCHITECTURE_GATE_REMAINING: UNKNOWN WITH EVIDENCE — unrelated program architecture backlog not recomputed here.
MVP1_TOTAL_ESTIMATE: UNKNOWN WITH EVIDENCE — not recomputed by this Customer package.
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE.
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE.
MVP1_REMAINING: UNKNOWN WITH EVIDENCE.
POST_MVP_KNOWN_INCREMENT: UNKNOWN WITH EVIDENCE.
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE.
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE.
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE.
ESTIMATE_CONFIDENCE: MEDIUM — architecture and existing Batch Sync precedent are known; XLSX writer/round-trip and identity edge cases create bounded implementation uncertainty.
ESTIMATE_AS_OF: 2026-08-31
ESTIMATE_METHOD: Component estimate: parsing/writing 3–5h; preview/confirm/data semantics 5–7h; UI 2–3h; tests/high-risk verification/UAT remediation 4–7h.

ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 1–2h
CORE_EXECUTION: 8–11h
TEST_AND_VALIDATION: 3–5h
REMEDIATION_BUFFER: 2–4h
ESTIMATED_EFFECTIVE_HOURS: 14–22h
ESTIMATE_RANGE: 14–22h
ESTIMATE_BASIS: Frozen full Customer master round-trip + two file formats + persistent batch evidence + high-risk migration gate.
OWNER_REVIEW: INCLUDED — Owner UAT required.
MERGE: EXCLUDED — separately gated.
MIGRATION: INCLUDED — Customer import batch/row evidence only.
MIGRATION_FILE_AUTHORING: INCLUDED.
MIGRATION_EXECUTION: LOCAL/TEST ONLY; production execution excluded.
UAT: INCLUDED — US-local authenticated Owner UAT.
DEPLOYMENT: EXCLUDED — no production authorization.

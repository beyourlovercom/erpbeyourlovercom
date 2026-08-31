# ERP-KOL-M1.4B-INFLUENCER-APPROVAL-PROVISIONING-001

Status: CURRENT / FAKE-PROVIDER OWNER-UAT PASS / CORE SLICE A+B+C+D GREEN / PROVISIONING UI CLOSURE GREEN / LIVE PROVIDER NOT RUN

## Baseline
- Branch: `feature/erp-kol-m1-4b-influencer-provisioning-001`
- Base: `main@91cb05dd2385e81074b814b92a8eabd5d80d9d6e`
- Depends on merged KOL M1.0-M1.4A.
- External execution dependency: Andy Core `v1.5.3` WordPress Connector contract in `25_ANDY_CORE_V1.5.3_WORDPRESS_CONNECTOR_CONTRACT.md`.

## Current implementation evidence
- Slice 1 green: explicit `kol.approvals.manage` / `kol.affiliates.manage`, `kol_manager` preset, canonical `/kol/affiliatewp` Influencer workspace shell, legacy redirect, menu baseline, and permission boundary.
- Slice 2 green: pure Application-layer `KolApprovalPolicyEvaluator` implementing `ALL` / `THRESHOLD` / `NONE`, privileged creator bypass, fail-closed missing/unreliable threshold data, deterministic outcome/reason/evidence, and no persistence/provider side effects.
- Focused Slice 2 matrix: 13 passed / 25 assertions.
- Combined Architecture + evaluator + workspace + M1.4A connector regression: 20 passed / 613 assertions / exit 0; Pint PASS; `git diff --check` PASS; changed-diff secret scan PASS.
- Core Slice A GREEN: portable approval migrations/models, legacy KOL grandfather/backfill, append-only/versioned approval policies, effective-at policy selection, immutable approval decisions, KOL-create approval integration, Manager/Admin manual approve/reject with `kol.approvals.manage`, and strict independence from operational `kols.status`.
- Slice A focused evaluator + approval-service gate: 13 passed / 83 assertions with no assertion failures.
- Slice A migration rehearsal: fresh legacy KOL -> approved + exactly one `legacy_backfill`; backfill rollback/reapply remains exactly one decision; all four Slice A migrations rollback cleanly and reapply cleanly; exit 0.
- Slice A affected regression: Architecture + Identity + KOL + M1.4A connector, 20 passed / 1286 assertions / exit 0; PHP lint/Pint PASS; `git diff --check` PASS; tracked + 16 untracked changed-file secret scan PASS.
- Core Slice B GREEN: portable `kol_affiliate_accounts` mirror, canonical `data.items` Affiliate snapshot contract, complete cursor-chain fetch before transactional apply, exact `(connection_key, affiliate_id)` upsert, binding/ERP-only-field preservation, fail-closed invalid items, empty-snapshot reconciliation, no partial writes after later-page failure, real Influencer workspace rows, and permission-gated manual sync.
- Slice B focused backend gate: 17 tests / 77 assertions / exit 0; no failures. AffiliateWP Vue focused gate: 3/3 PASS.
- Slice B affected backend regression: exit 0 / 1119 assertions / no failures; Architecture exit 0 / 436 assertions; M1.4A connector coverage included in KOL regression.
- Slice B frontend/static gate: typecheck PASS; build PASS; Pint PASS; `git diff --check` PASS. Migration fresh -> rollback -> reapply rehearsal PASS with both unique indexes present after reapply.
- Final changed-file secret scan PASS across 20 tracked changed files plus 21 untracked files.
- Core Slice C GREEN: explicit Manager/Admin Affiliate<->existing-KOL binding + explicit unbound-Affiliate->new-KOL conversion, transaction/lock conflict protection, exact same-binding idempotency, provider status preservation, canonical KOL creation with privileged-creator approval, audit evidence, and permission-aware workspace actions.
- Slice C focused backend gate: 26 warnings / 82 assertions / exit 0 with no failures; Architecture gate 7 passed / 436 assertions / exit 0 after legitimate service/route baselines.
- Slice C affected backend regression: exit 0 / 1143 assertions / no failures. AffiliateWP Vue gate 5/5 PASS; typecheck PASS; build PASS; Pint PASS; `git diff --check` PASS; tracked + 23 untracked changed-file secret scan PASS.
- Slice C provider-mutation audit PASS: binding/conversion does not call `AffiliateWpGateway`, `setAffiliateStatus()` or `provisionAffiliate()` and therefore preserves external application/provider status exactly.
- Core Slice D GREEN: `KolProvisioningService` with persisted stable idempotency key, find-or-create `(connection_key, kol_id)` placeholder row, `in_progress` transition before provider call, gateway `provisionAffiliate()` call, verified read-back (affiliate_id + wordpress_user_id + status=active) required for `active`, explicit `conflict`/`error`/`readback_mismatch` on all failure paths, retry-safe via reused idempotency key, permission gate `kol.affiliates.manage`, `POST /kol/affiliatewp/provision` route, and full provisioning state-machine tests.
- Slice D focused backend gate: 17 tests / 58 assertions / exit 0; Architecture + KOL + Identity regression: 236 passed / 1266 assertions / exit 0; Pint PASS; `git diff --check` PASS; new changed-file secret scan PASS.
- Provisioning UI / Owner-UAT readiness closure GREEN: `/kol/affiliatewp` now projects approved, non-archived KOL provisioning candidates; Manager/Admin can use the existing `POST /kol/affiliatewp/provision` orchestration; Specialist capability remains visible but disabled; `active`, `in_progress`, and externally bound Affiliate states are disabled with explicit reasons; `error`, `conflict`, and `readback_mismatch` remain visible and safe-retryable; provider status, provisioning status, sync status, and sanitized last-error feedback remain separate facts.
- Provisioning UI focused Vue gate: 9/9 PASS. Combined AffiliateWP + Identity frontend regression: 3 files / 16 tests PASS; `npm run typecheck` PASS; `npm run build` PASS (non-blocking Vite chunk-size warning only).
- Provisioning projection/feedback focused backend: AffiliateWP workspace 6 warnings / 74 assertions / exit 0; provisioning route/state tests 19 warnings / 69 assertions / exit 0; local fake-provider fixture integration 2 warnings / 20 assertions / exit 0. Warnings are existing test-environment `file_get_contents(...)` warnings, not assertion failures.
- Final affected KOL + Identity + M1.4A connector + Architecture regression on final working tree: 219 warnings / 1 existing risky / 28 passed / 1317 assertions / exit 0. Architecture standalone: 15 passed / 473 assertions / 1 existing risky / exit 0.
- Owner-UAT fixture/checklist added under `docs/70_UAT/ERP_KOL_M1.4B_OWNER_UAT.md`; `LocalUatAffiliateWpGateway` is selectable only in `local`/`testing` with explicit `ANDY_CORE_UAT_FAKE=true`. Synthetic fake-provider browser Owner UAT PASS: KOL-14B-01..14 all passed on the dedicated local SQLite fixture via real Playwright browser interactions. Retry preserved one account row and the persisted `provision_idempotency_key`; Manager/Specialist gating, fake snapshot sync, state feedback, and disabled M1.4C Coupon all matched the checklist. Live Andy Core/AffiliateWP compatibility remains NOT RUN and must not be inferred from fake-provider UAT.
- Final static gate: scoped Pint 40 changed PHP files PASS; `git diff --check` PASS; changed tracked+untracked text secret scan 51 files / 0 hits PASS. Existing five M1.4B migrations are unchanged by this closure and `migrate:status` reports all five as Ran.
- M1.4C remains out of scope and Coupon stays visible/disabled as `M1.4C 交付`. No commit, push, PR, merge, or Production deploy performed by this closure.

## Objective
Make ERP the normal operating surface for AffiliateWP Influencers and KOL approval/provisioning: synchronize every external Affiliate into ERP, preserve exact provider identity, support explicit Affiliate<->KOL binding, evaluate KOL approval policy deterministically, and idempotently provision approved KOLs into WordPress User + active AffiliateWP Affiliate.

## In scope
- ERP Affiliate/Influencer mirror keyed by exact `connection_key + affiliate_id`;
- provider snapshot sync using the M1.4A `AffiliateWpGateway` only;
- `/kol/affiliatewp` Influencer workspace foundation and manual sync action;
- display all synced Affiliates including unbound/external applications and provider status;
- explicit bind existing Affiliate -> existing KOL;
- explicit convert unbound Affiliate -> new KOL; never fuzzy-match by name/email;
- KOL approval status/facts and immutable approval-decision history;
- approval policy modes `ALL`, `THRESHOLD`, `NONE`;
- Manager/Admin-created KOL immediately approved;
- Specialist-created KOL evaluated against policy;
- prospective-only policy changes; each decision stores policy snapshot + reason;
- approved-KOL provisioning orchestration using M1.4A gateway;
- WordPress User ID/email + authoritative Affiliate ID persisted after verified read-back;
- idempotent retry-safe provisioning and explicit conflict/sync-error states;
- role/scope enforcement for Specialist vs Manager/Admin;
- focused backend/frontend tests and Owner-UAT fixtures using deterministic fake provider data.

## Approval policy contract
### ALL
Every Specialist-created KOL enters pending Manager approval.

### THRESHOLD
- configured thresholds are per social platform;
- if any present, reliable configured platform reaches/exceeds its threshold -> auto-approved;
- if all present configured platform values are below threshold -> manual approval;
- if follower data required for evaluation is missing/unreliable -> manual approval;
- no fuzzy estimate or inferred follower count.

### NONE
Every Specialist-created KOL is immediately approved.

Manager/Admin-created KOLs bypass the Specialist policy gate and are approved immediately, while still recording decision source/reason.

## Provider identity and binding
- external Affiliate identity is exact `connection_key + affiliate_id`;
- AffiliateWP Affiliate ID is authoritative and must never be regenerated locally;
- email/name are display/search hints only, never automatic binding keys;
- every external Affiliate remains visible even if unbound to ERP KOL;
- binding an existing Affiliate to a KOL is an explicit Manager/Admin action;
- converting an unbound Affiliate to a new KOL is explicit and auditable;
- existing WordPress user/email conflicts must surface for Manager decision; no guessed reuse.

## Provisioning state machine
```text
Create/approve KOL
-> approval decision recorded
-> approved
-> create or explicitly confirm WordPress User
-> create/approve AffiliateWP Affiliate idempotently
-> read-back verify authoritative IDs/status
-> active
```

Required failure behavior:
- no partial state may be reported as fully provisioned;
- safe retries reuse stable business idempotency identity;
- provider timeout/5xx may retry only according to M1.4A bounded transport rules;
- conflict responses stop automation and surface operator action;
- sync/read-back mismatch is explicit and auditable;
- secrets/raw signed headers are never persisted in business audit.

## ERP workspace
Canonical path: `/kol/affiliatewp`

M1.4B implements the **Influencer** mode only. Minimum rows expose:
- Influencer/display identity;
- authoritative Affiliate ID;
- WordPress User ID/email when known;
- ERP KOL binding;
- provider Affiliate status;
- ERP provisioning/sync state;
- last successful sync / last error state;
- explicit actions allowed by role.

The Coupon mode belongs to M1.4C and must not be implemented here.

## Permissions
### Specialist
- create/manage own KOLs under existing Owner scope;
- see own KOL approval/provisioning state;
- cannot approve/reject KOLs;
- cannot bind arbitrary external Affiliates to other KOLs;
- cannot edit provider Affiliate truth directly.

### Manager/Admin
- see all KOL/Affiliate mirror records;
- approve/reject pending KOLs;
- configure approval policy;
- explicitly bind/unbind external Affiliate mappings where contract permits;
- resolve provisioning conflicts and retry safe connector operations.

## Not in scope
- no Coupon sync/provisioning (M1.4C);
- no Referral/commission sync (M1.4D);
- no commission payout workflow (M1.4E);
- no paid-collaboration approval center/dashboard (M1.4F);
- no PayPal API;
- no direct WordPress/AffiliateWP database access;
- no fuzzy external identity matching;
- no automatic mutation of external Affiliate application statuses except approved-KOL provisioning explicitly required by this WP;
- no Production deployment.

## Live compatibility gate
Before Owner UAT against a real site, the matching Andy Core v1.5.3 Connector must pass exact contract compatibility for health, Affiliate snapshot and Affiliate provisioning/status operations. Until then, implementation verification may use deterministic fake/local provider fixtures; do not claim live-provider PASS.

## Verification tier
Tier B because this package creates external identities, approval authority and remote provisioning mutations.

Required gate:
- migrations and rollback/re-run checks;
- policy evaluator matrix tests for ALL/THRESHOLD/NONE;
- role/Owner-scope authorization tests;
- Affiliate snapshot sync identity/idempotency tests;
- bind/convert conflict tests;
- provisioning success, timeout, provider error, conflict and read-back mismatch tests;
- focused Vue/route tests for `/kol/affiliatewp` Influencer workspace;
- affected KOL regression;
- PHP lint/Pint and frontend type/build where touched;
- secret/credential leak scan;
- `git diff --check`;
- exact-SHA independent verification before merge.

## Delivery gate
- one Work Package / one branch / one PR;
- Provider routing follows Owner-approved Execution Role Model v1.2 (`8288bd43fb55c3ad38e96acaaf66e586d20e180b`): GPT+US DC may implement bounded approved slices; US Claude Code remains primary for complex schema/sync/provisioning implementation;
- `ONE_ACTIVE_IMPLEMENTATION_PROVIDER_PER_WORKTREE` is mandatory;
- China Codex CLI verifies/tests/evidence only;
- do not pull files from superseded monolithic M1.4 prototype branch;
- no merge without explicit Owner authorization;
- no Production deployment.

## Followed by
`ERP-KOL-M1.4C-COUPON-SYNC-PROVISIONING-001`.

## Frozen implementation map
Existing `kols.status` remains the KOL operational/business status and MUST NOT be reused as approval state. M1.4B adds a separate approval projection plus append-only decision history.

### Persistence
Use bounded domain tables/projections rather than raw provider payload storage:
- `kol_affiliate_accounts`: provider mirror + ERP binding/provisioning projection. Exact external unique key is `(connection_key, affiliate_id)` when `affiliate_id` exists. Store only bounded fields needed by ERP: Affiliate ID, WP User ID/email, display/email facts, provider status, KOL binding, sync/provisioning state, timestamps, stable idempotency identity, and sanitized error code/message.
- `kol_approval_policies`: append-only/versioned policy records. A policy edit creates a new effective policy; it never rewrites the policy used by historical decisions.
- `kol_approval_decisions`: append-only decision/event history containing KOL, outcome, actor/source, policy mode, full threshold snapshot, reason code/text and decision timestamp.
- `kols`: add only the current approval projection needed for efficient operator UX (for example `approval_status` and approval-updated timestamp); historical authority remains `kol_approval_decisions`.

Do not persist connector secrets, signed headers, raw sensitive request bodies, or arbitrary provider payload blobs.

### Complex-core schema/state contract
Use portable string columns rather than database-native enums; application validation owns the allowed values.

`kols` approval projection:
- add `approval_status`: `pending | approved | rejected`;
- add nullable `approval_updated_at`;
- pre-M1.4B KOLs are grandfathered as `approved` and receive one append-only `legacy_backfill` decision so the new policy is not applied retroactively.

`kol_approval_policies` is append-only/versioned:
- `id`, monotonically increasing `version` (unique), `mode`, `thresholds` JSON, `effective_at`, nullable `created_by`, `created_at`;
- `mode` is exactly `ALL | THRESHOLD | NONE`;
- THRESHOLD JSON is a platform=>non-negative-integer map using existing canonical KOL platform keys; ALL/NONE store `{}`;
- current policy is the latest effective version; edits insert a new row and never update prior rows.

`kol_approval_decisions` is append-only:
- `id`, `kol_id`, nullable `policy_id`, `outcome`, `source`, nullable `actor_user_id`, nullable `policy_mode`, `threshold_snapshot` JSON, `reason_code`, nullable `reason_text`, `decided_at`, `created_at`;
- `outcome`: `pending | approved | rejected`;
- `source`: `specialist_policy | privileged_creator | manager_manual | legacy_backfill`;
- every automatic/pending/manual decision appends a row; historical rows are never rewritten.

`kol_affiliate_accounts` provider mirror/provisioning projection:
- identity/binding: `connection_key`, nullable authoritative `affiliate_id`, nullable `kol_id`;
- provider facts: nullable `wordpress_user_id`, `wordpress_user_email`, `display_name`, `email`, `provider_status`;
- origin/state: `origin`, `sync_status`, `reconciliation_status`, `provisioning_status`;
- retry/reconciliation: nullable `provision_idempotency_key`, `last_synced_at`, `last_successful_sync_at`, `last_error_code`, `last_error_message`, timestamps;
- unique `(connection_key, affiliate_id)` and unique `(connection_key, kol_id)`; nullable keys allow unbound provider rows while preventing one KOL from binding to two Affiliates on the same connection;
- `origin`: `external_sync | erp_provisioned | erp_conversion`;
- `sync_status`: `never_synced | synced | error`;
- `reconciliation_status`: `present | missing_from_latest_snapshot`;
- `provisioning_status`: `not_requested | pending | in_progress | active | conflict | error | readback_mismatch`.

Provisioning transition contract:
- approved KOL with no Affiliate creates/reuses one `(connection_key, kol_id)` placeholder row with `affiliate_id = null` and one persisted stable `provision_idempotency_key`;
- `not_requested/pending/error -> in_progress`; provider conflict -> `conflict`; transport/provider failure -> `error`; read-back mismatch -> `readback_mismatch`;
- only verified authoritative WP User ID + Affiliate ID + expected active provider status may transition to `active`;
- retries reuse the same row and same idempotency key and MUST NOT generate a second Affiliate.

Snapshot reconciliation contract:
- upsert only by exact `(connection_key, affiliate_id)` and never by email/name;
- preserve explicit `kol_id` binding during later provider snapshots;
- mark `present` for every row observed in a successful snapshot;
- only after a complete full snapshot (all cursor pages succeed) may previously known unseen rows become `missing_from_latest_snapshot`; failed/partial/incremental sync MUST NOT mark rows missing or delete them.

### Manager authority
Do not infer KOL Manager authority from generic `kol.operate` or Owner scope. Add explicit permission boundary/boundaries for KOL approval/Affiliate administration and expose a `kol_manager` role preset if needed by the existing Identity architecture. Administrator receives all permissions through the existing catalog. KOL Specialist keeps owner-scoped operating rights but receives no approval/policy/external-binding authority.

### Sync algorithm
Affiliate snapshot sync upserts only by exact `(connection_key, affiliate_id)`. Email, username and display name are never match keys. Existing explicit KOL binding survives later snapshots. A missing item in one snapshot must not hard-delete a prior mirror row; provider status/reconciliation state is surfaced explicitly.

### Approval evaluation details
THRESHOLD evaluates the latest normalized follower metric for active configured social accounts. A non-null normalized follower count is reliable for V1; raw/unparsed-only follower text, absent metric, absent configured platform data, or otherwise non-comparable data cannot auto-approve and therefore requires manual approval. Reaching any configured threshold is sufficient for auto-approval; if every comparable configured value is below threshold, manual approval is required.

### Binding / conversion details
- Binding an existing external Affiliate to an existing KOL is explicit Manager/Admin action and must reject an Affiliate already bound elsewhere or a KOL already bound to another Affiliate for the same connection. Repeating the exact same Affiliate->KOL binding is idempotent.
- Bind requires authoritative `affiliate_id`, an existing non-archived KOL, and `kol.affiliates.manage`; it changes only ERP binding facts and never mutates provider status or calls a provider mutation endpoint.
- Converting an unbound external Affiliate to a new KOL is explicit Manager/Admin action and records provenance. Conversion requires an explicit active ERP owner; display name may be supplied by the operator or deterministically fall back to provider display name/email/Affiliate ID.
- Conversion creates the KOL through canonical `KolService::create` with operational defaults `status=active` and `relationship_stage=prospect`, provider email as contact email when present, and therefore receives the normal Manager/Admin privileged approval decision.
- Conversion then binds the same mirror row and changes only its ERP origin to `erp_conversion`; repeated conversion after a successful bind is rejected and cannot create a second KOL.
- Existing external Affiliate application/provider status is preserved exactly. Bind/convert MUST NOT silently approve a pending/rejected external Affiliate application.
- ERP KOL approval and provider Affiliate status are separate facts. Bind/convert operations are auditable.

### Provisioning details
For an approved ERP KOL that has no authoritative external Affiliate, use `provisionAffiliate()` with one persisted stable idempotency key for that KOL + connection. Success is accepted only after response/read-back facts identify the same ERP provision identity and return authoritative WP User ID, Affiliate ID and expected active provider status. Timeout/provider error/conflict/read-back mismatch leaves an explicit non-active provisioning state and is safe to retry without generating another Affiliate.

### UI behavior
`/kol/affiliatewp` is an ERP operational table, not a duplicate WordPress admin. Default mode is Influencer. Unbound Affiliates remain visible. Operator actions are state-aware and permission-aware; unavailable capabilities remain visible/disabled where consistent with ERP UI standards rather than disappearing. Coupon implementation remains M1.4C.

## Recovery / Rebase Gate — 2026-08-31

`KOL_M1.4B_RECOVERY_REBASE_GATE = PASS`

- pre-rebase HEAD: `91cb05dd2385e81074b814b92a8eabd5d80d9d6e`;
- rebased authority: `origin/main@9496144f825301c110c4ae688b353cee5b4f868b`;
- recovery patch/archive was retained through live-provider verification, then deleted after final green CI;
- recovery branch `recovery/kol-m1-4b-pre-rebase-20260831-065132` was deleted after final green CI;
- pre-rebase stash was dropped only after live-provider PASS and final green CI confirmed the rebased checkpoint;
- original 46 dirty entries / 29 untracked files were protected before rebase;
- only rebase conflict was the frozen Architecture baseline file;
- Connector M1 transport/TLS behavior in `HttpAffiliateWpGateway` was preserved;
- `ConnectorResponse` moved to the M1.4B Application boundary and the merged Connector settings test fake was aligned to that contract;
- no M1.4C Coupon/Referral/Payout implementation was introduced.

Verification after rebase:
- focused M1.4B + Identity + Connector compatibility: exit `0`, `643 assertions`;
- standalone Connector settings + shared gateway compatibility: exit `0`, `145 assertions`;
- frontend `vue-tsc --noEmit`: PASS;
- Vite production build: PASS;
- fresh temporary SQLite migration with all five M1.4B migrations plus Connector settings migration: PASS;
- changed/untracked PHP lint: `41` files, `0` failures;
- `git diff --check`: PASS; sensitive-literal scan: `0` hits;
- KOL Application cross-module Infrastructure import scan: CLEAN.

Architecture standalone is isolated to one latest-main baseline failure: 12 `APP-001` violations in Audit/Customers/Logistics/Shell/Support files. Every offending file is byte-for-byte unchanged from `origin/main`; zero KOL files appear in the violation set. Classification: `BASELINE_MAIN_APP_001_DEBT`, not M1.4B regression.

## Live Provider UAT Gate — 2026-08-31

`KOL_M1.4B_LIVE_PROVIDER_UAT_GATE = PASS`

- authority: Local ERP on US Mac + Andy Core v1.5.3 / AffiliateWP on Windows Local WordPress;
- signed health: PASS, contract v1, AffiliateWP ready, writable;
- full real Affiliate snapshot: 1,758 provider rows synced, 0 errors, 0 marked missing;
- live UAT used one synthetic `@example.test` KOL only; no production data was mutated;
- first provision attempt exposed a request-contract mismatch before Provider mutation: Andy Core v1.5.3 requires exactly six provision fields;
- ERP provisioning now deterministically sends `preferred_username`, `requested_affiliate_status=active`, and `payment_email` in addition to the existing KOL identity fields;
- second live attempt created one Affiliate + one WordPress User and exposed the v1.5.3 read-back keys `wp_user_id` / `affiliate_status`;
- ERP read-back now accepts the canonical v1.5.3 keys while retaining compatibility with the existing test aliases;
- retry reused the same persisted idempotency key and returned the exact same Affiliate and WordPress User IDs;
- ERP retained exactly one `(connection_key, kol_id)` provisioning row; no duplicate Affiliate was created;
- a complete post-provision snapshot preserved `origin=erp_provisioned`, the explicit KOL binding, `active`, and `present` reconciliation state;
- exact rollback removed only the synthetic Affiliate/User, its Connector binding/idempotency row, ERP test KOL/account/decision, and temporary Manager role;
- post-cleanup Provider count = 1,758 and ERP mirror count = 1,758; test business objects = 0;
- Connector secret was never printed or committed; temporary encrypted-transfer files/port were removed;
- Production deployment: NOT RUN; merge: NOT AUTHORIZED.

Final regression after live fixes:
- focused M1.4B + Identity + Connector compatibility: `172 passed / 649 assertions / exit 0`;
- provisioning-specific suite: `20 passed / 75 assertions`;
- frontend typecheck: PASS; production build: PASS;
- Architecture standalone remains isolated to the same 12 non-KOL `APP-001` latest-main violations;
- `git diff --check`: PASS; changed PHP lint: PASS;
- real credential/private-key/temp-IP/key scan: 0 hits (the documented synthetic `@example.test` marker is intentionally retained as UAT classification evidence).

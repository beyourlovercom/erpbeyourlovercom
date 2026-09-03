---
type: work-package
project: BYL ERP
work_package_id: ERP-KOL-V1-PRODUCTION-ENABLEMENT-001
status: READINESS_RECONCILED_WAITING_OWNER_REAL_USER_MAPPING_AND_PRODUCTION_MUTATION_GATE
verification_tier: HIGH_RISK
baseline: origin/main@ec725cef57762f7ddbdf067bfcfaf1bbce9a2f78
production_mutation_authorized: NO
merge_authorized: NO
---

# KOL V1 — Production Enablement / Real Data Activation

## Goal
Move the already closed KOL V1 product from verified code/UAT state to a production-activation-ready state without creating a second deployment/runtime system and without executing Production mutation before a separate Owner gate.

## Dependency
- Shared runtime foundation: `ERP-PRODUCTION-RUNTIME-FOUNDATION-M2.0A-001`.
- Current dependency status: `ACTIVE / HOSTING_TARGET_REQUIRED`.
- `erp.beyourlover.com` has no approved Production origin/runtime yet at activation start.
- KOL activation MUST reuse the shared Production runtime, database, Redis, queue, scheduler, TLS, backup and release mechanism established by M2.0A.
- This WP MUST NOT invent KOL-specific SSH/deploy/DNS/runtime infrastructure.

## Baseline
- KOL V1 cross-phase closure merged by PR #143.
- Exact activation baseline: `main@01d11061c7d02fc6275353deee59e69dbef62216`.
- Branch: `feature/erp-kol-v1-production-enablement-001`.
- Worktree: `/Users/a/AI/007/worktrees/erp-kol-v1-production-enablement-001`.
- KOL V1 business implementation is closed; this package is activation/readiness first.

## Frozen readiness scope
1. Inventory all KOL schema migrations and rehearse them on disposable MySQL/SQLite as appropriate.
2. Produce an exact Production migration-status / pending-list capture procedure; do not run Production migrations yet.
3. Verify KOL permission keys and role presets required for Specialist / Manager / Finance.
4. Define real-user assignment and KOL responsibility mapping for `owner_id`, `approval_manager_id`, `finance_owner_id`.
5. Verify Connector configuration contract and read-only health/snapshot readiness without printing secrets.
6. Define real-data reconciliation checks for Affiliate, Coupon, Referral, Payout mirrors and canonical Order links.
7. Freeze all external write surfaces until explicitly enabled: Affiliate provision/status, Coupon provision, Payout complete/write-back.
8. Define Production backup, rollback and smoke procedure as a child of the shared M2.0A runtime release contract.
9. Stop at `KOL_PRODUCTION_ACTIVATION_READY` until Production runtime and mutation gates are separately authorized.

## KOL schema inventory
Activation baseline contains 23 KOL-domain migrations:
- `2026_08_27_210000` through `2026_08_28_200000`: KOL master / CRM / collaboration / content foundation.
- `2026_08_29_100000` through `2026_08_29_140000`: approval + Affiliate account foundation.
- `2026_09_01_070000`: Affiliate coupon mirror.
- `2026_09_01_093000`: Referral / commission mirror.
- `2026_09_01_120000`: payout workflow.
- `2026_09_01_131000`: Manager approval center.
- `2026_09_01_153000`: Specialist / Manager / Finance responsibility owners.
Production truth is the target DB migration table. This WP must capture exact status before any migration execution and must never assume all 23 are pending.

## Identity / responsibility activation contract
Required role presets:
- `kol_specialist`: operational owner, request payout.
- `kol_manager`: approval owner, KOL/paid-collaboration/payout approval.
- `finance`: payment owner, Provider completion only.
Required responsibility fields per active KOL:
- `owner_id` = Specialist.
- `approval_manager_id` = Manager.
- `finance_owner_id` = Finance.
Missing assignment remains fail-closed. No bulk auto-assignment by email/name is allowed.

## Connector / Provider contract
Required redacted environment keys include `ANDY_CORE_URL`, `ANDY_CORE_CONNECTION_KEY`, `ANDY_CORE_KEY_ID`, `ANDY_CORE_SHARED_SECRET`, TLS verification and timeout/retry settings.
- Shared secret values MUST never be written to WP evidence.
- Production must use real HTTP gateway; local fake gateway is forbidden.
- Read-only health/snapshot operations may be used as smoke only after target runtime and DB are positively identified.
- Snapshot sync writes ERP mirror rows but must not mutate WordPress/AffiliateWP provider truth.

## External-write freeze
These actions remain disabled/not executed until separately approved:
1. Affiliate provision / Affiliate status mutation.
2. Coupon provision.
3. Payout `complete()` and Provider write-back.
Production enablement does not require a real payout to prove activation.

## Real-data reconciliation gate
Before activation is classified ready, produce counts and unresolved sets for the approved `connection_key`:
- Affiliate mirror rows: bound / unresolved / missing.
- Coupon rows: present / unresolved / conflict / missing.
- Referral rows: total by provider status; affiliate unresolved; order unresolved; duplicate order-reference behavior.
- Payout mirror rows and payout requests by workflow status.
- KOLs missing Specialist / Manager / Finance responsibility.
- KOLs missing required payout method/account where payout operation is expected.
No fuzzy binding, silent data repair or guessed KOL ownership is allowed.

## Production release gate
Production mutation requires a separate explicit Owner authorization after all of the following are available:
- M2.0A runtime is `PRODUCTION_RUNTIME_READY` or an explicitly approved equivalent release target exists.
- target DB identity is verified and backed up;
- exact pending migration list is reviewed;
- exact release SHA is approved;
- rollback release SHA and DB restore path are documented;
- Production `.env` key inventory is complete with values redacted;
- Connector health plan is ready;
- real-user/role/responsibility assignment list is reviewed.

## Post-deploy KOL smoke sequence
After an explicitly authorized Production deployment/migration:
1. Confirm exact deployed SHA and KOL migration status.
2. Login as one real Specialist, Manager and Finance account.
3. Verify owner-scoped KOL library and dashboard.
4. Verify Manager KOL / paid-collaboration / payout approval history visibility.
5. Verify Finance sees only assigned KOL payout workspace and recipient details.
6. Run Connector health with secrets redacted.
7. Run approved read-only/snapshot reconciliation sequence.
8. Confirm no real Provider mutation occurred unless separately authorized.
9. Record activation state and unresolved data exceptions.

## Explicit exclusions
- No Production SSH mutation under current authorization.
- No Production migration execution.
- No DNS/TLS change.
- No new hosting purchase/provisioning.
- No secret rotation/disclosure.
- No real Affiliate/Coupon/Payout Provider write.
- No Instagram/TikTok discovery/intelligence work.
- No KOL M2 feature development.
- No merge/deploy/tag/release without their own gates.

## Current gate
`KOL_V1_PRODUCT = CLOSED`
`READINESS_AUDIT = PASS`
`M2_0A_RUNTIME_DEPENDENCY = BLOCKED_HOSTING_TARGET_REQUIRED`
`PRODUCTION_ACCESS_MUTATION = NO`
`PRODUCTION_MIGRATION = NO`
`PROVIDER_MUTATION = NO`
`KOL_PRODUCTION_ACTIVATION_READY = BLOCKED_SHARED_RUNTIME_AND_REAL_KOL_SOURCE`
`MERGE_AUTHORIZED = NO`


## Readiness evidence — 2026-09-01
### Local schema / mirror baseline (read-only)
- Local database is not Production and is used only as readiness evidence.
- KOL master rows: 2; both approved and both owned by `UAT Admin`; Production migration/import of these KOL rows is prohibited.
- `2026_09_01_070000_create_kol_affiliate_coupons_table`: local status PENDING.
- `2026_09_01_153000_add_kol_responsibility_owners`: local status PENDING.
- Affiliate mirror rows: 1758, all `connection_key=localdev.beyourlover.com`, `origin=external_sync`, `reconciliation_status=present`, `sync_status=synced`.
- Referral mirror rows: 3299, all `connection_key=localdev.beyourlover.com`, `reconciliation_status=present`, `sync_status=synced`.
- Referral provider status distribution: draft 1; failed 598; paid 1548; pending 95; rejected 223; unpaid 834.
- Current local Affiliate bindings to KOL: 0. Current Referral affiliate resolution: 3299 unresolved. Current Referral order resolution: 3299 unresolved.
- This unresolved state is expected until authoritative Affiliate-to-KOL binding and sufficient canonical Order coverage exist; it MUST NOT be repaired by fuzzy matching.

### Local KOL provenance / activation conclusion
- Existing local KOL #1 is test-like by email classification; both current local KOLs are under UAT-only ownership/roles and neither has payout-ready fields.
- Neither existing local KOL has a unique exact-email Affiliate candidate in the current mirror.
- KOL V1 has no bulk import capability.
- Production activation therefore starts from Production schema + formal users/roles; it MUST NOT copy local UAT KOL rows or the `localdev.*` connection key into Production.
- Real KOL master data must come from an Owner-approved business source or normal canonical Create flow. Affiliate rows may be converted only when the business confirms that the Affiliate itself is a KOL.

### Role / binding mechanics
- Role presets are persisted lazily by canonical Identity `UserService` when a real user is created or reassigned; missing local `kol_specialist`, `kol_manager`, `finance` rows are not a code defect.
- Affiliate binding is exact `account_id + kol_id`, audited and one-to-one per connection. Automatic email/name binding remains prohibited.

### Connector readiness
- Default US-local worktree environment currently has no `ANDY_CORE_URL`, key ID or shared secret configured; no live Connector health was attempted from this WP.
- Existing mirror provenance proves prior localdev snapshot ingestion but does not prove Production Connector configuration.
- Production Connector configuration and signed health remain gated on shared Production runtime + redacted secret inventory.

### Engineering rehearsal
- Disposable fresh migration across current exact baseline: PASS.
- `npm run typecheck`: PASS.
- Production frontend build / Vite manifest: PASS.
- KOL + Identity activation regression: 264 tests / 1070 assertions PASS.
- No KOL business-code change required.
- Initial invalid test runs were environment-only: first stale shared optimized autoload, then missing Vite manifest. After exact worktree dependency/build preparation, the activation regression passed.

## Activation blockers
1. `ERP-PRODUCTION-RUNTIME-FOUNDATION-M2.0A-001` has not reached `PRODUCTION_RUNTIME_READY`; hosting/origin remains required.
2. Production DB identity / backup / pending migration list cannot be captured until the runtime exists.
3. Production Connector URL/key/secret inventory cannot be verified until the runtime exists; values must remain redacted.
4. Owner-provided Tencent export `KOL-tencent.xlsx` is now readable and source access is resolved. The workbook is order-grained historical KOL/fulfillment business data rather than a clean one-row-per-influencer master. It is approved for read-only mapping/reconciliation evidence only; this WP still does not authorize or implement a bulk importer. AffiliateWP/WooCommerce remain the authoritative performance/attribution truth.

## Current checkpoint
`KOL_SIDE_ENGINEERING_READINESS = PASS`
`LOCAL_REAL_MIRROR_AUDIT = PASS`
`LOCAL_UAT_KOL_COPY_TO_PRODUCTION = PROHIBITED`
`REAL_KOL_SOURCE_ACCESS = RESOLVED`
`REAL_KOL_SOURCE_STRUCTURE = ORDER_GRAINED_HISTORICAL_SOURCE`
`REAL_DATA_RECONCILIATION = REQUIRED`
`SHARED_PRODUCTION_RUNTIME = BLOCKED_HOSTING_TARGET_REQUIRED`
`PRODUCTION_MUTATION = NO`
`PROVIDER_MUTATION = NO`
`READY_FOR_GIT_DELIVERY = YES`
`MERGE_AUTHORIZED = NO`


## Recovery / rebase gate — PR #144
- Activation started from KOL Closure merge baseline `144a4975984cfc79ea4734f691ea47109ffc927c`.
- Main advanced to `ef5b62d6ce1c082ecccf2ffc4e30680a2af3d731` via Product Weight Evidence PR #144.
- Activation-file overlap: none.
- KOL business-code overlap: none.
- PR #144 adds a Product migration and changes Product frontend/Architecture, so activation fresh-migration, typecheck and production-build gates must be rerun on the new baseline.
- Dirty activation docs/workflow were protected with include-untracked stash, rebased, then restored without conflict.


## Post-rebase activation engineering gate — main@ef5b62d
- Typecheck: PASS.
- Production build / Vite manifest: PASS.
- Disposable fresh migration including Product Weight Evidence PR #144 migration: PASS.
- KOL + Identity activation regression: 264 tests / 1070 assertions PASS.
- No KOL business-code remediation required.
- Test-environment preparation is now exact-worktree local (`composer install`, `npm ci`, production build); no stale shared optimized autoload is reused.


## Latest-main recovery / source gate — 2026-09-01
- After exact-head CI passed at `41d8fe057e47a8fb3c8adde0321af25004888817`, `main` advanced by two corrective GitHub commits to `247b585627395e010839aa21c30734cf93a9820f`.
- Those two commits represent an accidental metadata-file write followed immediately by its exact deletion; `main@247b585` and tested `main@ef5b62d` have the identical tree `6bb959bbb65e7c2b3106429b51e183e36b80af60`.
- Recovery ref created before rebase: `recovery/erp-kol-v1-production-enablement-pre-rebase-20260901-044142`.
- Feature branch rebased cleanly onto `origin/main@247b585627395e010839aa21c30734cf93a9820f`; no product-content conflict exists.
- Connected Library was searched twice for an existing real KOL master containing identity plus social/contact facts. No suitable source was found. Logistics evidence contains `KOL...` shipment references but not authoritative influencer identity and MUST NOT be used to infer/create KOL master rows.
- Therefore `REAL_KOL_MASTER_SOURCE = SOURCE_IDENTIFIED_ACCESS_REQUIRED`. No fuzzy identity inference, shipment-to-KOL inference, Affiliate auto-conversion, import, Production mutation, or Provider write is authorized.
- Rebased exact-head CI must pass again before any later Ready gate.


## Current-main recovery / exact gate — main@e9912ac (2026-09-01)
- `main` advanced through Backup Migration PR #146 to `e9912acbd49d27e47641f085e8877d4c508e14b4`.
- PR #146 drift changed 15 Backup/Identity/Architecture files; overlap with the four Production Enablement PR files = none.
- Recovery ref created before rebase: `recovery/erp-kol-v1-production-enablement-pre-rebase-20260901-051441` -> `d837b0ea65bb39e0e2cafb4c7f822d2a0107e048`.
- Feature branch rebased cleanly onto `origin/main@e9912acbd49d27e47641f085e8877d4c508e14b4`; no conflict and no KOL business-code change was required.
- Formal source state normalized to Tencent Docs `BYL-Influencer Master List`, sheet `Influencers`; readable access is still required (`SOURCE_IDENTIFIED_ACCESS_REQUIRED`).
- Local exact gate on the rebased branch: typecheck PASS; production build PASS; disposable fresh migration PASS through `2026_09_01_210000_register_backup_permissions`; KOL + Identity regression completed with 1070 assertions and no failures; KOL frontend 80/80 PASS; cross-module closure completed with 844 assertions and no failures; `git diff --check` PASS; no `public/hot`; sensitive literal scan = 0.
- Production mutation = NO. Provider mutation = NO. Merge authorization = NO. PR #145 must remain Draft until shared runtime and real-source access/responsibility blockers are resolved.

## Source-access / latest-main recovery gate — main@01d1106 (2026-09-02)
- `main` advanced from `e9912acbd49d27e47641f085e8877d4c508e14b4` to `01d11061c7d02fc6275353deee59e69dbef62216` through Backup/Inventory work and Global Order Identity PR #151.
- Drift since the prior PR #145 base changed 80+ files; overlap with the four Production Enablement PR files = none.
- Recovery ref created before rebase: `recovery/erp-kol-v1-production-enablement-pre-rebase-20260902-073000` -> `8fa5e21380e03801570d4d5ebe36e67163ea1aa6`.
- Feature branch rebased cleanly onto `origin/main@01d11061c7d02fc6275353deee59e69dbef62216`; rebase head before this evidence update = `8fb037dbe1ee4ef932e6454ad7acff24ac8892df`.
- Global Order Identity PR #151 is now merged into `main`; KOL fulfillment identity uses `k{kols.id}` while source/legacy identifiers remain separately preserved.
- Owner-provided `KOL-tencent.xlsx` is readable. Read-only source audit confirms 971 historical KOL/order-grained rows, not a canonical one-row-per-KOL master. The workbook contains CRM/contact/shipping/social/history evidence and must be normalized before any import.
- Current source hierarchy is now explicit: Tencent/manual data = KOL CRM + historical collaboration/fulfillment evidence; WooCommerce = order truth; AffiliateWP = attribution/referral/commission/payout truth.
- Real-data work therefore moves to a separate WP `ERP-KOL-REAL-DATA-RECONCILIATION-M1-001`; no importer feature is added to PR #145.
- No fuzzy KOL↔Affiliate binding, Production import, Production migration/deploy, Provider mutation, merge, tag or release is authorized by this update.

## Current-main / Production reconciliation — 2026-09-03

This section supersedes earlier dynamic blocker statements in this Work Package. Historical checkpoints above remain evidence of how the branch reached this state.

### Git / branch
- Current canonical main: `ec725cef57762f7ddbdf067bfcfaf1bbce9a2f78` (PR #169 merge).
- Production Enablement branch was protected with recovery ref `recovery/erp-kol-v1-production-enablement-pre-rebase-20260903-1830` and rebased cleanly onto current `origin/main`.
- Main drift since prior base: 200 files; overlap with the four PR #145 files: 0.
- Post-rebase divergence before this evidence update: `0 behind / 7 ahead`.

### Production runtime / Connector
- Shared Production runtime M2.0A is no longer a blocker.
- Current Production release is the composite Connector release `1c0b6ec0215818af7e37bd19e77e40a8732f05ad-m21-162f7208c557-m22-ec725cef5776`.
- This composite release is not equivalent to deploying the complete current GitHub `main`; do not infer full-main code parity from the release suffix.
- Production signed AffiliateWP health: PASS. Contract version `1`; Andy Core `1.5.3`; WordPress `7.0.2`; WooCommerce active; AffiliateWP active.
- Connector reports Provider write capability, but Affiliate/Coupon/Payout Provider writes remain explicitly frozen by this WP.

### Production schema / exact pending migration evidence
- All KOL V1 core migrations through `2026_09_01_153000_add_kol_responsibility_owners` are `Ran` in Production.
- Current composite Production release does not contain the later P1/P2/P3 real-data workflow migrations.
- Comparing the Production `migrations` table to complete current `main` yields exactly five main migrations not yet recorded in Production:
  1. `2026_09_02_100000_create_kol_reconciliation_tables`
  2. `2026_09_02_150000_create_kol_import_preview_tables`
  3. `2026_09_03_000000_create_product_provider_snapshots_and_media`
  4. `2026_09_03_000100_add_product_id_to_sync_run_items`
  5. `2026_09_03_103000_create_kol_owner_mapping_tables`
- The two Product migrations are current-main release dependencies, not KOL data authority. Do not run a full-main Production migration merely to activate KOL without an exact approved release contract.

### Production real-data baseline — read-only
- Canonical KOL rows: `0`.
- Affiliate mirror rows: `1774`; authoritative Affiliate→KOL bindings: `0`.
- Coupon mirror rows: `2843`.
- Referral mirror rows: `3351`; KOL-resolved: `0`; canonical Order-resolved: `0`.
- Affiliate payout mirror rows: `1584`; ERP payout requests: `0`.
- Production users: `4`.
- Persisted Production role presets/users for `kol_specialist`, `kol_manager`, `finance`: none currently assigned/persisted. Role presets remain lazily created by canonical Identity mechanics and absence alone is not a code defect.

### Real-source / preview chain
- `ERP-KOL-REAL-DATA-RECONCILIATION-M1-001` merged by PR #158 with Owner UAT PASS.
- `ERP-KOL-HISTORICAL-IMPORT-PREVIEW-M2-001` merged by PR #163 with Owner UAT PASS.
- `ERP-KOL-OWNER-MAPPING-CLOSURE-M3-001` merged by PR #166 with Owner UAT PASS.
- Latest retained local preview evidence: `1077` source rows, `970` distinct exact identities, `961` deterministic KOL candidates, `12` unresolved source rows.
- Owner-mapping capability was verified in P3, but the current shared local DB retains `0` real source-owner mappings and `0` candidate decisions. No mapping may be reconstructed by fuzzy name/email inference.

### Current activation gate
`KOL_V1_PRODUCT = CLOSED`
`KOL_CORE_PRODUCTION_SCHEMA = READY / RAN`
`SHARED_PRODUCTION_RUNTIME = READY`
`PRODUCTION_CONNECTOR_SIGNED_HEALTH = PASS`
`REAL_SOURCE_RECONCILIATION = COMPLETE / MERGED PR #158`
`REAL_SOURCE_IMPORT_PREVIEW = COMPLETE / MERGED PR #163`
`OWNER_MAPPING_CAPABILITY = COMPLETE / MERGED PR #166`
`REAL_KOL_MASTER_IN_PRODUCTION = 0`
`REAL_RESPONSIBILITY_MAPPING = OWNER REVIEW REQUIRED`
`PRODUCTION_RELEASE_CONTRACT = OWNER REVIEW REQUIRED`
`PRODUCTION_MUTATION = NO`
`PROVIDER_MUTATION = NO`
`MERGE_AUTHORIZED = NO`
`STATUS = WAITING_OWNER_REAL_USER_MAPPING_AND_PRODUCTION_MUTATION_GATE`

### Next exact gate
1. Keep Provider writes frozen.
2. Produce the reviewed mapping from the seven historical source-owner values / 961 candidates to real ERP Specialist ownership, including explicit decisions for the 42 multi-owner candidates.
3. Identify real Manager and Finance responsibility assignments; missing assignment remains fail-closed.
4. Freeze the exact release strategy and rollback contract. Full-main deployment and a bounded composite release are materially different release choices and must not be silently substituted.
5. At the explicit Production mutation/deployment Owner gate, execute only the approved release/migration/import scope.
6. After activation, run real-role login isolation, Connector health, read-only reconciliation and zero-unapproved-Provider-write smoke.

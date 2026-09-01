# ERP-WORDPRESS-CONNECTOR-INTEGRATION-M1

Status: CURRENT / REBASELINED / PARALLEL INDEPENDENT WP

## Governance
- Execution model: `PARALLEL INDEPENDENT WP`.
- Delivery: `ONE WP / ONE BRANCH / ONE WORKTREE / ONE PR`.
- Branch: `feature/erp-wordpress-connector-integration-m1`.
- Worktree: `/Users/a/AI/007/worktrees/erp-wordpress-connector-integration-m1`.
- Current rebaseline: `origin/main@264fe20b79b7f145f3b976d939ddce0a07c57d8d`.
- Historical first implementation base: `origin/main@e627f7391cf9975fbc509d23724967d67a6e0f9b`.
- No direct work on `main`.
- No reuse or modification of Customer M1.9 worktree.
- No merge or production deployment without explicit Owner authorization.

## External authority
- Andy Core released stable baseline: `v1.5.3` at `715b83e4df9591cc5d47eb73dc8f4df30d157054`.
- Current Connector authority: YBY-Core `main@1b5d1b2ad13d26c15cf47db259351083c418230e` (PR #28 post-release connector patch; no version/tag/release bump).
- Connector contract version: `1`.
- REST namespace: `/wp-json/andy-core/v1/erp`.
- Frozen surface: 6 GET + 5 POST.
- Security: HMAC-SHA256 V1, nonce/replay protection, rate limits, mutation idempotency.

## Objective
Turn the released Andy Core WordPress Connector into one usable ERP integration surface for configuration, health, imports, write-back and audit evidence without requiring normal operators to edit `.env` or construct signed requests manually.

## Customer M1.9 isolation
Customer M1.9 remains active in `/Users/a/AI/007/worktrees/erp-customer-m1-9-customer360-closure-001`.
Connector M1 must not modify Customer360 logic, Customer Hub, customer projections, customer page layouts, customer fixtures, or the Customer M1.9 worktree.

At WP start Customer M1.9 has uncommitted work including shared navigation and `tests/Architecture/ModuleArchitectureTest.php`. Connector M1 therefore avoids shared navigation changes in early milestones and uses the existing `/sync/wpapi` integration entry point.

## M1.0 audit result
- `origin/main` exact SHA recorded: PASS.
- Existing Connector M1 branch/worktree before creation: NO / NO.
- Marketing WordPress subscriber gateway: FOUND.
- KOL AffiliateWP gateway + HMAC signer: FOUND.
- Sync Run / Sync Run Item orchestration: FOUND.
- Existing `/sync/wpapi` UI and subscriber action: FOUND.
- Existing `ANDY_CORE_*` service config: FOUND.
- Runtime connector credentials on baseline: env/config based.
- Customer M1.9 isolation: PASS.
- Live network gate: BLOCKED.

## Transport decision
The KOL AffiliateWP gateway is the stronger current transport authority because it already supports health, four snapshots, five mutation operations, bounded retries, typed response/error handling, contract validation and the shared HMAC signer.

Do not rewrite Andy Core transport from scratch. Reuse this gateway for unified health/write-back and consolidate duplicated Marketing signing only when regression-safe.

## Sync decision
Reuse existing `SyncRunService`, `sync_runs` and `sync_run_items` for operation evidence. Do not create a second sync engine.
## M1.0 exact file-change map
Initial implementation should prefer modifications to existing Connector/Sync surfaces and only add narrowly scoped persistence/schema files.

Primary existing files:
- `config/services.php` — retain env fallback contract.
- `app/Providers/AppServiceProvider.php` — load persisted Connector settings into runtime config only when the dedicated settings table exists.
- `app/Modules/Sync/Experience/Controllers/SyncWorkspaceController.php` — unified Connector page, save settings, health and recent-run presentation.
- `app/Modules/Sync/Experience/Routes/web.php` — Connector settings/health routes under `/sync/wpapi`.
- `resources/js/Pages/Sync/WpApi.vue` — replace subscriber-only surface with compact unified Connector UI.
- `app/Modules/Kol/Infrastructure/AffiliateWp/HttpAffiliateWpGateway.php` — canonical health/read/write transport; only minimal extensions if required.
- `app/Modules/Marketing/Infrastructure/AndyCore/HttpWordPressSubscriberGateway.php` — preserve subscriber compatibility; consolidate signing/config only when safe.
- `app/Modules/Marketing/Application/WordPressSubscriberSyncService.php` — preserve existing subscriber import domain ownership.

Narrow new schema:
- dedicated WordPress Connector settings migration with encrypted shared-secret persistence handled server-side.

Focused tests:
- Connector settings/secret redaction.
- health action/contract rendering.
- existing Marketing subscriber regression.
- existing KOL gateway regression.
- Sync workspace permission regression.

Avoid until Customer M1.9 merges:
- `config/erp_navigation.php`.
- `config/erp_shell_navigation.php`.
- `menu/APPROVED_MENU.json`.
- Customer module files.
- broad architecture-baseline edits unless required by newly added module files.
## Network gate
US Mac DNS lookup for `localdev.beyourlover.com` fails.

Tailscale sees the Windows Local authority as `cn-yby-wins-codex` at `100.87.29.56`, but a probe using `--resolve localdev.beyourlover.com:443:100.87.29.56` timed out. Therefore live integration is not yet reachable from ERP runtime.

This blocks live M1 UAT only. It does not block M1.0 Foundation or M1.1 settings/health implementation with deterministic HTTP fakes.

No application-code IP hardcoding is permitted.

## Internal milestones
- M1.0 Foundation / Contract Audit → `CONTRACT_FROZEN`.
- M1.1 Connection Settings + Health → `CONNECTION_READY`.
- M1.2 Read Import Orchestration → `READ_SYNC_READY`.
- M1.3 Mutation / Write-Back → `WRITEBACK_READY`.
- M1.4 Owner UI / Full UAT → `OWNER_UAT_READY`.
- M1.5 Final Gate → `READY_FOR_MERGE`.

## First real integration sequence
1. Configure connection.
2. Signed `/health`.
3. Signed Subscribers snapshot/import.
4. Repeat Subscribers sync and verify no duplication.
5. Affiliates → Coupons → Referrals → Payouts snapshots.
6. One synthetic reversible mutation.
7. Replay same idempotency key and verify no duplicate business object.

## Merge governance
Independent CI, Owner UAT and explicit Owner merge authorization are mandatory. Production deployment is a separate authorization gate.

## M1.0 / M1.1 execution checkpoint — 2026-08-31

`M1.0 = CONTRACT_FROZEN`

`M1.1 = CONNECTION_READY (CODE + FOCUSED TESTS)`

Implemented in the isolated Connector worktree:
- database-backed WordPress Connector settings with encrypted Shared Secret;
- env config remains a fallback when no persisted settings exist;
- secret replacement/rotation without ever returning the stored value to the frontend;
- unified `/sync/wpapi` Connector page;
- signed health action reused from the existing KOL AffiliateWP gateway;
- safe health-result persistence with a field whitelist;
- existing Marketing Subscribers sync preserved at `/sync/wpapi/subscribers`;
- compact M1.2 placeholders for Affiliates, Coupons, Referrals and Payouts.

Verification:
- PHP syntax checks: PASS;
- focused Connector + Marketing + Sync tests: PASS, 15 tests / 127 assertions / 0 failures;
- `vue-tsc --noEmit`: PASS;
- Vite production build: PASS;
- `git diff --check`: PASS;
- Customer M1.9 owned-file touch check: PASS (0 files).

## M1.2 KOL domain coordination decision

Read-only inspection found that the active independent KOL M1.4B worktree is already implementing `KolAffiliateAccount` and `AffiliateSnapshotSyncService`, including exact provider `affiliate_id` ownership and reconciliation. Those changes are not merged into `main` and are currently uncommitted in that separate worktree.

Connector M1 must not create a competing Affiliate mirror or copy unmerged KOL files. Subscribers can continue through the merged Marketing domain immediately. Affiliate import orchestration must integrate with the canonical KOL M1.4B domain capability after it is formally merged to `main`. Coupon / Referral / Payout mirrors must likewise be added only in their correct KOL/Finance ownership packages rather than as generic Connector tables.

This is not a dependency on Customer M1.9 and does not block M1.1 delivery. Live M1 UAT remains separately blocked by the US-Mac → Windows Local WordPress network gate.

## Pre-commit verification refinement

Expanded relevant functional regression:
- KOL AffiliateWP HMAC/contract gateway;
- WordPress Connector settings/health/security;
- Marketing WordPress subscriber sync;
- Marketing subscriber HTTP gateway;
- Sync workspace.

Result: `PASS` — 256 assertions / 0 functional failures. PHPUnit reports 77 pre-existing environment warnings (`file_get_contents` warning class) but exits 0.

Architecture gate classification:
- `tests/Architecture/ModuleArchitectureTest.php::test_frozen_module_and_support_boundaries` = FAIL;
- exact same failure reproduced on untouched `main@e627f7391cf9975fbc509d23724967d67a6e0f9b`;
- failure is the stale frozen directory list missing already-present KOL connector directories;
- classification: `BASELINE_STALE_ASSERTION`, not Connector regression;
- Connector M1 does not edit this file because Customer M1.9 currently owns/modifies it in its independent active worktree.

## Live network + signed health gate closure — 2026-08-31

This checkpoint supersedes the earlier network-blocked note above.

- Windows Local WordPress host: `YST-241030SSD` / `localdev.beyourlover.com`.
- Tailscale peer IP: `100.87.29.56` (transport-only UAT route; never application config).
- Windows Tailscale service: Running / Automatic.
- Windows Tailscale interface: Up / Private.
- Windows Firewall profiles: disabled; not the blocker.
- Nginx: listening on `0.0.0.0:443` and `[::]:443`.
- Windows-local HTTPS `/wp-json/`: HTTP 200.
- US Mac → Windows Tailscale ping: PASS.
- US Mac → `100.87.29.56:443`: TCP PASS.
- US Mac HTTPS with host resolution to `localdev.beyourlover.com`: HTTP 200.
- Signed Connector `/health`: HTTP 200 / `ok=true` / contract `1`.
- Live provider health: Andy Core `1.5.3`, WordPress `7.1`, WooCommerce `10.9.4`, AffiliateWP `2.35.0`, `writable=true`, `operational=true`.

`LIVE_NETWORK_GATE = PASS`
`SIGNED_HEALTH_GATE = PASS`

## M1.2 live provider schema audit — 2026-08-31

Signed read-only snapshots were executed from the US Mac against the Windows Local WordPress Connector with `limit=5`. No ERP or WordPress mutations were performed.

- Subscribers: PASS; 5 items; stable `external_subscription_id`; fields include explicit consent/status/source facts.
- Affiliates: PASS; 5 items; exact `affiliate_id`; fields include WP user identity, status, rate/rate_type and provider timestamps.
- Coupons: PASS; 5 items; exact WooCommerce `coupon_id`; fields include exact/normalized code, discount facts and linked affiliate.
- Referrals: PASS; 5 items; exact referral provider records; fields include affiliate, reference, amount/currency, status and payout link.
- Payouts: PASS; 5 items; exact payout provider records; fields include affiliate, referral IDs, amount/currency, method, status and date.
- All five resources returned `ok=true`, `contract_version=1` and a continuation cursor.

`M1.2_PROVIDER_SCHEMA_AUDIT = PASS`

Persistence ownership remains unchanged:
- Subscribers → existing merged Marketing domain.
- Affiliates → canonical KOL M1.4B domain after its formal merge.
- Coupons / Referrals / Payouts → their canonical KOL / Finance ownership packages.

Connector M1 will orchestrate these capabilities; it will not create parallel generic master tables.

## M1.2 real Subscribers import + idempotency gate — 2026-08-31

The Connector worktree was booted against an isolated temporary SQLite database. The live WordPress Connector was reached over the transport-only Tailscale UAT route. Real provider records were imported only into the isolated temporary ERP database.

First real import:
- before: 0 subscribers / 0 subscriptions;
- Sync Run status: `completed`;
- processed: 422;
- succeeded: 422;
- failed: 0;
- conflicts: 0;
- after: 422 subscribers / 422 subscriptions.

Second identical real import:
- before: 422 / 422;
- processed: 422;
- succeeded: 422;
- failed: 0;
- conflicts: 0;
- after: 422 / 422;
- duplicate growth: 0.

`M1.2_SUBSCRIBER_REAL_IMPORT = PASS`
`M1.2_SUBSCRIBER_IDEMPOTENCY = PASS`

## M1.3 REAL WRITE-BACK GATE — 2026-08-31

`M1.3 = WRITEBACK_READY`

Live target: Windows Local WordPress `localdev.beyourlover.com` only. No production site was touched.

Reversible AffiliateWP status mutation was executed through the real ERP `HttpAffiliateWpGateway::setAffiliateStatus()` transport/HMAC path:
- provider object: Affiliate `#1`;
- original state: `active`;
- mutation: `active -> inactive`;
- first read-back: `inactive`;
- exact same idempotency key replayed: provider result identical and state remained `inactive`;
- durable WordPress idempotency record for the mutation key: exactly `1`, action `affiliate.status`, state `succeeded`;
- restore used a different idempotency key: `inactive -> active`;
- final read-back: `active`;
- durable restore record: exactly `1`, action `affiliate.status`, state `succeeded`.

This satisfies the M1 idempotency requirement: replay did not create a second logical mutation, provider identity remained unchanged, and the original business state was restored.
Security / rollback evidence:
- the pre-existing WordPress Connector options and encrypted secret blob were backed up without decrypting or displaying the original secret;
- a temporary random UAT secret was used only for this gate;
- the original encrypted secret blob was restored byte-equivalently after the test;
- all US-Mac temporary secret files, proxy scripts and listeners were removed;
- the Windows temporary state directory was removed.

Local TLS coordination note:
- `services.andy_core.verify_tls` exists, but the current shared `HttpAffiliateWpGateway` does not yet consume it;
- active KOL M1.4B currently has uncommitted modifications to that same gateway file;
- Connector M1 intentionally did not edit the shared gateway, preserving parallel-worktree isolation;
- the M1.3 live gate therefore used a transient US-Mac loopback proxy whose upstream was the real Tailnet HTTPS endpoint;
- M1.4 direct Local UI UAT must resolve this shared gateway/TLS-setting ownership after the KOL M1.4B merge or via an independent non-conflicting baseline change.

Customer M1.9 owned-file touch: `0`.
Production deploy: `NO`.
Merge: `NO`.

## M1.4 OWNER UI / FULL UAT GATE — 2026-08-31

Pre-gate coordination:
- Customer M1.9 PR #133 is merged into `main` at `750cb68d`; Connector PR #134 rebased cleanly with zero overlapping files.
- Active KOL M1.4B still has no PR, has 46 uncommitted changes, and is 44 commits behind `main`.
- KOL M1.4B did not yet implement `services.andy_core.verify_tls` consumption.
- The frozen architecture-directory assertion remains stale and fails identically on untouched current `main`; this is still a baseline issue, not a Connector regression.

M1.4 transport closure:
- `HttpAffiliateWpGateway` now follows the same TLS policy already used by the Marketing WordPress gateway.
- TLS verification remains enabled by default.
- Production cannot disable TLS verification.
- Only `local` / `testing` may explicitly set `verify_tls=false` for self-signed development certificates.
- HMAC, retry, redirect, idempotency and response-contract behavior are unchanged.
Verification after the TLS fix:
- focused Connector / KOL / Marketing / Sync suites: PASS, 258 assertions / 0 failures;
- 79 pre-existing environment warnings remain non-functional;
- `vue-tsc --noEmit`: PASS;
- Vite production build: PASS;
- `git diff --check`: PASS.

Live self-signed HTTPS proof:
- direct ERP gateway health over Windows Tailnet HTTPS with `verify_tls=false`: PASS;
- contract version: `1`;
- connection key: `localdev.beyourlover.com`;
- direct Affiliate snapshot through the same gateway: PASS, first page 50 items;
- no loopback proxy was used for this proof.

The pre-existing WordPress encrypted Connector secret/options were restored exactly after the temporary UAT credential switch. Temporary UAT secrets and transfer files were removed from both devices.
Automated UI-route UAT on isolated local runtime:
- dedicated Connector worktree runtime bound to `127.0.0.1:18107`;
- dedicated temporary SQLite database; no main/customer data reused;
- existing repository `LocalUatSeeder` used for local authentication data;
- authenticated `/sync/wpapi` render: HTTP 200, component `Sync/WpApi`;
- unauthenticated access redirects to login;
- placeholder connection save: HTTP 302 success;
- placeholder Shared Secret stored encrypted at rest;
- frontend props expose only `secret_set`, never `shared_secret`;
- placeholder secret literal absent from rendered page;
- placeholder setting deleted after verification.

Owner-manual remainder:
- obtain/rotate the one-time Shared Secret in the Local WordPress Connector admin UI;
- enter the real secret in the ERP UAT Connector page and save;
- click `测试连接` and confirm `Connected` plus provider versions/status;
- click Subscribers `立即同步` twice and confirm the second run creates no duplicate subscriber/subscription rows;
- visually confirm Affiliates / Coupons / Referrals / Payouts remain explicitly disabled as `待接入` pending canonical domain ownership.

`M1.4_AUTOMATED_UI_GATE = PASS`
`M1.4_OWNER_VISUAL_UAT = PENDING`

## M1.4 OWNER UI / FULL UAT — 2026-08-31

`M1.4 = OWNER_UAT_READY`

Owner explicitly reported `PASS` after the live `/sync/wpapi` UAT flow.

Automated/UI evidence already established before Owner sign-off:
- Connector worktree rebased onto `main@750cb68d5062d8d411e875d62a6237eb03f362c4` after Customer M1.9 merged;
- Local self-signed TLS transport now honors `verify_tls=false` only in local/testing; production remains fail-closed with TLS verification enabled;
- direct real signed health and Affiliate snapshot pass without the M1.3 proxy;
- `/sync/wpapi` renders HTTP 200 for an authorized UAT admin;
- Shared Secret is encrypted at rest and is never returned in Inertia props;
- connection save route returns success and preserves the secret non-disclosure contract;
- Owner completed the real UI onboarding / health / sync checks and returned `PASS`.

Cleanup after Owner PASS:
- temporary `127.0.0.1:18107` UAT server stopped;
- temporary isolated SQLite database removed;
- temporary worktree `.env` removed;
- temporary Chrome UAT profile removed;
- no production deployment performed.

## M1.5 FINAL GATE — 2026-08-31

`M1.5 = READY_FOR_MERGE`

Final local delivery verification:
- Owner UAT: PASS;
- worktree: clean;
- branch rebased onto current `origin/main@750cb68d5062d8d411e875d62a6237eb03f362c4` before final gate;
- `git diff --check`: PASS;
- changed PHP syntax: PASS;
- frontend `npm run typecheck`: PASS;
- frontend production `npm run build`: PASS;
- temporary Tailnet IP / UAT key marker scan: PASS;
- credential literal scan across application/config/resource/database diff: PASS.

Full local PHP suite result:
- 5,590 assertions executed;
- 3 failures, all inside `Tests\Architecture\ModuleArchitectureTest`;
- the same three failures reproduce on untouched `main` in the architecture suite;
- classification: `BASELINE_STALE_ASSERTION`, not Connector regression.

Independent GitHub CI:
- workflow: `ERP WordPress Connector M1 CI`;
- path-scoped to Connector-related changes;
- final green run: `33385746221`;
- Node/frontend dependency install: PASS;
- Typecheck: PASS;
- Build: PASS;
- focused PHP Connector gate: PASS, 79 tests / 258 assertions;
- no business-code waiver was used.

CI portability notes resolved during the gate:
- Vite assets are built before Inertia-rendering PHP feature tests;
- Ubuntu case-sensitive Inertia test lookup is bridged to the repository's canonical `resources/js/Pages` directory only inside CI.

Delivery classification:
- M1.0 `CONTRACT_FROZEN`: PASS;
- M1.1 `CONNECTION_READY`: PASS;
- M1.2 real read/import gate: PASS;
- M1.3 `WRITEBACK_READY`: PASS;
- M1.4 `OWNER_UAT_READY`: PASS;
- M1.5 `READY_FOR_MERGE`: PASS.

Merge remains prohibited until explicit Owner merge authorization. Production deployment remains a separate gate.


## M1.0 canonical rebaseline — 2026-09-01

This section supersedes stale start-state statements above where repository history has advanced. Historical checkpoints remain preserved as delivery evidence.

### Exact baseline
- ERP `origin/main`: `264fe20b79b7f145f3b976d939ddce0a07c57d8d`.
- Formal local branch: `feature/erp-wordpress-connector-integration-m1`.
- Formal worktree: `/Users/a/AI/007/worktrees/erp-wordpress-connector-integration-m1`.
- Worktree was recreated cleanly from the exact current `origin/main` SHA.
- No push, new PR, merge or Production deployment was performed during rebaseline.

### Historical delivery truth
- ERP PR #134 used this same WP/branch and is already MERGED.
- Merge commit: `9496144f825301c110c4ae688b353cee5b4f868b`.
- PR #134 delivered the Connector settings/health foundation and M1.1 UI into `main`.
- The old remote branch still points to `217b21c6483af5787ca63de98bc87987e7fadd95` and is behind current `origin/main`; it is not the baseline for new work.

### Current domain ownership
- Subscribers: existing Marketing WordPress subscriber import remains canonical. Marketing OS is not reopened.
- Affiliates: existing KOL `AffiliateSnapshotSyncService` / `KolAffiliateAccount` are canonical.
- Coupons: existing KOL `CouponSnapshotSyncService` / `KolAffiliateCoupon` are canonical.
- Referrals: existing KOL `ReferralCommissionSyncService` / `KolAffiliateReferral` are canonical.
- Payout business workflow remains owned by the independent KOL M1.4E package; Connector M1 must orchestrate rather than duplicate it.
- `SyncRunService`, `sync_runs`, and `sync_run_items` remain the shared operation-evidence substrate; no second sync engine is permitted.

### YBY-Core PR #28 authority
- YBY-Core `main`: `1b5d1b2ad13d26c15cf47db259351083c418230e`.
- PR #28 changes only Referral snapshot provider completeness: AffiliateWP `draft` and `failed` statuses are now explicitly included alongside `paid`, `unpaid`, `rejected`, and `pending`.
- Connector REST surface remains 6 GET + 5 POST, HMAC-SHA256 V1, contract version `1`, replay protection and mutation idempotency.
- ERP `ReferralCommissionSyncService` already preserves non-empty provider status strings and dynamically groups workspace totals by provider status; no runtime business-code compatibility patch is required for `draft` / `failed`.

### Runtime / network truth
- Current ERP runtime Connector credentials are not configured: URL empty, Key ID absent, Shared Secret absent.
- US Mac DNS for `localdev.beyourlover.com` remains unresolved.
- Tailnet transport is reachable: Windows peer `100.87.29.56`, TCP 443 PASS, HTTPS with correct Host/SNI PASS.
- Tailnet IP is transport-only UAT plumbing and must never be hardcoded into ERP application configuration or source.
- Classification: `NETWORK_TRANSPORT_GATE = PASS`; `CANONICAL_RUNTIME_DNS = NOT_CONFIGURED`.

### Rebased M1.0 exact file-change map
M1.0 rebaseline changes are documentation-only:
1. `docs/60_Codex_Operations/work-packages/ERP-WORDPRESS-CONNECTOR-INTEGRATION-M1.md` — current baseline, history, ownership and gate truth.
2. `docs/10_Planning_Roadmap/25_ANDY_CORE_V1.5.3_WORDPRESS_CONNECTOR_CONTRACT.md` — YBY-Core PR #28 authority and Referral all-status contract.

No Customer, Marketing, KOL business/domain, navigation, production configuration, or YBY-Core source files belong in this M1.0 rebaseline patch.

`M1.0_REBASELINE = CONTRACT_FROZEN`

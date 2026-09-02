# ERP-KOL-REAL-DATA-RECONCILIATION-M1-001

STATUS: ACTIVE / FORMAL CONTRACT FROZEN / LOCAL IMPLEMENTATION AUTHORIZED
EXECUTION_PROVIDER: GPT_WEB
VERIFICATION_TIER: HIGH_RISK
OWNER: Harr
SYSTEM: BYL ERP
BASELINE: origin/main@0a9ab219ac7fa80dfcde1958350503d8206c92d8
BRANCH: feature/erp-kol-real-data-reconciliation-m1-001
WORKTREE: /Users/a/AI/007/worktrees/erp-kol-real-data-reconciliation-m1-001
DEPENDENCIES: Global Order Identity PR #151 and warehouse recommendation PR #152 are merged in baseline; PR #145 is parallel evidence only and is NOT a code dependency
DEPENDENCY_TYPE: MERGED_PR
DEPENDENCY_STATUS: SATISFIED
PARALLEL_SAFE: YES — this package must not modify the four PR #145 files while PR #145 remains open

## Purpose
Build the deterministic reconciliation layer between Owner-provided Tencent/manual KOL evidence, ERP KOL Master, the AffiliateWP mirror and existing Referral projections without importing historical business truth or guessing identity.

## Source truth hierarchy
- Tencent/manual source = KOL CRM/contact/social/shipping facts plus historical collaboration/fulfillment evidence.
- WooCommerce = customer order truth.
- AffiliateWP = Affiliate/Referral/Commission/Payout attribution truth.
- ERP `kols.id` = canonical KOL identity after a reviewed KOL exists.
- Existing `AffiliateBindingService` = only authoritative Affiliate→KOL binding mutation path.
- `kol_affiliate_referrals` remains a local projection of AffiliateWP Referral truth; P1 may recompute only its ERP resolution fields.

## Frozen P1 boundary
P1 is reconciliation/staging only. It does not create KOL Master rows, import historical orders/collaborations, provision Affiliates/Coupons, or write Provider state.

## Frozen staging schema
Create `kol_reconciliation_batches` as non-canonical reconciliation evidence with: source type, original filename, SHA-256 checksum, current Affiliate connection key, creator user, row/distinct-identity counters, status, and preview summary counters.
Create `kol_reconciliation_rows` with: batch FK, source row number, row hash, raw source JSON, normalized identity JSON, source identity key, matched `kol_id`, matched Affiliate account id, KOL/Affiliate/reconciliation statuses, exact-match evidence JSON, and human-readable message.
Staging rows are evidence only. No downstream business module may treat them as KOL, Order, Collaboration, Affiliate, Referral or payout truth.

## Source file contract
- `KOL-tencent.xlsx` is SUPERSEDED / NOT CANONICAL.
- Canonical 2026-to-date manual KOL operational source: `0902-网红订单.xlsx` (sheet `工作表1`). Aggregate audit facts: used range A1:AE1078 (1 header plus 1,077 data rows and 31 columns), physical size 58,827,902 bytes, 133 ZIP entries, 61,366,490 total uncompressed ZIP bytes, largest entry 1,776,084 bytes, `sharedStrings.xml` 1,371,198 bytes, `sheet1.xml` 1,229,790 bytes, standard internal worksheet relation, exact identity headers `Contact Email` and `Influencer`s Link/URL`, valid Contact Email rows 1,055 with 956 unique normalized emails, and valid profile URL rows 1,060 with 1,019 unique normalized URLs. The workbook is mostly embedded media; P1 does not parse media. These are aggregate audit facts only; no workbook PII is stored in Git, docs or tests.
- Performance truth remains WooCommerce + AffiliateWP and is unchanged by this source.
- Accept CSV and XLSX only; 64 MiB maximum; 5,000 non-empty rows maximum.
- XLSX must use bounded ZIP/XML parsing: no external relationships/network access, max 200 ZIP entries, max 96 MiB total uncompressed content and max 16 MiB per entry. Media is ignored for parsing but remains subject to total and per-entry limits.
- M1 reads one bounded worksheet and preserves all original row values in staging evidence.
- Unknown columns remain raw evidence and are never silently mapped to another semantic field.
- Canonical matching inputs are explicit mapped columns only: email, platform, handle/profile URL, plus display/contact/location fields for operator context.
- Order/reference/product/history columns may be preserved as evidence but MUST NOT participate in KOL or Affiliate identity matching.
- Name/display name, city, country, address, product and order values are NEVER identity keys in P1.

## Normalization contract
- email: trim + lowercase; invalid emails remain evidence but are not match keys.
- platform: trim + lowercase; bounded known/social text, never inferred from a display name.
- handle: trim, strip one leading `@`, lowercase for comparison while preserving raw source.
- profile URL: trim, lowercase host, remove fragment/trailing slash for comparison; no network fetch.
- source identity key: deterministic evidence key from valid exact email and/or exact social identity; repeated order-grained source rows retain separate staging rows but share the same source identity key when their exact identity evidence is identical.
- If a row has no exact identity evidence, its identity key is null and status is unresolved.

## Exact KOL candidate contract
Resolve only against existing non-archived ERP KOLs.
1. Exact normalized active social `profile_url` may identify candidates.
2. Exact normalized `(platform, handle)` may identify candidates.
3. Exact normalized `contact_email` may identify candidates.
4. If all available exact evidence resolves to exactly one same KOL, classify `matched`.
5. If exact evidence points to different KOLs or any exact key has multiple KOL candidates, classify `conflict`.
6. If no exact key resolves, classify `unresolved`.
No fuzzy name, follower count, country/city, shipping address, product, historical order, logistics reference or phonetic matching is allowed.

## Exact Affiliate candidate contract
Resolve only within the current AffiliateWP `connection_key` mirror.
- Exact normalized source email may compare with both `kol_affiliate_accounts.email` and `wordpress_user_email`.
- Display name/username alone never matches an Affiliate.
- Exactly one Affiliate candidate + matched KOL + Affiliate unbound => `ready_for_binding`.
- Exactly one Affiliate already bound to the same matched KOL => `already_bound`.
- Affiliate bound to another KOL => `conflict`.
- Multiple exact Affiliate candidates => `conflict`.
- No Affiliate candidate => `no_affiliate`; this is not an error and does not authorize provisioning.
- Affiliate candidate without a deterministic KOL match => reconciliation remains `unresolved` or `conflict`; never auto-convert Affiliate to KOL.

## Reconciliation status vocabulary
`matched_kol_only`, `ready_for_binding`, `already_bound`, `no_affiliate`, `unresolved`, `conflict`.
The preview UI must expose counts and row evidence for every status. There is NO bulk confirm/bind action in P1.

## Local Referral re-resolution contract
Add one ERP-local service that recomputes Referral resolution from existing `kol_affiliate_accounts` only; it MUST NOT call AffiliateWP/WooCommerce or any Provider.
For a bound `(connection_key, affiliate_id)` it may update existing `kol_affiliate_referrals` rows for that exact pair:
- set `kol_id` to the authoritative bound KOL;
- set `affiliate_resolution_status = bound`;
- preserve referral_id, affiliate_id, order_id/order resolution, amount, currency, provider status, payout id, dates, context and all Provider facts.
If an Affiliate is absent or unbound, the service may classify local resolution as `missing`/`unbound` but must never guess a KOL.
Existing manual `AffiliateBindingService::bind()` and explicit `convert()` remain the only binding mutations; after successful binding they invoke local Referral re-resolution inside the same business transaction boundary.

## UX contract
Add a dedicated `/kol/affiliatewp/reconciliation` workspace protected by `kol.affiliates.manage`.
Do NOT add or change any level-1/level-2 menu item while PR #145 is open; the existing AffiliateWP page may expose one upper-right page-header action linking to `真实数据核对` for authorized users.
The reconciliation page uses the canonical upper-right Page Header Action Zone for Back/Upload/Preview actions.
Show: source file identity/checksum, total rows, distinct source identities, matched/ready/already-bound/no-affiliate/unresolved/conflict counts, and a review table with source row, exact identity evidence, ERP KOL candidate, Affiliate candidate, status and message.
No Confirm Import button, no bulk bind button and no Provider action appear in P1.

## Permissions
- GET reconciliation workspace: `kol.affiliates.manage` required server-side.
- POST preview: `kol.affiliates.manage` required server-side.
- Existing AffiliateWP read/sync/bind permissions otherwise remain unchanged.
- No new permission vocabulary is introduced.

## Explicit prohibitions
- No fuzzy/AI/LLM identity matching.
- No name-only, address, logistics-reference or historical-order identity inference.
- No automatic Affiliate→KOL binding or Affiliate→new-KOL conversion.
- No real KOL creation/update/archive from reconciliation staging.
- No historical Woo Order/Collaboration import.
- No Affiliate/Coupon provision, status mutation, payout completion or other Provider write.
- No Production migration, deploy, DNS/TLS mutation, tag, release or merge.
- No modification of PR #145 branch/worktree and no edits to its four changed files while it remains open.
- No Claude use for this WP unless Owner explicitly changes provider authorization.
- No China-machine verification by default.

## Acceptance criteria
- Fresh migration creates staging tables/FKs/indexes and rolls back/reapplies cleanly.
- CSV/XLSX preview rejects malformed/oversized files and has zero KOL/Affiliate/Referral provider-fact mutation.
- Repeated order-grained rows are retained but deterministic exact identities are counted distinctly.
- Exact email/social evidence that agrees on one KOL yields `matched`; conflicting exact evidence yields `conflict`; name-only rows yield `unresolved`.
- Unique exact unbound Affiliate + matched KOL yields `ready_for_binding`; same existing binding yields `already_bound`; different existing binding yields `conflict`; absent Affiliate yields `no_affiliate`.
- Existing manual bind remains authorization/audit authority and updates historical local Referral `kol_id`/resolution without a Provider call.
- Referral re-resolution preserves all Provider/order/payout facts.
- Unauthorized reconciliation GET/POST is denied server-side.
- Existing AffiliateWP/KOL/referral/payout regressions remain green.
- Focused Vitest, typecheck, production build, fresh migration and `git diff --check` pass.
- US-local runtime/UAT page loads with local-only `admin/password` if Owner-facing UAT is reached.

## Frozen implementation ownership
Expected owned files are limited to new reconciliation migration/models/service/controller/page/tests plus bounded changes to:
- `AffiliateBindingService.php` for local Referral re-resolution invocation;
- `AffiliateWpSyncController.php` only if needed to expose the reconciliation link state;
- `app/Modules/Kol/Experience/Routes/web.php` for protected reconciliation routes;
- `resources/js/Pages/Kol/AffiliateWp/Index.vue` and its focused test for the authorized header link.
Do not modify `menu/APPROVED_MENU.json`, `config/erp_shell_navigation.php`, Work Package Index, unified KOL roadmap or PR #145 workflow/docs in P1 while PR #145 remains open.

## Git delivery authority
LOCAL_IMPLEMENTATION: AUTHORIZED by Owner continuation instruction.
LOCAL_MIGRATION_FILE_AUTHORING_AND_TESTING: AUTHORIZED.
LOCAL_UAT_PREPARATION: AUTHORIZED after automated verification.
COMMIT: NOT YET AUTHORIZED by this package checkpoint.
PUSH: NOT YET AUTHORIZED.
DRAFT_PR: NOT YET AUTHORIZED.
MERGE: NOT AUTHORIZED.
PRODUCTION_MIGRATION_DEPLOYMENT: NOT AUTHORIZED.
TAG_RELEASE: NOT AUTHORIZED.

## Discovery evidence
Current main already has exact `(connection_key, affiliate_id)` and `(connection_key, kol_id)` uniqueness in `kol_affiliate_accounts`, audited/authorized manual binding, and Referral sync that derives `kol_id` from that binding during Provider snapshot sync.
The current gap is deterministic real-source staging/candidate review plus ERP-local historical Referral re-resolution after a reviewed binding; re-fetching Provider Referral snapshots solely to refresh `kol_id` is unnecessary coupling.
Owner replaced the earlier source with canonical `0902-网红订单.xlsx`; read-only audit confirms 1,077 order-grained data rows across 31 columns, with dense exact email/profile-URL identity evidence. P1 preserves repeated source rows while separating distinct identity review from business-history import.
Latest-main drift gate on 2026-09-02: P1 was safely rebased to `origin/main@0a9ab219ac7fa80dfcde1958350503d8206c92d8`; intervening main changes had zero overlap with P1 frozen ownership.

PROJECT POSITION
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate -> MVP1 Existing Operations / Tencent Docs Replacement -> ERP Inquiry -> Remote Milestone -> Woo Inbound -> Tracking Outbound -> Sync Orchestration -> Product/Media -> WordPress Product/Image Sync -> Content/SEO -> Posts/Pages -> GA4/GSC/Cloudflare -> Google Docs Publishing -> Full Order Workspace -> Logistics OS -> KOL OS/Social Providers -> Inventory/Procurement -> Accounting/ROI -> Additional Integrations -> Production Hardening/Expansion
CURRENT_TRACK_PHASE_MAP: KOL V1 Product Closure [COMPLETE] -> Source Access/Audit [COMPLETE] -> P1 Real Data Reconciliation [CURRENT] -> P2 Historical Import Preview [WAITING] -> P3 KOL/Affiliate Binding Closure [WAITING] -> P4 Attribution Closure [WAITING] -> Production Enablement [WAITING SHARED RUNTIME]
COMPLETED_PHASES: KOL V1 cross-phase closure; Tencent source read-only audit; P1 read-only discovery; P1 formal contract freeze
CURRENT_PHASE: P1 local implementation
CURRENT_WORK_PACKAGE: ERP-KOL-REAL-DATA-RECONCILIATION-M1-001
PACKAGE_STATUS: ACTIVE / FORMAL CONTRACT FROZEN / LOCAL IMPLEMENTATION AUTHORIZED
WAITING_PHASES: automated verification; UAT-ready checkpoint; separate Git delivery authorization; P2-P4; Production runtime/activation
BASELINE: origin/main@0a9ab219ac7fa80dfcde1958350503d8206c92d8
GITHUB_STATE: P1 branch local-only with no PR; PR #145 remains separate OPEN/DRAFT; main includes merged PR #151 and warehouse recommendation PR #152
SOURCE_CONFLICTS: AI/CURRENT_CONTEXT.md and older roadmap text are stale relative to merged KOL V1 history; current GitHub/main + current P1 contract govern mutable execution facts. PR #145 shared roadmap/docs remain unmerged and are intentionally not edited by P1.
GLOBAL_GATE: ONE_WORK_PACKAGE_ONE_BRANCH_ONE_PR + ONE_ACTIVE_IMPLEMENTATION_PROVIDER_PER_WORKTREE + NO FUZZY IDENTITY + NO PROVIDER/PRODUCTION MUTATION
PRODUCTION_DEPLOYMENT_STATUS: NO; P1 explicitly forbids Production mutation
NEXT_DEPENDENCY: GPT+DC bounded real-source compatibility remediation, then US-local HIGH_RISK verification
EVIDENCE_AS_OF: 2026-09-02 +08:00

ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 8.0 effective hours planning value; range 6.0-10.5h
KNOWN_CURRENT_TRACK_REMAINING: 8.0h planning value for this frozen P1 only; P2-P4 are not yet formally estimated and cannot be safely summed
ARCHITECTURE_GATE_REMAINING: 0 for the historical architecture foundation; P1 is bounded KOL reconciliation extension
MVP1_TOTAL_ESTIMATE: 122-190h total estimate from document 13; not automatically remaining
MVP1_PLANNING_BASELINE: 166h total planning value from document 13
MVP1_MAXIMUM_ENVELOPE: 192h maximum total planning envelope from document 13
MVP1_REMAINING: UNKNOWN WITH EVIDENCE; accepted actual-hours ledger and overlap map remain incomplete
POST_MVP_KNOWN_INCREMENT: approximately 68-120h beyond MVP1 from document 13; incremental, not added here
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE; not recomputed by this bounded P1 package
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE; parallel completed/open packages and incomplete actual ledger prevent safe arithmetic
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE; no accepted P1 Work Log ledger yet
ESTIMATE_CONFIDENCE: MEDIUM
ESTIMATE_AS_OF: 2026-09-02
ESTIMATE_METHOD: bounded staging schema + secure CSV/XLSX parsing + deterministic candidate engine + review UI + local Referral re-resolution + focused/full regression

ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 0.8h planning value; range 0.5-1.0h
CORE_EXECUTION: 4.5h planning value; range 3.5-5.7h
TEST_AND_VALIDATION: 1.7h planning value; range 1.2-2.3h
REMEDIATION_BUFFER: 1.0h planning value; range 0.8-1.5h
ESTIMATED_EFFECTIVE_HOURS: 8.0h
ESTIMATE_RANGE: 6.0-10.5h
ESTIMATE_BASIS: Frozen two-table staging model, secure bounded parser, exact-only KOL/Affiliate candidate classification, one protected review workspace, existing binding authority reuse and local Referral re-resolution.
OWNER_REVIEW: INCLUDED
MERGE: EXCLUDED
MIGRATION: INCLUDED
MIGRATION_FILE_AUTHORING: INCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED

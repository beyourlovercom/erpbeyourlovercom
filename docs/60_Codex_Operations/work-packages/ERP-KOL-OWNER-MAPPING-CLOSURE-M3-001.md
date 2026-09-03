# ERP-KOL-OWNER-MAPPING-CLOSURE-M3-001

STATUS: ACTIVE / IMPLEMENTED / AUTOMATED_VERIFICATION_PASS / OWNER_REAL_PAGE_PASS / GIT_DELIVERY_AUTHORIZED
EXECUTION_PROVIDER: GPT_WEB
VERIFICATION_TIER: HIGH_RISK
OWNER: Harr
SYSTEM: BYL ERP
BASELINE: origin/main@2cb6ef4481c7a3f129fc6515321ad8e195b5805b
BRANCH: feature/erp-kol-owner-mapping-closure-m3-001
WORKTREE: /Users/a/AI/007/worktrees/erp-kol-owner-mapping-closure-m3-001
DEPENDENCIES: PR #163 / ERP-KOL-HISTORICAL-IMPORT-PREVIEW-M2-001 MERGED
DEPENDENCY_TYPE: MERGED_PR
DEPENDENCY_STATUS: PASS
PARALLEL_SAFE: YES — dedicated worktree, serialized KOL successor package
OWNER_REAL_PAGE_UAT: PASS
LATEST_MAIN_CHECK: origin/main@919d6bce434c39c3fd021d0385d16a17845c501a / 4 commits ahead of package baseline / path overlap = 0

## Purpose
Close historical `Belongs to` owner responsibility before any KOL Master import.
P3A consumes persisted P2 preview candidates and stores only explicit owner mapping / candidate owner decisions.
It does NOT create/update KOLs, bind Affiliates, import historical orders/collaborations/content, or call a Provider.

## Real-source baseline
- P2 preview batch #3: 961 deterministic KOL identity candidates.
- 919 candidates have exactly one source Owner value but no eligible ERP-user match in Local.
- 42 candidates contain multiple source Owner values and require explicit review unless configured mappings converge to one ERP user.
- Source Owner mentions: Young 265, Emma 222, Hiko 198, Summer 144, Zhixi 100, Aries 78, Kelsey 1.
- Single-owner candidates: Young 237, Emma 199, Hiko 195, Summer 128, Zhixi 88, Aries 71, Kelsey 1.

## Persistence contract
`kol_owner_source_mappings`
- normalized `source_owner_key` is unique; preserve display `source_owner_value`.
- `mapped_user_id` must reference one active ERP user currently eligible for KOL visibility.
- record `created_by` and `updated_by`; updates are auditable.

`kol_owner_candidate_decisions`
- stable identity is `(reconciliation_batch_id, cluster_key)` so P2 regeneration does not erase an explicit decision.
- stores `mapped_user_id`, source-owner evidence snapshot and operator reason.
- record `decided_by`; updates are auditable.

P2 tables remain immutable evidence. P3A reads P2 and overlays mapping state; it never rewrites P2 candidate evidence.

## Resolution contract
1. zero source Owner values => `no_source_owner`.
2. one source Owner value + no mapping => `unmapped`.
3. one source Owner value + valid mapping => `mapped`.
4. multiple source Owner values + explicit candidate decision => `decided`.
5. multiple values + every value mapped to the same ERP user => `mapped_converged`.
6. multiple values with missing mappings or different ERP users => `needs_decision`.
7. deleted/inactive/ineligible ERP user invalidates the effective resolution and fails closed.

No fuzzy user match, alias inference, importing-user fallback, or name-only auto-assignment is allowed.

## UX / permissions
Canonical workspace: `/kol/owner-mapping`, reachable from `/kol/import-preview`; no level-1/level-2 menu change.
GET and mapping/decision writes require BOTH `kol.approvals.manage` and `kol.affiliates.manage`.
The page must show a permanent banner: `仅保存 Owner 映射，不创建 KOL、不绑定 Affiliate。`

Minimum UI:
- total candidates / resolved / unresolved / multi-owner queue counters;
- 7-source-owner mapping table with single-candidate and total-mention counts;
- eligible ERP user selector and explicit save/reset action per source Owner;
- multi-owner candidate queue with identity evidence, source owners, effective mappings and explicit final owner decision;
- search/filter and candidate detail evidence;
- no `Confirm Import`, `Create KOL`, Affiliate bind/provision, Provider action or canonical mutation button.

## Audit contract
Every source mapping create/update/delete records audit action `kol.owner_mapping.*`.
Every candidate decision create/update/delete records audit action `kol.owner_decision.*`.
Audit snapshots contain IDs/source labels/status only; no arbitrary provider payload or secret material.

## Explicit prohibitions
- No writes to `kols`, KOL social accounts, collaborations, content, orders, shipments, Affiliate/Referral/Payout/Coupon mirrors.
- No Affiliate binding/provisioning or remote Provider calls.
- No P2 candidate mutation.
- No fuzzy identity or fuzzy Owner matching.
- No Production migration/deploy, tag, release, commit, push or PR without a later explicit Owner gate.

## Acceptance criteria
- Fresh migration / rollback / reapply PASS for P3A tables.
- Specialist without Manager/Affiliate authority receives HTTP 403 on all P3A routes.
- Ineligible/inactive ERP users cannot be saved as mappings or decisions.
- Re-saving the same mapping/decision is idempotent and does not duplicate rows.
- P2 preview regeneration does not destroy a decision keyed by reconciliation batch + cluster key.
- 919/42 real-source split projects correctly before any mapping is saved.
- Mapping one source Owner updates the projected resolved candidate count deterministically without changing P2/KOL/Affiliate facts.
- Multi-owner candidates remain fail-closed until mappings converge or an explicit candidate decision exists.
- Full KOL regression, focused frontend, typecheck, build, PHP lint and diff check PASS.
- Real authenticated US-local page screenshot with bypass disabled is provided to Owner for final PASS.

## Authorization
OWNER_VISUAL_PASS: YES — Owner `pass` on 2026-09-03.
LOCAL_IMPLEMENTATION: AUTHORIZED.
LOCAL_MIGRATION_FILE_AUTHORING_AND_TESTING: AUTHORIZED.
LOCAL_UAT_PREPARATION: AUTHORIZED after automated verification.
COMMIT / PUSH / PR: AUTHORIZED BY ERP-GOVERNANCE-CONTINUOUS-AUTHORIZATION-V1-001 AFTER OWNER UAT PASS.
MERGE: AUTHORIZED AFTER REQUIRED CHECKS BY ERP-GOVERNANCE-CONTINUOUS-AUTHORIZATION-V1-001. PRODUCTION / TAG / RELEASE: NOT AUTHORIZED.

PROJECT POSITION
PROGRAM_ROADMAP: KOL real-data closure → Product OS closure / parallel approved tracks → Production runtime milestone → later ERP expansion
CURRENT_TRACK_PHASE_MAP: P1 real-data reconciliation COMPLETE → P2 identity preview COMPLETE/MERGED → P3A Owner Mapping CURRENT → P4 canonical KOL import WAITING → post-import validation WAITING
COMPLETED_PHASES: P1, P2
CURRENT_PHASE: P3A Owner Mapping Closure
CURRENT_WORK_PACKAGE: ERP-KOL-OWNER-MAPPING-CLOSURE-M3-001
PACKAGE_STATUS: ACTIVE / IMPLEMENTED / AUTOMATED_VERIFICATION_PASS / OWNER_REAL_PAGE_PASS / GIT_DELIVERY_AUTHORIZED
WAITING_PHASES: P4 canonical KOL import and post-import validation
BASELINE: origin/main@2cb6ef4481c7a3f129fc6515321ad8e195b5805b
GITHUB_STATE: PR #163 merged; P3A branch local only; no P3A PR
SOURCE_CONFLICTS: AI/CURRENT_CONTEXT.md is historically stale versus merged main; this WP uses current GitHub main + P2 merged evidence
GLOBAL_GATE: OWNER REAL-PAGE PASS required before closure
PRODUCTION_DEPLOYMENT_STATUS: NO — not authorized
NEXT_DEPENDENCY: P3A engineering + real-page Owner UAT
EVIDENCE_AS_OF: 2026-09-03

ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 5.5 effective hours planning value / 4–7 hour range
KNOWN_CURRENT_TRACK_REMAINING: P3A 4–7h; P4 and later are not yet frozen enough for safe arithmetic
ARCHITECTURE_GATE_REMAINING: 0 for P3A; architecture frozen in this WP
MVP1_TOTAL_ESTIMATE: UNKNOWN WITH EVIDENCE — historical program estimate source is stale relative to current merged roadmap
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE
MVP1_REMAINING: UNKNOWN WITH EVIDENCE
POST_MVP_KNOWN_INCREMENT: UNKNOWN WITH EVIDENCE
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE — no accepted current ledger read for this activation
ESTIMATE_CONFIDENCE: MEDIUM-HIGH for P3A only
ESTIMATE_AS_OF: 2026-09-03
ESTIMATE_METHOD: frozen schema/routes/UI/tests + real 961-candidate data shape

ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 0.5h planning / 0.4–0.8h
CORE_EXECUTION: 3.0h planning / 2.0–4.0h
TEST_AND_VALIDATION: 1.5h planning / 1.2–1.8h
REMEDIATION_BUFFER: 0.5h planning / 0.4–0.7h
ESTIMATED_EFFECTIVE_HOURS: 5.5h
ESTIMATE_RANGE: 4–7h
ESTIMATE_BASIS: two small mapping tables + bounded service/controller/routes + one manager workspace + high-risk regression/UAT
OWNER_REVIEW: INCLUDED
MERGE: EXCLUDED
MIGRATION: INCLUDED
MIGRATION_FILE_AUTHORING: INCLUDED
MIGRATION_EXECUTION: INCLUDED — Local/UAT only
UAT: INCLUDED — US Local real-page preparation/evidence only
DEPLOYMENT: EXCLUDED

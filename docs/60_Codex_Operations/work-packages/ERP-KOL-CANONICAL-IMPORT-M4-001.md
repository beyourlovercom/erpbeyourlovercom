# ERP-KOL-CANONICAL-IMPORT-M4-001

STATUS: MERGED / PASS / COMPLETE / CLOSED
EXECUTION_PROVIDER: GPT_WEB_DIRECT_IMPLEMENTATION
VERIFICATION_TIER: HIGH_RISK
OWNER: Harr
SYSTEM: BYL ERP
BASELINE: origin/main@ec725cef57762f7ddbdf067bfcfaf1bbce9a2f78
BRANCH: feature/erp-kol-canonical-import-m4-001
WORKTREE: /Users/a/AI/007/worktrees/erp-kol-canonical-import-m4-001
DEPENDENCIES: PR #158 P1 reconciliation MERGED; PR #163 P2 import preview MERGED; PR #166 P3A Owner Mapping MERGED
DEPENDENCY_STATUS: SATISFIED
PARALLEL_SAFE: YES — dedicated KOL successor worktree; Packaging and PR #145 worktrees remain separate

## Purpose
Implement the P4 canonical KOL import boundary already identified by P3A: turn one reviewed P2 identity preview plus P3A effective Owner decisions into an auditable, idempotent KOL Master activation plan and a separately gated canonical import action.

P4 is the first package allowed to write canonical KOL Master records, but only in Local/UAT during implementation and verification. Production import, Production migration/deploy and Provider mutation remain hard Owner gates.

## Frozen source hierarchy
- `0902-网红订单.xlsx` / P1 staging = manual KOL CRM/contact/social/shipping/history evidence.
- P2 preview = deterministic identity-level candidate evidence only.
- P3A = explicit Owner mapping / candidate Owner decision authority only.
- `kols.id` = canonical KOL identity after a reviewed import succeeds.
- WooCommerce remains customer Order truth.
- AffiliateWP remains Affiliate/Referral/Commission/Payout provider truth.
- P4 does not import historical Orders, Collaborations, Content, Coupons, commissions or payouts.

## Real-source baseline
Latest retained real preview evidence:
- source rows: 1,077
- distinct exact identities: 970
- deterministic KOL candidates: 961
- unresolved source rows safely excluded: 12
- single-source-owner candidates: 919
- multi-owner candidates: 42
- source Owner values: Young 265 mentions, Emma 222, Hiko 198, Summer 144, Zhixi 100, Aries 78, Kelsey 1
- non-owner review evidence includes 27 field-variation candidates, 5 multiple-email clusters and 2 multiple-Affiliate-account clusters
- Production canonical KOL rows at reconciliation time: 0

## P4 activation-plan persistence
Add non-provider, ERP-local activation evidence with a batch + item model.

`kol_import_activation_batches`
- references one P2 `kol_import_preview_batches.id`
- references its reconciliation batch
- records source checksum / generated-at / generated-by
- records candidate, ready, blocked, existing and skipped counters
- records immutable plan checksum/version after prepare
- states: `prepared`, `applied`, `superseded`, `failed`

`kol_import_activation_items`
- references activation batch + P2 candidate
- unique by activation batch + cluster key
- stores exact readiness status and machine-readable block reasons
- stores effective Owner ID snapshot
- stores canonical proposal snapshot and social proposal snapshot
- stores existing-KOL guard snapshot
- stores source owner evidence snapshot
- stores created `kol_id` only after successful apply
- stores applied timestamp / audit metadata

Activation-plan tables are evidence/control-plane state. They are not a second KOL Master.

## Readiness contract
A P2 candidate is import-ready only when all of these are true at plan generation time:
1. candidate exact-identity cluster still exists in the selected P2 preview;
2. candidate does not resolve to an existing active KOL and is not a P2 identity conflict;
3. P3A projects one valid effective Owner (`mapped`, `mapped_converged`, or `decided`);
4. a nonblank deterministic display-name proposal exists;
5. no hard identity contradiction exists;
6. current exact identity guard re-check does not find a newly created duplicate KOL;
7. activation plan has not been superseded by a newer P2/P3 source state.

Optional source fields that are ambiguous are never guessed. Ambiguous optional values remain evidence and are omitted from canonical write unless an already-frozen P2 deterministic proposal exists. Multiple-email evidence must not silently choose a canonical contact email; the candidate can use deterministic social identity while ambiguous email remains evidence/blank. Multiple Affiliate candidates do not block KOL identity creation by themselves, but P4 never binds an Affiliate.

## Canonical write contract
- P4 imports only `new KOL` candidates that are ready under the plan.
- Existing-KOL candidates are skipped; P4 does not merge/update existing KOLs.
- Canonical creation must reuse the existing KOL application/service boundary and current approval/audit semantics; no raw direct insert that bypasses canonical rules.
- The importing actor must have Administrator-level authority (`system.permissions.manage` plus required KOL permissions).
- Effective P3A Owner becomes canonical `owner_id`.
- `approval_manager_id` and `finance_owner_id` are NOT guessed from historical Owner evidence; they remain fail-closed until explicitly assigned under the existing responsibility model.
- Social accounts are created only from P2 deterministic URL/handle proposals.
- Contact/shipping/tax proposals are written only where P2 produced deterministic canonical proposals; ambiguous source evidence remains staging evidence.
- Apply is idempotent: a candidate already linked to a created KOL cannot create a second KOL.
- Before apply, re-run exact KOL identity guard against current canonical state.
- Apply must be transactional for the selected prepared plan; any failed canonical item rolls the canonical apply back rather than leaving an unreported partial batch.

## Explicit exclusions
- No Affiliate binding in P4. Exact Affiliate binding + local Referral re-resolution is a separate successor step after canonical KOL identity exists.
- No Affiliate provision/status mutation.
- No Coupon provision.
- No payout complete/write-back.
- No Provider HTTP write.
- No historical Order/Collaboration/Content/Shipment import.
- No fuzzy/AI/name/address/order/logistics identity matching.
- No automatic creation of Production ERP users.
- No Production data mutation, migration, deploy, release switch or rollback in this WP implementation phase.

## Permissions / safety
- Read/prepare activation workspace: Administrator authority plus KOL operate/affiliate-management authority.
- Apply canonical import: same Administrator authority plus explicit environment gate `KOL_CANONICAL_IMPORT_ENABLED`.
- Default config value is disabled.
- Local/testing UAT may enable it explicitly.
- Production remains disabled until the separate Owner Production deployment + real-data mutation gate.
- Server-side authorization is authoritative; UI-disabled states are not the security boundary.

## UX contract — visual proposal required before implementation
Canonical route target: `/kol/import-activation`.
Reachable from P3A Owner Mapping only; no new level-1/level-2 menu item.

Page structure:
1. page header + source/preview identity + upper-right Page Header Action Zone;
2. permanent safety banner explaining canonical-write boundary and Production lock;
3. compact KPI row: candidate total / import-ready / blocked / unresolved-source rows;
4. blocker summary chips/counts (Owner, multi-owner decision, identity, data variation, Affiliate evidence);
5. activation-plan Global Data Grid with KOL candidate identity fixed left, configurable evidence/readiness columns, status and row detail action right;
6. row detail exposes exact identity, effective Owner, canonical fields that will be written, ambiguous evidence that will remain omitted, and existing-KOL guard result;
7. `生成 / 刷新导入计划` is a safe non-canonical action;
8. `确认导入到 KOL Master` is the single high-risk primary action, absent/disabled when environment gate is off or plan has no ready items, and confirmation-protected with exact ready count + plan checksum.

Mobile/narrow layouts preserve semantic order; KPI cards stack and the table may horizontally scroll per Global Data Grid contract.

## Acceptance criteria
- visual proposal Owner PASS before tracked UI implementation;
- fresh migration / rollback / reapply PASS for activation tables;
- plan generation mutates only activation evidence tables;
- P3A unresolved Owner candidates are blocked, not imported;
- multi-owner candidates require resolved convergence or explicit P3A decision;
- new exact KOL created after plan preparation causes re-check block, not duplicate creation;
- ambiguous optional source fields never become fabricated canonical facts;
- existing-KOL candidates are skipped without update/merge;
- Local/UAT apply creates exactly one canonical KOL per ready candidate and is idempotent on replay;
- canonical `owner_id` comes only from P3A effective Owner;
- `approval_manager_id` / `finance_owner_id` remain unassigned unless explicitly set through their existing authority path;
- no Affiliate/provider mutation occurs;
- full KOL + Identity regression, frontend tests, typecheck, production build, migration and `git diff --check` PASS;
- after implementation GPT opens the live Local/UAT page for Owner inspection; final Owner UAT PASS is mandatory before Git closure.

## Implementation / verification evidence — 2026-09-04
- Owner pre-development visual proposal: PASS.
- P4 activation batch/item persistence, plan preparation, environment lock, exact-identity recheck, checksum confirmation, transactional apply and idempotent replay implemented.
- Canonical creation reuses `KolService::create()`; Affiliate/Referral/Provider write paths remain outside P4.
- Focused backend: 6 tests / 37 assertions / 0 failures.
- Full KOL + Identity backend: 1,266 assertions / 0 failures; warning-only existing file-read notices remain.
- KOL frontend: 11 files / 93 tests PASS; focused P2/P3/P4 frontend: 10 tests PASS.
- `npm run typecheck`, production build and `git diff --check`: PASS.
- Activation migration isolated SQLite contract: up=2 tables, rollback=0, reapply=2.
- Historical source bytes were not recoverable from current machine, retained temp DBs, or ChatGPT Library; no real PII was fabricated.
- Local/UAT uses clearly labeled `UAT-SYNTHETIC-0902-AGGREGATE-961.csv`, reproducing the verified aggregate shape: 1,077 rows / 961 candidates / 12 unresolved / 919 single-owner unresolved / 42 multi-owner decision-required / 27 field variations / 5 multi-email / 2 multi-Affiliate.
- P4 Local/UAT plan: ready=0 / blocked=961 / existing=0 / skipped=12; blockers: owner_unresolved=919, owner_decision_required=42.
- Real browser validation on `127.0.0.1:18125`: 961 grid rows, apply disabled at ready=0, row details render, mobile 390px has no document overflow, console/page errors=[] .
- Owner live-page UAT: PASS on 2026-09-04. This authorizes Git delivery for P4; Production deployment/migration and real KOL import remain prohibited.
- Post-UAT final regression rerun: KOL + Identity 1,266 assertions / 0 failures; P4 focused 37 assertions PASS; KOL frontend 93/93 PASS; typecheck/build/diff-check PASS.
- Git delivery complete: feature commit `fc46b590eeb38b59ab80342247edf90eae48ac5c`; PR #170 four required CI checks PASS; squash merged to `main@76f0f0342dbb0a6fb67b1318f5bb2517c7e0593e` on 2026-09-04.
- Production and Packaging worktrees were not touched.

## Authorization
DESIGN / CONTRACT PREPARATION: AUTHORIZED by Owner continuation instruction.
TRACKED UI IMPLEMENTATION: COMPLETE — Owner visual PASS received before implementation; live-page Owner UAT PASS received 2026-09-04.
LOCAL/UAT CANONICAL MUTATION TESTING: AUTHORIZED only after visual PASS and implementation, on isolated local/test data.
COMMIT / PUSH / PR / MERGE: follows `BYL_ERP_CONTINUOUS_OWNER_AUTHORIZATION_V1` after required Owner gates.
PRODUCTION DEPLOYMENT / MIGRATION / REAL KOL IMPORT: NOT AUTHORIZED.
PROVIDER MUTATION: NOT AUTHORIZED.

PROJECT POSITION
CURRENT_TRACK_PHASE_MAP: P1 reconciliation COMPLETE → P2 identity preview COMPLETE → P3A Owner Mapping COMPLETE → P4 canonical KOL import CLOSED / MERGED PR #170 → P4B exact Affiliate binding + Referral re-resolution CURRENT / DESIGN_PENDING_OWNER_PASS → post-import validation → Production activation gate
CURRENT_WORK_PACKAGE: ERP-KOL-CANONICAL-IMPORT-M4-001
PACKAGE_STATUS: MERGED / PASS / COMPLETE / CLOSED
GITHUB_STATE: PR #170 MERGED / main squash 76f0f0342dbb0a6fb67b1318f5bb2517c7e0593e
NEXT_EXACT_GATE: ERP-KOL-AFFILIATE-BINDING-REFERRAL-RERESOLUTION-M4B-001 pre-development visual proposal / Owner PASS

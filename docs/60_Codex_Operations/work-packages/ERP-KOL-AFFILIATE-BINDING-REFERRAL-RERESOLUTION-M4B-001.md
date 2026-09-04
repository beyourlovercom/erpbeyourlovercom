# ERP-KOL-AFFILIATE-BINDING-REFERRAL-RERESOLUTION-M4B-001

STATUS: MERGED / PASS / COMPLETE / CLOSED
EXECUTION_PROVIDER: GPT_WEB_DIRECT_IMPLEMENTATION
BASELINE: origin/main@e23d5c27c153f5bba5c1811bf8e6c91645021942

## Dependency chain

- P1 real-data reconciliation: COMPLETE; exact Affiliate candidate and local Referral re-resolution contracts frozen.
- P2 historical import preview: COMPLETE; deterministic identity candidates and Affiliate evidence available.
- P3A Owner Mapping: COMPLETE; source Owner mappings/decisions are explicit and fail-closed.
- P4 canonical KOL import: MERGED / PASS / CLOSED by PR #170.
- Real canonical KOL import remains a separate irreversible real-data gate; P4B implementation/UAT uses local/testing fixtures only until explicitly authorized.

## Objective

Turn an applied P4 canonical KOL activation result plus exact Affiliate candidate evidence into an auditable Affiliate-binding readiness plan, then execute only deterministic existing-KOL bindings through the existing `AffiliateBindingService::bind()` authority. Successful binding must trigger the already-existing ERP-local Referral re-resolution path and must not call WordPress, AffiliateWP, WooCommerce or any other Provider.

P4B is not a new identity engine, not a second binding engine and not a Provider synchronization workflow.

## Canonical authorities

- KOL truth: `kols.id` created/resolved by P4.
- Affiliate provider mirror identity: exact `(connection_key, affiliate_id)` in `kol_affiliate_accounts`.
- Binding mutation authority: existing `AffiliateBindingService::bind()` only.
- Referral local re-resolution authority: existing `ReferralResolutionService::resolve()` only.
- P1/P2 evidence remains evidence; it never overrides current exact mirror state.

## Readiness contract

For each applied P4 item that created/resolved exactly one canonical KOL:

1. Re-read current P4 item, source reconciliation connection and exact Affiliate evidence.
2. Re-read the current Affiliate mirror; never trust an old candidate snapshot as mutation authority.
3. Exactly one current exact Affiliate candidate, unbound, plus one available canonical KOL => `ready_for_binding`.
4. The exact Affiliate already bound to that same KOL => `already_bound`; no duplicate mutation.
5. No exact Affiliate candidate => `no_affiliate`; safe skip, no provisioning or conversion.
6. Multiple candidates, Affiliate bound elsewhere, KOL bound to another Affiliate on the same connection, missing canonical KOL, stale P4 evidence, or identity mismatch => `blocked` / `conflict`.
7. No fuzzy/name/address/follower/product/order inference is permitted.

P4B must never call `AffiliateBindingService::convert()` because P4 already owns canonical KOL creation.

## Mutation contract

- Plan generation is evidence-only and must not bind Affiliates or rewrite Referrals.
- Apply may execute only plan items still `ready_for_binding` after a fresh exact-state recheck.
- Every mutation delegates to `AffiliateBindingService::bind()`; P4B must not update `kol_affiliate_accounts.kol_id` directly.
- The existing bind service must remain responsible for audit evidence and invoking local Referral re-resolution in the same business transaction boundary.
- Re-resolution may change only Referral `kol_id` and `affiliate_resolution_status` for the exact `(connection_key, affiliate_id)`.
- Referral ID, Affiliate ID, canonical Order relation, amount, currency, Provider status, payout ID, dates, context and all Provider facts remain unchanged.

## UX proposal scope

Proposed bounded workspace: `/kol/affiliatewp/binding-activation`.

The page is an Administrator/KOL Manager safety workspace, not a new level-1/level-2 menu item. Entry should be contextual from the completed P4 activation page and/or existing AffiliateWP reconciliation flow.

Visual hierarchy to present for Owner approval before tracked UI implementation:
- source/P4 activation identity and checksum strip;
- KPI cards: eligible KOLs, ready bindings, already bound, no Affiliate, blocked/conflict;
- blocker summary with exact reason counts;
- canonical data grid with fixed KOL identity left, configurable evidence columns, fixed action/status right;
- row detail showing exact source identity, current Affiliate mirror facts, canonical KOL, Referral impact count and preserved Provider facts;
- high-risk confirmation area that states exact binding count and that no Provider write/convert occurs;
- apply action disabled unless at least one item is ready and Local/UAT binding gate is explicitly enabled.

This is a material UI workflow. Per `AGENTS.md`, tracked UI implementation MUST NOT start until the Owner explicitly PASSes a rendered visual proposal.

## Permissions and environment gate

- Workspace/plan preparation: existing Administrator/KOL Manager authority including `kol.affiliates.manage` plus the same elevated P4 management boundary.
- Binding apply: same authority plus an explicit local/testing environment gate; default is disabled.
- No new permission vocabulary unless implementation proves an unavoidable gap; such a change would require scope reconciliation before proceeding.
- Production apply, real-data binding, Production migration/deployment, Provider mutation, tag and release are NOT authorized by this WP.

## Acceptance criteria

- A P4-created canonical KOL with one exact unbound Affiliate is projected `ready_for_binding`.
- Same exact Affiliate already bound to the same KOL is idempotent `already_bound`.
- No Affiliate is a safe `no_affiliate` result and never provisions/converts anything.
- Multiple exact Affiliates, conflicting existing bindings, stale/missing P4 KOL or current-state drift fail closed before any mutation.
- Apply performs a fresh exact-state recheck and never relies only on stored plan evidence.
- Binding uses only `AffiliateBindingService::bind()` and produces existing audit evidence.
- Historical local Referrals for the exact Affiliate re-resolve to the bound KOL without Provider calls.
- Referral/provider/order/payout facts outside local KOL resolution remain unchanged.
- Replaying the same successful plan is idempotent and never creates a second KOL/Affiliate binding.
- Existing KOL/Affiliate/Referral/Payout regressions remain green.
- Focused backend/frontend tests, typecheck, production build, migration rehearsal when applicable, and `git diff --check` PASS.
- Local/UAT browser verification opens every materially affected page/state for Owner inspection; final Owner PASS is mandatory before Git delivery.

## Explicit prohibitions

- No fuzzy identity matching or LLM identity decision.
- No `convert()` or new KOL creation in P4B.
- No Affiliate/Coupon/Referral/Payout Provider mutation or remote API write.
- No direct bypass of the existing binding service/audit authority.
- No Production deployment/migration and no real-data binding under current authorization.
- No new navigation item.
- No tracked UI implementation before pre-development Owner visual PASS.

PROJECT_POSITION: P1 COMPLETE → P2 COMPLETE → P3A COMPLETE → P4 CLOSED/MERGED → P4B CLOSED/MERGED → Production enablement reconciliation CURRENT → Production activation gate
CURRENT_WORK_PACKAGE: ERP-KOL-AFFILIATE-BINDING-REFERRAL-RERESOLUTION-M4B-001
PACKAGE_STATUS: MERGED / PASS / COMPLETE / CLOSED
NEXT_EXACT_GATE: resume ERP-KOL-V1-PRODUCTION-ENABLEMENT-001 against latest main; reconcile remaining Production/real-data gates before any live mutation

## Owner visual gate — 2026-09-04
- Pre-development rendered P4B visual proposal: PASS.
- Tracked UI implementation is authorized within the frozen P4B scope.

## Implementation / verification evidence — 2026-09-04
- Owner pre-development P4B visual proposal: PASS.
- Binding Activation batch/item persistence, plan generation, current-mirror exact recheck, environment lock, checksum confirmation and transactional apply implemented.
- Actual mutation delegates only to existing `AffiliateBindingService::bind()`; `convert()` and Provider writes are absent from the P4B execution path.
- Referral Provider-fact checksum is verified before/after bind; only ERP-local `kol_id` and `affiliate_resolution_status` may change.
- Focused backend: 6 tests / 46 assertions / 0 failures.
- Full KOL + Identity backend: 1,312 assertions / 0 failures; existing warning-only file-read notices remain.
- Full KOL frontend: 14 files / 97 tests PASS; P4+P4B focused frontend: 8 tests PASS.
- Typecheck, production build and `git diff --check`: PASS.
- P4B migration isolated SQLite contract: fresh tables=2, rollback=0, reapply=2.
- Local/UAT uses `P4B-UAT-SYNTHETIC-12.csv`: 12 canonical KOL / 7 ready / 2 already bound / 1 no Affiliate / 2 blocked.
- Real browser validation on `127.0.0.1:18126`: 12 plan rows, detail expansion PASS, apply enabled only under explicit Local/UAT gate, mobile 390px document width=390, console/page errors=[].
- Owner live-page UAT: PASS on 2026-09-04 after direct inspection of the real P4B Local/UAT page. This authorizes Git delivery only; Production deployment/migration and real Affiliate binding remain prohibited.
- Post-UAT final regression: focused P4B 46 assertions PASS; KOL + Identity 1,312 assertions / 0 failures; KOL frontend 97/97 PASS; typecheck/build/diff-check PASS.
- Git delivery complete: feature commit `196d21315b21a023afec571ee1a62e627a62f611`; PR #172 four required CI checks PASS; squash merged to `main@66159c5abd12cf1169a5f3a419b4daefdaa958b2` on 2026-09-04.
- Production and all Provider systems remain untouched.

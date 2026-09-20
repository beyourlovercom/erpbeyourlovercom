# ERP Roadmap + Production Reconciliation V3

Date: 2026-09-19
Canonical baseline: `main@add4df3ced34849fa90a62e919800d04b0f386c9`
Purpose: reconcile current GitHub main, Production reality, stale Work Package metadata, and open-PR hygiene after the 2026-09-05 V2 snapshot.

## 1. Authority

This V3 supersedes `27_ERP_ROADMAP_PRODUCTION_RECONCILIATION_V2.md` for current program status.

Older Work Package statuses remain historical evidence only when they conflict with merged GitHub facts. A Work Package that still says `IN_PROGRESS`, `PR_PENDING`, `READY_FOR_PRODUCTION`, or similar must not override a later merged PR / Production closure.

Truth priority:

1. GitHub `main`
2. merged PR closure evidence
3. current bounded Production release / runtime evidence
4. Work Package historical status text

ERP Changelog remains manual-only and is not changed by this reconciliation.

## 2. Program status matrix — 2026-09-19

| Module | Current program state | GitHub evidence | Remaining roadmap meaning |
|---|---|---|---|
| Architecture / Foundation | CLOSED | Global Architecture Gate previously closed; Production Stability P3/P4 merged | maintenance only |
| Dashboard | PARTIAL / legacy surface | old Dashboard pilot PR #81 remains stale Draft | redesign only if reprioritized; do not merge old branch |
| Order OS | CORE CLOSED / ACTIVE HARDENING | canonical order identity, Woo identity/backfill, multi-shipment, inventory projection, order search fixes through PR #370 | continue bounded UX/data-quality fixes only |
| Logistics OS | V1 CLOSED / PRODUCTION OPERATIONAL | V1 closure #189; review/exception/multi-shipment/historical recovery; admin fulfillment #372; UTC writer fix #373 | automatic fulfillment and provider-cost maturity are later layers |
| Sync / Woo | CORE CLOSED / PRODUCTION OPERATIONAL | hourly Woo inbound, manual today sync, outbound tracking, Sync control center | provider-specific expansion only |
| Customer OS | CORE CLOSED | M1.1–M1.9 merged/UAT closed | later intelligence/CRM extensions only |
| Marketing OS | FOUNDATION ONLY | subscriber foundation + real subscriber sync | campaigns/newsletters/automation remain future |
| KOL OS | V1 CLOSED / PRODUCTION ENABLED | cross-phase closure + production import/binding closure; P4/P4B closed | intelligence/discovery/social automation remains deferred |
| Inventory OS | M1 CLOSED / V2 OPERATIONAL | reservation #193, allocation/replenishment, multi-warehouse, inventory/order matrix, Warehouse OS expansion | data coverage + automation hardening |
| Warehouse OS | ACTIVE / MAJOR V2 COMPLETE | Warehouse Detail/360, MorePlus/US-NY bills, cost reconciliation, Shipment View #365/#371 | US-SM/DE/CA real bill adapters + long-term cost coverage |
| Purchase OS | V1 CLOSED / LIVE | Purchase V1 closure and Production promotion complete | later purchase-document/history expansion only |
| Product OS | CORE CLOSED | Product Final Closure #192; Woo cover sync; shipping-weight candidate work | packaging UI cleanup + Production parity review only |
| Accounting | P9 CLOSED / PRODUCTION OPERATIONAL | Order Profit, A7/A8, P6/P7/P8, P9 #366/#368, live canary closure, UTC fix #373 | coverage/completeness improvement; no P8/P9 reopen |
| Settings / Identity | CORE LIVE | User & Access #92, FX, Changelog read-only | Backup/restore operational maturity and UX cleanup |
| Guide OS | CLOSED | accepted/live | maintenance only |
| YBY Traffic lane in this repo | V0.1–V0.4 MERGED | PR #292/#295/#304/#314 | separate product roadmap; must not silently drive BYL ERP priority |

## 3. Major completed program sequence

```text
Architecture / Shell / Identity
→ Product / Order / Inventory core
→ Manual Fulfillment
→ Woo inbound + tracking outbound + orchestration
→ KOL V1
→ Customer OS
→ Purchase OS V1
→ Product OS final closure
→ Logistics OS V1
→ Inventory V2 reservation / allocation / replenishment
→ Accounting Order Profit / A7 / A8
→ Accounting P6 / P7 / P8
→ Warehouse OS V2 / bill reconciliation
→ Accounting P9 storage aging + FIFO + Order Profit bridge
→ P9 Live Canary
→ Logistics MySQL UTC writer normalization
```

## 4. Current strategic line

The old V2 line `Production Consolidation → Fulfillment Intelligence` has substantially executed.

Current line is now:

```text
GitHub / Production Truth Reconciliation
→ Production Stability + Historical Catch-up Audit
→ Warehouse real-bill coverage (US-SM / DE / CA)
→ Inventory / Fulfillment automation hardening
→ Accounting completeness improvement
→ Product/Packaging cleanup
→ Marketing / Email OS expansion
→ Global Search / Dashboard redesign only when reprioritized
```

Do not reopen completed KOL V1, Logistics V1, Accounting P8, or Accounting P9 foundations unless a bounded regression proves a defect.

## 5. Immediate repair backlog

### P0 — governance / source-of-truth repair

1. **Roadmap metadata drift**
   - V2 is dated 2026-09-05 and is no longer representative.
   - Many Work Packages still report stale `IN_PROGRESS` / `PR_PENDING` states despite later merged closures.
   - V3 becomes the current authority; Work Package metadata should be reconciled incrementally, not trusted blindly.

2. **Open PR cleanup — PASS / ACTIVE SET RECONCILED 2026-09-19**
   - Six superseded Draft PRs were closed during this reconciliation: #276, #247, #226, #145, #81 and #20.
   - Parked PRs remain #335, #232 and #97; all require explicit re-evaluation/rebase before merge.
   - #375 US-SM Warehouse Inbound V1 is a current active Warehouse workstream and is not classified as parked/stale.

3. **Remote branch hygiene — FIRST SAFE PASS COMPLETE 2026-09-19**
   - Pre-cleanup repository had 338 remote heads.
   - Latest ancestry audit identified 249 branches fully contained in `main`.
   - 174 merged branches were deliberately retained because they are still attached to local US-Mac worktrees.
   - 75 merged branches with no open PR, no protected status, and no local worktree were deleted from the remote.
   - Post-cleanup: 266 remote heads total = `main` + 265 non-main; 174 merged/worktree branches retained; 91 divergent branches retained for review.
   - No divergent branch, open-PR branch, protected branch, or active worktree branch was deleted.

4. **CI infrastructure — ACCOUNT-SIDE EXTERNAL BLOCKER CONFIRMED 2026-09-19**
   - PR #377 Production Stability run 35421269204 ended in about one second with both jobs `steps=[]`, `runner_id=0`, and no runner name/log artifact.
   - The same account condition was already surfaced by GitHub on PR #335: "The job was not started because recent account payments have failed or your spending limit needs to be increased."
   - This is not repairable through repository code. The account owner must restore a valid GitHub billing/payment path and/or an Actions budget that allows usage.
   - Until restored, exact-head local SQLite/MySQL evidence plus an explicit infrastructure exception may be used; CI must never be described as green.

### P0 — Production data/stability audit

5. **Logistics timestamp catch-up audit — PASS / CLOSED 2026-09-19**
   - PR #373 fixed future ShipmentService MySQL writes by normalizing the session to UTC.
   - Bounded read-only audit covered 2026-09-10 through PR #373 and resolved fulfillment-derived children to the parent customer Order.
   - Population: 1,846 Shipment-linked eligible customer Orders.
   - Logistics-sensitive preview result: warehouse_cost apply=0; last_mile_estimate apply=0; last_mile_actual apply=0; overseas_handling_actual apply=0.
   - The live P9 canary order 7008 was already exact-gated and converged to noop; no second Order needs catch-up.
   - No blanket replay required. Evidence: `ERP-ACCOUNTING-CONTINUOUS-DELTA-HISTORICAL-CATCHUP-AUDIT-2026-09-19.md`.

6. **Accounting MySQL test-fixture timezone contract — PASS / CLOSED 2026-09-19**
   - Reproduced on isolated MySQL 8.4.11 with `SYSTEM=PDT`: 7 fixture-related failures.
   - MySQL test setup now writes fixtures under `+00:00`; the dedicated runtime-normalization test still resets to `SYSTEM` and verifies CandidateStore switches to UTC.
   - The DDL-based runtime-failure fixture was replaced with a deterministic throwing evidence reader to avoid MySQL implicit-commit/savepoint breakage.
   - Full `AccountingContinuousDeltaTest` now passes on MySQL: 18 tests / 95 assertions / 0 failures.
   - Production Stability CI now includes this MySQL Continuous Delta gate.
   - Evidence: `ERP-ACCOUNTING-CONTINUOUS-DELTA-MYSQL-FIXTURE-NORMALIZATION-2026-09-19.md`.

### P1 — business/data coverage

7. **Warehouse provider coverage — PARTIAL / EXTERNAL BILL EVIDENCE BLOCKED**
   - MorePlus / US-LA is active with one applied Production bill batch.
   - US-NY Speeda provider adapter is active with five applied Production bill batches.
   - US-SM inbound/transit/restock V1 is merged via PR #375; this is inbound inventory evidence, not a warehouse-bill adapter.
   - US-SM, DE and CA currently have zero Production warehouse-bill batches and no real bill samples were recovered from US/CN Macs or connected Google Drive.
   - Existing US-SM PI/labels and DE/CA inventory snapshots are not billing evidence and must not be used as substitutes.
   - No guessed provider mapping.

8. **Accounting completeness — ACTIVE / EVIDENCE AUDIT COMPLETE 2026-09-19**
   - P9 is closed; focus has shifted to missing evidence coverage rather than new cost engines.
   - Production read-only evidence audit: 5,569 revenue-ready customer Orders; 3,718 currently lack canonical Shipment; only 33 currently have all six P8.2 required cost types present.
   - Product Cost is the largest evidence gap: 292/5,569 revenue-ready Orders currently have product_cost. The existing 57 confirmed source rows intentionally begin at 2026-07-01 and must not be back-propagated without historical cost evidence.
   - KOL immutable-FX replay false positives are fixed and Production dry-run converged from apply=645 to apply=0 / noop=2,417.
   - Three last-mile estimate apply candidates are refreshes of existing estimates, not missing-cost backfills; no cosmetic Production write was performed.
   - Historical Shipment evidence: HFR-5 and HFR-6 are CLOSED/UAT PASS. HFR-6 ended READY=0 / REVIEW=3,627 / SKIP=1,727. The dominant remaining frozen discovery blocker is missing approved historical warehouse evidence (~2,966 candidates). HFR-7 searched US Mac + Library and did not recover the original Tencent row-level warehouse source, so further backfill is evidence-blocked and must remain fail-closed.
   - **Progressive Estimated Profit V1 — CLOSED / PRODUCTION ACTIVE / OWNER UAT PASS** via PR #386 + list binding hotfix PR #387. Valid revenue always produces a numeric Estimated Profit; missing costs remain visible projection-only fallbacks and are progressively replaced by real evidence; 10/10 confirmed remains the only Final Profit gate. Production Owner UAT confirmed list/detail parity on `007-0919-c40801`: USD 30.35 estimated total cost -> USD 48.65 estimated profit -> 61.58% margin, while Final Profit correctly remained pending.
   - Product Cost Evidence Coverage audit refreshed 2026-09-20: post-July revenue-ready Orders=1,141; canonical product_cost=294; mixed=231; fully missing=616; 53 used Products have no confirmed source. Top 5 missing SKUs would cover 76.7% of 846 affected Orders; Top 20 would cover 95.6%. Original 007 cost book was not recovered from US/CN Macs, ChatGPT Library, or connected Google Drive. Confirmed Product Cost expansion is therefore external-evidence blocked; Progressive Estimated Profit remains the safe fallback.
   - Product-cost ambiguity and other missing facts remain explicit until evidence exists; do not fabricate confirmed facts.
   - Evidence: `ERP-ACCOUNTING-EVIDENCE-COMPLETENESS-KOL-FX-REPLAY-2026-09-19.md`, `ERP-HISTORICAL-FULFILLMENT-RECONCILIATION-HFR7-EVIDENCE-BOUNDARY-2026-09-19.md`, `ERP-ACCOUNTING-COMPLETENESS-SEMANTICS-V1-2026-09-19.md`, and `ERP-ACCOUNTING-PROGRESSIVE-ESTIMATED-PROFIT-V1-2026-09-20.md`.

9. **Product / Packaging UI**
   - PR #232 is an old one-file packaging comparison Draft.
   - Re-evaluate against current Product/Packaging UI before merge; do not merge as-is from the old baseline.

10. **Email OS ERP read-only consumer — MERGED / PRODUCTION ACTIVE 2026-09-20**
    - PR #335 was rebuilt cleanly on current main and merged as a88dd96592e1f5c7a4984440b29dce202b17be52.
    - The stale unrelated Finance OrderAnalysis.vue change was removed; final scope is exactly 9 Email OS / WordPress Connector files.
    - Production release: email-os-p8c-pr335-20260920-a88dd965.
    - Production Connector contract is email-os-published-v1, read_only=true; current Published Native count is 0.
    - ERP shows metadata only and exposes no Email OS edit/publish/rollback/send action. WordPress Email OS remains source of truth.
    - Prior isolated Owner UAT remains valid; Production technical UAT PASS on the current empty Published set.

### P2 — active / parked

11. **Global Search V1 — PRODUCTION ACTIVE / TECHNICAL UAT PASS / OWNER UAT PENDING 2026-09-20**
    - PR #395 merged as 7460d816cdd550dacab300a2e4bffc4e5284b1db and deployed code-only.
    - V1 is bounded to Product + Order + Inventory Context through one Search Provider contract and one Header overlay.
    - Search is read-only navigation infrastructure; source modules remain authoritative.
    - Product identifiers: SKU, name, GTIN, Woo Product ID and SKU aliases.
    - Order identifiers: dbid technical lookup, ERP/internal/external IDs, provider references and tracking number.
    - Inventory results are Product + Warehouse context and deep-link to the existing filtered Inventory view.
    - No migration or external search engine in V1; performance indexes remain a later evidence-driven hardening step.
    - PR #97 remains a broader entity-architecture design reference and is not implementation authority for this branch.

12. **Dashboard redesign**
    - PR #81 is a stale experiment and should not be revived as-is.
    - Start a fresh dashboard package if/when dashboard becomes a priority.

13. **Marketing expansion**
    - subscriber foundation is real; campaigns/newsletters/automations remain future scope.

14. **KOL intelligence**
    - discovery, social scraping/OAuth, automated scoring/outreach remain deferred and outside KOL V1 closure.

## 6. Open PR classification

### Closed as superseded during this reconciliation — 2026-09-19

- #276 — customer tracking review gate: superseded by later Logistics review/exception/fulfillment architecture.
- #247 — P0AB 5xx diagnostics: superseded by merged Production Stability observability/performance work.
- #226 — Manual Fulfillment Pilot M1: superseded by real Production fulfillment and later Inventory/Logistics automation.
- #145 — KOL V1 Production Enablement Readiness: superseded by KOL Production closure.
- #81 — old Dashboard pilot: stale implementation experiment.
- #20 — old Menu temporary-closure docs: current menu/runtime has evolved; statements are no longer current.

### Current open PR classification

- #232 — Product packaging comparison UI: PARKED UX intent; current Product Packaging page already carries the important operational capabilities; do not merge the old one-file UI patch without reprioritization.
- #97 — Entity Architecture V2 / Search Standard: PARKED design reference only.

Recently closed from the prior open set:
- #375 — US-SM Warehouse Inbound V1: MERGED / OWNER UAT PASS.
- #335 — Email OS P8C: MERGED / PRODUCTION ACTIVE.

## 7. Issue classification

- Issue #91 — User & Access M1 was already implemented and merged by PR #92; closed as completed on 2026-09-19.
- After this reconciliation there are no known open roadmap-governance issues requiring immediate action.

## 8. Repository hygiene rules going forward

- One new bounded Work Package = one branch = one PR.
- After merge/closure, branch cleanup should happen in batches using ancestry evidence.
- Closed programs must have one canonical closure document and must not leave an old active marker.
- Draft PRs older than the current canonical roadmap require explicit `PARKED`, `SUPERSEDED`, or `ACTIVE` classification.
- Roadmap reconciliation should be refreshed whenever Production/main divergence or active-program priority materially changes.
- GitHub main remains the only code/execution source of truth.

## 9. Current execution priority

```text
P0. GitHub roadmap/open-PR cleanup ✅ CLOSED
P0. Logistics timestamp catch-up audit ✅ CLOSED
P0. Full MySQL Continuous Delta fixture repair ✅ CLOSED
P0. GitHub Actions billing/runner restoration ⚠ ACCOUNT-SIDE OWNER ACTION
P0. Remote branch hygiene first safe pass ✅ 75 DELETED / 174 WORKTREE-BOUND RETAINED
P1. Warehouse provider coverage: US-LA + US-NY active; US-SM / DE / CA real-bill adapters ⚠ EXTERNAL SAMPLE BLOCKED
P1. Accounting evidence completeness: Progressive Estimated Profit active; confirmed Product Cost expansion ⚠ EXTERNAL COSTBOOK BLOCKED
P1. Email OS P8C ✅ MERGED / PRODUCTION ACTIVE
P1. Product/Packaging comparison UI ⏸ PARKED
P2. Global Search V1 ✅ PRODUCTION ACTIVE / TECHNICAL UAT PASS → OWNER UAT PENDING
P2. Marketing expansion / Dashboard redesign / KOL intelligence
```

This sequence intentionally prioritizes truth, stability, and real-data coverage over starting another broad feature program.

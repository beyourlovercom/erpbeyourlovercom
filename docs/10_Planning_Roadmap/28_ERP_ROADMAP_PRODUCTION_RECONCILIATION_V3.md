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

2. **Open PR cleanup**
   - Six superseded Draft PRs were closed during this reconciliation: #276, #247, #226, #145, #81 and #20.
   - Remaining open parked PRs: #335, #232 and #97.
   - These three must be explicitly re-evaluated/rebased before any merge.

3. **Remote branch hygiene**
   - Remote repository has 338 heads total: `main` + 337 other branches.
   - Ancestry audit: 247 non-main branches are already fully contained in `main` and are safe cleanup candidates after a name/protection sanity check.
   - 90 non-main branches are not ancestors of `main` and require review before deletion.
   - Do not bulk-delete divergent branches blindly.

4. **CI infrastructure**
   - Recent Production Stability runs can fail before steps execute (`steps=null`).
   - Treat these as infrastructure failures, not green and not code-test failures.
   - Restore a reliable GitHub Actions billing/runner path before relying on CI status as a merge gate.

### P0 — Production data/stability audit

5. **Logistics timestamp catch-up audit**
   - PR #373 fixed future ShipmentService MySQL writes by normalizing the session to UTC.
   - Before #373, Logistics writes used MySQL SYSTEM timezone while Continuous Delta queried in UTC.
   - Run one bounded read-only audit for changes between the C1 UTC normalization rollout and PR #373 (approximately 2026-09-10 through 2026-09-19).
   - Identify orders whose Logistics/FIFO timestamps may have fallen outside Continuous Delta windows.
   - Repair only proven missed materializations with exact per-order gates; no blanket replay.

6. **Accounting MySQL test-fixture timezone contract**
   - The focused MySQL contracts pass.
   - The full `AccountingContinuousDeltaTest` under MySQL exposes 7 pre-existing fixture assumptions where tests manually write UTC-looking TIMESTAMP strings under SYSTEM session.
   - Normalize those fixtures so the full MySQL suite becomes a trustworthy gate.

### P1 — business/data coverage

7. **Warehouse provider coverage**
   - MorePlus / US-LA is active.
   - US-NY provider adapter is now implemented.
   - US-SM, DE and CA still need real bill samples/provider adapters before upload can safely enable.
   - No guessed mapping.

8. **Accounting completeness**
   - P9 is closed; focus shifts to missing evidence coverage rather than new cost engines.
   - Product-cost ambiguity and other missing facts should remain explicit incomplete states until evidence exists.
   - Do not fabricate fallback facts.

9. **Product / Packaging UI**
   - PR #232 is an old one-file packaging comparison Draft.
   - Re-evaluate against current Product/Packaging UI before merge; do not merge as-is from the old baseline.

10. **Email OS ERP read-only consumer**
    - PR #335 is still conceptually useful.
    - Rebase/revalidate on current main; remove unrelated stale baseline drift from its PR scope before merge.

### P2 — parked/future

11. **Entity Architecture / Global Search**
    - PR #97 remains a design reference, not current implementation authority.
    - Refresh only when Global Search becomes an active bounded Work Package.

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

### Remaining open / parked after cleanup


- #335 — Email OS P8C: keep, rebase, revalidate.
- #232 — Product packaging comparison UI: keep only as UX intent; re-evaluate against current page.
- #97 — Entity Architecture V2 / Search Standard: parked design reference only.

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
P0. GitHub roadmap/open-PR cleanup
P0. Logistics timestamp catch-up audit
P0. Full MySQL Continuous Delta fixture repair
P1. Warehouse provider coverage: US-SM / DE / CA
P1. Accounting evidence completeness
P1. Rebase Email OS P8C if still desired
P1. Product/Packaging UI cleanup
P2. Marketing expansion / Global Search / Dashboard redesign / KOL intelligence
```

This sequence intentionally prioritizes truth, stability, and real-data coverage over starting another broad feature program.

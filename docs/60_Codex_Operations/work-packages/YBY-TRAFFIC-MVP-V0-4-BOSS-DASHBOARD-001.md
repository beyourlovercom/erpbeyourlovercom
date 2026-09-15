# YBY-TRAFFIC-MVP-V0-4-BOSS-DASHBOARD-001

## Goal
Deliver the Release A owner-facing dashboard that combines persisted Google Ads traffic metrics with V0.3 inquiry analytics projections for the YBY Irrigation project.

## Canonical scope
- Route: `/marketing/traffic`
- Default project: `yby-irrigation`
- Permission: reuse existing `marketing.view`; no new RBAC truth in V0.4.
- Read-only dashboard only; no schema migration, scheduler, Google Ads mutation, Case/Customer mutation, or Production data write.
- Default date window: trailing 30 calendar days; explicit ranges capped at 366 days.

## Metrics
- Spend in source-account currency, impressions, clicks, CTR, CPC.
- Project-level inquiries and blended cost per inquiry.
- Daily spend and inquiry trends.
- Google Ads campaign traffic table.
- Inquiry source split (`form` / `WhatsApp`).
- Data freshness and sync-mode disclosure.

## Truth boundaries
- `qualified_inquiries` MUST render unavailable until canonical Case truth exists.
- Provider conversion counts MUST NOT be relabeled as qualified inquiries.
- Inquiry projection MUST NOT be back-attributed to Campaign without deterministic attribution evidence.
- Mixed-currency projects MUST NOT present a fake summed money total.
- Dashboard data is persisted analytics truth; V0.4 does not call Google Ads live during page render.

## Validation gates
1. Focused backend and Vue tests.
2. Existing Traffic V0.1–V0.3 regressions.
3. App-shell navigation regression.
4. Build + `git diff --check`.
5. Read-only real Production payload verification.
6. Latest-main reconciliation before PR.

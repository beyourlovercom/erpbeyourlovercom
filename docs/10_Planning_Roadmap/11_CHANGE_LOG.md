---
type: roadmap-change-log
project: BYL ERP
version: 1.0
---

# Roadmap Change Log

## 2026-07-31 — Planning foundation

Added:

- complete requirements;
- final-compatible MVP1 principle;
- one-month Tencent Docs replacement objective;
- Finance / Accounting order analysis;
- sync naming and providers;
- Product/SEO requirements;
- KOL social enrichment;
- effective-hours baseline;
- Obsidian/Codex operating system.

Current implementation status was not changed.

## 2026-07-31 — 001B merged / 001C prepared

- 001B Authentication / Authorization merged in PR #1 at `5e100cc8e80480ecb6dbd1b38cbfbe9bddba12f4`.
- 001C application shell branch prepared; implementation not started.
- Global Architecture Gate remains FAIL; deployment remains NO.

## 2026-08-01 — 001C merged / 001D prepared

```text
Owner decision: Accepted and merged PR #5; proceed to 001D preparation only.
Previous state: 001C current / implementation active or pending merge.
New state: 001C MERGED / PASS; 001D CURRENT / PREPARED; implementation authorization NO.
Reason: Complete the ordered architecture migration sequence.
Affected modules: Application Shell, Runtime, Audit, Sync.
Affected Work Packages: ERP-ARCH-MIGRATION-001C, ERP-ARCH-MIGRATION-001D.
Estimate impact: No MVP estimate change in this governance package.
Architecture conflict: NONE.
Documents updated: AI/CURRENT_CONTEXT.md; docs/10_Planning_Roadmap/04_LIVING_ROADMAP.md; docs/10_Planning_Roadmap/10_DECISION_REGISTER.md; docs/10_Planning_Roadmap/11_CHANGE_LOG.md; docs/10_Planning_Roadmap/12_WORK_PACKAGE_INDEX.md; docs/60_Codex_Operations/logs/2026-08-01_ERP-ARCH-MIGRATION-001C.md; docs/60_Codex_Operations/work-packages/ERP-ARCH-MIGRATION-001D.md.
```

## 2026-08-01 — 001D scope frozen and split

```text
Owner decision: Freeze 001D into three ordered, independently authorized implementation subpackages.
Previous state: 001D was one prepared Runtime / Audit / Sync package with a preliminary 51-hour execution concept.
New state: 001D remains CURRENT / PREPARED; 001D-A Runtime Foundation is PREPARED / AUTHORIZATION NO; 001D-B Audit Foundation and 001D-C Sync Outbox Foundation are WAITING; execution order is 001D-A → 001D-B → 001D-C.
Reason: Bound implementation, review, Migration authoring and risk into independently executable packages before any implementation authorization.
Affected modules: Runtime, Audit, Sync Outbox.
Affected Work Packages: ERP-ARCH-MIGRATION-001D, ERP-ARCH-MIGRATION-001D-A, ERP-ARCH-MIGRATION-001D-B, ERP-ARCH-MIGRATION-001D-C.
Estimate impact: Replaced the preliminary 51-hour concept with 001D-A 12–18h, 001D-B 9–14h and 001D-C 14–22h; combined planning value 45h and range 35–54h.
Architecture conflict: NONE.
Documents updated: docs/60_Codex_Operations/work-packages/ERP-ARCH-MIGRATION-001D.md; docs/10_Planning_Roadmap/10_DECISION_REGISTER.md; docs/10_Planning_Roadmap/11_CHANGE_LOG.md; docs/10_Planning_Roadmap/12_WORK_PACKAGE_INDEX.md.
```

## Change entry format

```text
Date:
Owner decision:
Previous state:
New state:
Reason:
Affected modules:
Affected Work Packages:
Estimate impact:
Architecture conflict:
Documents updated:
```

# ERP V4 Warehouse Map Native Port V1

Owner scope: migrate the previously accepted ERP2 offline map to ERP1 only, with explicit ERP1 release authorization. No ERP2 release, no DB or provider writes, no new maps service, no change to allocation/permissions/SKU behavior.
Execution provider: GPT_WEB via authorized self-built DC; US Mac mini isolated worktree; verification tier MILESTONE_RELEASE. Existing ERP1 source remains Laravel + Vue + Inertia.

- Baseline main: 3ed90f9ae25387a805d9f5485fbfaf5a35f6c9fd; dependency merged PR #875 (map source/data, not its other overlays).
- Worktree: /Users/a/AI/worktrees/erp-native-warehouse-map-v1-20261127; one provider; branch feat/erp-native-warehouse-map-v1-20261127.
- Parallel preflight: checked open PRs #887/#888/#889/#859 changed-file lists; no overlap with native allocation Vue/map files. Do not edit other worktrees, ERP2 assets, backend, shell, Grid, or pending V4 integration.
- Acceptance: reuse actual offline map, country/city estimate, warehouse distance ranking, zoom and world/region/nearest, existing item/warehouse data including replacement availability. No external request, no allocation or inventory authority inferred from map. Unconfirmed availability stays unknown; 007 is not a shipping warehouse.
- Main source typecheck PASS; map focused Vue/logic tests 5/5 PASS; Vite build and workspace-tab protection PASS. Broad allocation regression on baseline 26 passed / 5 failed; candidate 29 passed / same exact 5 failed before new non-US test. Historical failures relate to already changed manual stock/gift confirmation policy, not a new map regression. No business logic or legacy test expectations changed to hide them.
- Exact-head CI, real browser isolated test, and readback-controlled production release required. Production changelog only after verified release; no schema or data writes, no DB backup required under risk-based rule for UI-only change.
- Rollback: keep currently live immutable release and verify release pointer before a scoped switch. Never wholesale deploy unrelated main differences.
- Current track M0 source verified -> M1 native port -> M2 field adaptation -> M3 automated/browser checks -> M4 CI/merge -> M5 ERP1-only controlled release.
- Baseline global roadmap estimates and actual program-wide work-log hours not current/available in this package; no invented totals. Source conflicts: historical global context is stale; current GitHub and runtime take precedence.

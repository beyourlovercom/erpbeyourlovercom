# R4-5 国内发货今日速达 / 今日纬狮 Global Grid

Owner: R4-4 今日订单 Production visual PASS (2026-11-11).
Each provider view remains independently gated by Owner UAT and separate
Production release authorization. No new bulk toolbar.

## R4-5A 今日速达 (4PX) — this PR
- Original domesticTodayProvider query with 4px carrier unchanged:
  reviewed pending 4PX orders plus 4PX shipments dispatched today;
  order creation date is not a filter. Exclude Weship/old shipped.
- Grid defaults on for today-4px; global_grid=0 is original-table fallback.
- One original 4PX export: /logistics/china/export/4px. Preserve the
  27-column XLSX, 69-barcode and quantity data, no new file format.
- Tracking return upload/preview/Apply stays on original pending queue.
- Original authorized right-side re-review, website sync/retry, read-only
  EU 69-barcode action and single common selection footer.
- Owner Compact Workspace V1: short header and tabs, compact provider card,
  descriptive instructions in small bottom footer.

## R4-5B 今日纬狮 (Weship) — prepared but default OFF
- Reuse the same daily provider action cell, footer, compact layout and
  Weship-specific 24-column download endpoint.
- Weship stays original table by default until Speeda Production Owner PASS.
- Follow-up PR to enable Weship Grid independently. Do not activate in
  this PR; no third-party file import or backend changes.

## Release verification
- PHP: exact provider filtered population and Grid/native parity, search,
  20/50/100 pagination, old fallback, other four views native.
- Vue: direct authorized actions, no extra download/upload/toolbar,
  69-barcode and footer, staff permissions.
- Disposable SQLite Chrome: provider isolation, original download route,
  right actions, Grid row, fallback, no real Production writes.
- GitHub CI then-current head, Production live source hashes and rollback.
  Production deployment requires explicit Owner release approval; Owner
  visually PASS each view before activating the next.
- Never perform actual shipping, Tracking Apply or Woo sync in Production
  for visual testing.

State: development branch; PR #713 CI final HEAD pending. Local PHP 55/55 (922 assertions), Vue 31/31, typecheck/build PASS, isolated Chrome 14/14 PASS, cleanup PASS. Production not changed and R4-5B not activated.

## Coexistence: 4PX Chinese labels PR #710
Feature branch merged source main a830c499 with the provider Chinese-label
fix. Both modifications co-exist in Index.vue; rerun PHP 55/55, Vue 31/31,
Chrome 14/14 and CI on merge commit. Deployment must preserve the then-current
4PX Chinese labels and its original XLSX template, not copy older frontend.

## PR #712 compact workspace reconciliation
Latest production after owner approval advanced to PR #712 Compact Workspace
and PR #715 Chinese labels. This branch is merged with the then-latest main
and intentionally preserves shared CompactWorkspaceToolbar, CompactWorkspaceHelp,
all-nine-view compact layout and blocking provider warnings. The R4-5-specific
old footer/CSS were removed to prevent duplicated help or layout regressions.
Do not deploy an old release candidate based on PR #710. Re-run tests/CI and
build a new allowlisted overlay against the actual Production pointer.

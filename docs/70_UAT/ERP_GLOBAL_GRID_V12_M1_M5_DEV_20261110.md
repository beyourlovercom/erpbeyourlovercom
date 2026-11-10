# Global Grid V1.2 M1-M5 Dev delivery / 2026-11-10

Owner requested continuous implementation M1-M5 under the REDUCED 7-13h contract. Builds on L1 Draft PR #679. Does not authorize Production rollout. Shipment/OMS/Tracking and unrelated permissions unchanged.

## Implemented
- M1: OmsGrid uses exactly one existing server-pagination footer. Redundant title subtitle counts removed. Generic GridBatchFooterControls appears only when a row is selected; dropdown conditional, Apply only with action selected, reason input inline in SAME bottom row.
- M2: domestic review/stockout right-pinned direct actions. Approve, stockout, reject with inline reason, restock and reopen reuse existing authorized endpoints. Checkbox selection pinned left, current server page only. No review/stockout popup or extra table.
- M3: bounded max 50 distinct ID bulk API for domestic review REUSES DomesticReviewDecisionService::decide transactions/status gates/audit. Independent archive batch API directly loops through existing OrderOperationalArchiveController::archive with the original actor/KOL/lock/audit checks and per-order result. U4 excluded-logistics feature CANCELLED.
- M4: TWO active financial report query builders exclude direct operational_archived_at and children whose fulfillment parent is archived; posted historic finance records remain intact.
- M5: PHP/Vitest/TypeScript/Vite, disposable Chrome, pending exact-head GitHub CI and Owner UAT. Separate Production authorization required.

## Automated evidence
- PHP: four domain suites, 42 passed, 835 assertions.
- Vue: TypeScript PASS, four targeted suites 32/32 PASS.
- Vite production build PASS.
- Chrome isolated SQLite: Grid suite 4/4 PASS, including single footer, selected count, dropdown, Apply conditional, row inline reject reason, original list fallback, allocation and read-only review sidecar. Disposable SQLite cleanup PASS.
- Additional pinned-right regression check and GitHub CI will be read back on final head.

## Owner acceptance and rollout caveat
- Isolated UAT is testing/SQLite, synthetic data only, no OMS/WWW calls.
- Verify /logistics/china?view=review right-side button/inline reason and one bottom row. No write should be triggered on Production.
- Finance exclusion is active reports only and does not erase settled historical evidence.
- **Known parity limitation:** Other China logistics tabs still use their original specialized carrier/Tracking controls via the native table bridge. These tabs have NOT achieved full no-accordion parity. This delivery is review/stockout canary only, not approval to switch all operational tabs on Production.
- Keep L2/L3 migration and unconditional Production default behind Owner UAT and per-view parity gates.

## Owner reproducible UAT environment, verified after latest commit
- Temporary Tailscale URL: http://100.94.52.109:18784/logistics/china?view=review
- Testing-only login: admin@local.test / password. This login exists ONLY inside the fail-closed, disposable SQLite E2E UAT database; never uses the Production database or changes Production credentials.
- Nine synthetic pending-review rows verified visible in the current Dev Grid. Zero selected = no batch controls. Selecting one synthetic order => one footer indicator and four choices: approve, stockout, reject, archive. The excluded-logistics operation is absent.
- Actual AG Grid v34 pinned action class (ag-cell-first-right-pinned) verified and full real Chrome Grid suite 4/4 PASS with corresponding assertion on branch after rebase.
- The UAT page permits execution against **synthetic test records** only; do not confuse results with real Production transactions. Owner visual PASS still pending. The permanent source of truth is exact GitHub Draft PR #679 head.

## 2026-11-10 Owner reported CSRF 419 on batch archive
- Screenshot: 2 synthetic rows selected, batch archive Apply shows CSRF token mismatch. CSRF validation rejects the HTTP write before executing any archive action.
- Root-cause class: in long-lived Inertia sessions the original Blade meta token can become stale after session regeneration or another tab refresh. The old fetch handler always preferred the page meta token over the current XSRF-TOKEN cookie. The screenshot alone cannot prove which session transition occurred.
- Minimal Dev fix: all Domestic importPost requests now prefer the current, URL-decoded XSRF-TOKEN cookie as X-XSRF-TOKEN, falling back to Blade meta only if the cookie is unavailable. On HTTP 419 show an explicit Chinese session-token message without ANY automatic write retry. No CSRF bypass or backend security weakening.
- Proof: CSRF helper 3 Vitest PASS; typecheck/build PASS; isolated Chrome Global Grid 6/6 PASS, including a deliberately tampered/stale meta token followed by a REAL batch archive against exactly one synthetic order, with success readback, and clean disposal of test database. Original browser login diagnostic: fresh meta and cookie pathways both functioned when the session was valid. The stale-meta scenario was deliberately injected for reproducibility.
- Browser clients holding the old pre-build JS asset must refresh the Dev UAT page once to load the fix. Never publish Production without Owner approval.

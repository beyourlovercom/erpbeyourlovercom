# MorePlus R11 — selected-order or all-pending batch with double confirmation

## Scope
- Reuses R8/R9 one-shot attempt ledger, 14-day content duplicate guard, Dede review, shipment state, asynchronous signed GET OMS confirmation and independent queue. No new dispatch engine, Woo direct sync, table or migration.
- MorePlus pending view has one selection column with a check-current-page control and an independent list of selected ERP order IDs. Selection persists while existing Inertia component navigates pages; it is not the Tracking website-sync selection.
- When selected: preview and batch start are scoped to those exact IDs; the server intersects them with secure existing candidate checks and the Production finite operator allowlist. Out-of-scope IDs never bypass controls.
- When no selection: first native confirmation asks whether to scan ALL pending MorePlus orders, including stockout ones. Cancel results in zero HTTP and zero batch creation. Confirm performs GET-only preview of all pending MorePlus shipments, including stockout/unreviewed rows in scan_count but not submitting them to provider.
- Preview displays scanned count, eligible count, excluded count, and max 200; no immediate ShipOut calls.
- Second confirmation explicitly states scanned/candidate/excluded numbers. Server still requires fixed confirmation, fingerprint, operator scope, and existing Production batch/owner kill switches.
- Preview fingerprint binds full pending count, selection IDs, scope, and candidate IDs: stale changes cause 409 without provider POST.
- Explicit out_of_stock workflow and stockout or domestic_stockout markers are excluded from candidate IDs even with earlier approval. Worker repeats real stock and 14-day content risk before any real POST.
- At most 200 scanned pending orders per batch, max 200 eligible, one active batch, and exact Production allowlist. Never unilaterally authorize warehouse-wide Production operations.
- Important Production provider discrepancy remains: C42199 ERP7960 had signed OMS status6 PROVIDER_DUPLICATE_ORDER despite a complete visible 14-day history risk clear. Keep Production POST batch flags OFF until provider identifies actual duplicate match/source. Existing C42199/C42149/C42141/C42203 are never auto-resubmitted.
- Authorized admins retain read-only preview/history even when safe-paused; final POST confirmation remains disabled and server rejects writes.

## Dev tests (no provider POST)
- Cross-page selection, check current page, clear, no-selection cancellation.
- Scoped selected start never creates a different order's batch item.
- All scan includes stockout in total, excludes it from dispatch.
- Invalid/duplicate selected IDs and stale fingerprint fail without dispatch.
- Newly arrived stockout row invalidates prior ALL fingerprint despite unchanged eligible set.
- ShipOut backend regression 531 assertions, overseas workbench 736 assertions, Vue page 52/52, build and Pint all PASS.

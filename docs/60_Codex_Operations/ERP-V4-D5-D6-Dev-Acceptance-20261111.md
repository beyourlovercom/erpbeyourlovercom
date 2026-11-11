# ERP V4 · D5 State Restore → D6 Resource/Concurrency Verification

Date: 2026-11-11. Source PR: #704. **No Production deployment or all-staff enablement in this branch.**

## D5 implementation and invariants
- Keep Laravel/Inertia/Vue/AG Grid exactly as previously accepted.
- Existing server list URL remains the source of truth for search, filter, sort, `page`, `per_page`, and view. This branch does not invent a competing filter store.
- New bounded visual scroll snapshots: at most 30 route-view entries per user; exact URL (including query) match; 30-minute expiry; sessionStorage for harmless scroll offsets only; no form field values, passwords, order data, selected rows, edit drafts, or API tokens.
- Restore `.shell-content` scroll, AG Grid horizontal viewport, center and body viewport if present. Native account-level grid column preferences remain unchanged.
- D5 discovered and fixed true Inertia races: old unmounted layout's async callback could apply stale scroll to the next page; silent same-route revalidation could overwrite the saved value with 0; AG Grid async preference layout reports ready before scrollWidth is calculated. Guard using active component/URL, skip redundant capture and wait for usable scroll geometry + grid layout-ready signal.
- Existing 30 navigation bookmarks, 3 hot **data** snapshots, 3 pinned bookmarks, draft confirmation and server revalidation locks unchanged.
- No new backend PHP route, schema, queue worker, inventory or ShipOut integration.

## D5 real component browser acceptance (US Mac mini isolated Dev)
Isolated local `127.0.0.1:56989`, SQLite, synthetic admin and 50 synthetic orders, actual `Logistics/Domestic/Index` and `GlobalGrid/OmsGrid` components:
- Real horizontal scroll 300px before switching → **300px** after returning to the same Tab.
- Browser F5 after return → **300px**.
- Search `needle`, `page=3`, `per_page=50` → correct search input and `第 3 / 5 页` after both Tab return and F5.
- Scroll restore after route settled: about **97ms** in two observed runs. JS page errors 0.
- The first Dev tests exposed actual scroll races, then succeeded after fixes. Do not represent the original failures as already passing.
- Unsaved business form contents are deliberately **not** silently persisted. Existing dirty-form protection remains mandatory.

## D6 bounded five-user sustained Dev load
Synthetic 5 separate authenticated browser contexts, each visiting real ERP Domestic Grid component with 50 **synthetic** rows per page. Single US Mac mini localhost Dev server, no production customer data or fulfillment POST.

- Duration **150.683 seconds**, 51 rounds × 5 = **255 successful navigation requests**.
- **Median 240ms**, **P95 405ms**, max 677ms; these are local browser action-to-render timings, not Production TTFB or office-network latency.
- Browser JavaScript errors **0**, HTTP 5xx errors **0**, business POSTs **0**; observed POST count **5**, all synthetic logins.
- Per-session tab cap reached 30; no runaway background independent AG Grid component mounting.
- Forced-GC aggregate Chrome JS heap (five active sessions), MiB:

| Elapsed | Total JS Heap | DOM nodes (aggregate) |
|---|---:|---:|
| Initial synthetic logins | 16.45 | 3,170 |
| 36s / 13 tabs per user | 64.97 | 12,436 |
| 65s / 23 tabs per user | 68.11 | 12,786 |
| 97s / 30 tabs per user | 70.01 | 13,037 |
| 125s / 30 tabs per user | 70.97 | 13,035 |
| 151s / 30 tabs per user | 71.49 | 13,035 |

- After 30 tabs were reached, aggregate DOM nodes stayed virtually constant. JS heap increased 1.48 MiB from 97s to 151s, not evidence of unbounded growth in this limited observation.
- Host swap used **13209.75 → 13201.75MiB** (a decrease of 8MiB), cumulative swapouts counter **2990233 → 2990233** (no new swapouts during this measurement); cumulative swapins **1765133 → 1765149**. macOS total swap usage can be historical, not proof of active memory pressure.

## Regression
- Clean fresh checkout, TypeScript PASS, canary Vite build PASS.
- Targeted Vue workspace state + hidden polling: **5 files PASS**.
- After build assets were available, Laravel feature regression **59 tests, 698 assertions PASS**. The first attempt failed because the clean checkout lacked `public/build/manifest.json` *before* the build was run; rerun after generating the manifest was green.
- Full Vue suite: **107 passing files, 2 failing files / 5 failing assertions**, the same pre-existing WarehouseClearanceGiftPool and WarehouseCostCenterP3 old-UI expectation tests previously reproducible before D5. Do not claim global green CI.

## Safety and remaining gates
- The 150-second exercise is a **bounded Dev sustained check**, **not** a multi-hour memory leak certification, physical browser RSS measurement, or five human employees concurrently operating Production.
- Before all-staff rollout, still require multi-hour real-world browser full memory footprint and server-request P95 monitoring, five actual staff acceptance during ordinary workflows, and an explicit Owner release gate.
- PR #704 should be merged to GitHub main only after review, **not deployed to Production automatically**. Keep existing PR #702 R3-D administrator Canary unchanged until D5/D6 acceptance on a newly staged UI Release.
- Existing per-user access controls and all shipment safety gates remain server enforced.

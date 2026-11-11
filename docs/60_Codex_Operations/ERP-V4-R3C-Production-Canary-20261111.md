# BYL ERP V4 R3-C controlled Production admin canary — 2026-11-11

## Deployment and scope
- GitHub source PR [#701](https://github.com/beyourlovercom/erpbeyourlovercom/pull/701), merged as `1345db678311dc62a1f19c4f17c550f8453bcdeb`.
- Source base for actual Production release: `amazon-us-manual-pr699-ccffd0f-20261111` (the live `current` snapshot at staging time). **Do not replace this with the entire latest main**; the latter contains unrelated ongoing ERP work.
- Resulting release: `/Users/a/ai/production/erp.beyourlover.com/releases/erp-v4-r3c-admin-canary-pr701-5650c6b-20261111`.
- Deployment manifest: `r3c-release-manifest.json` inside the release; has the previous release path, source-file SHA-256 digests and precise scope.
- Only 11 source files overlaid: existing R2-A Inertia shared-data closures; R2-B domestic nine-view-count closures; R3 existing AuthenticatedLayout canary gating, WorkspaceTabs + workspaceTabs library; draft boolean hook and KOL create/User edit opt-ins; first-load AG Grid column-state safety; Warehouse + MorePlus tab-visibility polling. All other Production source files preserved.
- Production release was switched atomically at **2026-11-11 02:43 UTC** (Beijing 10:43). No migrations, no Production DB edits, no warehouse API POST, no Worker or Chrome restart.
- Administrator-only allowlist is compiled at build time; requires opt-in per browser session. For a permitted administrator: `https://erp.beyourlover.com/dashboard?erp_v4_tabs=1`; opt-out `https://erp.beyourlover.com/dashboard?erp_v4_tabs=0`. Other employees see the unchanged shell. The allowlist is not a replacement for Laravel route authorization.
- One active Inertia page/AG Grid at a time, maximum 30 lightweight *navigation bookmarks*. Returning to a closed/background tab requires normal route reload; this is not a persistent DOM/editor state cache.

## Acceptance evidence (isolated US Mac mini)
- Isolated new SQLite database, synthetic account, original ERP Domestic GlobalGrid with 50 synthetic order rows per page; 1→10→30→31 routes tested in headless Chromium. Retained tab count stayed at 30. At 30 heavy route visits, JS Heap ~14.13 MiB after forced GC. Zero browser page exceptions and zero HTTP 5xx after fixing a preexisting first-load AG Grid column-state initialization gap.
- Real KOL creation and User/Edit pages: unsaved edit warning and cancel navigation PASS; no form values/passwords persisted by the guard.
- Unit tests: 34 opt-in workspace + KOL tests, and 2 hidden-state polling tests PASS. Hidden tab test uses simulated document.visibilityState, not claimed to reproduce all desktop browser scheduling behavior.
- TypeScript / Vite build PASS. Staged Production-source composite build PASS, PHP lint for both changed request handlers PASS.
- Previously tested R2 Dev full/partial logistics requests and expected nine view counts PASS. R2 request microbenchmarks were on synthetic SQLite and are not Production P95 claims.
- Historical 5 UI expectation failures across the WarehouseClearanceGiftPool and WarehouseCostCenterP3 Vue tests were reproducible before R3 and are tracked separately; **do not claim entire Vue suite PASS**.

## Production cutover health check
- Pre-cutover: source release pointer matched the expected PR #699 path; canary release `.env` remained the existing shared Production symlink. Git source hashes and build manifest checked, no staged source mutation.
- Post-cutover: `https://erp.beyourlover.com/login`, unauthenticated `/dashboard` redirect sequence, and new `/build/assets/app-BTDZTOdZ.js` all returned HTTP 200 with curl success.
- Live checks at 02:46 UTC: Web `current` still points to R3-C canary; Scheduler, default Queue and ShipOut Queue LaunchAgents running; Laravel Redis queue sizes 0/0 at the sampled instant.
- Compared to the immediate previous PR #699 release, PHP source changed only in `app/Http/Middleware/HandleInertiaRequests.php` and `DomesticLogisticsController.php`; routes, scheduler definitions, business queue jobs, ShipOut config, Composer lock, and schema migration code were unchanged. No Worker restart was performed, consistent with this request-only/UI-only cutover.
- The sampled Scheduler/PHP-FPM/Queue/Nginx error logs contained no new 02:43–02:47 cutover-time error entries. This is an observation, not proof of zero future errors.
- The underlying per-release Laravel `storage_path` versus `shared/storage` structure is a **pre-existing architecture concern**: the PR #699 and new release storage trees both contained no application files in the snapshot, while `shared/storage` held canonical historical artifacts. Do not refactor storage within R3; track as R4 release/storage governance.

## Rollback (only after preflight; exact target)
- Previous release: `/Users/a/ai/production/erp.beyourlover.com/releases/amazon-us-manual-pr699-ccffd0f-20261111`.
- If Production canary problems emerge, verify current still points to the R3-C candidate, confirm the previous release remains intact, then atomically change the `current` symlink back to the prior release. Do not issue global queue restart, replay shipments, alter databases or clear Redis caches.
- If a **different** parallel release has since become current, stop: do not overwrite that release with an old pointer.
- Review any new application files created within the current release's local `storage` before rollback, because release-local storage may not automatically follow the pointer.

## Remaining acceptance
- Admin manual UX verification of live opt-in / opt-out and key ERP navigation (authenticated Production browser). Do not claim this has happened merely because static asset/login smoke checks pass.
- Production authenticated HTTP request performance and 30 real concurrent staff workload not yet measured; expanded rollout remains pending.
- Project next: close R3 canary after admin UAT; R4 slow queries/cache and shared file storage governance; R6 reconcile the 5 pre-existing UI tests.

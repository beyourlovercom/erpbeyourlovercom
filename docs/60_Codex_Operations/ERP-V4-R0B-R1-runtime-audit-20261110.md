# BYL ERP V4 — R0-B / R1 Runtime Audit (2026-11-10)

Status: **read-only evidence recorded; no Production service change authorized by this artifact.**

## Scope and facts
- Ground truth: US Mac mini, Production release root `/Users/a/ai/production/erp.beyourlover.com`; `current` pointed at PR #690 (`global-grid-stockout-pr690-d7e9fe42-20261110`) when sampled.
- Production PHP-FPM pool: 2 workers, aggregate RSS 71.8 MiB at the sampled instant. Host: 8 logical CPUs and 16 GiB RAM. These are snapshots, not peak measures.
- Production long-lived queue/scheduler worker CWDs were older releases: general queue and scheduler PR #659; dedicated ShipOut batch worker PR #679.
- LaunchAgent definitions for general queue, scheduler, and batch queue specify the `current` symlink, so updating the symlink alone **does not move running processes** to the new release.
- The PR #690 scheduler source contains `shipout:reconcile-oms --apply` every 30 minutes; the PR #659 scheduler source does **not**. Confirm the actual scheduler owner and any alternative scheduling mechanism before making an operational change.
- Local Dev queue/scheduler share the MySQL/Redis host but use a **different database and Redis DB** from Production, based on current local configuration. Do not assume this prevents all other cross-environment effects.
- Samples found roughly 51–53 artisan-serve launcher processes and 54–74 PHP built-in HTTP processes across two different process filters/times. Some are parent/child pairs; **these are not independent app counts**. Combined RSS may double-count shared memory. Do not kill them based solely on age.
- At one sample there were 149 local UAT directories and 330 Production release directories. Directory count is not memory consumption.

## Observed server timings
From a bounded Production Nginx access-log sample spanning Oct 17–Nov 10, 2026, **successful application requests only** for selected ERP business paths:
- n=3,531, median=506ms, P95=2,056ms.
- Dashboard GET n=72, median=2,325ms, P95=4,360ms.
- China logistics GET n=477, median=458ms, P95=1,269ms.
- MorePlus logistics GET n=340, median=438ms, P95=2,244ms.
- Ship POST n=42, median=842ms, P95=9,879ms.
- Reopen POST n=24, median=852ms, P95=14,846ms.
- Grid preferences GET n=515, median=165ms, P95=726ms.
These are Nginx request times, not SQL profiles, browser page-load values, or causal findings. A sample with old and new releases is not a single-release benchmark.

## Added read-only release drift check
```bash
python3 scripts/ops/erp_runtime_release_audit.py \
  --root /Users/a/ai/production/erp.beyourlover.com
# Optional for later gated validation (exit 2 if drift detected):
python3 scripts/ops/erp_runtime_release_audit.py \
  --root /Users/a/ai/production/erp.beyourlover.com --strict
python3 -m unittest discover -s tests/ops -p 'test_erp_runtime_release_audit.py'
```
The script reads process metadata, process working directories and scheduler source, and reports release drift. It does not read credentials, stop processes, restart services, submit tasks, or modify Production.

## R1 safe remediation sequence (requires operational preflight)
1. Identify active schedule runner(s), queue worker ownership, launchd KeepAlive, queue names and any special fixed-release worker contract. Confirm whether `shipout:reconcile-oms` is scheduled elsewhere.
2. Prove queue backlog, reserved jobs/in-flight execution and idempotency, especially `shipout-production-batch`. Record Rollback release/launchd config and a bounded quiet window.
3. Verify a **single** new Production worker from the intended release under the existing supervisor mechanism only after drain/transaction safety is established. Observe queue acknowledgments and scheduler runs. Roll back if anything diverges. Never manually replay real shipments or reset queue state.
4. Audit the 51+ Dev server launchers by PID, port, UAT owner, active session, and recent use. Mark stale candidates but **do not kill** active UAT or Chrome sessions. Replace indefinite servers with a supervised lifecycle on future UATs.
5. Make drift report + queue-specific smoke checks part of a future release checklist; a read-only checker alone is not an automatic deployment gate.

## V4 roadmap after R0
R1 runtime/release/UAT governance; R2 Inertia shared-prop and SQL optimization; R3 workspace tabs + bounded KeepAlive and browser visibility; R4 DB/index/Redis/queue metrics; R5 cross-module fulfillment state contracts; R6 CI and business regression; R7 staged release and rollback.

## R0 limitations
No authenticated browser memory/30-tab profile, SQL slow query capture, or China office network benchmark has been completed. Do not claim a measured RAM or CPU speedup until those are tested.

## Production change log
**No Production files, services, orders, queues, or databases changed during R0-B or this read-only R1 tooling preparation.**

## Additional R1 preflight confirmation (2026-11-10)
- The Production environment currently enables `SHIPOUT_OMS_RECONCILIATION_ENABLED` and `SHIPOUT_PRODUCTION_READ_ENABLED`; the new `shipout:reconcile-oms` scheduled command is gated and GET-only with a local audit write, not a shipment API POST.
- Recent scheduler log sample had zero `shipout:reconcile-oms` matches while older recurring commands appeared; this supports the version-drift concern but is not a proof that no external scheduler exists.
- The strict read-only check returned expected exit code `2` because Production Worker release drift is still present.
- Sampled Redis `queues:default` and `queues:shipout-production-batch` ready/reserved/delayed counters were zero for the checked keys; this is a point-in-time observation, **not** proof there are no in-flight provider requests, alternate Redis key prefixes, or outstanding external side effects.


## R1-B continued audit (same day)
- LaunchAgent inspection confirms scheduler PID 91401, default queue PID 91369, dedicated batch PID 37680; the running process CWDs retain PR #659 / PR #679 despite LaunchAgent configured to launch from current.
- Non-mutating Laravel schedule:list comparison confirms current Web release includes half-hourly shipout:reconcile-oms --apply; old scheduler does not. Production read and OMS reconciliation feature flags currently enabled.
- Executed PREVIEW ONLY: php artisan shipout:reconcile-oms --no-interaction; result mode=preview and readback_candidates=10. No --apply, external POST, or order mutation.
- Added scripts/ops/erp_uat_server_inventory.py. Read-only UAT scan: 33 artisan launchers, 24 paired HTTP children and 10 orphan candidate processes. Never terminate based only on age.
- Validation using commit-pinned code on US Mac mini: 4/4 Python unit tests PASS. The preliminary macOS /tmp path-alias test failure was corrected in commit f0588c3.
- Production scheduler/queue/batch and UAT processes remain untouched; version drift remains operationally unresolved.

## R1-B controlled switch prerequisites
1. Check MorePlus R10 coordination, in-flight external effects, unique dispatch ledgers, and whether 10 OMS candidates may be processed by the enabled GET-only reconciliation.
2. Diff all schedules from PR #659 versus current. Restarting a scheduler activates all current scheduled jobs, not only OMS reconciliation.
3. Verify LaunchAgent supervision, exactly one scheduler, no overlapping schedule:run child, queue inflight and release rollback target.
4. Capture before/after schedule:list, actual Worker CWD, queue metrics, and confirmation of no duplicate dispatches. Never replay real shipment jobs.
5. For UAT cleanup, verify PID, port, browser/owner activity, and last use; a high process age is NOT sufficient permission to terminate.

## R1-B stage
Read-only checks and tool validation complete. Production Worker synchronization and UAT service cleanup remain pending controlled preflight.


## Important production gate: OMS reconciliation is not wholly side-effect-free
- Verified source in ShipOutProductionCanaryReconcileService: the provider integration performs a signed read-only GET and does not issue a new shipment POST, apply Tracking, or mark a shipment shipped.
- Verified source in ShipOutProductionOmsReadbackService: inspectAndRecord inserts rows into shipout_oms_readbacks, and on cancelled/unverified responses can change shipout_production_batch_items from oms_visible to provider_cancelled/needs_review and completed batches to completed_partial.
- Therefore a scheduler cutover enabling the 30-minute task is a real Production behavior change, not merely an infrastructure restart. The preview identified ten pending orders; do not activate this job solely because Redis ready/reserved counts are currently zero.
- A safe next gate must reconcile with the running MorePlus R10 operations, confirm the 10 candidates' current status and history, and validate the local auditing/quarantine state transition against the existing authoritative batch workflow before changing the scheduler.
- Status as of this review: the diagnostic tool and UAT inventory are tested; the Production scheduler/worker processes remain unchanged and release drift remains open.


## R1 Production cutover execution — 2026-11-10 15:12–15:15 UTC
**This section supersedes earlier operational status that Scheduler and Queue had not been changed.** Live Production Web release changed concurrently from PR #690 to PR #692 before the cutover; all commands guarded the exact new release.

- Production Web current: global-grid-pref-stability-pr692-d9cea59d-20261110.
- Scheduler LaunchAgent com.byl.erp.production.scheduler: launchctl kickstart -k performed once with safety guards for exact Release, previous PID/CWD, running service, and absence of active artisan schedule:run and OMS reconciliation commands. PID 91401 (PR #659) -> PID 46979 (PR #692). LaunchAgent running; lsof CWD verified PR #692. No second scheduler launched.
- Default Queue LaunchAgent com.byl.erp.production.queue: Laravel Queue::connection('redis')->size('default') returned 0. A TERM signal was sent to old worker PID 91369 for graceful exit, and KeepAlive restarted PID 47320 from PR #692. Postcheck LaunchAgent running and CWD verified PR #692. No global queue:restart issued.
- Dedicated ShipOut batch queue PID 37680 remains from PR #679 deliberately. Laravel queue size('shipout-production-batch') returned 0 at read time; empty snapshot does not exclude external in-flight state. Never force-switch or auto-retry this worker without separate batch reconciliation.
- Both Production flags SHIPOUT_PRODUCTION_READ_ENABLED and SHIPOUT_OMS_RECONCILIATION_ENABLED are true. The first 30-minute scheduled OMS reconciliation after cutover is expected at 2026-11-10 15:30 UTC. Its **real execution result has not been observed yet**.
- Read-only Production DB preview returned 10 candidate orders, all with acknowledged one-shot attempts and pending ERP shipment status; latest OMS audit states were 6 cancelled, 3 confirmed, 1 warehouse_awaiting. Provider IDs were present for all ten. Current batch item statuses in the candidate cohort include three provider_cancelled and two oms_visible; others had no batch item rows. An enabled reconciliation may append readback rows and quarantine anomalous batch statuses but must not submit a shipment.
- Recheck at 2026-11-10 15:15 UTC: Web / Scheduler / default queue all same PR #692 release; all relevant LaunchAgents running; specialized ShipOut batch remains PR #679. Laravel queues default and shipout-production-batch both size 0; scheduler.err.log size 0; job execution after the next due time not yet verified.
- The automation tool rejected scheduling a one-time 15:35 UTC post-run check because the user's 5-task plan limit was reached. No monitoring automation was created. Do not claim future completion or automatic follow-up.
- PR #691 still Draft; its checker should continue reporting drift for intentionally pinned ShipOut batch worker until the owner decides the batch-specific upgrade gate. No merge or deploy of PR #691 occurred here.

### Cutover acceptance gate (pending)
1. After the next due time, read-only verify scheduler output contains real run and no new errors, plus newly recorded OMS audit state/quantity; compare with pre-cutover rows.
2. Review cancelled/exception batch transitions, in-flight ShipOut dispatch ledger and no duplicate POST attempts.
3. Confirm default queue continues to process normal jobs; no unexpected worker CWD mismatch after future Web deployments.
4. Only after MorePlus R10 owner/UAT confirms batch safety, propose a separate graceful cutover of the dedicated batch queue. Leave PR #691 Draft meanwhile.


## R1-C guarded dedicated ShipOut worker cutover — 2026-11-10 15:25 UTC
- Before changing the dedicated worker, verified that the ShipOut Application directory's 20 PHP files, Infrastructure gateway directory's 6 PHP files, both queued Job files and config/shipout.php are byte-for-byte identical between PR #679 and current PR #692.
- Verified live Production DB returned zero nonterminal batch items (queued, processing, pending_oms, oms_visible_provisional), zero nonterminal batches (queued, running, awaiting_oms, paused), and zero potentially submitting dispatch attempts; Laravel Redis queue size(shipout-production-batch) returned zero.
- Under guards for exact Production release and old PID 37680, sent SIGTERM to dedicated worker only; launchd KeepAlive started new worker PID 49482 with CWD pointing to PR #692. No global queue:restart and no replay.
- Follow-up confirmed Web, Scheduler, default queue and dedicated ShipOut queue all running on PR #692; both queues had size zero. Live orders remain unaffected by the worker restart.
- The observed first half-hour OMS scheduled execution receipt is still pending at the time of this note. Before that execution, shipout_oms_readbacks contained 38 records (max id 38) and immutable shipout_dispatch_attempts contained 22 records (max id 22). After the first execution, compare both counts and inspect batch outcomes.
- Separately identified historical failures in Production scheduler output during the same day: orders:sync-woocommerce 17 instances of exit 1 as of 15:00 UTC and xlwms:sync-inventory 93 instances of exit 1 as of 15:20 UTC. These errors predate the Worker cutovers. Investigate under a separate scoped issue; do not conflate with R1 worker version drift or manually replay synchronization tasks.

### Remaining acceptance criterion
R1-C cannot be called completely closed until actual half-hourly OMS reconciliation run is observed, resulting local readback changes are accounted for and no duplicate dispatch attempt is detected. All Production worker version drift is resolved as of post-cutover verification.

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

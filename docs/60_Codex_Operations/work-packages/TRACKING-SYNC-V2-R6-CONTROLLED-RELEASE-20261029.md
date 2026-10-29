# Tracking Sync V2 — R6 controlled release plan (2026-10-29)

Status: staged / awaiting exact Owner Production PASS. PR #537 is Draft, not merged.

## Release principles
- GitHub/runtime truth; preserve production PR #535 and earlier uncommitted overlays.
- Deployment Stage: /Users/a/AI/uat/erp-tracking-v2-r6-composition-20261029
- Exact Production baseline at read-only audit: /Users/a/AI/production/erp.beyourlover.com/releases/XLWMS-SM-pr535-20261029-c68aa55f
- 6 PR-touched files had drift vs origin/main: 5 three-way auto-merged, one LogisticsController manually composed ONLY inside isolated stage.
- Manual composition preserves existing order Tracking validation messages, HttpExceptionInterface catch and 422 field UX, then appends optional post-commit outbound. No other production file was overwritten.
- Composite stage Composer autoload, PHP lint, TypeScript typecheck and Vite production build PASS; route logistics/tracking-outbound/selected registered, Woo inbound */30 preserved, new outbound scheduler absent by default.

## Explicitly independent feature switches
- TRACKING_SYNC_V2_MANUAL_MODE=off (default) disables every new manual push button and route eligibility even for Vita/logistics.
- TRACKING_SYNC_V2_MANUAL_MODE=canary enables only strictly specified ERP IDs in TRACKING_SYNC_V2_MANUAL_CANARY_ORDER_IDS; if empty all requests denied.
- TRACKING_SYNC_V2_MANUAL_MODE=general enables ordinary authorized-role bulk/row/manual functions ONLY after broad rollout approval.
- TRACKING_SYNC_V2_IMMEDIATE_ENABLED=false by default; do not set true for initial canary.
- TRACKING_SYNC_V2_PERIODIC_ENABLED=false by default. Requires positive TRACKING_SYNC_V2_MIN_OUTBOX_ID and numeric TRACKING_SYNC_V2_MIN_SHIPMENT_ID, captured as frozen max IDs AT REAL activation. Historic Outbox and Shipments excluded.
- Outbound schedule is bounded to 50 total retry+discovery orders, uses lock/onOneServer and existing Outbox idempotence. Auto-discovery excludes orders already having pending/processing/failed Outbox.

## Read-only operational snapshot, NOT activation baseline
- 2026-10-29 04:53 UTC: max(sync_outbox.id)=14; max(shipments.id)=1876; Woo Tracking Outbox completed=5, pending=1, due=1, oldest pending ID=1; failed_jobs=0.
- These values are stale the moment new rows arrive. Re-query immediately before any approved periodic activation. Historic pending ID1 MUST NOT be silently flushed.

## UAT status
- Real isolated local WordPress 7.1.3 + WooCommerce 11.2.1 + genuine AfterShip Tracking 1.18.5 plugin: Woo REST via actual ERP gateway, 2 packages, repeated no-op, conflict refusal, plugin read and customer template all PASS.
- Local stand-alone test site 127.0.0.1:18763, independent DB and fake order. External AfterShip Cloud, mail and webhooks intentionally blocked. No claims of real Dev or AfterShip Cloud ingestion.
- Existing Dev G5 quarantine (Woo order ingress and AfterShip egress) remains ON; 61/61 smoke PASS. Do not bypass.

## Separate release gates; none auto-authorized
1. Owner explicitly approves code-only R6 Production deployment with all three outbound switches OFF. Stage must be rebased on exact current release and dry-run validated again; no database migration.
2. Verify /up, assets, original logistics UX, scoped route, role permissions, existing inbound Woo schedule, Tracking cron OFF. This step must NOT write Woo Tracking.
3. Owner separately names/approves one real Woo ERP order for manual Canary and allowlists it; no legacy backlog flush. Manual mode=canary, immediate=false, periodic=false. Confirm Woo plugin admin and if connected actual AfterShip cloud separately. If origin/line-item conflict, abort without overwriting.
4. Owner separately approves general manual / immediate / periodic activation. Freeze fresh Outbox/Shipment highwater and observe at least two natural half-hour tracking runs.
5. Rollback: turn off flags, atomically repoint current to immediately previous verified release and restart ONLY the ERP Production scheduler if necessary; never restart READY devices or Local ERP. Real remote tracking already sent cannot be erased by code rollback; inspect before any retry.

## Risk register
- Real dev.beyourlover.com is quarantined; local plugin success does not certify real merchant AfterShip Cloud sync.
- Pre-existing unrelated Mvp1WorkflowTest shipment.reopened audit assertion fails identically on untouched main c68aa55f; not introduced in PR #537.
- Control risk of a feature visible to operators immediately after code-only release is mitigated by manual_mode=off and separate canary allowlist.

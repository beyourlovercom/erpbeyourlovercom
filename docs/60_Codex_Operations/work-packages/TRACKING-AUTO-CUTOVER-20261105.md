# Tracking Sync V2 — R0 audit / controlled automatic cutover
Date: 2026-11-05 (Asia/Taipei). Scope: ERP to WooCommerce Tracking and eligible order status; reuse the existing engine only.

## Verified read-only baseline
- Current US Mac Production release mirror: inventory-ticker-pr627-80cfb975-20261104T133642Z.
- Production config: MANUAL_MODE=general, IMMEDIATE_ENABLED=false, PERIODIC_ENABLED=false. Both automatic activation baselines unset.
- SELECT-only DB query via Production-configured connection: Tracking Outbox completed=8, pending=1; Order Status Outbox completed=9; manual_sync completed=8, pending=8.
- Old Tracking Outbox ID 1: woocommerce_tracking_write_failed (2/3 attempts). Snapshot maximum IDs: Outbox=18, Shipment=1919; RECOMPUTE fresh cutover baselines before rollout, do not blindly reuse.
- Tracking Immediate Dispatch already hooks generic and domestic quick shipment/update paths. Tracking V2 already handles Woo readback, idempotent Outbox, no-overlap half-hour periodic retry, status aggregation for partial shipments, and manual operations.
- Pending manual sync records are NOT necessarily eligible for automatic push. Do not replay legacy pending or overwrite Woo/AfterShip data.
- Local isolated baseline regression: 94 tests, 884 assertions PASS; no external Woo writes.

## Minimal development delta
- Keep old Boolean IMMEDIATE_ENABLED; require independent IMMEDIATE_MODE (off | canary | general), default off.
- Canary requires exact ERP IDs in IMMEDIATE_CANARY_ORDER_IDS; empty/malformed/unknown mode fails closed.
- Do not change inbound Woo import, existing Outbox, providers, shipment truth, or legacy business rows. Production flags unchanged.

## Roadmap / engineer hours
| Stage | Scope | Hours | Gate |
| --- | --- | ---: | --- |
| R0 | Config + Outbox read-only audit | 0.5–1 | Aggregate done; case-level pending review outstanding |
| R1 | Exact-ID immediate Canary gate + regression | 1–2 | Isolated tests pass |
| R2 | Read-only classification of 8 pending manual rows and legacy Outbox ID 1 | 1–2 | No replay |
| R3 | Dev/local domestic, overseas, split, Woo/AfterShip readback UAT | 2–3 | No WWW writes |
| R4 | Separate Owner PASS to deploy code OFF, one real new-order Canary and actual Woo readback | 1–2 | Exact order authorization |
| R5 | Separate Owner PASS to enable general immediate + 30-min scheduler with freshly frozen highwater IDs; monitor at least 2 ticks | 1.5–2.5 | Idempotent / no legacy flush |
| **Total** | Existing engine reused | **7–12.5** | No Production mutation approved implicitly |

## Safety and rollback
- Do NOT use R0 max IDs as future scheduler activation baselines; capture reviewed exclusive maxima immediately before cutover.
- Do NOT mark partial, cancelled, refunded or failed Woo orders Completed. No duplicate Tracking packages, conflict overwrites, or mass pending replay.
- Canary must only run with an exact order allowlist; any unknown status or uncertain Woo write is a failure, never a green success.
- On rollback, set IMMEDIATE_ENABLED=false, IMMEDIATE_MODE=off, PERIODIC_ENABLED=false; verify schedule list and existing already-verified Woo data without deleting it.
- No production config/DB/provider writes, no READY device restart, and no Chrome restart in this package. GitHub merge is not Production approval.

## R2 final read-only audit and R3 isolated UAT — 2026-11-05

### R2: 8 pending ERP manual_sync records, Production SELECT only
The 8 rows were individually classified using source, shipment status, carrier, Tracking, shipped_at, Woo reference and exact Woo line-item ID. No Production rows were modified.

| ERP ID | Source and shipment | Woo read-only result | Decision |
| --- | --- | --- | --- |
| 7008 | Woo, shipped; all fields and line ID present | Woo completed, existing Tracking **matches ERP** | ERP manual_sync pending is stale; no new Tracking package needed. Reconcile separately |
| 7638 | Woo, shipped; all fields and line ID present | Woo completed, existing Tracking **matches ERP** | ERP manual_sync pending is stale; no new Tracking package needed. Reconcile separately |
| 7851 | Woo, shipped; all fields and line ID present | Woo processing, existing Tracking **matches ERP**; no derived children | Status drift candidate; verify business/aggregate prior to any Woo Completed reconciliation. No automatic history replay |
| 7496 | Manual source, shipped; no Woo reference or line ID | Not queried | Never automatic Woo push |
| 7211 | Woo but shipment pending; no carrier/Tracking/shipped_at | Not queried | Ineligible |
| 7838 | Woo but shipment pending; no carrier/Tracking/shipped_at | Not queried | Ineligible |
| 7853 | Woo-derived child but shipment pending; no Tracking/shipped_at | Not queried | Ineligible; do not complete parent |
| 7855 | Woo-derived child but shipment pending; no Tracking/shipped_at | Not queried | Ineligible; do not complete parent |

Read-only HTTPS Woo queries were restricted to verified target host beyourlover.com, response summaries only, no customer/Tracking number output.
All three eligible-looking Woo orders have original Woo reference and exact stored Woo line identity; none has Tracking Outbox records locally. Therefore do **not** bulk replay these records and risk duplicate packages.
Historical Outbox ID=1 remains pending (2/3 attempts), error code woocommerce_tracking_write_failed, shipment=13. Remains protected and unmodified.
All automatic Production flags remain false. Do not reuse the audited max IDs for later cutover.

### R3: local PHP + independent real WordPress/Woo/AfterShip integration
Independent US-Mac localhost Woo fixture http://127.0.0.1:18763, Woo 11.2.1, AfterShip 1.18.5. MU plugin blocks external HTTP, email and Woo webhooks. No www/Dev provider mutation.
- Fresh isolated Woo order #20: real Laravel manual HTTP Woo REST + status drift and refund guard: 1 test / 27 assertions PASS.
- Fresh isolated Woo order #22: simulated overseas ShipOut shipped status, ERP and Vita manual Tracking, local Woo integration: 1 test / 24 assertions PASS.
- Fresh isolated Woo order #23: NEW exact ERP ID automatic Canary test, disallowed order no write, allowlisted write, Woo completed readback, repeat no duplicate Outbox: 1 test / 15 assertions PASS. Test file tests/Feature/TrackingAutoLocalWooCanaryTest.php added to this Draft PR. Test is opt-in TRACKING_AUTO_REAL_LOCAL=1 only and asserts exact localhost URL.
- Real AfterShip plugin native get_tracking_items and get_tracking_items_for_display: 1 package, 2 line-item bindings, USPS slug, matching Tracking; customer template renders Tracking; local order completed: PASS. AfterShip Cloud not checked.
- Existing scoped regressions after R1 changes: 103 tests / 915 assertions, 0 failures, 1 expected opt-in local test skipped by default. Included domestic inline, generic/overseas, split/partial aggregate, Woo gateway, manual, retry and fail-closed.
- Pint verification and git diff check PASS.

### Remaining gates (do not misreport as complete)
- PR remains **Draft**, no main merge and no Production deploy or flags change.
- Real Dev/AfterShip Cloud ingestion remains unverified. If required prior to Production rollout, a separate strictly isolated Dev UAT and Owner authorization are necessary.
- R4 requires new eligible real-order ID + Owner explicit PASS to deploy code with flags OFF, enable narrow Canary, prove actual Woo/AfterShip readback; no historical record is to be repushed.
- R5 requires a separate Owner PASS for immediate general/periodic; capture *fresh* exclusive Outbox and Shipment highwaters at activation, verify two distinct scheduler ticks and rollback.

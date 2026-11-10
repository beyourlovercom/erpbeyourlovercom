# MorePlus controlled one-click batch launch — 2026-11-10

## Evidence and scope
- GitHub main retains R8 isolation and R9 14-day recipient/SKU/quantity content duplicate checks; orderNO alone is not enough.
- Original R10 signed Canary C42203-007 / ERP7963 stays one-shot (OMS status3, no real Tracking/physical shipment confirmed at latest check).
- C42141 / ERP7903 is EzeeShip provider status6, Dede manual only. C42149 / ERP7913 is confirmed duplicate status6. Their original immutable attempts remain one each; cannot auto-resubmit.
- Three additional Production orders: 7960 (C42199-007, BX033B x1), 7957 (C42197-007, VA617A x1), 7972 (C42187-007B, VG515A x1) were individually signed GET preflighted against 18 OMS history rows: ready=true, history_risk=clear, no prior attempt. These are candidates, not permission to send all.
- Production previously had only generic default workers. Dedicated launchd agent installed as com.byl.erp.production.shipout-batch using queue:work redis --queue=shipout-production-batch --sleep=3 --tries=1 --timeout=70 --max-time=3600 --no-interaction. KeepAlive/RunAtLoad and separate logs, without restarting existing workers, the computer or Chrome.
- Synthetic tinker closure smoke job failed from eval serialization (failed_jobs row13), without provider I/O or any ERP order mutation. A safe smoke with the actual named ProcessShipOutProductionBatch class and nonexistent batch UUID was consumed in 28ms, queue empty. Preserve both observations.
- Production batch requires two authorization flags and a finite explicit allowlist. No list means no candidates. Never enable warehouse-wide batches without separate evidence. Existing Dede/Owner security, per-order R9 14-day content guard and immutable one-shot ledger remain mandatory.
- The MorePlus page previously disabled new batches when prior batch was completed_partial despite backend permitting new batch. UI now blocks only queued, running, awaiting_oms and paused; regression test added. Production button visibility now matches exact V1 operator access, not any view-only user.
- Button preview is GET; start requires fingerprint and operator confirmation; asynchronous queue processes independently with isolation. API ACK is not final OMS or physical shipment. Tracking/Woo Sync OS is a separate stage.
- Owner requested a live operational button. Launch first with one exact new order as controlled real batch Canary; this is not blanket automatic release.

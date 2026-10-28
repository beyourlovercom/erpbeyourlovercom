# ERP Woo Order Sync 30m + Operations Manual Import

Owner approved 30-minute cadence, single-click manual today Woo import, narrow Vita role grant, health and source reconciliation. Production release remains gated by separate PASS.

- Scheduler: everyThirtyMinutes; overlap TTL 55 minutes (crash-safe, may skip next tick rather than overlap); onOneServer; feature flag unchanged.
- Manual today uses UTC 00:00 to current UTC, never changes automatic cursor, idempotent upsert, and no prior-day backfill.
- Operations preset with orders.view + sync.view may perform only today's import. Custom users must be explicitly granted sync.operate. Probe, single and historical routes remain sync.operate.
- Health checks latest automatic Woo sync for the active connection independently of recent unrelated tasks; 75-minute stale threshold; latest failure and last success shown separately.
- Source backlog only from operator-requested bounded, read-only Woo REST comparison against ERP external references. Unknown if API fails or >1000 source rows; admin empty shells and Dev sandbox excluded.
- UI in general/customer order list and Sync; no Woo or Production writes during code tests.
- After isolated CI and role UAT, separate Owner PASS required for Production. Read-only latest SyncRun, missed tick/failed jobs and source comparison first; inspect scheduler every :00/:30 after approved rollout. Roll back on failure.

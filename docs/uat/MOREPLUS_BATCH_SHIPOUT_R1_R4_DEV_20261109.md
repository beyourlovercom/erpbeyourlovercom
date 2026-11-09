# MorePlus Batch ShipOut — R1–R4 Dev UAT

Date: 2026-11-09. Branch: feat/moreplus-batch-shipout-dev-20261109.
Scope: **Dev/UAT only. No Production deployment, real ShipOut creation, tracking writes, Woo/AfterShip writes, or main merge.**

## User-facing behavior
- MorePlus heading top-right shows **一键推送仓库** when the feature is enabled.
- First click previews current **whole warehouse** candidate count, not only the paginated current page.
- A second explicit confirmation queues one immutable batch snapshot.
- Batch results survive page reload for the current operator, with per-order status and reason.
- Single-order UI remains available as a fallback.
- Does not mark an async OMS acknowledgement as shipped or invent tracking.

## Server behavior and fail-closed safeguards
- Candidate snapshot: US-LA, Dede approved for current warehouse, pending shipment, pending_shipment order, US address, USPS, no tracking, active/unarchived and no previous ShipOut ledger attempt.
- Max 200 candidates; stale snapshot fingerprint and concurrent active batches are blocked.
- One queued worker processes one production order per job, schedules next after 70 seconds.
- Separate production flags (both default OFF):
  SHIPOUT_PRODUCTION_V1_BATCH_ENABLED=false;
  SHIPOUT_PRODUCTION_V1_BATCH_OWNER_APPROVED=false.
- Production also requires existing individual V1 gates, a real async queue and authorized operator.
- Production manual and batch outbound submits share a conservative, atomic 1/minute provider pace; R1 preflight/send/status browser throttles now have isolated prefixes.
- Signed GET preflight, SKU/stock guards, duplicate OMS lookup and immutable order attempt ledger are reused before each provider POST.
- Any ambiguous POST result stays locked; no automatic retry; no second submission on a page refresh or worker repeat.
- Dev dry-run deliberately performs no provider GET or POST. Local UAT uses synthetic order numbers and an isolated SQLite database.
- Read-only OMS status query now distinguishes ERP-no-ledger/OMS-no-order from ERP-no-ledger/OMS-order-present; no submission is authorized by a status check.

## Dedicated local UAT
Mac mini only, local loopback:
http://127.0.0.1:56909/logistics/oversea/us-moreplus?view=pending

Isolated project:
/Users/a/ai/uat/erp-moreplus-batch-shipout-dev-20261109

No Production DB or credentials were copied. Local-only admin bypass rejects non-loopback access.
Use php artisan migrate --force and php artisan db:seed --class=MorePlusBatchDevUatSeeder --force to reset/build sample data.
Sample data include two approved shipments and one unapproved shipment.
Dry-run API observed: preview 2, POST start HTTP 202, status completed with 2/2 simulated, no real OMS calls.

## Regression evidence
- Backend PHPUnit targeted suites: **64/64 passed, 322 assertions**.
- Frontend Vitest Show.test.ts: **42/42 passed**.
- npm run build: passed.
- php -l for new PHP files, git diff --check: passed.
- Tests cover batch preview/authorization, stale fingerprint, single active batch, no network on Dev, shared pacing, no ledger direct OMS GET, background worker with no web session, synthetic ACK, timeout/uncertain with no retry, and Vue confirmation flow.

## Not authorized
- No Production deployment.
- No Production configuration changes, migration or enabling batch flags.
- No real ShipOut outbound POST.
- No GitHub main merge.

## Production release gates for a separate approval
Require dedicated queue worker and Redis shared lock/rate limiter, DB migration, exact operator allowlist, isolated feature flags, and a one-order Production canary with real OMS readback before any mass push.

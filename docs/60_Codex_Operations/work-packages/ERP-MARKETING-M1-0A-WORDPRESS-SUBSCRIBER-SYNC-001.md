---
type: work-package
project: BYL ERP
work_package_id: ERP-MARKETING-M1-0A-WORDPRESS-SUBSCRIBER-SYNC-001
status: REAL_SYNC_PASS / CLOSURE_GATE_PASS / READY_FOR_PR
execution_provider: US_CODEX_CLI
verification_tier: HIGH_RISK
baseline: origin/main@59dd5870c15aea5140a7ddf44bb046e470008eca
---

# Marketing M1.0A — WordPress Subscriber Sync

## Owner decision
Owner authorized this incremental package on 2026-08-29 after Marketing M1.0 merged as PR #124. M1.0A extends the closed M1.0 foundation; it does not rewrite M1.0 history.

OWNER_IMPLEMENTATION_AUTHORIZATION: GRANTED IN CHAT 2026-08-29
EXECUTION_PROVIDER: US_CODEX_CLI
GIT_DELIVERY_AUTHORIZATION: GRANTED IN CHAT 2026-08-30 FOR FORMAL CLOSURE GATE. COMMIT/PUSH/PR AUTHORIZED; MERGE/DEPLOY/TAG/RELEASE NOT GRANTED.

## Goal
Provide one truthful inbound path:
`WordPress explicit marketing subscription facts -> /sync/wpapi -> marketing_subscribers + marketing_subscriptions -> /marketing/subscribers`.
Customer M1.3 will later read only Marketing authority and must never query WordPress directly.

## Truth boundaries
- WordPress/its subscription component is provider truth for source subscription/consent facts.
- ERP Marketing owns the synchronized Subscriber identity and provider/source-specific subscription projection.
- A WordPress User account, Subscriber role, WooCommerce Customer or Order does NOT by itself prove marketing consent.
- Connector `subscribers` snapshot MUST contain only explicit email-subscription/consent records exposed by the WordPress site subscription source.
- If no explicit source exists or the connector does not support the resource, sync fails truthfully; no synthetic rows, no fallback to WP users/orders.

## Connector contract increment
Extend Andy Core v1.5.3 ERP connector snapshot resources with read-only:
`GET /wp-json/andy-core/v1/erp/snapshot/subscribers`
using the existing HMAC V1 request contract, connection key, cursor, updated_after and bounded limit semantics.

Subscriber item minimum:
- external_subscription_id: required provider-stable string
- email: required
- first_name / last_name nullable
- status: subscribed | unsubscribed | suppressed | pending | unknown
- consent_source nullable but must preserve source wording when available
- subscribed_at / unsubscribed_at / status_updated_at nullable ISO-8601
- provider_modified_at nullable ISO-8601
- external_list_id / external_list_name nullable
Connector response must identify the actual site connection/source and must never fabricate consent from generic WordPress users.

## ERP sync workspace
Add `/sync/wpapi` as a real Sync workspace/card.
GET requires `sync.view` and `marketing.view`.
POST subscriber sync requires `sync.operate` and `marketing.operate`.
The page shows configured/not-configured state, WordPress/connection/site identity, last run, last success/failure/conflict counts, and a primary `同步 WordPress 订阅用户` action.
Use existing sync-run/orchestration evidence where practical; do not create a second generic sync truth.

## Import contract
- Provider stored as `wordpress`.
- `source_site` uses the configured/returned connection site identity, e.g. `beyourlover.com`.
- Normalize email via existing Marketing trim+lower rule.
- Upsert canonical `marketing_subscribers` by normalized email.
- Upsert subscription fact idempotently by provider + external_subscription_id; never duplicate repeated snapshots.
- Preserve provider status and timestamps exactly; do not collapse contradictory source/provider facts into a global consent state.
- `last_synced_at` reflects successful ERP import time.
- Re-sync updates changed provider facts and leaves unrelated provider/source facts untouched.
- One failed item must be evidenced; do not silently promote failures to success.

## Marketing Subscriber UI increment
`/marketing/subscribers` remains canonical read-only Subscriber workspace.
Add a header action `同步 WP` linking to `/sync/wpapi`.
Rows must visibly expose provider (`WordPress`), source site, status, subscribed/unsubscribed times and last sync using existing grid columns.
No fabricated data.

## Customer M1.3 boundary
M1.0A does NOT implement Customer M1.3.
Future Customer M1.3 may only project Marketing Subscriber/Subscription facts by exact normalized-email equality.
Customer does not call `/sync/wpapi`, Andy Core, WordPress, WooCommerce or subscriber provider APIs directly.

## Explicit exclusions
- No Klaviyo API/integration.
- No newsletter sending, Campaign, Automation or Promotion engine.
- No Customer M1.3 implementation.
- No production deployment or production database migration.
- No direct WordPress database access.
- No treating WordPress users/orders/customers as consent.
- No Andy Core repository mutation in this ERP worktree; ERP may update the frozen connector contract document only.

## Verification
HIGH_RISK because external-provider facts mutate Marketing canonical projections.
Required before Owner UAT readiness:
- focused Marketing subscriber sync gateway/service/controller tests;
- HMAC request/path/query contract test for snapshot/subscribers;
- idempotent repeated snapshot, status update, invalid item/failure, no-customer/order derivation tests;
- Sync workspace navigation/permission tests;
- Marketing Subscriber UI test with `同步 WP` link;
- existing Marketing M1.0, Sync, KOL connector regression tests;
- Architecture tests classify pre-existing drift only;
- `npm run typecheck`, fresh `npm run build`, `git diff --check`, no `public/hot`;
- disposable/local DB only;
- real browser `/sync/wpapi` and `/marketing/subscribers`.

REAL_SYNC_GATE:
Owner UAT is `REAL_SYNC_PASS` only when a real configured WordPress/Andy Core endpoint returns explicit subscriber facts and at least one truthful snapshot can be imported or a truthful zero-record source is confirmed from the real provider. Mock/fake HTTP is automated-test evidence only and never counts as real sync UAT.

## Delivery gate
Owner granted the separate formal-closure Git delivery authorization in chat on 2026-08-30. Commit/push/PR are authorized for this Work Package; merge, Dev/production deployment, tag and release still require separate explicit Owner authorization.

## Formal closure evidence — 2026-08-30

External dependency alignment:
- Andy Core M3.1 Subscriber Snapshot: PR #19 merged, merge `f1a63574962b5df65e12d7d9e3828b2850437439`.
- Andy Core M3.1A `external_subscription_id` contract alignment: PR #20 merged, merge `204c79e16c9b22241ac6c6ec1e17b803810f062a`.
- ERP branch realigned to `origin/main@59dd5870c15aea5140a7ddf44bb046e470008eca` before final Real Sync closure.

Real provider UAT:
- Connector target: authoritative Local WordPress `localdev.beyourlover.com` through configured HMAC Andy Core endpoint.
- Signed `GET /wp-json/andy-core/v1/erp/snapshot/subscribers`: PASS; real provider source identity returned.
- First real sync: `422` processed / `422` succeeded / `0` failed / `0` conflicts.
- Persisted after first sync: `marketing_subscribers=422`, `marketing_subscriptions=422`.
- Second real sync idempotency run (`sync_runs.id=2`): `422` succeeded / `0` failed / `0` conflicts.
- Persisted after repeat: `marketing_subscribers=422`, `marketing_subscriptions=422`; duplicate growth = `0`.
- `erp.b/marketing/subscribers`: HTTP 200 and real synchronized dataset retained for later local/UAT work.

Verification:
- Marketing gateway/sync/foundation + Sync workspace focused tests: PASS (`16` tests / `193` assertions).
- KOL connector regression: PASS (`61` tests / `118` assertions).
- Additional Sync regression: PASS (`40` tests / `473` assertions).
- `npm run typecheck`: PASS.
- fresh `npm run build`: PASS.
- changed/new PHP lint: PASS.
- `git diff --check`: PASS.
- `public/hot`: ABSENT.
- obvious secret diff scan: PASS; no shared secret committed.

Architecture classification:
- M1.0A-owned file/directory/frontend/route baselines are updated in `ModuleArchitectureTest`. Final Architecture run: `5` passed / `3` failed; the three reached failures are pre-existing `origin/main` file-set/application-layer/migration baseline drift, not M1.0A behavior failures.
- Remaining Architecture drift is pre-existing on `origin/main`, not introduced by M1.0A: current main already contains Finance `EcbFxService`, KOL `AffiliateWpGateway`, Shell `UserUiPreferenceService`, KOL content migration, and a Customer Companies Create frontend hash that differs from the frozen test expectation.
- M1.0A does not widen scope to repair or whitelist those unrelated cross-module drifts.

Closure decision:
- `REAL_SYNC_GATE = PASS`.
- `IDEMPOTENCY_GATE = PASS`.
- `M1.0A_CLOSURE_GATE = PASS / READY_FOR_PR`.
- Real subscriber records in `erp.b` are intentionally retained for later Customer/Marketing UAT.
- Newsletter/Campaign/Automation/Promotion and all real outbound email remain out of scope; no production deployment, tag or release is authorized.

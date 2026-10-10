# ERP Marketing M2 — Gap audit and contract handoff

**Issue** #448. **Baseline ERP main** b9641286f2d8f15dcc659fc6f1f820f44b221486. **ERP branch only**; no main merge, real data, production deployment, email, G2, Woo, or AfterShip.

## M2.0 existing assets reused
- Existing marketing_subscribers (unique normalized email) + marketing_subscriptions (unique provider/external_subscription_id) and migrations, **unchanged**.
- Existing WordPressSubscriberGateway / signed HttpWordPressSubscriberGateway, WordPressSubscriberSyncService, EloquentMarketingSubscriberSyncWriter, SyncRunService and sync.operate + marketing.operate authorization.
- Existing /marketing/subscribers filter / grid UI; no second interface or plugin.
- Elementor source is the default: no source query parameter, original HMAC path and external IDs remain unchanged.

## M2.1–M2.4 implementation
- Gateway trailing optional nullable source preserves old PHP callers; native source is included **before signing the exact query path**.
- New ANDY_CORE_NEWSLETTER_SNAPSHOT_ENABLED default false prevents calls to a not-yet-shipped Core API. Enabling it deliberately syncs each source with its own cursors/page guard and writes an auditable Sync Run.
- Native item validation: signed native source, explicit source_site, provider, ID prefix/digest, UTC timestamp/status; no import of tokens/IP. No automatic cross-site connection registry.
- Monotonic timestamp updates and equal-time restrictive-status precedence. Unsubscribed must be observed pending before subscribed; suppressed can never silently reactivate.
- Separate provider errors, failed-item traces and no invented consent. Existing UI displays all per-source statuses and clarifies that subscribed is not an EDM sending authorization.

## BLOCKED_CORE_NATIVE_SIGNED_SNAPSHOT
Andy Core PR #68 and PR #70 are still Draft/unmerged. The actual source=andy_core_newsletter HMAC snapshot endpoint does not yet exist. Core window A must freeze: signed source query, validated envelope, stable source-isolated cursor/order, updated_after and same-second tie-break, canonical timestamps, allowlist item schema, and site_key vs connection_key relationship.

## Gates
- Isolated ERP fake-gateway/DB tests and CI; then Core native contract Freeze; separately authorized synthetic two-endpoint E2E; Owner UAT; explicit separate GitHub main merge/production authorization.
- **No** ERP or WWW production migrations, subscriber imports, email sends or EDM in this phase.

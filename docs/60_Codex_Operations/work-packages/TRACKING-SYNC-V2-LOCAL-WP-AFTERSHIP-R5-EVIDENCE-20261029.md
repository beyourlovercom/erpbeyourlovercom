# Tracking Sync V2 — R5 isolated Woo / AfterShip plugin evidence (2026-10-29)

**Result:** Real local WordPress plugin integration PASS. AfterShip Cloud ingestion NOT verified.

## Isolation
- Standalone WordPress 7.1.3, WooCommerce 11.2.1, actual AfterShip WooCommerce Tracking 1.18.5 plugin from WordPress.org.
- US Mac localhost-only 127.0.0.1:18763; independent MySQL aicc_tracking_v2_20261029; fictional WC order #12 with two line items (IDs 1 and 2).
- Separate local API credentials in UAT-only 0600 file outside GitHub, never checked in or displayed.
- Local MU safety plugin blocks all non-loopback HTTP, email and Woo webhooks; verified outbound AfterShip HTTP blocked and email intercepted. No real customer or payment.
- Local HTTP router sets HTTPS=on only to simulate a TLS-terminating reverse proxy for Woo BasicAuth. The actual wire remains loopback HTTP; this is not a TLS certification, and ERP production TLS settings remain unchanged.

## Genuine ERP Gateway → Woo REST → AfterShip plugin
1. ERP real HttpWooCommerceTrackingGateway GET/PUT against isolated Woo /wp-json/wc/v3/orders/12 for USPS test tracking, line item 1: written=true, verified=true, package_count=1.
2. Exact same call: written=false, verified=true, package_count=1; idempotent, no duplicate tracking.
3. DHL second test tracking, line item 2: written=true, verified=true, package_count=2; original package preserved.
4. Different new tracking for already-claimed line item 1: WooCommerceTrackingConflictException, remote record protected.
5. Genuine plugin AfterShip_Actions::get_tracking_items returned two packages with matching carrier, test tracking number, line IDs and MD5 tracking_id.
6. Plugin get_tracking_items_for_display returned two; actual display_tracking_info customer template included BOTH tracking numbers; WC_Order meta stored two.
7. No PHP fatal errors. Test instance blocks AfterShip Cloud, so no external tracking record was created.

## Regression and release boundaries
- Auto-discovery now excludes new Shipments with an existing pending, processing or terminal failed Tracking Outbox. Prevents retrying one shipment twice in one half-hour tick.
- Targeted PHP 34 tests / 156 assertions PASS, prior domestic/overseas UI 22 tests PASS, TS/Vite PASS.
- Legacy Mvp1WorkflowTest audit-count failure independently reproduced at same line on unmodified main c68aa55f; not introduced by PR.
- Real Dev remains G5 quarantined: Woo orders REST 403, provider network blocked; G5 safety 61/61 PASS. No change to Dev or Production.
- Not certified: actual Dev plugin installed version, AfterShip Cloud ingestion, production site canary, two natural half-hour triggers. PR stays Draft; separate Owner Production PASS required.

# P2.3 Gate A — ERP → Woo Dev destination isolation audit

Date: 2026-10-08. **Status: BLOCKED (runtime connection not proven)**.

## Intent
Before attempting any ERP Shipment → WooCommerce/AfterShip Tracking Canary, prove that a **separate ERP non-production runtime** is pointed **only** to `https://dev.beyourlover.com`, uses a distinct Woo connection key and dedicated test credentials, and has external callbacks isolated. **Do not update ERP Production, Woo WWW, real orders or live fulfillment.**

## Existing source behavior (ERP GitHub main)
- `config/services.php`: the outbound Woo gateway uses `WOO_BASE_URL`, `WOO_CONSUMER_KEY`, `WOO_CONSUMER_SECRET`, and `WOO_CONNECTION_KEY` (default `beyourlover.com`).
- `app/Modules/Sync/Infrastructure/WooCommerce/HttpWooCommerceTrackingGateway.php`: Woo REST `GET/PUT .../wp-json/wc/v3/orders/{id}`. Updating metadata can lead to a later `WooCommerceOrderStatusOutboundService::pushOne` from `WooCommerceTrackingOutboundService`, not just a tracking number write.
- `app/Modules/Sync/Experience/Routes/web.php`: interactive Tracking outbound operations have `can:sync.operate` permission checks.
- `routes/console.php` schedules optional Woo **product/order inbound imports** using `WOO_PRODUCT_SYNC_ENABLED` / `WOO_ORDER_SYNC_ENABLED`. These flags are **not a global outbound Tracking kill switch**. Explicit tracking operations/orchestration still exist. No assumption about production's scheduler, queue workers or independent plugin hooks is warranted without runtime audit.
- The consumer on Dev is WooCommerce 11.1.1/AfterShip 1.18.4 with HPOS and matched metadata shape (P2.3 previous audit).

## Audit tool
`tools/audit/p23-woo-dev-target.php` is an **inspect-only** PHP CLI helper:
- `php tools/audit/p23-woo-dev-target.php --self-test`: pure deterministic fail-closed classification tests.
- `php tools/audit/p23-woo-dev-target.php --inspect`: boots Laravel and reads **effective** `config('services.woocommerce')`, not raw `.env`; prints *host classification*, environment, HTTPS, key/secret **presence booleans**, whether the connection key is the known default, sync-import flag, configuration-cache flag, and result. It **never prints credentials, tokens, raw connection key or customer/order data and never calls Woo REST**.
- A Dev candidate requires exact `https://dev.beyourlover.com` root target, both credentials configured, a **nondefault distinct** connection key, and ERP runtime environment `local/staging/testing`. Even then it reports **CANDIDATE**, not permission to execute Tracking: source destination and callback isolation still need separate verification.
- If host is WWW, absent, non-HTTPS, wrong path, unknown or using the default connection key, result **BLOCKED_NO_DEV_OUTBOUND_CANARY**. Source account may be intentionally configured for production; do not change it simply to make a test pass.

## Executed evidence
Read-only source check via GitHub, self-hosted BYL Remote MCP UAT China Mac fixed self-test, and authorized read-only fallback on China Mac:
- Current China Mac clone `/Users/a/AI/007/erpbeyourlovercom` uses Laravel `APP_ENV=local`.
- Exact GitHub script PHP lint **PASS**, `--self-test` **PASS (10/10)**.
- `--inspect` on this local clone: `woocommerce_target_class=UNSET`; consumer key/secret absent; connection key resolves to the **default production key** in config; inbound order sync flag false; `dev_target_prerequisites_met=false`; `gate_a=BLOCKED_NO_DEV_OUTBOUND_CANARY`.
- The local ERP clone is **not** evidence for the actual ERP Production runtime host/connection.
- No remote runtime credentials, tokens or production `.env` contents accessed. No ERP production or Woo order writes executed.
- Self-hosted BYL Remote MCP UAT connector currently exposes only device list, tool descriptions and a fixed process self-test; generic file read/command execution via that connector is not available yet. No device restarts.

## Pending isolation gates (hard stop until complete)
1. Identify a separate **ERP test runtime**, then run `--inspect` there with authorized read-only access, verify host exactly Dev and a nondefault test connection key. Do **not** repoint an ERP Production instance's existing Woo connection to Dev.
2. Establish dedicated **Dev Woo REST credentials**; no live Woo WWW secrets or real customer account reuse. Confirm access scope.
3. Implement/verify a **hard stop for Tracking outbound** in non-test environments, not just Woo *inbound* scheduler toggles. Inspect order-status follow-up, SMTP/MU, Jetpack, AfterShip background tasks and webhooks for external side effects.
4. Confirm test ERP orders have correct Dev Woo root order and line-item IDs, with no live shipment/warehouse processing. Existing Woo #39921 is a draft with no item IDs/ERP reference and is **not** suitable.
5. Only after all gates and Owner review: allow controlled non-real sandbox metadata Canary on a dedicated test order, verify replay, split packages and denied access.
6. Do not change the approved `WOO_BASE_URL`, Woo credentials, tracking order status or production deployment based on this read-only audit.

**Conclusion: Gate A tool and source preflight done; actual separate ERP↔Dev target isolation still NOT PASS. Continue code-level multi-package edge-case test design while the test runtime and secure DC command interface are being arranged.**

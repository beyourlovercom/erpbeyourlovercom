# ERP-WORDPRESS-CONNECTOR-PRODUCTION-CONNECTION-M2.1-001

## Scope
Production Connection / Signed Health Only. No subscriber, affiliate, coupon, referral, payout sync; no WordPress business mutation.

## Baseline
- Branch: `feature/erp-wordpress-connector-production-connection-m2-1-001`
- Worktree: `/Users/a/AI/007/worktrees/erp-wordpress-connector-production-connection-m2-1-001`
- Baseline main: `ee8a166410a1226d60bedd6f48e113d21a269b81`

## ERP Production preflight
- `.env.production`: ANDY_CORE_URL BLANK; ANDY_CORE_KEY_ID BLANK; ANDY_CORE_SHARED_SECRET BLANK.
- ANDY_CORE_CONNECTION_KEY present; ANDY_CORE_VERIFY_TLS=true; ANDY_CORE_UAT_FAKE=false.
- `wordpress_connector_settings`: no Production row.
- `php artisan kol:affiliatewp-health --json`: fail-closed, RC=1, no request sent.

## WordPress Production public preflight
- `https://www.beyourlover.com/` is publicly reachable through a browser-class request.
- TLS verification succeeds.
- `GET /wp-json/andy-core/v1/erp/health` with browser-class UA returns `404 rest_no_route`.
- WordPress REST index is reachable and `andy-core/*` namespace is absent.
- Non-browser UA from the ERP host receives Cloudflare HTTP 445 on the same route.

## Current state
`SIGNED_HEALTH = NOT_EXECUTED`
`BUSINESS_SYNC = NOT_EXECUTED`
`WORDPRESS_MUTATION = NOT_EXECUTED`

## Blockers
1. Production WordPress does not currently expose the Andy Core Connector REST namespace; signed health route is not registered.
2. Production ERP has no Connector URL/key ID/shared secret configured.
3. ERP connector User-Agent is currently blocked by Cloudflare with HTTP 445; browser-class UA reaches WordPress REST normally.
4. Production wp-admin is at a fresh login screen on the US Mac; no reusable authenticated admin session exists.

## Exact next gate
Restore/upgrade/activate the canonical Andy Core Connector on Production WordPress, establish one Production credential pair (`connection_key=beyourlover.com`, key ID, one-time shared secret), and add the narrow Cloudflare exception required for the ERP Connector health path/identity. Then store the same credential in ERP and execute exactly one signed `GET /wp-json/andy-core/v1/erp/health`.

## Windows Local Clone UAT — Technical PASS

- Clone host: `YST-241030SSD`
- Clone URL: `https://localdev.beyourlover.com`
- WordPress: `7.1`
- Andy Core: `1.5.3`; DB contract `1.4.0`
- WooCommerce: active
- AffiliateWP: active
- Connector: enabled with existing Local-only credential; secret value never read or printed
- Pre-install data fingerprint: users `6051`, AffiliateWP affiliates `1758`, Woo coupons `2827`, YBY tables `9`
- Rollback evidence: plugin snapshot `104/104`, DB dump ~222 MB, dump completion PASS, SHA256 recorded
- UAT package: official `v1.5.3` plus current-main Connector post-release patch; `103` files; no `.bak`
- Post-install target/package file comparison: `103/103`, `FILE_DIFFS=0`
- Local root: HTTP `200`, TLS verification PASS
- Local login: HTTP `200`, TLS verification PASS
- Unsigned health: standard contract-v1 rejection (`CONTRACT_VERSION_UNSUPPORTED`)
- Signed GET `/wp-json/andy-core/v1/erp/health`: HTTP `200`, TLS PASS, `ok=true`, contract `1`, connection match PASS, Andy Core `1.5.3`, operational PASS
- Post-install data fingerprint unchanged: users `6051`, affiliates `1758`, coupons `2827`, YBY tables `9`
- Business snapshots/mutations: NOT EXECUTED
- Production WordPress/Cloudflare/ERP Connector config: UNTOUCHED
- Local WP-API page opened for Owner UAT

`LOCAL_CLONE_TECHNICAL_UAT = PASS`
`NEXT_GATE = OWNER_LOCAL_WP_API_UAT`

## Owner Local Clone UAT — PASS

- Owner visual UAT on `localdev.beyourlover.com`: PASS
- Local Signed Health: HTTP 200 / TLS PASS / contract v1 / operational YES
- Data fingerprints unchanged: users 6051, affiliates 1758, coupons 2827, YBY tables 9
- Business sync / mutation: NOT EXECUTED
- Production WordPress / Cloudflare / ERP Connector: NOT CHANGED during Local UAT

`OWNER_LOCAL_WP_API_UAT = PASS`
`NEXT_GATE = PRODUCTION_PREDEPLOY_AUTHENTICATED_BACKUP + DEPLOY`

## Production origin User-Agent compatibility evidence — 2026-09-03
- Production Andy Core install/activation and unsigned Connector health are PASS.
- Production Connector identity and shared credential bootstrap are complete; business sync remains 0.
- Exact Cloudflare health allowlist matched the ERP request and logged `Action=Skip`, `Service=Custom rules`.
- Origin behavior is User-Agent dependent on the same health endpoint:
  - `BYL-ERP-AffiliateWP-Connector/1.0` -> HTTP 445, empty body.
  - `Mozilla/5.0` -> HTTP 400 JSON Connector contract rejection.
  - `Mozilla/5.0 BYL-ERP-AffiliateWP-Connector/1.0` -> HTTP 400 JSON Connector contract rejection.
- Conclusion: the origin/server layer rejects the pure custom User-Agent before WordPress; Cloudflare WAF is not the blocking layer.
- M2.1 compatibility fix: retain the identifiable Connector token but prefix it with `Mozilla/5.0`; HMAC, timestamp, nonce, TLS, redirect and contract controls are unchanged.

## Production closure — 2026-09-03

- Owner backup: completed manually with All-in-One WP Migration before deploy.
- Production Andy Core candidate installed and activated; REST namespace present.
- Production connector identity: enabled, connection key `beyourlover.com`, key id `prod-20260903-v1`.
- Shared secret generated once in WordPress and transferred to ERP Production without disclosure; clipboard cleared after transfer.
- Origin behavior identified: pure custom UA `BYL-ERP-AffiliateWP-Connector/1.0` receives HTTP 445, while a browser-compatible UA reaches WordPress JSON normally.
- ERP runtime compatibility fix merged in PR #167 / merge commit `162f7208c557ca6542b80b6e04823c5a001db2d1`: `Mozilla/5.0 BYL-ERP-AffiliateWP-Connector/1.0`.
- Required CI on PR #167: connector PASS, coupon PASS, closure PASS.
- Production release: `1c0b6ec0215818af7e37bd19e77e40a8732f05ad-m21-162f7208c557`.
- Rollback release retained unchanged: `1c0b6ec0215818af7e37bd19e77e40a8732f05ad`.
- Final signed health: PASS; contract v1; Andy Core 1.5.3; WordPress 7.0.2; WooCommerce active; AffiliateWP active; writable true.
- Business snapshot/sync/mutation executed in M2.1: **0**.
- M2.1 stops here. M2.2 Production Read-Only First Sync remains an explicit Owner gate.

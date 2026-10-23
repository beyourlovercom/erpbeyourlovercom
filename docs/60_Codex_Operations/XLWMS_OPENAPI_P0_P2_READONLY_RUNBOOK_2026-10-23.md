# XLWMS / 领星 OMS OpenAPI P0–P2 Read-Only Integration

Work Package: ERP-XLWMS-OPENAPI-P0P2-20261023
Execution provider: GPT_WEB (bounded read-only infrastructure)
Verification tier: HIGH_RISK (external authentication and API secrets)
Scope: DEV-only + new GitHub branch. No main merge, production deployment, real outbound, Woo order/status write, database writes, or credentials in Git history.

## Official v1 contract

- Base: `https://api.xlwms.com/openapi/v1`, **OMS v1**; do not substitute OMP v2 (different authentication).
- Signature: case-insensitive dictionary sort for fields in `data`, JSON serialization, HMAC-SHA256 with App Secret of `appKey + sortedDataJSON + reqTime`; `authcode` query parameter, all other parameters POST JSON. 10-digit seconds timestamp valid for five minutes.
- Warehouse list: `POST warehouse/options`; no data parameter; enabled/customer-bound warehouses only.
- Channel list: `POST logistics/channel/list`, data object.
- Product list: `POST product/pagelist`, data object.
- `POST outboundOrder/create` is **not implemented** or callable from this P0–P2 client.

Sources:
- https://apidoc-oms.xlwms.com/docs/%E5%BC%80%E5%8F%91%E9%AA%8C%E7%AD%BE%E5%B7%A5%E5%85%B7
- https://apidoc-oms.xlwms.com/reference/getwarehouselistusingpost
- https://apidoc-oms.xlwms.com/reference/getproductforpageusingpost
- https://apidoc-oms.xlwms.com/reference/getproductforpageusingpost_1

## Environment configuration (server-side only)

Never commit actual App Key / Secret or send them to GitHub Actions.

```dotenv
XLWMS_ENABLED=false
XLWMS_BASE_URL=https://api.xlwms.com/openapi/v1
XLWMS_APP_KEY=
XLWMS_APP_SECRET=
XLWMS_TIMEOUT_SECONDS=15
XLWMS_WAREHOUSE_MAP_JSON='{"US-NY":"OMS-WAREHOUSE-CODE"}'
XLWMS_SKU_MAP_JSON='{}' # optional exceptions only, e.g. {"US-NY":{"ERP-ALIAS":"OMS-NATIVE-SKU"}}'
```

App Key / Secret supplied by warehouse are partially masked in chat, thus **no authenticated API execution** can be claimed until they are securely injected into the US Dev environment.

- `XLWMS_ENABLED=false` is the default fail-closed state.
- Hard gate in `config/xlwms.php`: `auto_dispatch_enabled=false`; there is no P0–P2 dispatch implementation.
- URL is pinned to `https://api.xlwms.com/openapi/v1` (no alternate host, redirects, userinfo or non-HTTPS).
- Mask secrets from output; `authcode` appears in mandatory vendor URL query parameter; never print/log signed URLs or raw HTTP client exceptions.
- Keep config cache in mind in Dev when changing environment; do not automatically alter production config.

## Operator commands — DEV only

```sh
php artisan xlwms:audit --warehouse=US-NY --sku=ERP-SKU
# After authenticated DEV credentials are injected and XLWMS_ENABLED=true:
php artisan xlwms:audit --live --warehouse=US-NY --sku=ERP-SKU
```

The first command runs local configuration checks and does not call XLWMS.
The second calls only the three allowlisted read-only endpoints. It prints HTTP evidence **not** proof of vendor business success. A separate contract/response-body inspection is needed before declaring authentication pass.

## P0–P2 acceptance criteria

1. Disabled/missing credentials never make network calls.
2. Sample signature canonicalization is covered by PHP tests.
3. All P1 calls are signed, HTTPS, no redirects and no create endpoint available.
4. ERP-to-OMS warehouse binding is explicit. SKUs use identical codes by default with **optional warehouse-scoped exception overrides only**. A missing SKU override is not an error; remote catalog existence remains unverified. Configuration-only readiness is named `configured_unverified`, never ready to dispatch.
5. Remote existence of warehouse, SKU, channel and stock is **unverified** until real OMS authenticated read-only responses are inspected.
6. No production modifications, no external orders, no migrations or customer-facing UI.

## P3–P5 deferred

P3: outboundOrder/create and idempotency/remote read-after-timeout; P4: controlled test order and tracking callback/poll; P5: warehouse-scoped controlled dispatch and production Owner gate. This PR must not silently introduce these.

## Verification and current blockers

- Available: GitHub source review, PHP syntax / dedicated regression tests via CI (once workflow completes).
- The official Desktop Commander entry previously returned a usage limit; the self-hosted formal OAuth MCP was used on US Mac mini without device restarts.
- Owner's actual one-shot test screenshot showed `verified`, HTTP 200, OMS code 200, and one warehouse row; no secrets are saved to source control or application storage by the page.
- SKU existence, native shipping-channel selection, remote warehouse identity and Dev deployment/Production release remain separate proof gates.

## Admin-only one-time credential reality check (new)

- Page after Dev deployment: `/logistics/integrations/xlwms`.
- Menu access: Logistics → Carriers → **仓库 API · 领星 OMS** (visible only with `system.permissions.manage`).
- Dedicated switch: `XLWMS_MANUAL_PROBE_ENABLED=true` on the authorized Dev runtime. This switch does **not** enable `XLWMS_ENABLED` catalog jobs or any outbound functionality.
- Enter the real App Key and Secret in the authenticated page; click “测试连接（仅查询仓库）”.
- This request sends one POST to `https://api.xlwms.com/openapi/v1/warehouse/options`, returns a sanitized JSON verdict, and clears the Secret from browser form state.
- **No persistence**: page does not save credentials or expose them in Inertia props, redirects, sessions, database, settings table, logs, or Git.
- Requires `system.permissions.manage` at GET and POST routes, CSRF, a 5-per-minute rate limit, no HTTP redirects and no URL override.
- `verified` requires HTTP 200, explicit success code / flag and presence of `data`. If the vendor uses an undocumented response shape, report `unconfirmed` rather than claiming authentication.
- **Read-only**: never invoke `outboundOrder/create`. No real order, warehouse inventory or WooCommerce status writes.

### Readiness after P0-P2 branch UI addition

Local US Mac isolated worktree PHP lint and targeted PHP regression tests passed: 12 tests / 59 assertions; Vite build and vue-tsc typecheck passed after correcting optional carrier integration prop. These are **simulated responses**, not live vendor authorization evidence. GitHub Actions remote jobs were failing at runner scheduling (no job steps available); US Mac local verification is the reliable source for this package.

The formal `https://erp.beyourlover.com` Production endpoint must not be changed without its independent Production release authorization, even though read-only verification itself is not an outbound shipment operation.

## Owner-frozen default-match contract (2026-10-23)

- **Warehouse binding remains explicit:** ERP warehouse code -> real OMS warehouse code; never infer from labels or automatically bind another warehouse.
- **SKU identity by default:** Submit the ERP SKU unchanged when the remote catalog confirms that same exact SKU. This is not a bulk mapping process. Store only differing warehouse-specific SKU aliases as exceptional overrides. Missing/ambiguous remote SKU or inactive stock must block that order in the future dispatch phase.
- **Shipping channel is NOT mapped:** ERP channel/service master values are created from the actual warehouse's available OMS services. Future outbound payload must use the OMS native service code stored in ERP directly, with its human-friendly name separately displayed. Never invent a translation dictionary or guess service codes from names.
- A channel absent, disabled, or not accepted by the selected warehouse must be flagged for administrator correction or creation in the ERP native carrier master; do not silently substitute another channel.
- These read-only P0-P2 checks never assert a channel or SKU is remotely verified until a live catalog response has been compared. `auto_dispatch_enabled=false` remains hard OFF.
- User-visible integration page should show connection, saved credentials after a separate secure-storage implementation and explicit warehouse binding. **Do not reintroduce mandatory SKU/channel mapping setup.** Normal matching is automatic; exceptions only.

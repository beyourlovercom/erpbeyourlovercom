# DE Tianyun XLWMS R1–R3 handoff and evidence

## Boundaries
- Warehouse in ERP: `DE` (existing canonical record). OMS warehouse: exactly `Hannover`.
- US-SM remains the default and existing behavior is preserved.
- German OMS access is read-only: no outboundOrder/create, no carrier/service writes, and no DE inventory apply even if US-SM apply is enabled.
- Connection secrets remain encrypted server-side; local operator-only provisioning through existing trusted TTY is mandatory. No plaintext key or full signature in this document.

## Operator commands (ERP Dev, after owner review)
1. `php artisan xlwms:configure-inventory-api --warehouse=DE --check`
2. If MISSING, a trusted **local** operator executes `php artisan xlwms:configure-inventory-api --warehouse=DE --configure`. The hidden prompts require an authorized operator; do not pipe credentials over DC or commit them.
3. `php artisan xlwms:sync-inventory --warehouse=DE --dry-run`
4. `php artisan xlwms:inspect-de-channels`
5. Review snapshot rows, SKU identities, unmatched/blocked reasons and logistics-channel candidate records; obtain separate Owner approval before any business mapping activation.
6. Never run `--apply --warehouse=DE`: it explicitly fails closed.

## Live read-only OMS evidence (US Mac mini Keychain)
- Date: 2026-11-20.
- `POST /openapi/v1/logistics/channel/list`, data `{"whCode":"Hannover"}`: HTTP 200, business code 200, **5** channels.
- Observed codes: `Service with the lowest price`; `Upload_Shipping_Label`; `No_Shipping_Service`; `DHL PAKET DOMESTIC`; `DHL PAKET International N`.
- Both DHL codes report provider carrier `DE-DHL-TG`; all 5 records currently return `whCodes: []`. Therefore mapping scope is marked **unverified**, and no ERP carrier/service is automatically bound.
- Earlier R0 inventory query returned 34 stock-SKU records from `Hannover` and succeeded with HTTP 200/business 200. Channel count changed from 4 (earlier R0 sample) to 5 on this re-query; never hardcode channel totals.
- No credentials or complete auth signatures are printed or saved in evidence.

## Validation
- From dedicated worktree, Composer dependencies installed locally without plugins/scripts.
- `php artisan test` limited to ConfigureXlwmsInventoryApiTest, XlwmsInventoryReadOnlyGatewayTest, XlwmsWarehouseChannelCatalogTest, XlwmsInventorySyncServiceTest: **46 passed / 295 assertions**.
- PHP syntax checks passed; `git diff --check` passed; new R3 files formatted with Laravel Pint.
- All three artisan commands are registered.
- This is code/test validation only. No ERP Dev DB credential provisioning, Dev dry-run or Production rollout is claimed.

## Go/no-go after code review
- Require Owner review, branch PR, Dev encrypted connection configured via trusted local TTY, Dev read-only snapshot inspection and explicit matching decision.
- No Production deployment or live outbound authorization in R1–R3 scope.

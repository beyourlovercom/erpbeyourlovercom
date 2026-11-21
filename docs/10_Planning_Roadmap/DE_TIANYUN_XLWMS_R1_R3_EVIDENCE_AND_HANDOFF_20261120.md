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

## Actual isolated Dev R2–R3 live UAT (2026-11-20, subsequent evidence)
- Operator locally configured encrypted DE/Hannover credentials; `--warehouse=DE --check` returned PRESENT. No plaintext secret or full signature captured.
- Isolated US Mac mini local-only SQLite: 182 migrations, DE and US-SM each exactly one warehouse row, distinct generated Laravel encryption key; Production and Remote Dev unchanged.
- Two real signed `xlwms:sync-inventory --warehouse=DE --dry-run` runs succeeded; snapshot ID remained 1, checksum stable, no ERP stock writes.
- Provider catalog: 64 SKUs; Hannover stock: 34 SKUs; good stock 609, available 602, locked 7.
- Snapshot: 64 rows (34 `unmatched` and 30 `catalog_only`). Status `blocked` reflects isolated Dev `products` table is empty, NOT an OMS transport/auth failure.
- Read-only DB evidence: DE inventory rows 0, US-SM inventory rows 0, one DE snapshot recorded. No `--apply`.
- Real signed `xlwms:inspect-de-channels`: 5 provider channels; all `whCodes: []` (warehouse binding not confirmed); no automatic ERP mapping or dispatch.
- DE provider DHL codes: `DHL PAKET DOMESTIC`, `DHL PAKET International N` with provider carrier `DE-DHL-TG`. Local ERP seeded carrier DHL offers `paket_domestic` / `paket_international`, but exact provider-to-ERP carrier identity and OMS warehouse binding remain unverified.
- Next: join actual ERP Product master into isolated Dev (read-only source/authorized fixture) to review 34 SKU mappings; confirm channel binding at Tianyun OMS, then Owner UAT.
- The prior code-stage-only note above records the original PR snapshot; this section is the subsequent real isolated Dev evidence.

# DE Tianyun/Hannover — Owner SKU ownership correction (2026-11-23)

## Binding ownership decisions
- **EXCLUDE** `zuodian`: other customer's stock; **NEVER** map to ERP `pillow`. Historic 159 QOH/148 available belongs to a third party and must not be counted in BYL stock.
- **EXCLUDE** `X002DEC9SZ`: owner explicitly says ignore, identity unknown. Even if canonical/alias records appear later, the excluded provider SKU is never eligible to apply to DE ERP inventory.
- OMS `007-VM465A` → ERP `VM465A` (historic alias id 25)
- OMS `TL278A` → ERP `TL278A-Green` (historic alias id 29)
- OMS `TL278B` → ERP `TL278B-Black` (historic alias id 31)
- OMS `M3` → new ERP canonical product `M3`
- OMS `SPM03E` → new ERP canonical product `SPM03E`

## Production correction evidence
- Reversed false `zuodian→pillow` alias id 38 and DE warehouse stock id 512 (159 QOH). Stock row soft-deleted; a compensating `inventory_adjustments` row and audit events preserve the history.
- New `M3` Product id 1489: DE QOH 1 / available 1.
- New `SPM03E` Product id 1490: DE QOH 0 / available 0.
- Confirmed aliases 25/29/31 for physical inventory only, **no OMS outbound alias dispatch approval**.
- `VM465A` DE QOH 0, `TL278A-Green` DE QOH 0 (1 historic ERP reserved still attached to open order), `TL278B-Black` DE QOH 1 / OMS available 0 (locked 1).
- Owner-prior correction of 28 SKUs is preserved; no US-SM/other warehouse inventory changed.
- Signed prechange targeted backup for alias/new product correction: `de-owner-remaining-sku-identity-pre-20261123-132910Z.json` (SHA-256 `7987bc775e09f8cf27ad4a65edb21345c2afea44d39ac15a4c8c1102673b5332`).
- `zuodian` wrong mapping reversal has its own signed backup and compensating audit history.

## Code enforcement
- Owner-only exclusions are frozen in `config/xlwms.php`, scoped to DE and case-insensitive.
- Ignore before Product OS SKU resolution, lot/FIFO reconciliation, apply and mismatch counting.
- Keep excluded source observations for audit as `resolution_status=excluded`, non-editable; do not count them as an ERP product, missing SKU or synchronization failure.
- `summary.oms_rows` and stock totals refer to **eligible BYL stock**, while separately exposing raw provider inventory and excluded SKU counts/quantities. ERP stock is never changed for excluded entries.
- UI detail filter shows "已排除"; products are not mapped or linked from excluded rows.
- No changes to US-SM identity/sync and no opening Production apply gates, OMS CREATE or recurring job.

## Verification and follow-up
- Synthetic exclusions test deliberately creates canonical `zuodian` and `X002DEC9SZ` products; DE still skips both. US-SM does not inherit DE exclusions.
- After Production deployment, perform fresh real OMS `--dry-run` and verify 32 eligible stock rows/444 eligible QOH, 2 excluded stock rows/159 excluded QOH, and 30 catalog-only rows from the known 64 catalog products (subject to actual new OMS data).
- ERP reservations exceeding physical QOH remain a separate fulfillment issue and do not change warehouse physical truth.

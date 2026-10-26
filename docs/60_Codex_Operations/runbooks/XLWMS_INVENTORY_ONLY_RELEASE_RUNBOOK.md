# XLWMS Inventory-Only OMS Release Runbook

This release candidate contains only signed, fixed read calls for the US-SM
inventory snapshot and FIFO lot reconciliation. It does not migrate or write
Production data as part of release preparation.

## Future Production provisioning (independent operation)

Production currently lacks `warehouse_openapi_connections`. After the schema
migration is separately scheduled and approved, provision the connection in a
controlled server-only session using the encrypted Eloquent model cast:

```php
WarehouseOpenApiConnection::updateOrCreate(
    ['warehouse_id' => $usSmId, 'provider' => 'xlwms'],
    [
        'app_key_encrypted' => $ownerSuppliedAppKey,
        'app_secret_encrypted' => $ownerSuppliedAppSecret,
        'oms_warehouse_code' => 'US-SM',
    ],
);
```

Values must come from the approved secret source, must never be committed or
logged, and must not be provisioned by this release-preparation task.

## Read-only verification

Keep `XLWMS_INVENTORY_SYNC_SCHEDULE_ENABLED=false` until the connection and
read-only smoke test are independently approved. A scheduled run selects
`--dry-run` unless both `XLWMS_INVENTORY_APPLY_ENABLED` and
`XLWMS_LOT_RECONCILIATION_V2_ENABLED` are true.

## First apply gate

The first Production `--apply` requires explicit Owner authorization after a
review of a successful read-only snapshot, SKU/alias coverage, unmatched SKU
report, FIFO/unknown-age plan, reservation state, and rollback/stop procedure.
No Production migration, credential provisioning, or apply is authorized by
this runbook or by the release-candidate preparation.

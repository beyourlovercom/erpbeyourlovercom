<?php

/* Inventory-only, read-only OMS release candidate. All switches fail closed. */
return [
    'enabled' => filter_var(env('XLWMS_ENABLED', false), FILTER_VALIDATE_BOOL),
    'timeout_seconds' => max(2, min(30, (int) env('XLWMS_TIMEOUT_SECONDS', 15))),
    'ca_bundle' => (string) env('XLWMS_CA_BUNDLE', ''),
    'inventory_sync_schedule_enabled' => filter_var(env('XLWMS_INVENTORY_SYNC_SCHEDULE_ENABLED', false), FILTER_VALIDATE_BOOL),
    'inventory_sync_schedule_minutes' => in_array((int) env('XLWMS_INVENTORY_SYNC_SCHEDULE_MINUTES', 30), [10, 30], true)
        ? (int) env('XLWMS_INVENTORY_SYNC_SCHEDULE_MINUTES', 30) : 30,
    'inventory_apply_enabled' => filter_var(env('XLWMS_INVENTORY_APPLY_ENABLED', false), FILTER_VALIDATE_BOOL),
    'lot_reconciliation_v2_enabled' => filter_var(env('XLWMS_LOT_RECONCILIATION_V2_ENABLED', false), FILTER_VALIDATE_BOOL),
    // First Production write must match an explicitly reviewed read-only snapshot.
    'inventory_first_apply_approved_checksum' => (string) env('XLWMS_INVENTORY_FIRST_APPLY_APPROVED_CHECKSUM', ''),
    // After that, automatic deltas are deliberately conservative. Anomalies
    // persist a blocked snapshot, never an ERP quantity or FIFO write.
    'inventory_auto_max_sku_delta_units' => max(0, (int) env('XLWMS_INVENTORY_AUTO_MAX_SKU_DELTA_UNITS', 5)),
    'inventory_auto_max_total_absolute_delta_units' => max(0, (int) env('XLWMS_INVENTORY_AUTO_MAX_TOTAL_ABSOLUTE_DELTA_UNITS', 15)),
];

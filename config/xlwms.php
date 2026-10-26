<?php

/*
|--------------------------------------------------------------------------
| XLWMS / Lingxing OMS — P0-P2 read-only configuration
|--------------------------------------------------------------------------
| Secrets are provided by server-side environment variables only.
| No shipment creation, warehouse stock mutation, or Woo sync is enabled.
| All values are opt-in and fail closed when absent.
*/

return [
    'enabled' => filter_var(env('XLWMS_ENABLED', false), FILTER_VALIDATE_BOOL),
    'base_url' => env('XLWMS_BASE_URL', 'https://api.xlwms.com/openapi/v1'),
    'app_key' => env('XLWMS_APP_KEY', ''),
    'app_secret' => env('XLWMS_APP_SECRET', ''),
    'timeout_seconds' => max(2, min(30, (int) env('XLWMS_TIMEOUT_SECONDS', 15))),
    // Optional trusted CA bundle. TLS verification stays enabled.
    'ca_bundle' => (string) env('XLWMS_CA_BUNDLE', ''),
    // Keys: ERP warehouse code -> XLWMS warehouse code.
    'warehouse_map' => json_decode((string) env('XLWMS_WAREHOUSE_MAP_JSON', '{}'), true) ?: [],
    // Optional exceptions only: ERP warehouse code -> { differing ERP SKU -> OMS SKU }.
    // Missing override means use the original SKU unchanged; no full SKU mapping needed.
    'sku_map' => json_decode((string) env('XLWMS_SKU_MAP_JSON', '{}'), true) ?: [],
    // Hard-off in P0-P2; a separate approved package is required for writes.
    'auto_dispatch_enabled' => false,
    // P1-P3: deliberate single-order send; off until explicit Owner live-order authorization.
    'live_dispatch_enabled' => filter_var(env('XLWMS_LIVE_DISPATCH_ENABLED', false), FILTER_VALIDATE_BOOL),
    // Explicit one-order Owner allowlist; no other order may dispatch even with the switch ON.
    'live_order_id' => (int) env('XLWMS_LIVE_ORDER_ID', 0),
    // Separate opt-in for privileged one-shot read-only credential verification.
    // Entered keys are never stored or used for outbound-order creation.
    'manual_probe_enabled' => filter_var(env('XLWMS_MANUAL_PROBE_ENABLED', false), FILTER_VALIDATE_BOOL),
    // Local-only, one-shot T2 draft exercise. Never available in Production.
    't2_test_draft_enabled' => filter_var(env('XLWMS_T2_TEST_DRAFT_ENABLED', false), FILTER_VALIDATE_BOOL),
    'inventory_sync_schedule_enabled' => filter_var(env('XLWMS_INVENTORY_SYNC_SCHEDULE_ENABLED', false), FILTER_VALIDATE_BOOL),
    'inventory_sync_schedule_minutes' => in_array((int) env('XLWMS_INVENTORY_SYNC_SCHEDULE_MINUTES', 30), [10, 30], true) ? (int) env('XLWMS_INVENTORY_SYNC_SCHEDULE_MINUTES', 30) : 30,
    'inventory_apply_enabled' => filter_var(env('XLWMS_INVENTORY_APPLY_ENABLED', false), FILTER_VALIDATE_BOOL),
    'lot_reconciliation_v2_enabled' => filter_var(env('XLWMS_LOT_RECONCILIATION_V2_ENABLED', false), FILTER_VALIDATE_BOOL),
];

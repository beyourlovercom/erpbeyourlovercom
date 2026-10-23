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
    // Keys: ERP warehouse code -> XLWMS warehouse code.
    'warehouse_map' => json_decode((string) env('XLWMS_WAREHOUSE_MAP_JSON', '{}'), true) ?: [],
    // Optional exceptions only: ERP warehouse code -> { differing ERP SKU -> OMS SKU }.
    // Missing override means use the original SKU unchanged; no full SKU mapping needed.
    'sku_map' => json_decode((string) env('XLWMS_SKU_MAP_JSON', '{}'), true) ?: [],
    // Hard-off in P0-P2; a separate approved package is required for writes.
    'auto_dispatch_enabled' => false,
    // Separate opt-in for privileged one-shot read-only credential verification.
    // Entered keys are never stored or used for outbound-order creation.
    'manual_probe_enabled' => filter_var(env('XLWMS_MANUAL_PROBE_ENABLED', false), FILTER_VALIDATE_BOOL),
];

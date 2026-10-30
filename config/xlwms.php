<?php

/*
|--------------------------------------------------------------------------
| XLWMS OMS — guarded read / single-order / warehouse batch integration
|--------------------------------------------------------------------------
| Credentials remain server-side encrypted or in authorized runtime.
| Provider order creation requires separately enabled production gates.
| Defaults are OFF. No implicit Woo completion or stock mutations.
*/

return [
    'enabled' => filter_var(env('XLWMS_ENABLED', false), FILTER_VALIDATE_BOOL),
    'base_url' => env('XLWMS_BASE_URL', 'https://api.xlwms.com/openapi/v1'),
    'oms_web_base_url' => env('XLWMS_OMS_WEB_BASE_URL', 'https://oms.xlwms.com/gateway/woms'),
    'oms_web_token' => (string) env('XLWMS_OMS_WEB_TOKEN', ''),
    'oms_web_hmac_key' => (string) env('XLWMS_OMS_WEB_HMAC_KEY', ''),
    'oms_web_timeout_seconds' => max(2, min(30, (int) env('XLWMS_OMS_WEB_TIMEOUT_SECONDS', 15))),
    'oms_web_enabled' => filter_var(env('XLWMS_OMS_WEB_ENABLED', false), FILTER_VALIDATE_BOOL),
    'timeout_seconds' => max(2, min(30, (int) env('XLWMS_TIMEOUT_SECONDS', 15))),
    // Optional trusted CA bundle. TLS verification stays enabled.
    'ca_bundle' => (string) env('XLWMS_CA_BUNDLE', ''),
    // No unattended dispatch or schedule without a separate approval.
    'auto_dispatch_enabled' => false,
    // Production fail-closed: provider auto-submits outboundOrder/create regardless
    // of isSubmit=0; only OMS UI Save Draft / official XLS import is safe.
    'us_sm_outbound_entry_mode' => 'oms_manual_draft',

    // P1-P3: deliberate single-order send; off until explicit Owner live-order authorization.
    'live_dispatch_enabled' => filter_var(env('XLWMS_LIVE_DISPATCH_ENABLED', false), FILTER_VALIDATE_BOOL),
    // Separate warehouse-batch gate. Off unless explicitly enabled together
    // with the existing live dispatch gate and all per-order preflight gates.
    'warehouse_batch_dispatch_enabled' => filter_var(env('XLWMS_WAREHOUSE_BATCH_DISPATCH_ENABLED', false), FILTER_VALIDATE_BOOL),
    // Explicit one-order Owner allowlist; no other order may dispatch even with the switch ON.
    'live_order_id' => (int) env('XLWMS_LIVE_ORDER_ID', 0),
    // Owner 2026-10-29: all NEW US-SM outbound drafts use the provider's exact
    // type-2 special channel. No bought/shipping label, no cheapest/USPS fallback.
    // This does NOT assert that a carrier or Tracking exists.
    'us_sm_require_no_shipping_service' => true,
    'us_sm_oms_logistics_channel' => 'No_Shipping_Service',
    'us_sm_create_draft_only' => true,
    // R8 R1-R4 independent reissue: enabled ONLY for one approved order and one
    // UUID request. Defaults OFF; never reuses ordinary M1 dispatch allowlist.
    'reissue_create_enabled' => filter_var(env('XLWMS_REISSUE_CREATE_ENABLED', false), FILTER_VALIDATE_BOOL),
    'reissue_order_id' => (int) env('XLWMS_REISSUE_ORDER_ID', 0),
    'reissue_request_id' => (string) env('XLWMS_REISSUE_REQUEST_ID', ''),
    // Existing inventory-only release controls remain authoritative and fail closed.
    'inventory_sync_schedule_enabled' => filter_var(env('XLWMS_INVENTORY_SYNC_SCHEDULE_ENABLED', false), FILTER_VALIDATE_BOOL),
    'inventory_sync_schedule_minutes' => in_array((int) env('XLWMS_INVENTORY_SYNC_SCHEDULE_MINUTES', 30), [10, 30], true)
        ? (int) env('XLWMS_INVENTORY_SYNC_SCHEDULE_MINUTES', 30) : 30,
    'inventory_apply_enabled' => filter_var(env('XLWMS_INVENTORY_APPLY_ENABLED', false), FILTER_VALIDATE_BOOL),
    'lot_reconciliation_v2_enabled' => filter_var(env('XLWMS_LOT_RECONCILIATION_V2_ENABLED', false), FILTER_VALIDATE_BOOL),
    'inventory_first_apply_approved_checksum' => (string) env('XLWMS_INVENTORY_FIRST_APPLY_APPROVED_CHECKSUM', ''),
    'inventory_auto_max_sku_delta_units' => max(0, (int) env('XLWMS_INVENTORY_AUTO_MAX_SKU_DELTA_UNITS', 5)),
    'inventory_auto_max_total_absolute_delta_units' => max(0, (int) env('XLWMS_INVENTORY_AUTO_MAX_TOTAL_ABSOLUTE_DELTA_UNITS', 15)),
];

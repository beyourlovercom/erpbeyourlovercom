<?php

return [
    // R2-R5 isolated bridge: deny all network calls unless explicitly enabled in sandbox.
    // Production must always keep dispatch OFF until a separate Owner release.
    'sandbox_read_enabled' => filter_var(env('SHIPOUT_SANDBOX_READ_ENABLED', false), FILTER_VALIDATE_BOOL),
    'sandbox_dispatch_enabled' => filter_var(env('SHIPOUT_SANDBOX_DISPATCH_ENABLED', false), FILTER_VALIDATE_BOOL),
    'sandbox_tracking_apply_enabled' => filter_var(env('SHIPOUT_SANDBOX_TRACKING_APPLY_ENABLED', false), FILTER_VALIDATE_BOOL),
    // Sandbox credentials must be injected at the authorized Dev runtime.
    // No token or key material is stored in GitHub or hardcoded here.
    'sandbox_app_key' => (string) env('SHIPOUT_SANDBOX_APP_KEY', ''),
    'sandbox_app_secret' => (string) env('SHIPOUT_SANDBOX_APP_SECRET', ''),
    'sandbox_bearer' => (string) env('SHIPOUT_SANDBOX_BEARER', ''),
    // Required explicit ONE order and ONE sandbox warehouse. Empty blocks all dispatch.
    'sandbox_order_allowlist' => (string) env('SHIPOUT_SANDBOX_ORDER_ALLOWLIST', ''),
    'sandbox_warehouse_id' => (string) env('SHIPOUT_SANDBOX_WAREHOUSE_ID', ''),
    // Operator-selected configured channel (sysServiceId=10): explicit
    // trusted warehouse service-group ID. Never infer IDs from carrier names.
    // Fail closed on actual dispatch unless SKU & OMS available-stock match.
    // Legacy isolated fixtures may explicitly turn this check off.
    'sandbox_inventory_preflight_enabled' => filter_var(env('SHIPOUT_SANDBOX_INVENTORY_PREFLIGHT_ENABLED', true), FILTER_VALIDATE_BOOL),
    'sandbox_client_org_id' => (string) env('SHIPOUT_SANDBOX_CLIENT_ORG_ID', ''),
    // ShipOut query API returns 'YYYY-MM-DD HH:mm:ss' with NO offset.
    // Must be independently confirmed with provider BEFORE live ERP apply.
    // Default blank: fail closed; never assume UTC or Asia/Shanghai.
    'sandbox_shipped_time_timezone' => (string) env('SHIPOUT_SANDBOX_SHIPPED_TIME_TIMEZONE', ''),

    'sandbox_enabled_modes' => (string) env('SHIPOUT_SANDBOX_ENABLED_MODES', '9'),
    'sandbox_service_group_id' => (string) env('SHIPOUT_SANDBOX_SERVICE_GROUP_ID', ''),
    'sandbox_service_group_label' => (string) env('SHIPOUT_SANDBOX_SERVICE_GROUP_LABEL', ''),

    // R9 independent production credential namespace; never loaded from sandbox.
    // All production operations default OFF, including read, dispatch and apply.
    'production_read_enabled' => filter_var(env('SHIPOUT_PRODUCTION_READ_ENABLED', false), FILTER_VALIDATE_BOOL),
    'production_dispatch_enabled' => filter_var(env('SHIPOUT_PRODUCTION_DISPATCH_ENABLED', false), FILTER_VALIDATE_BOOL),
    'production_tracking_apply_enabled' => filter_var(env('SHIPOUT_PRODUCTION_TRACKING_APPLY_ENABLED', false), FILTER_VALIDATE_BOOL),
    'production_app_key' => (string) env('SHIPOUT_PRODUCTION_APP_KEY', ''),
    'production_app_secret' => (string) env('SHIPOUT_PRODUCTION_APP_SECRET', ''),
    'production_bearer' => (string) env('SHIPOUT_PRODUCTION_BEARER', ''),
    'production_warehouse_id' => (string) env('SHIPOUT_PRODUCTION_WAREHOUSE_ID', ''),
    'production_client_org_id' => (string) env('SHIPOUT_PRODUCTION_CLIENT_ORG_ID', ''),
    // Official ShipOut OpenAPI standard: time-type fields are UTC.
    // Owner requests Asia/Shanghai only as a China-operator DISPLAY preview.
    // Still require real shippedTime sample verification before ERP tracking apply.
    'production_shipped_time_source_timezone' => (string) env('SHIPOUT_PRODUCTION_SHIPPED_TIME_SOURCE_TIMEZONE', 'UTC'),
    'production_shipped_time_display_timezone_preview' => (string) env('SHIPOUT_PRODUCTION_SHIPPED_TIME_DISPLAY_TIMEZONE_PREVIEW', 'Asia/Shanghai'),
    'production_shipped_time_timezone_verified' => filter_var(env('SHIPOUT_PRODUCTION_SHIPPED_TIME_TIMEZONE_VERIFIED', false), FILTER_VALIDATE_BOOL),

];
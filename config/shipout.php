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
    // R10-B single-order Production Canary. ALL OFF until a separate exact-order Owner PASS.
    // No outbound transport or route is activated by these settings.
    // Separate manual operator surface gate, OFF for every normal deploy.
    // ShipOut Production V1 standard Dede-approved flow. All three OFF
    // by default, independent from the frozen R10 single-order Canary.
    'production_v1_read_enabled' => filter_var(env('SHIPOUT_PRODUCTION_V1_READ_ENABLED', false), FILTER_VALIDATE_BOOL),
    'production_v1_dispatch_enabled' => filter_var(env('SHIPOUT_PRODUCTION_V1_DISPATCH_ENABLED', false), FILTER_VALIDATE_BOOL),
    'production_v1_owner_approved' => filter_var(env('SHIPOUT_PRODUCTION_V1_OWNER_APPROVED', false), FILTER_VALIDATE_BOOL),
    // Legacy single-operator setting retained for safe rollback only.
    // Three-account V1 sends use a separate exact-ID list; no global grants.
    // New-order cutover ID is independently configured by the Owner.
    'production_v1_operator_user_id' => (int) env('SHIPOUT_PRODUCTION_V1_OPERATOR_USER_ID', 0),
    // Explicit allowlist of three approved Production V1 operators only.
    // Empty/malformed config denies dispatch; no global logistics grant.
    'production_v1_operator_user_ids' => (string) env('SHIPOUT_PRODUCTION_V1_OPERATOR_USER_IDS', ''),
    'production_v1_min_order_id' => (int) env('SHIPOUT_PRODUCTION_V1_MIN_ORDER_ID', 0),
    'production_operator_cli_enabled' => filter_var(env('SHIPOUT_PRODUCTION_OPERATOR_CLI_ENABLED', false), FILTER_VALIDATE_BOOL),
    'production_canary_execute_enabled' => filter_var(env('SHIPOUT_PRODUCTION_CANARY_EXECUTE_ENABLED', false), FILTER_VALIDATE_BOOL),
    'production_canary_owner_approved' => filter_var(env('SHIPOUT_PRODUCTION_CANARY_OWNER_APPROVED', false), FILTER_VALIDATE_BOOL),
    'production_canary_order_id' => (string) env('SHIPOUT_PRODUCTION_CANARY_ORDER_ID', ''),
    'production_canary_order_no' => (string) env('SHIPOUT_PRODUCTION_CANARY_ORDER_NO', ''),
    'production_canary_expires_at_utc' => (string) env('SHIPOUT_PRODUCTION_CANARY_EXPIRES_AT_UTC', ''),

];

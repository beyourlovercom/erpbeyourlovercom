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
];

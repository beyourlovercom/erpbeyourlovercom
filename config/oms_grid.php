<?php

return [
    // Production OFF by default; enables only the explicitly enumerated accounts.
    'production_enabled' => (bool) env('ERP_OMS_GRID_PRODUCTION_ENABLED', false),
    'production_user_ids' => env('ERP_OMS_GRID_CANARY_USER_IDS', ''),
    // Separate, stronger release gates; switching on the read-only grid does
    // not expose export or bulk customer-order creation.
    'production_export_enabled' => (bool) env('ERP_OMS_GRID_PRODUCTION_EXPORT_ENABLED', false),
    // P3-A: permit downloading template and no-write preflight only.
    'production_import_preview_enabled' => (bool) env('ERP_OMS_GRID_PRODUCTION_IMPORT_PREVIEW_ENABLED', false),
    // P3-B: independently authorizes real order creation.
    'production_import_enabled' => (bool) env('ERP_OMS_GRID_PRODUCTION_IMPORT_ENABLED', false),
];

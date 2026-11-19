<?php

return [
    // Owner approval is required before any real Dev write is allowed.
    // Production stays blocked in the controller regardless of this setting.
    'dev_apply_enabled' => (bool) env('ERP_PRODUCT_COST_IMPORT_DEV_APPLY_ENABLED', false),
];

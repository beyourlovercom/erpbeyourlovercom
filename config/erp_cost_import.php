<?php

return [
    // Both environments default to read-only. Production requires an independent
    // operator-controlled flag AND a real administrator role for every write.
    'dev_apply_enabled' => (bool) env('ERP_PRODUCT_COST_IMPORT_DEV_APPLY_ENABLED', false),
    'production_apply_enabled' => (bool) env('ERP_PRODUCT_COST_IMPORT_PRODUCTION_APPLY_ENABLED', false),
];

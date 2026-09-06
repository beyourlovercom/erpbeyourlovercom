<?php

return [
    'historical_import_commit_enabled' => filter_var(env('LOGISTICS_HISTORICAL_IMPORT_COMMIT_ENABLED', false), FILTER_VALIDATE_BOOL),
    'historical_order_materialization_apply_enabled' => filter_var(env('LOGISTICS_HISTORICAL_ORDER_MATERIALIZATION_APPLY_ENABLED', false), FILTER_VALIDATE_BOOL),
];

<?php

return [
    'continuous_delta' => [
        'enabled' => filter_var(env('ERP_ACCOUNTING_CONTINUOUS_DELTA_ENABLED', false), FILTER_VALIDATE_BOOL),
    ],
    'financial_bootstrap' => [
        'production_apply_enabled' => (bool) env('ERP_ACCOUNTING_FINANCIAL_BOOTSTRAP_PRODUCTION_APPLY_ENABLED', false),
    ],
];

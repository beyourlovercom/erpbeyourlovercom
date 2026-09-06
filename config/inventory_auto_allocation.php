<?php

$allowlist = array_values(array_filter(array_map(
    static fn (string $country): string => strtoupper(trim($country)),
    explode(',', (string) env('INVENTORY_AUTO_ALLOCATION_COUNTRY_ALLOWLIST', 'DE')),
)));

return [
    'enabled' => filter_var(env('INVENTORY_AUTO_ALLOCATION_ENABLED', false), FILTER_VALIDATE_BOOL),
    'dry_run' => filter_var(env('INVENTORY_AUTO_ALLOCATION_DRY_RUN', true), FILTER_VALIDATE_BOOL),
    'scheduler_enabled' => filter_var(env('INVENTORY_AUTO_ALLOCATION_SCHEDULER_ENABLED', false), FILTER_VALIDATE_BOOL),
    'catch_up_limit' => max(1, (int) env('INVENTORY_AUTO_ALLOCATION_CATCH_UP_LIMIT', 100)),
    'country_allowlist' => $allowlist,
    'warehouse_by_country' => [
        'DE' => 'DE',
    ],
];

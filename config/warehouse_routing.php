<?php

/*
|--------------------------------------------------------------------------
| Warehouse Routing Policy — Single Source of Truth
|--------------------------------------------------------------------------
| Business routing, fallback and warehouse priority rules belong here.
| Do not hard-code warehouse routing policy inside recommendation or
| automatic-allocation services. Runtime safety switches live separately.
*/

return [
    'version' => '2026-09-07-v1',

    'global' => [
        'require_active_warehouse' => true,
        'require_full_snapshot' => true,
        'require_fulfillment_ready' => true,
        'allow_split_shipment' => false,
        'allow_cross_region_fallback' => false,
    ],

    'countries' => [
        'DE' => [
            'automatic' => true,
            'strategy' => 'fixed',
            'warehouses' => [
                ['code' => 'DE', 'priority' => 1, 'role' => 'primary'],
            ],
        ],

        'US' => [
            'automatic' => false,
            'strategy' => 'discovery',
            'warehouses' => [
                ['code' => 'US-LA', 'role' => 'west_current'],
                ['code' => 'US-NY', 'role' => 'east_current'],
                ['code' => 'SMGJ', 'role' => 'west_in_transit'],
            ],
            'rules' => [],
        ],
    ],
];

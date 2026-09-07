<?php

return [
    // Carrier routing business policy single source of truth.
    // Do not hard-code carrier selection policy in Shipment services.
    'version' => '2026-09-07-v2',

    'countries' => [
        'US' => [
            'automatic' => true,
            'strategy' => 'fixed_by_warehouse',
            'warehouses' => [
                'US-LA' => ['carrier' => 'USPS', 'service' => null],
                'US-NY' => ['carrier' => 'USPS', 'service' => null],
                'US-SJ' => ['carrier' => 'USPS', 'service' => null],
            ],
            'manual_overrides' => ['DHL'],
            'auto_rank_carriers' => false,
            'compare_price' => false,
            'compare_transit_time' => false,
        ],
    ],
];

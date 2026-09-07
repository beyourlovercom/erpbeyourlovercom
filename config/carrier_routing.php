<?php

$deFulfillmentCountries = ['DE', 'CZ', 'PL', 'SK', 'HU', 'SI', 'AT', 'NL', 'BE', 'FR', 'ES', 'IT', 'PT', 'GR', 'EE'];

$countries = [
    'CA' => [
        'automatic' => true,
        'strategy' => 'fixed_by_warehouse',
        'warehouses' => [
            'CA' => ['carrier' => 'Canada Post', 'service' => null],
        ],
        'manual_overrides' => [],
        'auto_rank_carriers' => false,
        'compare_price' => false,
        'compare_transit_time' => false,
    ],

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
];

foreach ($deFulfillmentCountries as $country) {
    $countries[$country] = [
        'automatic' => true,
        'strategy' => 'fixed_by_warehouse',
        'warehouses' => [
            'DE' => ['carrier' => 'DHL', 'service' => null],
        ],
        'manual_overrides' => [],
        'auto_rank_carriers' => false,
        'compare_price' => false,
        'compare_transit_time' => false,
    ];
}

return [
    // Carrier routing business policy single source of truth.
    // Do not hard-code carrier selection policy in Shipment services.
    'version' => '2026-09-07-v4',
    'countries' => $countries,
];

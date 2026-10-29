<?php

return [
    // Keep new behavior explicitly disabled until Dev AfterShip and Production Canary pass.
    'immediate_enabled' => env('TRACKING_SYNC_V2_IMMEDIATE_ENABLED', false),
    'periodic_enabled' => env('TRACKING_SYNC_V2_PERIODIC_ENABLED', false),
    // Exclusive outbox ID snapshot at controlled Production activation.
    // Prevents a new cron from draining legacy pending Tracking records.
    'min_outbox_id' => env('TRACKING_SYNC_V2_MIN_OUTBOX_ID'),
    // New shipments only: zero or a frozen max(shipment.id) at activation.
    'min_shipment_id' => env('TRACKING_SYNC_V2_MIN_SHIPMENT_ID'),
    'max_batch' => 50,
];

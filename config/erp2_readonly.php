<?php

return [
    // ERP2 projections are opt-in and cannot be enabled outside local/testing.
    'enabled' => env('ERP2_READONLY_PROJECTIONS_ENABLED', false),
];

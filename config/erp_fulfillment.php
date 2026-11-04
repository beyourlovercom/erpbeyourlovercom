<?php

return [
    // Fixed migration boundary. Never use today() here: yesterday's queued orders must not disappear tomorrow.
    'allocation_eligible_from' => env('ERP_ALLOCATION_ELIGIBLE_FROM', '2026-11-04'),
];

<?php

return [
    // R2-R5 isolated bridge: deny all network calls unless explicitly enabled in sandbox.
    // Never set this flag in Production; no write/dispatch methods are present.
    'sandbox_read_enabled' => filter_var(env('SHIPOUT_SANDBOX_READ_ENABLED', false), FILTER_VALIDATE_BOOL),
];

<?php

return [
    'historical_import_commit_enabled' => filter_var(env('LOGISTICS_HISTORICAL_IMPORT_COMMIT_ENABLED', false), FILTER_VALIDATE_BOOL),
];

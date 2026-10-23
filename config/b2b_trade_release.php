<?php

return [
    /*
     * Controlled release of the independent B2B Trade Order.
     *
     * These flags default OFF in all non-test environments, even when code is
     * deployed. Enable trade and Finance independently after Owner acceptance.
     * The dedicated S4 Dev-only checkout is intentionally not altered.
     */
    'trade_writes_enabled' => filter_var(env('B2B_TRADE_WRITES_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'finance_cash_writes_enabled' => filter_var(env('B2B_FINANCE_CASH_WRITES_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
];

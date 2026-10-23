<?php

namespace App\Modules\B2B\Sales;

final class B2BTradeReleaseGuard
{
    public static function assertTradeWriteAllowed(): void
    {
        if (! app()->environment('testing')
            && ! config('b2b_trade_release.trade_writes_enabled', false)) {
            abort(503, 'B2B Trade Order writes are disabled until approved rollout.');
        }
    }

    public static function assertFinanceCashWriteAllowed(): void
    {
        if (! app()->environment('testing')
            && ! config('b2b_trade_release.finance_cash_writes_enabled', false)) {
            abort(503, 'B2B Trade Finance cash writes are disabled until Finance approval.');
        }
    }
}

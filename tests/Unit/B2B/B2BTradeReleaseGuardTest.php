<?php

namespace Tests\Unit\B2B;

use App\Modules\B2B\Sales\B2BTradeReleaseGuard;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

final class B2BTradeReleaseGuardTest extends TestCase
{
    private function withProduction(callable $fn): void
    {
        $before = app()->environment();
        app()->instance('env', 'production');
        try {
            $fn();
        } finally {
            app()->instance('env', $before);
        }
    }

    public function test_production_defaults_both_write_paths_to_disabled(): void
    {
        $this->withProduction(function (): void {
            config()->set('b2b_trade_release.trade_writes_enabled', false);
            config()->set('b2b_trade_release.finance_cash_writes_enabled', false);
            foreach ([
                static fn () => B2BTradeReleaseGuard::assertTradeWriteAllowed(),
                static fn () => B2BTradeReleaseGuard::assertFinanceCashWriteAllowed(),
            ] as $blocked) {
                try {
                    $blocked();
                    self::fail('Unapproved production write was not blocked.');
                } catch (HttpExceptionInterface $e) {
                    self::assertSame(503, $e->getStatusCode());
                }
            }
        });
    }

    public function test_trade_can_be_enabled_without_enabling_cash_writes(): void
    {
        $this->withProduction(function (): void {
            config()->set('b2b_trade_release.trade_writes_enabled', true);
            config()->set('b2b_trade_release.finance_cash_writes_enabled', false);
            B2BTradeReleaseGuard::assertTradeWriteAllowed();
            try {
                B2BTradeReleaseGuard::assertFinanceCashWriteAllowed();
                self::fail('Finance write was unexpectedly enabled by Trade flag.');
            } catch (HttpExceptionInterface $e) {
                self::assertSame(503, $e->getStatusCode());
            }
        });
    }

    public function test_cash_can_be_enabled_only_by_explicit_separate_flag(): void
    {
        $this->withProduction(function (): void {
            config()->set('b2b_trade_release.trade_writes_enabled', false);
            config()->set('b2b_trade_release.finance_cash_writes_enabled', true);
            B2BTradeReleaseGuard::assertFinanceCashWriteAllowed();
            try {
                B2BTradeReleaseGuard::assertTradeWriteAllowed();
                self::fail('Trade write was unexpectedly enabled by Finance flag.');
            } catch (HttpExceptionInterface $e) {
                self::assertSame(503, $e->getStatusCode());
            }
        });
    }
}

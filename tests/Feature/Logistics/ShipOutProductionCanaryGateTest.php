<?php

namespace Tests\Feature\Logistics;

use App\Modules\Logistics\Application\ShipOut\ShipOutProductionCanaryGate;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class ShipOutProductionCanaryGateTest extends TestCase
{
    private const WAREHOUSE = '1265864414750314497';

    private const ORDER_NO = 'C99999-007';

    private const HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Http::preventStrayRequests();
        config()->set([
            'shipout.production_read_enabled' => false,
            'shipout.production_dispatch_enabled' => false,
            'shipout.production_tracking_apply_enabled' => false,
            'shipout.production_canary_execute_enabled' => false,
            'shipout.production_canary_owner_approved' => false,
            'shipout.production_canary_order_id' => '',
            'shipout.production_canary_order_no' => '',
            'shipout.production_canary_expires_at_utc' => '',
            'shipout.production_warehouse_id' => '',
            'shipout.production_client_org_id' => '',
            'shipout.production_app_key' => '',
            'shipout.production_app_secret' => '',
            'shipout.production_bearer' => '',
        ]);
    }

    private function enableSynthetic(): void
    {
        config()->set([
            'shipout.production_read_enabled' => true,
            'shipout.production_dispatch_enabled' => true,
            'shipout.production_canary_execute_enabled' => true,
            'shipout.production_canary_owner_approved' => true,
            'shipout.production_warehouse_id' => self::WAREHOUSE,
            'shipout.production_client_org_id' => '57893',
            'shipout.production_canary_order_id' => '999',
            'shipout.production_canary_order_no' => self::ORDER_NO,
            'shipout.production_canary_expires_at_utc' => gmdate('Y-m-d\TH:i:s\Z', time() + 1200),
            'shipout.production_app_key' => 'synthetic-test-key',
            'shipout.production_app_secret' => 'synthetic-test-secret',
            'shipout.production_bearer' => 'synthetic-test-bearer',
        ]);
    }

    private function withSimulatedProduction(callable $run): void
    {
        $prior = $this->app['env'];
        $this->app->instance('env', 'production');
        try {
            $run();
        } finally {
            $this->app->instance('env', $prior);
            Http::assertNothingSent();
        }
    }

    private function verify(
        int $order = 999,
        string $reference = self::ORDER_NO,
        string $warehouse = self::WAREHOUSE,
        string $expected = self::HASH,
        string $actual = self::HASH,
        string $confirmation = 'SUBMIT_SHIPOUT_ORDER_999',
    ): void {
        app(ShipOutProductionCanaryGate::class)
            ->assertExecutionAuthorized($order, $reference, $warehouse, $expected, $actual, $confirmation);
    }

    public function test_default_off_never_authorizes_even_simulated_production(): void
    {
        $this->withSimulatedProduction(function (): void {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('SHIPOUT_R10_CANARY_DISABLED');
            $this->verify();
        });
    }

    public function test_testing_environment_never_authorizes_with_all_flags_on(): void
    {
        $this->enableSynthetic();
        $this->expectExceptionMessage('SHIPOUT_R10_PRODUCTION_ONLY');
        try {
            $this->verify();
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_correct_synthetic_approval_only_passes_pure_gate_and_does_not_post(): void
    {
        $this->enableSynthetic();
        $this->withSimulatedProduction(function (): void {
            $this->verify();
            $this->assertFalse((bool) config('shipout.production_tracking_apply_enabled'));
        });
    }

    public function test_wrong_warehouse_organization_or_order_stays_blocked(): void
    {
        $this->enableSynthetic();
        $this->withSimulatedProduction(function (): void {
            $cases = [
                fn () => $this->verify(1000),
                fn () => $this->verify(999, 'C1234-007'),
                fn () => $this->verify(999, self::ORDER_NO, 'unapproved-warehouse'),
                function (): void {
                    config()->set('shipout.production_client_org_id', 'wrong-organization');
                    try {
                        $this->verify();
                    } finally {
                        config()->set('shipout.production_client_org_id', '57893');
                    }
                },
            ];
            foreach ($cases as $call) {
                try {
                    $call();
                    $this->fail('Unapproved identity must fail closed');
                } catch (RuntimeException $e) {
                    $this->assertContains($e->getMessage(), [
                        'SHIPOUT_R10_ORDER_NOT_ALLOWLISTED', 'SHIPOUT_R10_IDENTITY_DENIED',
                    ]);
                }
            }
        });
    }

    public function test_invalid_hash_or_typed_confirmation_stays_blocked(): void
    {
        $this->enableSynthetic();
        $this->withSimulatedProduction(function (): void {
            foreach ([
                [str_repeat('x', 64), self::HASH, 'SUBMIT_SHIPOUT_ORDER_999'],
                [self::HASH, str_repeat('b', 64), 'SUBMIT_SHIPOUT_ORDER_999'],
                [self::HASH, self::HASH, 'SUBMIT_SHIPOUT_ORDER_1000'],
            ] as [$expected, $actual, $confirmation]) {
                $this->expectExceptionOrAssertion($expected, $actual, $confirmation);
            }
        });
    }

    private function expectExceptionOrAssertion(string $expected, string $actual, string $confirmation): void
    {
        try {
            $this->verify(999, self::ORDER_NO, self::WAREHOUSE, $expected, $actual, $confirmation);
            $this->fail('Invalid preflight or confirmation must fail');
        } catch (RuntimeException $e) {
            $this->assertContains($e->getMessage(), [
                'SHIPOUT_R10_PREFLIGHT_STALE', 'SHIPOUT_R10_CONFIRMATION_MISMATCH',
            ]);
        }
    }

    public function test_expired_or_overlong_approval_window_blocks(): void
    {
        $this->enableSynthetic();
        $this->withSimulatedProduction(function (): void {
            foreach ([-1, 7200] as $seconds) {
                config()->set('shipout.production_canary_expires_at_utc',
                    gmdate('Y-m-d\TH:i:s\Z', time() + $seconds));
                try {
                    $this->verify();
                    $this->fail('Expired or far-future approval must fail');
                } catch (RuntimeException $e) {
                    $this->assertSame('SHIPOUT_R10_APPROVAL_EXPIRED_OR_INVALID', $e->getMessage());
                }
            }
        });
    }

    public function test_missing_credentials_block_even_if_all_canary_flags_are_on(): void
    {
        $this->enableSynthetic();
        config()->set('shipout.production_bearer', '');
        $this->withSimulatedProduction(function (): void {
            $this->expectExceptionMessage('SHIPOUT_R10_CREDENTIALS_MISSING');
            $this->verify();
        });
    }
}

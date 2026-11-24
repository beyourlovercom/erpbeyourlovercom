<?php

namespace Tests\Feature\Logistics;

use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseOpenApiConnection;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsOrderGateway;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class DeTianyunLiveOutboundGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config()->set('xlwms.de_order_batch_read_enabled', true);
        config()->set('xlwms.de_order_batch_live_enabled', true);
        config()->set('xlwms.de_order_batch_live_order_ids', [321]);
        config()->set('xlwms.de_verified_channel_codes', ['DE_NATIVE_1']);
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        return [
            'whCode' => 'Hannover',
            'thirdOrderNo' => 'BYL-ERP-321', 'platformOrderNo' => '007-ORDER-321',
            'referOrderNo' => '007-ORDER-321',
            'isSubmit' => 1, 'logisticsChannel' => 'DE_NATIVE_1',
            'productList' => [['sku' => 'DE-OWNED', 'quantity' => 1]],
        ];
    }

    private function connection(): WarehouseOpenApiConnection
    {
        return new WarehouseOpenApiConnection([
            'provider' => 'xlwms', 'oms_warehouse_code' => 'Hannover',
            'app_key_encrypted' => 'TEST-ONLY-APP-KEY',
            'app_secret_encrypted' => 'TEST-ONLY-APP-SECRET',
        ]);
    }

    public function test_exact_production_hannover_allowlist_can_reach_the_real_provider_create_method(): void
    {
        $old = $this->app['env'];
        $this->app->instance('env', 'production');
        try {
            Http::fake([
                'https://api.xlwms.com/openapi/v1/outboundOrder/create*' => Http::response([
                    'code' => 200, 'data' => [[
                        'thirdOrderNo' => 'BYL-ERP-321',
                        'success' => true, 'orderNo' => 'DE-OMS-READBACK-321',
                    ]],
                ], 200),
            ]);
            $result = app(XlwmsOrderGateway::class)->createSingle($this->connection(), $this->payload());
            self::assertTrue($result['confirmed']);
            self::assertSame('DE-OMS-READBACK-321', $result['order_no']);
            Http::assertSentCount(1);
        } finally {
            $this->app->instance('env', $old);
        }
    }

    public function test_excluded_customer_sku_wrong_warehouse_and_missing_allowlist_cannot_reach_create(): void
    {
        $old = $this->app['env'];
        $this->app->instance('env', 'production');
        try {
            $mutations = [
                'EXCLUDED_SKU' => ['productList' => [['sku' => 'zuodian', 'quantity' => 1]]],
                'WRONG_WH' => ['whCode' => 'US-SM'],
                'WRONG_CHANNEL' => ['logisticsChannel' => 'FAKE_DHL'],
                'UNAPPROVED_ORDER' => ['thirdOrderNo' => 'BYL-ERP-322'],
            ];
            foreach ($mutations as $key => $change) {
                try {
                    app(XlwmsOrderGateway::class)->createSingle(
                        $this->connection(), array_replace($this->payload(), $change));
                    self::fail('Expected DE live gate: '.$key);
                } catch (RuntimeException $e) {
                    self::assertSame('XLWMS_OPENAPI_CREATE_BLOCKED_USE_OMS_DRAFT_IMPORT', $e->getMessage());
                }
            }
            Http::assertNothingSent();
        } finally {
            $this->app->instance('env', $old);
        }
    }

    public function test_global_switch_off_denies_production_create(): void
    {
        $old = $this->app['env'];
        $this->app->instance('env', 'production');
        config()->set('xlwms.de_order_batch_live_enabled', false);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('XLWMS_OPENAPI_CREATE_BLOCKED_USE_OMS_DRAFT_IMPORT');
            app(XlwmsOrderGateway::class)->createSingle($this->connection(), $this->payload());
        } finally {
            $this->app->instance('env', $old);
            Http::assertNothingSent();
        }
    }
}

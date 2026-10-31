<?php

namespace Tests\Feature\Logistics;

use App\Modules\Logistics\Infrastructure\ShipOut\ShipOutProductionReadOnlyGateway;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ShipOutProductionReadOnlyGatewayTest extends TestCase
{
    private mixed $oldEnv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->oldEnv = $this->app['env'];
        $this->app->instance('env', 'production');
        config()->set('shipout.production_read_enabled', false);
        config()->set('shipout.production_app_key', 'fake-app-key');
        config()->set('shipout.production_app_secret', 'fake-secret');
        config()->set('shipout.production_bearer', 'bearer fake-token');
        config()->set('shipout.production_warehouse_id', 'PROD-W-1');
        config()->set('shipout.production_client_org_id', 'PROD-ORG-1');
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->app->instance('env', $this->oldEnv);
        parent::tearDown();
    }

    private function gateway(): ShipOutProductionReadOnlyGateway
    {
        return new ShipOutProductionReadOnlyGateway;
    }

    public function test_read_disabled_by_default_even_with_credentials(): void
    {
        $this->expectExceptionMessage('SHIPOUT_PROD_READ_DISABLED');
        try {
            $this->gateway()->warehouses();
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_production_gateway_denies_nonproduction_environment(): void
    {
        config()->set('shipout.production_read_enabled', true);
        $this->app->instance('env', 'testing');
        $this->expectExceptionMessage('SHIPOUT_PROD_ENVIRONMENT_DENIED');
        try {
            $this->gateway()->warehouses();
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_credentials_missing_never_egress(): void
    {
        config()->set('shipout.production_read_enabled', true);
        config()->set('shipout.production_bearer', '');
        $this->expectExceptionMessage('SHIPOUT_PROD_CREDENTIAL_MISSING');
        try {
            $this->gateway()->warehouses();
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_warehouse_get_uses_fixed_production_host_signed_headers(): void
    {
        config()->set('shipout.production_read_enabled', true);
        Http::fake(['https://open.shipout.com/api/*' => Http::response([
            'result' => 'OK', 'data' => [
                ['warehouseId' => 'PROD-W-1', 'warehouseName' => 'West'],
            ],
        ], 200)]);
        $this->assertSame('PROD-W-1', $this->gateway()->warehouses()[0]['warehouseId']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === 'https://open.shipout.com/api/open-api/oms/info/warehouse/list'
            && $request->hasHeader('Authorization', 'bearer fake-token')
            && $request->hasHeader('appKey', 'fake-app-key')
            && $request->hasHeader('sign')
            && $request->hasHeader('version', '1.0.0'));
    }

    public function test_warehouse_duplicate_is_rejected(): void
    {
        config()->set('shipout.production_read_enabled', true);
        Http::fake(['https://open.shipout.com/api/*' => Http::response([
            'result' => 'OK', 'data' => [
                ['warehouseId' => 'A'], ['warehouseId' => 'A'],
            ],
        ], 200)]);
        $this->expectExceptionMessage('SHIPOUT_PROD_WAREHOUSE_UNTRUSTED');
        $this->gateway()->warehouses();
    }

    public function test_stock_reads_only_available_quantity_from_bound_organization_and_warehouse(): void
    {
        config()->set('shipout.production_read_enabled', true);
        Http::fake(['https://open.shipout.com/api/*' => Http::response([
            'result' => 'OK', 'data' => ['total' => 1, 'records' => [
                ['omsSku' => 'REAL-SKU', 'warehouseId' => 'PROD-W-1',
                    'availableQuantity' => 2, 'standardQuantity' => 99,
                    'recipientPhone' => 'DO_NOT_LEAK'],
            ]],
        ], 200)]);
        $stock = $this->gateway()->stock();
        $this->assertSame(2, $stock['records'][0]['availableQuantity']);
        $this->assertStringNotContainsString('DO_NOT_LEAK', json_encode($stock));
        $this->assertArrayNotHasKey('standardQuantity', $stock['records'][0]);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_contains($request->url(), 'clientOrgId=PROD-ORG-1')
            && str_contains($request->url(), 'warehouseId=PROD-W-1'));
    }

    public function test_stock_never_queries_without_exact_organization_binding(): void
    {
        config()->set('shipout.production_read_enabled', true);
        config()->set('shipout.production_client_org_id', '');
        $this->expectExceptionMessage('SHIPOUT_PROD_IDENTITY_UNBOUND');
        try {
            $this->gateway()->stock();
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_stock_from_other_warehouse_rejected(): void
    {
        config()->set('shipout.production_read_enabled', true);
        Http::fake(['https://open.shipout.com/api/*' => Http::response([
            'result' => 'OK', 'data' => ['total' => 1, 'records' => [
                ['omsSku' => 'SKU-A', 'warehouseId' => 'WRONG-W',
                    'availableQuantity' => 10],
            ]],
        ], 200)]);
        $this->expectExceptionMessage('SHIPOUT_PROD_STOCK_UNTRUSTED');
        $this->gateway()->stock();
    }

    public function test_incomplete_page_rejected(): void
    {
        config()->set('shipout.production_read_enabled', true);
        Http::fake(['https://open.shipout.com/api/*' => Http::response([
            'result' => 'OK', 'data' => ['total' => 5, 'records' => []],
        ], 200)]);
        $this->expectExceptionMessage('SHIPOUT_PROD_PAGE_INCOMPLETE');
        $this->gateway()->products();
    }

    public function test_provider_auth_errors_do_not_leak_response(): void
    {
        config()->set('shipout.production_read_enabled', true);
        Http::fake(['https://open.shipout.com/api/*' => Http::response(['message' => 'SECRET_FROM_PROVIDER'], 401)]);
        $this->expectExceptionMessage('SHIPOUT_PROD_HTTP_REJECTED');
        $this->gateway()->warehouses();
    }

    public function test_redirect_does_not_follow(): void
    {
        config()->set('shipout.production_read_enabled', true);
        Http::fake(['https://open.shipout.com/api/*' => Http::response('', 302, ['Location' => 'https://other.example.invalid'])]);
        $this->expectExceptionMessage('SHIPOUT_PROD_HTTP_REJECTED');
        $this->gateway()->warehouses();
    }

    public function test_official_utc_source_and_owner_shanghai_display_never_enable_tracking_apply(): void
    {
        $this->assertSame('UTC', config('shipout.production_shipped_time_source_timezone'));
        $this->assertSame('Asia/Shanghai', config('shipout.production_shipped_time_display_timezone_preview'));
        $this->assertFalse((bool) config('shipout.production_shipped_time_timezone_verified'));
        $this->assertFalse((bool) config('shipout.production_tracking_apply_enabled'));
        $local = new \DateTimeImmutable('2026-10-30 10:00:00', new \DateTimeZone('UTC'));
        $this->assertSame('2026-10-30 18:00:00', $local->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s'));
        Http::assertNothingSent();
    }
}

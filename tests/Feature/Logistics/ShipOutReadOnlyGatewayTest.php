<?php

namespace Tests\Feature\Logistics;

use App\Modules\Logistics\Infrastructure\ShipOut\ShipOutReadOnlyGateway;
use App\Modules\Logistics\Infrastructure\ShipOut\ShipOutSignature;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class ShipOutReadOnlyGatewayTest extends TestCase
{
    private function client(): ShipOutReadOnlyGateway
    {
        return new ShipOutReadOnlyGateway('sandbox-app', 'sandbox-secret', 'bearer sandbox-jwt');
    }

    public function test_off_by_default_even_with_credentials(): void
    {
        config()->set('shipout.sandbox_read_enabled', false);
        Http::fake();
        $this->expectExceptionMessage('SHIPOUT_SANDBOX_READ_DISABLED');
        $this->client()->warehouses();
    }

    public function test_official_md5_vector_matches(): void
    {
        $this->assertSame(
            'C9A285632306F49A2C9CBF7DCFE67231',
            ShipOutSignature::sign('/http/order/findById', '1639795057257', 'FC300595425B48C39FA3F56F3787D9CA')
        );
    }

    public function test_warehouse_read_signed_and_sandbox_only(): void
    {
        config()->set('shipout.sandbox_read_enabled', true);
        Http::fake(['https://opendev.shipout.com/api/*' =>
            Http::response(['result' => 'OK', 'data' => [['warehouseId' => '123', 'warehouseName' => 'sandbox']]], 200),
        ]);
        Http::preventStrayRequests();

        $this->assertCount(1, $this->client()->warehouses());
        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            return $request->method() === 'GET'
                && $request->url() === 'https://opendev.shipout.com/api/open-api/oms/info/warehouse/list'
                && $request->hasHeader('Authorization', 'bearer sandbox-jwt')
                && $request->hasHeader('version', '1.0.0')
                && $request->hasHeader('sign');
        });
    }

    public function test_stock_uses_available_not_total_and_enforces_warehouse_identity(): void
    {
        config()->set('shipout.sandbox_read_enabled', true);
        Http::fake(['https://opendev.shipout.com/api/*' =>
            Http::response(['result' => 'OK', 'data' => [
                'records' => [[
                    'warehouseId' => 'TEST-W', 'omsSku' => 'UAT-001',
                    'availableQuantity' => 3, 'standardQuantity' => 5,
                    'outboundingQuantity' => 2,
                ]],
                'total' => '1',
            ]], 200),
        ]);
        Http::preventStrayRequests();
        $page = $this->client()->stockPage('ORG-SANDBOX', 'TEST-W');
        $this->assertSame(3, $page['records'][0]['availableQuantity']);
        $this->assertSame(5, $page['records'][0]['standardQuantity']);
        $this->assertSame(1, $page['total']);
        Http::assertSent(fn ($request): bool =>
            str_contains($request->url(), 'clientOrgId=ORG-SANDBOX')
            && str_contains($request->url(), 'warehouseId=TEST-W'));
    }

    public function test_empty_stock_is_valid_but_missing_availability_is_rejected(): void
    {
        config()->set('shipout.sandbox_read_enabled', true);
        Http::fake(['https://opendev.shipout.com/api/*' =>
            Http::sequence()
                ->push(['result' => 'OK', 'data' => ['records' => [], 'total' => 0]], 200)
                ->push(['result' => 'OK', 'data' => ['records' => [
                    ['omsSku' => 'ABC', 'warehouseId' => 'W', 'standardQuantity' => 5],
                ], 'total' => 1]], 200),
        ]);
        $this->assertSame(['records' => [], 'total' => 0], $this->client()->stockPage('ORG', 'W'));
        $this->expectExceptionMessage('SHIPOUT_STOCK_ROW_UNTRUSTED');
        $this->client()->stockPage('ORG', 'W');
    }

    public function test_stock_page_with_positive_total_and_no_rows_is_not_trusted(): void
    {
        config()->set('shipout.sandbox_read_enabled', true);
        Http::fake(['https://opendev.shipout.com/api/*' =>
            Http::response(['result' => 'OK', 'data' => ['records' => [], 'total' => '5']], 200),
        ]);
        $this->expectExceptionMessage('SHIPOUT_STOCK_PAGE_INCOMPLETE');
        $this->client()->stockPage('ORG', 'W');
    }

    public function test_duplicate_provider_sku_rejects_page(): void
    {
        config()->set('shipout.sandbox_read_enabled', true);
        Http::fake(['https://opendev.shipout.com/api/*' =>
            Http::response(['result' => 'OK', 'data' => ['records' => [
                ['omsSku' => 'S1', 'warehouseId' => 'W', 'availableQuantity' => 2],
                ['omsSku' => 'S1', 'warehouseId' => 'W', 'availableQuantity' => 1],
            ], 'total' => 2]], 200),
        ]);
        $this->expectExceptionMessage('SHIPOUT_STOCK_DUPLICATE_SKU');
        $this->client()->stockPage('ORG', 'W');
    }

    public function test_mismatched_warehouse_stock_is_rejected(): void
    {
        config()->set('shipout.sandbox_read_enabled', true);
        Http::fake(['https://opendev.shipout.com/api/*' =>
            Http::response(['result' => 'OK', 'data' => ['records' => [
                ['omsSku' => 'ABC', 'warehouseId' => 'WRONG', 'availableQuantity' => 4],
            ], 'total' => 1]], 200),
        ]);
        $this->expectExceptionMessage('SHIPOUT_STOCK_ROW_UNTRUSTED');
        $this->client()->stockPage('ORG', 'W');
    }

    public function test_business_error_is_fail_closed_without_leaking_provider_message(): void
    {
        config()->set('shipout.sandbox_read_enabled', true);
        Http::fake(['https://opendev.shipout.com/api/*' =>
            Http::response(['result' => 'ERR', 'message' => 'provider sensitive detail'], 200),
        ]);
        $this->expectExceptionMessage('SHIPOUT_BUSINESS_REJECTED');
        $this->client()->warehouses();
    }

    public function test_authentication_error_fails_closed(): void
    {
        config()->set('shipout.sandbox_read_enabled', true);
        Http::fake(['https://opendev.shipout.com/api/*' =>
            Http::response(['message' => 'token invalid'], 401),
        ]);
        $this->expectExceptionMessage('SHIPOUT_HTTP_REJECTED');
        $this->client()->warehouses();
    }
}

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

    public function test_order_page_projects_only_tracking_and_status(): void
    {
        config()->set('shipout.sandbox_read_enabled', true);
        Http::fake(['https://opendev.shipout.com/api/*' =>
            Http::response(['result' => 'OK', 'data' => [
                'records' => [[
                    'orderNO' => 'UAT-1', 'orderId' => '123', 'status' => 3,
                    'recipientName' => 'NEVER LEAK',
                    'shipmentList' => [[
                        'warehouseId' => 'W', 'shipmentId' => 'S1',
                        'outboundStatus' => 3, 'labelStatus' => 2,
                        'trackingNumber' => 'USPS-TEST-ONLY', 'carrierId' => 1,
                        'recipientPhone' => 'NEVER LEAK',
                    ]],
                ]],
                'total' => 1,
            ]], 200),
        ]);
        Http::preventStrayRequests();
        $page = $this->client()->orderPage('W', 'UAT-1');
        $this->assertSame('USPS-TEST-ONLY', $page['records'][0]['shipments'][0]['trackingNumber']);
        $this->assertSame(3, $page['records'][0]['shipments'][0]['outboundStatus']);
        $this->assertStringNotContainsString('NEVER LEAK', json_encode($page));
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/open-api/oms/order/queryListV2?')
            && str_contains($request->url(), 'version=v2'));
        Http::assertSentCount(1);
    }

    public function test_order_detail_reads_official_shipment_list_and_excludes_address(): void
    {
        config()->set('shipout.sandbox_read_enabled', true);
        Http::fake(['https://opendev.shipout.com/api/*' =>
            Http::response(['result' => 'OK', 'data' => [
                'orderId' => '123', 'orderNO' => 'C99999-007', 'status' => 4,
                'toAddress' => ['name' => 'PRIVATE RECIPIENT'],
                'orderShipments' => [[
                    'warehouseId' => 'TEST-W', 'shipmentId' => 'SHIP-1',
                    'outboundStatus' => 4, 'carrierId' => 1,
                    'trackingNumber' => '9400TESTING', 'shippedTime' => '2026-10-29 10:00:00',
                ]],
            ]], 200),
        ]);
        $detail = $this->client()->orderDetail('TEST-W', '123');
        $this->assertSame('9400TESTING', $detail['shipments'][0]['trackingNumber']);
        $this->assertSame(4, $detail['shipments'][0]['outboundStatus']);
        $this->assertStringNotContainsString('PRIVATE RECIPIENT', json_encode($detail));
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_contains($request->url(), '/open-api/oms/order/query?'));
    }

    public function test_cancelled_order_with_missing_warehouse_projects_balance_error_safely(): void
    {
        config()->set('shipout.sandbox_read_enabled', true);
        Http::fake(function ($request) {
            $response = [
                'result' => 'OK',
                'data' => [
                    'orderId' => 'TEST-ORDER-ID',
                    'orderNO' => 'TEST-ORDER-NO',
                    'status' => 6,
                    'cancelReason' => 'Account Balance or Credit insufficient, Please add balance before proceed TRACESECRET',
                    'shipmentList' => [[
                        'shipmentId' => 'S1', 'warehouseId' => null,
                        'outboundStatus' => 0, 'trackingNumber' => null,
                    ]],
                    'toAddress' => ['phone' => 'SECRET_PHONE'],
                ],
            ];
            if (str_contains($request->url(), '/queryListV2?')) {
                $response['data'] = ['total' => 1, 'records' => [$response['data']]];
            } else {
                $response['data']['orderShipments'] = $response['data']['shipmentList'];
                unset($response['data']['shipmentList']);
            }
            return Http::response($response, 200);
        });
        Http::preventStrayRequests();

        $page = $this->client()->orderPage('WH1', 'TEST-ORDER-NO');
        $this->assertSame('PROVIDER_ACCOUNT_BALANCE_INSUFFICIENT', $page['records'][0]['cancelReasonCode']);
        $this->assertSame([], $page['records'][0]['shipments']);
        $detail = $this->client()->orderDetail('WH1', 'TEST-ORDER-ID');
        $this->assertSame(6, $detail['status']);
        $this->assertSame('PROVIDER_ACCOUNT_BALANCE_INSUFFICIENT', $detail['cancelReasonCode']);
        $this->assertSame([], $detail['shipments']);
        $json = json_encode([$page, $detail]);
        $this->assertStringNotContainsString('TRACESECRET', $json);
        $this->assertStringNotContainsString('SECRET_PHONE', $json);
        Http::assertSentCount(2);
    }

    public function test_order_detail_warehouse_mismatch_is_rejected(): void
    {
        config()->set('shipout.sandbox_read_enabled', true);
        Http::fake(['https://opendev.shipout.com/api/*' =>
            Http::response(['result' => 'OK', 'data' => [
                'orderId' => '123', 'orderNO' => 'UAT-1', 'status' => 2,
                'orderShipments' => [[
                    'warehouseId' => 'OTHER', 'outboundStatus' => 2,
                ]],
            ]], 200),
        ]);
        $this->expectExceptionMessage('SHIPOUT_SHIPMENT_WAREHOUSE_MISMATCH');
        $this->client()->orderDetail('W', '123');
    }

    public function test_product_list_is_read_only_and_duplicates_fail_closed(): void
    {
        config()->set('shipout.sandbox_read_enabled', true);
        Http::fake(['https://opendev.shipout.com/api/*' =>
            Http::response(['result' => 'OK', 'data' => [
                'records' => [['omsSku' => 'UAT-SHIPOUT', 'skuId' => '123', 'skuNameEN' => 'Sandbox Sample', 'warehouseIds' => ['W']]],
                'total' => '1',
            ]], 200),
        ]);
        $result = $this->client()->productPage();
        $this->assertSame('UAT-SHIPOUT', $result['records'][0]['omsSku']);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/open-api/oms/product/queryList?'));
    }

    public function test_product_rows_must_be_unique(): void
    {
        config()->set('shipout.sandbox_read_enabled', true);
        Http::fake(['https://opendev.shipout.com/api/*' =>
            Http::response(['result' => 'OK', 'data' => [
                'records' => [['omsSku' => 'A'], ['omsSku' => 'A']],
                'total' => 2,
            ]], 200),
        ]);
        $this->expectExceptionMessage('SHIPOUT_PRODUCT_DUPLICATE_SKU');
        $this->client()->productPage();
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

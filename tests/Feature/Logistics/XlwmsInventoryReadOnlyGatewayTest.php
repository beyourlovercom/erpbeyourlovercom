<?php

namespace Tests\Feature\Logistics;

use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseOpenApiConnection;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsInventoryReadOnlyGateway;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class XlwmsInventoryReadOnlyGatewayTest extends TestCase
{
    public function test_only_the_two_inventory_reads_are_allowlisted_and_no_outbound_request_is_possible(): void
    {
        Http::fake(fn ($request) => Http::response(['code' => 200, 'data' => ['records' => [], 'total' => 0, 'page' => 1, 'pageSize' => 10, 'pages' => 0]], 200));
        Http::preventStrayRequests();

        $connection = new WarehouseOpenApiConnection([
            'app_key_encrypted' => 'test-key',
            'app_secret_encrypted' => 'test-secret',
        ]);
        $gateway = new XlwmsInventoryReadOnlyGateway();

        $gateway->productPage($connection, ['page' => 1, 'pageSize' => 10]);
        $gateway->integratedInventoryPage($connection, ['page' => 1, 'pageSize' => 10, 'whCode' => 'US-SM']);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/product/pagelist?authcode='));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/integratedInventory/pageOpen?authcode='));
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'outboundOrder'));
    }

    public function test_non_200_http_or_business_code_is_rejected_without_leaking_response_details(): void
    {
        Http::fake(['https://api.xlwms.com/*' => Http::response(['code' => 500, 'message' => 'secret detail'], 200)]);
        $connection = new WarehouseOpenApiConnection(['app_key_encrypted' => 'key', 'app_secret_encrypted' => 'secret']);

        $this->expectExceptionMessage('OMS_BUSINESS_REJECTED');
        (new XlwmsInventoryReadOnlyGateway())->productPage($connection, ['page' => 1]);
    }
}

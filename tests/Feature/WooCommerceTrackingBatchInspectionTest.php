<?php

namespace Tests\Feature;

use App\Modules\Sync\Infrastructure\WooCommerce\HttpWooCommerceTrackingGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class WooCommerceTrackingBatchInspectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.woocommerce.url' => 'https://shop.test',
            'services.woocommerce.consumer_key' => 'ck_test',
            'services.woocommerce.consumer_secret' => 'cs_test',
            'services.woocommerce.timeout' => 3,
        ]);
    }

    public function test_batch_inspection_reads_all_packages_and_order_status_in_one_request(): void
    {
        Http::fake([
            'https://shop.test/wp-json/wc/v3/orders*' => Http::response([
                [
                    'id' => 28518,
                    'status' => 'completed',
                    'meta_data' => [[
                        'key' => '_aftership_tracking_items',
                        'value' => [
                            ['tracking_number' => '4PX-A', 'slug' => '4px', 'additional_fields' => ['ship_date' => '2026-11-01']],
                            ['tracking_number' => '4PX-B', 'slug' => '4px', 'additional_fields' => ['ship_date' => '2026-11-02']],
                        ],
                    ]],
                ],
                [
                    'id' => 29066,
                    'status' => 'processing',
                    'meta_data' => [
                        ['key' => '_aftership_tracking_number', 'value' => 'USPS-LEGACY'],
                        ['key' => '_aftership_tracking_provider_name', 'value' => 'usps'],
                    ],
                ],
            ], 200),
        ]);

        $result = app(HttpWooCommerceTrackingGateway::class)->inspectTrackingBatch([28518, 29066, 28518, 0]);

        self::assertSame('completed', $result[28518]['order_status']);
        self::assertSame([
            ['tracking_number' => '4PX-A', 'provider' => '4px', 'ship_date' => '2026-11-01'],
            ['tracking_number' => '4PX-B', 'provider' => '4px', 'ship_date' => '2026-11-02'],
        ], $result[28518]['tracking_items']);
        self::assertSame('processing', $result[29066]['order_status']);
        self::assertSame([
            ['tracking_number' => 'USPS-LEGACY', 'provider' => 'usps', 'ship_date' => null],
        ], $result[29066]['tracking_items']);
        self::assertTrue($result[28518]['has_tracking']);
        self::assertArrayNotHasKey(0, $result);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://shop.test/wp-json/wc/v3/orders?')
            && str_contains($request->url(), 'per_page=2')
            && str_contains($request->url(), 'include%5B0%5D=28518')
            && str_contains($request->url(), 'include%5B1%5D=29066'));
    }

    public function test_batch_inspection_chunks_requests_at_one_hundred_orders(): void
    {
        Http::fake([
            'https://shop.test/wp-json/wc/v3/orders*' => Http::response([], 200),
        ]);

        $ids = range(1000, 1100);
        $result = app(HttpWooCommerceTrackingGateway::class)->inspectTrackingBatch($ids);

        self::assertCount(101, $result);
        Http::assertSentCount(2);
    }
}

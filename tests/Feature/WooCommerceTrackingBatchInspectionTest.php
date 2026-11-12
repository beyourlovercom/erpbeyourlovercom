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

    public function test_parallel_batch_uses_at_most_three_100_order_gets_with_exact_result_parity(): void
    {
        $groups = [];
        Http::fake(function (Request $request) use (&$groups) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $ids = array_values(array_map('intval', (array) ($query['include'] ?? [])));
            $groups[] = $ids;

            return Http::response(array_values(array_map(
                function (int $id): array {
                    return [
                        'id' => $id,
                        'status' => $id % 2 === 0 ? 'completed' : 'processing',
                        'meta_data' => [[
                            'key' => '_aftership_tracking_items',
                            'value' => $id % 2 === 0
                                ? [['tracking_number' => 'TRACK-'.$id, 'slug' => 'usps']]
                                : [],
                        ]],
                    ];
                },
                array_values(array_filter($ids, fn (int $id): bool => $id !== 1300))
            )), 200);
        });

        $ids = range(1000, 1349);
        $result = app(HttpWooCommerceTrackingGateway::class)->inspectTrackingBatch($ids);

        self::assertCount(350, $result);
        self::assertSame('completed', $result[1100]['order_status']);
        self::assertSame('processing', $result[1101]['order_status']);
        self::assertSame('not_found', $result[1300]['order_status']);
        self::assertSame([[
            'tracking_number' => 'TRACK-1100', 'provider' => 'usps', 'ship_date' => null,
        ]], $result[1100]['tracking_items']);
        self::assertSame([], $result[1101]['tracking_items']);
        Http::assertSentCount(4);
        self::assertCount(4, $groups);
        self::assertSame($ids, array_values(array_merge(...$groups)));
        foreach ($groups as $group) {
            self::assertLessThanOrEqual(100, count($group));
        }
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://shop.test/wp-json/wc/v3/orders?')
            && $request->hasHeader('Authorization'));
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    }

    public function test_any_failed_parallel_page_aborts_the_entire_snapshot(): void
    {
        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $ids = array_map('intval', (array) ($query['include'] ?? []));

            return Http::response([], in_array(1200, $ids, true) ? 503 : 200);
        });
        $this->expectException(\Illuminate\Http\Client\RequestException::class);
        app(HttpWooCommerceTrackingGateway::class)->inspectTrackingBatch(range(1000, 1250));
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

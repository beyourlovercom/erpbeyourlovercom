<?php

namespace Tests\Feature;

use App\Modules\Sync\Application\WooCommerceTrackingConflictException;
use App\Modules\Sync\Infrastructure\WooCommerce\HttpWooCommerceTrackingGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class HttpWooCommerceTrackingGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.woocommerce.url' => 'https://shop.test',
            'services.woocommerce.consumer_key' => 'ck_test',
            'services.woocommerce.consumer_secret' => 'cs_test',
            'services.woocommerce.connection_key' => 'shop-a',
            'services.woocommerce.timeout' => 5,
        ]);
        Carbon::setTestNow('2026-08-28T01:02:03Z');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_empty_remote_tracking_is_written_with_aftership_native_meta_and_verified(): void
    {
        Http::fakeSequence('https://shop.test/wp-json/wc/v3/orders/7001')
            ->push(['id' => 7001, 'meta_data' => []], 200)
            ->push(['id' => 7001], 200)
            ->push($this->remoteOrder('dhl-api', 'TRACK-7001'), 200);

        $result = app(HttpWooCommerceTrackingGateway::class)
            ->syncTracking(7001, 'dhl-api', 'TRACK-7001', '2026-08-28');

        $this->assertSame(['written' => true, 'verified' => true], $result);
        Http::assertSentCount(3);
        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'PUT') {
                return false;
            }
            $data = $request->data();
            $meta = collect($data['meta_data'] ?? [])->keyBy('key');
            $item = $meta['_aftership_tracking_items']['value'][0] ?? [];

            return ($item['slug'] ?? null) === 'dhl-api'
                && ($item['tracking_number'] ?? null) === 'TRACK-7001'
                && ($item['tracking_id'] ?? null) === md5('dhl-api-TRACK-7001')
                && ($item['additional_fields']['ship_date'] ?? null) === '2026-08-28'
                && ($meta['_aftership_tracking_number']['value'] ?? null) === 'TRACK-7001'
                && ($meta['_aftership_tracking_provider_name']['value'] ?? null) === 'dhl-api';
        });
    }

    public function test_same_remote_tracking_is_idempotent_and_does_not_write(): void
    {
        Http::fake([
            'https://shop.test/wp-json/wc/v3/orders/7001' => Http::response(
                $this->remoteOrder('usps', 'TRACK-7001'), 200
            ),
        ]);

        $result = app(HttpWooCommerceTrackingGateway::class)
            ->syncTracking(7001, 'usps', 'TRACK-7001', '2026-08-28');

        $this->assertSame(['written' => false, 'verified' => true], $result);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'PUT');
    }

    public function test_different_remote_tracking_is_conflict_and_never_overwritten(): void
    {
        Http::fake([
            'https://shop.test/wp-json/wc/v3/orders/7001' => Http::response(
                $this->remoteOrder('usps', 'REMOTE-DIFFERENT'), 200
            ),
        ]);

        $this->expectException(WooCommerceTrackingConflictException::class);

        try {
            app(HttpWooCommerceTrackingGateway::class)
                ->syncTracking(7001, 'usps', 'TRACK-7001', '2026-08-28');
        } finally {
            Http::assertNotSent(fn (Request $request) => $request->method() === 'PUT');
        }
    }

    private function remoteOrder(string $provider, string $tracking): array
    {
        return [
            'id' => 7001,
            'meta_data' => [
                [
                    'id' => 501,
                    'key' => '_aftership_tracking_items',
                    'value' => [[
                        'slug' => $provider,
                        'tracking_number' => $tracking,
                        'tracking_id' => md5($provider.'-'.$tracking),
                    ]],
                ],
                ['id' => 502, 'key' => '_aftership_tracking_number', 'value' => $tracking],
                ['id' => 503, 'key' => '_aftership_tracking_provider_name', 'value' => $provider],
            ],
        ];
    }
}

<?php

namespace Tests\Feature\Kol;

use App\Modules\Kol\Infrastructure\WooCommerce\HttpWooCouponSettingsGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class HttpWooCouponSettingsGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.woocommerce.url' => 'https://coupon.example.test',
            'services.woocommerce.consumer_key' => 'test_key',
            'services.woocommerce.consumer_secret' => 'test_secret',
        ]);
    }

    private function coupon(array $overrides = []): array
    {
        return array_merge([
            'id' => 42,
            'code' => 'Creator20',
            'meta_data' => [['key' => 'affwp_discount_affiliate', 'value' => '7']],
            'description' => 'Old Description',
            'date_expires' => null,
            'discount_type' => 'percent',
            'amount' => '20',
            'usage_limit' => 0,
            'usage_limit_per_user' => 0,
            'individual_use' => false,
            'free_shipping' => false,
            'product_ids' => [],
            'excluded_product_ids' => [],
        ], $overrides);
    }

    public function test_only_description_and_expiry_are_written_then_read_back(): void
    {
        $provider = $this->coupon();
        $calls = [];
        Http::fake(function (Request $request) use (&$provider, &$calls) {
            $calls[] = $request->method();
            self::assertTrue($request->hasHeader('User-Agent', 'Mozilla/5.0 (compatible; BYL-ERP-WooCommerce-Inbound/1.0; +https://www.beyourlover.com/)'));
            self::assertSame('https://coupon.example.test/wp-json/wc/v3/coupons/42', $request->url());
            if ($request->method() === 'PUT') {
                self::assertSame([
                    'description' => 'https://www.instagram.com/creator/',
                    'date_expires' => '2029-07-21T23:59:59',
                ], $request->data());
                $provider = array_merge($provider, $request->data());
            }
            return Http::response($provider, 200);
        });

        $result = (new HttpWooCouponSettingsGateway)->sync(42, 'CREATOR20', 7, 'https://www.instagram.com/creator/', '2029-07-21');
        self::assertSame(['GET', 'PUT', 'GET'], $calls);
        self::assertSame([
            'coupon_id' => 42,
            'description' => 'https://www.instagram.com/creator/',
            'date_expires' => '2029-07-21',
        ], $result);
        Http::assertSentCount(3);
    }

    public function test_other_affiliate_owner_blocks_before_write(): void
    {
        Http::fake([
            'coupon.example.test/*' => Http::response($this->coupon([
                'meta_data' => [['key' => 'affwp_discount_affiliate', 'value' => '999']],
            ])),
        ]);
        try {
            (new HttpWooCouponSettingsGateway)->sync(42, 'Creator20', 7, 'https://www.instagram.com/creator/', '2029-07-21');
            self::fail('Owner mismatch must block');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('Affiliate owner', $error->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_wrong_coupon_code_blocks_before_write(): void
    {
        Http::fake(['coupon.example.test/*' => Http::response($this->coupon(['code' => 'SOMEONE_ELSE']))]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('normalized code');
        (new HttpWooCouponSettingsGateway)->sync(42, 'Creator20', 7, 'https://www.instagram.com/creator/', '2029-07-21');
    }

    public function test_absent_affiliate_meta_is_fail_closed(): void
    {
        Http::fake(['coupon.example.test/*' => Http::response($this->coupon(['meta_data' => []]))]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Affiliate owner cannot be verified');
        (new HttpWooCouponSettingsGateway)->sync(42, 'Creator20', 7, 'https://www.instagram.com/creator/', '2029-07-21');
    }

    public function test_provider_readback_mismatch_rejects_success(): void
    {
        $provider = $this->coupon();
        Http::fake(function (Request $request) use (&$provider) {
            if ($request->method() === 'PUT') {
                return Http::response(array_merge($provider, $request->data()), 200);
            }
            return Http::response($provider, 200);
        });
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('read-back mismatch');
        (new HttpWooCouponSettingsGateway)->sync(42, 'Creator20', 7, 'https://www.instagram.com/creator/', '2029-07-21');
    }

    public function test_malformed_date_is_rejected_without_provider_request(): void
    {
        Http::fake();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid KOL Coupon');
        try {
            (new HttpWooCouponSettingsGateway)->sync(42, 'Creator20', 7, 'https://www.instagram.com/creator/', '2029-02-31');
        } finally {
            Http::assertNothingSent();
        }
    }
}

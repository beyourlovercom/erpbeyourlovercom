<?php

namespace Tests\Feature;

use App\Modules\Sync\Infrastructure\WooCommerce\HttpWooCommerceCustomerNoteGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class HttpWooCommerceCustomerNoteGatewayTest extends TestCase
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
    }

    public function test_customer_note_is_created_only_after_completed_and_verified_by_note_id(): void
    {
        $note = null;
        $created = false;
        Http::fake(function (Request $request) use (&$note, &$created) {
            $url = $request->url();
            if ($request->method() === 'GET' && str_contains($url, '/orders/7001/notes?')) {
                return Http::response([], 200);
            }
            if ($request->method() === 'GET' && str_ends_with($url, '/orders/7001')) {
                return Http::response(['id' => 7001, 'status' => 'completed'], 200);
            }
            if ($request->method() === 'POST' && str_ends_with($url, '/orders/7001/notes')) {
                $created = true;
                $note = $request->data()['note'] ?? '';

                return Http::response(['id' => 151, 'note' => $note, 'customer_note' => true], 201);
            }
            if ($request->method() === 'GET' && str_ends_with($url, '/orders/7001/notes/151')) {
                return Http::response(['id' => 151, 'note' => $note, 'customer_note' => true], 200);
            }

            return Http::response(['error' => 'unexpected'], 404);
        });

        $result = app(HttpWooCommerceCustomerNoteGateway::class)->notifyPackage(7001, 'usps', 'TRACK-A');
        self::assertSame(['created' => true, 'verified' => true, 'note_id' => 151], $result);
        self::assertTrue($created);
        self::assertStringContainsString('Tracking number: TRACK-A', $note);
        self::assertStringContainsString('separate packages', $note);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && ($request->data()['customer_note'] ?? false) === true);
    }

    public function test_repeated_customer_note_is_found_remotely_and_never_posted_twice(): void
    {
        $note = "Shipping update: a package from your order is on its way.\n"
            ."Courier: usps\nTracking number: TRACK-A\n"
            .'Your order may arrive in separate packages. You can view all shipment updates in your order details.';
        Http::fake([
            'https://shop.test/wp-json/wc/v3/orders/7001/notes*' => Http::response([
                ['id' => 151, 'note' => '<p>'.nl2br($note).'</p>', 'customer_note' => true],
            ], 200),
        ]);
        $result = app(HttpWooCommerceCustomerNoteGateway::class)->notifyPackage(7001, 'usps', 'TRACK-A');
        self::assertSame(['created' => false, 'verified' => true, 'note_id' => 151], $result);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_non_completed_order_never_triggers_customer_email(): void
    {
        Http::fake([
            'https://shop.test/wp-json/wc/v3/orders/7001/notes*' => Http::response([], 200),
            'https://shop.test/wp-json/wc/v3/orders/7001' => Http::response([
                'id' => 7001, 'status' => 'refunded',
            ], 200),
        ]);
        $this->expectException(RuntimeException::class);
        try {
            app(HttpWooCommerceCustomerNoteGateway::class)->notifyPackage(7001, 'usps', 'TRACK-A');
        } finally {
            Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
        }
    }

    public function test_unverifiable_note_response_is_error_even_if_post_succeeded(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            if ($request->method() === 'GET' && str_contains($url, '/orders/7001/notes?')) {
                return Http::response([], 200);
            }
            if ($request->method() === 'GET' && str_ends_with($url, '/orders/7001')) {
                return Http::response(['id' => 7001, 'status' => 'completed'], 200);
            }
            if ($request->method() === 'POST') {
                return Http::response(['id' => 151, 'customer_note' => true], 201);
            }

            return Http::response(['id' => 151, 'note' => 'DIFFERENT', 'customer_note' => true], 200);
        });
        $this->expectException(RuntimeException::class);
        app(HttpWooCommerceCustomerNoteGateway::class)->notifyPackage(7001, 'usps', 'TRACK-A');
    }
}

<?php

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Orders\Infrastructure\Models\OrderExternalReference;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class WooCommerceInboundTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_single_import_creates_canonical_order_and_reimport_is_idempotent(): void
    {
        $user = $this->userWith(['sync.view', 'sync.operate']);
        $product = Product::create([
            'woocommerce_product_id' => 5001, 'sku' => 'WOO-5001', 'name' => 'Canonical Widget', 'product_type' => 'simple',
        ]);
        Http::fakeSequence('https://shop.test/wp-json/wc/v3/orders/101')
            ->push($this->wooOrder(101, 'WC-101', 'processing', '19.95', 5001, 'WOO-5001'), 200)
            ->push($this->wooOrder(101, 'WC-101', 'processing', '21.50', 5001, 'WOO-5001', note: 'Updated'), 200);

        $this->actingAs($user)->post('/sync/woocommerce/single', ['woo_order_id' => 101])->assertSessionHasNoErrors();

        $order = Order::with(['address', 'items', 'externalReferences'])->firstOrFail();
        $this->assertSame('woocommerce', $order->source_type->value);
        $this->assertSame('customer', $order->order_kind->value);
        $this->assertSame('WC-101', $order->external_order_number);
        $this->assertSame('EUR', $order->currency);
        $this->assertSame('DE', $order->address->country);
        $this->assertSame($product->id, $order->items->first()->product_id);
        $this->assertSame('19.95', $order->items->first()->unit_price);
        $this->assertDatabaseHas('order_external_references', [
            'order_id' => $order->id, 'provider' => 'woocommerce', 'connection_key' => 'shop-a', 'reference' => '101', 'provider_modified_at' => '2026-08-27 01:05:03',
        ]);
        $this->assertDatabaseHas('audit_records', ['action' => 'order.inbound_created']);

        $this->actingAs($user)->post('/sync/woocommerce/single', ['woo_order_id' => 101])->assertSessionHasNoErrors();
        $this->assertSame(1, Order::count());
        $this->assertSame(1, OrderExternalReference::count());
        $order->refresh()->load('items');
        $this->assertSame('Updated', $order->order_note);
        $this->assertSame('21.50', $order->items->first()->unit_price);
        $this->assertDatabaseHas('audit_records', ['action' => 'order.inbound_updated']);
    }

    public function test_unmatched_woo_product_does_not_create_product_master_record(): void
    {
        $user = $this->userWith(['sync.operate']);
        Http::fake(['https://shop.test/wp-json/wc/v3/orders/202' => Http::response(
            $this->wooOrder(202, 'WC-202', 'processing', '9.00', 999999, 'UNKNOWN-SKU'), 200
        )]);

        $this->actingAs($user)->post('/sync/woocommerce/single', ['woo_order_id' => 202])->assertSessionHasNoErrors();
        $this->assertSame(0, Product::count());
        $item = Order::firstOrFail()->items()->firstOrFail();
        $this->assertNull($item->product_id);
        $this->assertSame('UNKNOWN-SKU', $item->sku);
    }

    public function test_same_provider_reference_can_exist_on_two_connection_keys(): void
    {
        $user = $this->userWith(['sync.operate']);
        Http::fake(['https://shop.test/wp-json/wc/v3/orders/303' => Http::response($this->wooOrder(303, 'WC-303'), 200)]);
        $this->actingAs($user)->post('/sync/woocommerce/single', ['woo_order_id' => 303])->assertSessionHasNoErrors();

        config(['services.woocommerce.connection_key' => 'shop-b']);
        $this->actingAs($user)->post('/sync/woocommerce/single', ['woo_order_id' => 303])->assertSessionHasNoErrors();

        $this->assertSame(2, Order::count());
        $this->assertSame(2, OrderExternalReference::where('reference', '303')->count());
        $this->assertDatabaseHas('order_external_references', ['provider' => 'woocommerce', 'connection_key' => 'shop-a', 'reference' => '303']);
        $this->assertDatabaseHas('order_external_references', ['provider' => 'woocommerce', 'connection_key' => 'shop-b', 'reference' => '303']);
    }

    public function test_incremental_import_requires_cursor_then_uses_modified_after_with_overlap(): void
    {
        $user = $this->userWith(['sync.operate']);
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/orders/404')) {
                return Http::response($this->wooOrder(404, 'WC-404'), 200);
            }
            if (str_contains($request->url(), '/orders?')) {
                return Http::response([$this->wooOrder(405, 'WC-405')], 200, ['X-WP-TotalPages' => '1']);
            }
            return Http::response([], 404);
        });

        $this->actingAs($user)->post('/sync/woocommerce/incremental')->assertSessionHas('error');
        $this->actingAs($user)->post('/sync/woocommerce/single', ['woo_order_id' => 404])->assertSessionHasNoErrors();
        $this->actingAs($user)->post('/sync/woocommerce/incremental')->assertSessionHasNoErrors();
        $this->assertSame(2, Order::count());

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/orders?')
            && str_contains($request->url(), 'modified_after=')
            && str_contains($request->url(), 'per_page=100'));
    }

    public function test_historical_import_paginates_explicit_date_range(): void
    {
        $user = $this->userWith(['sync.operate']);
        Http::fake(function (Request $request) {
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $page = (int) ($query['page'] ?? 1);
            return Http::response(
                [$this->wooOrder($page === 1 ? 501 : 502, $page === 1 ? 'WC-501' : 'WC-502')],
                200,
                ['X-WP-TotalPages' => '2'],
            );
        });

        $this->actingAs($user)->post('/sync/woocommerce/historical', ['from' => '2026-08-01', 'to' => '2026-08-27'])->assertSessionHasNoErrors();
        $this->assertSame(2, Order::count());
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'after=') && str_contains($request->url(), 'before=') && str_contains($request->url(), 'page=2'));
    }

    public function test_inbound_page_and_connection_probe_are_operator_accessible(): void
    {
        $user = $this->userWith(['sync.view', 'sync.operate']);
        $this->actingAs($user)->get('/sync/woocommerce')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Sync/WooCommerceInbound')
            ->where('connection.configured', true)
            ->where('connection.connection_key', 'shop-a'));

        Http::fake(['https://shop.test/wp-json/wc/v3/orders*' => Http::response([], 200)]);
        $this->actingAs($user)->post('/sync/woocommerce/probe')->assertSessionHas('success');
        Http::assertSent(fn (Request $request) => str_starts_with((string) $request->header('User-Agent')[0], 'Mozilla/5.0')
            && str_contains((string) $request->header('User-Agent')[0], 'BYL-ERP-WooCommerce-Inbound/1.0'));
    }

    private function wooOrder(
        int $id,
        string $number,
        string $status = 'processing',
        string $price = '12.34',
        int $productId = 0,
        string $sku = '',
        ?string $note = null,
    ): array {
        return [
            'id' => $id,
            'number' => $number,
            'status' => $status,
            'currency' => 'EUR',
            'date_created_gmt' => '2026-08-27T01:02:03',
            'date_modified_gmt' => '2026-08-27T01:05:03',
            'customer_note' => $note ?? '',
            'shipping' => [
                'first_name' => 'Ada', 'last_name' => 'Lovelace', 'company' => '',
                'address_1' => '1 Test Str', 'address_2' => '', 'city' => 'Berlin', 'state' => 'BE', 'postcode' => '10115', 'country' => 'DE',
            ],
            'billing' => ['phone' => '+49123456'],
            'line_items' => [[
                'name' => 'Imported Product', 'product_id' => $productId, 'variation_id' => 0,
                'sku' => $sku, 'quantity' => 1, 'price' => $price, 'total' => $price,
            ]],
        ];
    }

    private function userWith(array $permissions): User
    {
        $role = Role::create(['key' => 'woo-inbound-'.uniqid(), 'label' => 'Woo Inbound']);
        foreach ($permissions as $permission) {
            $role->permissions()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }
}

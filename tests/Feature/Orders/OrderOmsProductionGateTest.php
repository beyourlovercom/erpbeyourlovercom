<?php

namespace Tests\Feature\Orders;

use App\Models\Role;
use App\Models\User;
use App\Modules\Orders\Application\OrderOmsBatchImport;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Logistics\Application\OverseaFiles\ProviderXlsxWriter;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class OrderOmsProductionGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Simulating Production environment inside PHPUnit activates CSRF middleware;
        // bypass only CSRF in this feature test to reach the real authorization gates.
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);
        $this->withHeader('Accept', 'application/json');
    }

    private function user(): User
    {
        $role = Role::create(['key' => 'oms-prod-canary', 'label' => 'OMS Production Canary']);
        foreach (['orders.view', 'orders.create'] as $key) {
            $role->permissions()->firstOrCreate(['key' => $key], ['label' => $key]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }

    private function asProduction(callable $test): void
    {
        $previous = app()->environment();
        app()->detectEnvironment(fn (): string => 'production');
        try {
            $test();
        } finally {
            app()->detectEnvironment(fn (): string => $previous);
        }
    }

    public function test_production_defaults_fail_closed_even_with_oms_query_and_order_permissions(): void
    {
        $user = $this->user();
        $this->asProduction(function () use ($user): void {
            config()->set('oms_grid.production_enabled', false);
            config()->set('oms_grid.production_user_ids', (string) $user->id);
            config()->set('oms_grid.production_export_enabled', false);
            config()->set('oms_grid.production_import_enabled', false);
            $this->actingAs($user)->get('/orders?oms_grid=1', ['Accept' => 'text/html'])
                ->assertInertia(fn (Assert $page) => $page
                    ->where('omsGridAvailable', false)
                    ->where('omsGridEnabled', false));
            $this->actingAs($user)->get('/orders/oms/export')->assertNotFound();
            $this->actingAs($user)->get('/orders/oms/import/template')->assertNotFound();
            $this->actingAs($user)->post('/orders/oms/import/preview', [])->assertNotFound();
            $this->actingAs($user)->post('/orders/oms/import/confirm', [])->assertNotFound();
            $this->assertDatabaseCount('orders', 0);
        });
    }

    public function test_allowlist_is_mandatory_before_readonly_grid_canary_opens(): void
    {
        $user = $this->user();
        $outsider = User::factory()->create(['is_active' => true]);
        $outsider->roles()->attach($user->roles()->first()->id);
        $this->asProduction(function () use ($user, $outsider): void {
            config()->set('oms_grid.production_enabled', true);
            config()->set('oms_grid.production_user_ids', ' ,invalid,-1,  ');
            $this->actingAs($user)->get('/orders?oms_grid=1', ['Accept' => 'text/html'])
                ->assertInertia(fn (Assert $page) => $page->where('omsGridEnabled', false));
            config()->set('oms_grid.production_user_ids', (string) $user->id);
            $this->actingAs($outsider)->get('/orders?oms_grid=1', ['Accept' => 'text/html'])
                ->assertInertia(fn (Assert $page) => $page->where('omsGridEnabled', false));
            $this->actingAs($user)->get('/orders?oms_grid=1', ['Accept' => 'text/html'])
                ->assertInertia(fn (Assert $page) => $page
                    ->where('omsGridAvailable', true)
                    ->where('omsGridEnabled', true)
                    ->where('canImportOmsOrders', false)
                    ->where('canExportOmsOrders', false));
            $this->actingAs($user)->get('/orders/oms/export')->assertNotFound();
            $this->actingAs($user)->get('/orders/oms/import/template')->assertNotFound();
        });
    }

    public function test_production_preview_only_accepts_valid_multisku_file_but_commit_stays_404(): void
    {
        $user = $this->user();
        $outsider = User::factory()->create(['is_active' => true]);
        $outsider->roles()->attach($user->roles()->first()->id);
        \App\Modules\Products\Infrastructure\Models\Product::query()->create(['sku' => 'MF414A', 'name' => 'Main']);
        \App\Modules\Products\Infrastructure\Models\Product::query()->create(['sku' => 'A1', 'name' => 'Accessory']);
        $one = array_fill_keys(OrderOmsBatchImport::FIELDS, '');
        $one['external_order_number'] = 'P3-PREVIEW-GROUP-001';
        $one['order_date'] = '2026-11-06';
        $one['recipient_name'] = 'Synthetic Test Only';
        $one['country'] = 'US';
        $one['province_state'] = 'CA';
        $one['city'] = 'Los Angeles';
        $one['address_line_1'] = '123 Example St';
        $one['sku'] = 'MF414A';
        $one['quantity'] = '2';
        $one['unit_price'] = '79.00';
        $two = array_fill_keys(OrderOmsBatchImport::FIELDS, '');
        $two['external_order_number'] = $one['external_order_number'];
        $two['sku'] = 'A1';
        $two['quantity'] = '1';
        $two['unit_price'] = '0.00';
        $file = UploadedFile::fake()->createWithContent(
            'p3-preview.xlsx',
            app(ProviderXlsxWriter::class)->write('批量订单导入', OrderOmsBatchImport::FIELDS, [
                array_values($one), array_values($two),
            ]),
        );

        $this->asProduction(function () use ($user, $outsider, $file): void {
            config()->set('oms_grid.production_enabled', true);
            config()->set('oms_grid.production_user_ids', (string) $user->id);
            config()->set('oms_grid.production_import_preview_enabled', true);
            config()->set('oms_grid.production_import_enabled', false);
            $this->actingAs($user)->get('/orders?oms_grid=1', ['Accept' => 'text/html'])
                ->assertInertia(fn (Assert $page) => $page
                    ->where('canImportOmsOrders', true)
                    ->where('canConfirmOmsOrders', false));
            $this->actingAs($outsider)->get('/orders/oms/import/template')->assertNotFound();
            $this->actingAs($outsider)->post('/orders/oms/import/preview', ['file' => $file])->assertNotFound();
            $this->actingAs($user)->get('/orders/oms/import/template')->assertOk();
            $preview = $this->actingAs($user)->post('/orders/oms/import/preview', ['file' => $file])
                ->assertOk()->json();
            $this->assertSame(1, $preview['count']);
            $this->assertSame(2, $preview['row_count']);
            $this->assertSame(1, $preview['valid_count']);
            $this->assertSame([], $preview['errors']);
            $this->assertNotEmpty($preview['token']);
            $this->actingAs($user)->post('/orders/oms/import/confirm', [
                'file' => $file, 'token' => $preview['token'], 'confirm' => '1',
            ])->assertNotFound();
            $this->assertDatabaseCount('orders', 0);
            $this->assertDatabaseCount('oms_order_import_batches', 0);
            $this->assertDatabaseCount('oms_order_import_claims', 0);
        });
    }

    public function test_import_and_export_are_independent_production_canary_switches(): void
    {
        $user = $this->user();
        $this->asProduction(function () use ($user): void {
            config()->set('oms_grid.production_enabled', true);
            config()->set('oms_grid.production_user_ids', (string) $user->id);
            config()->set('oms_grid.production_export_enabled', true);
            config()->set('oms_grid.production_import_enabled', false);
            $this->actingAs($user)->get('/orders?oms_grid=1', ['Accept' => 'text/html'])
                ->assertInertia(fn (Assert $page) => $page
                    ->where('canExportOmsOrders', true)
                    ->where('canImportOmsOrders', false));
            $this->actingAs($user)->get('/orders/oms/export?format=csv')->assertOk();
            $this->actingAs($user)->get('/orders/oms/import/template')->assertNotFound();

            config()->set('oms_grid.production_import_enabled', true);
            $this->actingAs($user)->get('/orders?oms_grid=1', ['Accept' => 'text/html'])
                ->assertInertia(fn (Assert $page) => $page->where('canImportOmsOrders', true));
            $this->actingAs($user)->get('/orders/oms/import/template')->assertOk();
            $this->actingAs($user)->post('/orders/oms/import/preview', [])
                ->assertUnprocessable(); // endpoint opened, but file validation is still enforced
            $this->assertDatabaseCount('orders', 0);
        });
    }

    public function test_p3b_enabled_creates_once_for_allowlisted_admin_and_denies_other_users(): void
    {
        $admin = $this->user();
        $outsider = User::factory()->create(['is_active' => true]);
        $outsider->roles()->attach($admin->roles()->first()->id);
        $this->assertSame(1, (int) $admin->id);
        Product::query()->create(['sku' => 'MF414A', 'name' => 'Main', 'is_active' => true]);
        Product::query()->create(['sku' => 'A1', 'name' => 'Accessory', 'is_active' => true]);

        $first = array_fill_keys(OrderOmsBatchImport::FIELDS, '');
        $first['external_order_number'] = 'P3B-ADMIN-001';
        $first['order_date'] = '2026-11-06';
        $first['recipient_name'] = 'P3-B Synthetic Buyer';
        $first['phone'] = '5551234567';
        $first['country'] = 'US';
        $first['province_state'] = 'CA';
        $first['city'] = 'Los Angeles';
        $first['address_line_1'] = '123 P3B Test Street';
        $first['postal_code'] = '90001';
        $first['currency'] = 'USD';
        $first['sku'] = 'MF414A';
        $first['quantity'] = '2';
        $first['unit_price'] = '79.00';
        $second = array_fill_keys(OrderOmsBatchImport::FIELDS, '');
        $second['external_order_number'] = $first['external_order_number'];
        $second['sku'] = 'A1';
        $second['quantity'] = '1';
        $second['unit_price'] = '0.00';
        $file = UploadedFile::fake()->createWithContent(
            'p3b-admin-confirm.xlsx',
            app(ProviderXlsxWriter::class)->write('批量订单导入', OrderOmsBatchImport::FIELDS, [
                array_values($first), array_values($second),
            ]),
        );

        $this->asProduction(function () use ($admin, $outsider, $file): void {
            config()->set('oms_grid.production_enabled', true);
            config()->set('oms_grid.production_user_ids', '1');
            config()->set('oms_grid.production_import_preview_enabled', true);
            config()->set('oms_grid.production_import_enabled', true);

            $this->actingAs($admin)->get('/orders?oms_grid=1', ['Accept' => 'text/html'])
                ->assertInertia(fn (Assert $page) => $page
                    ->where('omsGridEnabled', true)
                    ->where('canImportOmsOrders', true)
                    ->where('canConfirmOmsOrders', true));
            $this->actingAs($outsider)->get('/orders?oms_grid=1', ['Accept' => 'text/html'])
                ->assertInertia(fn (Assert $page) => $page
                    ->where('omsGridEnabled', false)
                    ->where('canConfirmOmsOrders', false));
            $this->actingAs($outsider)->get('/orders/oms/import/template')->assertNotFound();
            $this->actingAs($outsider)->post('/orders/oms/import/preview', ['file' => $file])->assertNotFound();
            $this->actingAs($outsider)->post('/orders/oms/import/confirm', [
                'file' => $file, 'token' => 'not-issued-to-outsider', 'confirm' => '1',
            ])->assertNotFound();
            $this->assertDatabaseCount('orders', 0);

            $preview = $this->actingAs($admin)->post('/orders/oms/import/preview', ['file' => $file])
                ->assertOk()->json();
            $this->assertSame(1, $preview['count']);
            $this->assertSame(2, $preview['row_count']);
            $this->assertSame([], $preview['errors']);
            $this->assertNotEmpty($preview['token']);
            $this->assertDatabaseCount('orders', 0);
            $this->assertDatabaseCount('oms_order_import_batches', 0);

            $created = $this->actingAs($admin)->post('/orders/oms/import/confirm', [
                'file' => $file, 'token' => $preview['token'], 'confirm' => '1',
            ])->assertOk()->json();
            $this->assertSame(1, $created['created']);
            $order = \App\Modules\Orders\Infrastructure\Models\Order::query()
                ->with(['items', 'address', 'shipment', 'fulfillmentAllocation', 'manualSync', 'externalReferences'])
                ->where('external_order_number', 'P3B-ADMIN-001')
                ->firstOrFail();
            $this->assertSame('manual', $order->source_type->value);
            $this->assertSame('customer', $order->order_kind->value);
            $this->assertSame((int) $admin->id, (int) $order->owner_id);
            $this->assertNotEmpty($order->erp_order_id);
            $this->assertCount(2, $order->items);
            $this->assertSame('P3-B Synthetic Buyer', $order->address?->recipient_name);
            $this->assertNull($order->shipment);
            $this->assertNull($order->fulfillmentAllocation);
            $this->assertNull($order->manualSync);
            $this->assertCount(0, $order->externalReferences);
            $this->assertDatabaseHas('audit_records', [
                'entity_id' => (string) $order->id, 'action' => 'order.created',
            ]);
            $this->assertDatabaseCount('orders', 1);
            $this->assertDatabaseCount('order_items', 2);
            $this->assertDatabaseCount('oms_order_import_batches', 1);
            $this->assertDatabaseCount('oms_order_import_claims', 1);

            $this->actingAs($admin)->post('/orders/oms/import/confirm', [
                'file' => $file, 'token' => $preview['token'], 'confirm' => '1',
            ])->assertUnprocessable();
            $this->assertDatabaseCount('orders', 1);
            $this->assertDatabaseCount('order_items', 2);
            $this->assertDatabaseCount('oms_order_import_batches', 1);
            $this->assertDatabaseCount('oms_order_import_claims', 1);
        });
    }
}

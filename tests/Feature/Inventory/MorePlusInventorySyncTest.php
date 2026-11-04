<?php

namespace Tests\Feature\Inventory;

use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inventory\Application\MorePlusInventorySyncService;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventoryLot;
use App\Modules\Logistics\Infrastructure\ShipOut\ShipOutProductionInventoryAudit;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductSkuAlias;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

final class MorePlusInventorySyncTest extends TestCase
{
    use RefreshDatabase;

    private mixed $oldEnvironment;

    private array $provider = [];

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->oldEnvironment = $this->app['env'];
        $this->app->instance('env', 'production');
        config()->set('shipout.production_read_enabled', true);
        config()->set('shipout.production_inventory_apply_enabled', false);
        config()->set('shipout.production_app_key', 'synthetic-key');
        config()->set('shipout.production_app_secret', 'synthetic-secret');
        config()->set('shipout.production_bearer', 'synthetic-bearer');
        config()->set('shipout.production_warehouse_id', 'WH-A');
        config()->set('shipout.production_client_org_id', 'ORG-A');
        $this->warehouse = Warehouse::query()->where('code', 'US-LA')->firstOrFail();
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/warehouse/list')) {
                return Http::response([
                    'result' => 'OK', 'data' => [['warehouseId' => 'WH-A', 'warehouseName' => 'MorePlus']],
                ]);
            }
            if (str_contains($url, '/product/queryList')) {
                return Http::response([
                    'result' => 'OK', 'data' => ['total' => count($this->provider),
                        'records' => array_map(fn ($sku) => ['omsSku' => $sku, 'status' => 1], array_keys($this->provider))],
                ]);
            }
            if (str_contains($url, '/stock/list')) {
                return Http::response([
                    'result' => 'OK', 'data' => ['total' => count($this->provider),
                        'records' => array_map(fn ($sku, $q) => [
                            'warehouseId' => 'WH-A', 'omsSku' => $sku, 'standardQuantity' => $q[0],
                            'availableQuantity' => $q[1], 'outboundingQuantity' => $q[0] - $q[1],
                        ], array_keys($this->provider), array_values($this->provider))],
                ]);
            }

            return Http::response([], 404);
        });
    }

    protected function tearDown(): void
    {
        $this->app->instance('env', $this->oldEnvironment);
        parent::tearDown();
    }

    private function product(string $sku, int $qoh, int $reserved = 0): Product
    {
        $product = Product::query()->create([
            'sku' => $sku, 'name' => $sku, 'product_type' => 'simple', 'is_active' => true,
        ]);
        WarehouseInventory::query()->create([
            'warehouse_id' => $this->warehouse->id, 'product_id' => $product->id,
            'quantity_on_hand' => $qoh, 'quantity_reserved' => $reserved,
        ]);

        return $product;
    }

    public function test_readonly_preview_does_not_modify_stock_and_write_flag_is_off(): void
    {
        $p = $this->product('MP-SKU-A', 2);
        $this->provider = ['MP-SKU-A' => [7, 7]];
        $service = app(MorePlusInventorySyncService::class);
        $preview = $service->preview($this->warehouse);
        self::assertSame(1, $preview['ready']);
        self::assertSame(7, $preview['rows'][0]['provider_physical']);
        self::assertSame(2, WarehouseInventory::query()->where('product_id', $p->id)->value('quantity_on_hand'));
        $this->expectExceptionMessage('MOREPLUS_INVENTORY_APPLY_DISABLED');
        $service->apply($this->warehouse, $preview['checksum'], null);
    }

    public function test_safe_confirmation_updates_only_verified_skus_preserving_erp_reserved(): void
    {
        $p = $this->product('MP-SKU-B', 2, 1);
        $this->provider = ['MP-SKU-B' => [9, 8], 'UNMAPPED-SKU' => [4, 4]];
        $service = app(MorePlusInventorySyncService::class);
        $preview = $service->preview($this->warehouse);
        self::assertSame(1, $preview['ready']);
        self::assertSame(1, $preview['blocked']);
        config()->set('shipout.production_inventory_apply_enabled', true);
        $result = $service->apply($this->warehouse, $preview['checksum'], null);
        self::assertSame(1, $result['updated']);
        self::assertSame(1, $result['blocked']);
        $row = WarehouseInventory::query()->where('product_id', $p->id)->firstOrFail();
        self::assertSame(9, $row->quantity_on_hand);
        self::assertSame(1, $row->quantity_reserved);
    }

    public function test_supplier_hold_not_in_erp_reservations_blocks_available_overstatement(): void
    {
        $p = $this->product('MP-SKU-C', 3);
        $this->provider = ['MP-SKU-C' => [15, 10]];
        $service = app(MorePlusInventorySyncService::class);
        $preview = $service->preview($this->warehouse);
        self::assertSame(1, $preview['blocked']);
        self::assertSame('PROVIDER_AVAILABLE_BELOW_ERP_PROJECTED_AVAILABLE', $preview['rows'][0]['reason']);
        config()->set('shipout.production_inventory_apply_enabled', true);
        $result = $service->apply($this->warehouse, $preview['checksum'], null);
        self::assertSame(0, $result['updated']);
        self::assertSame(3, WarehouseInventory::query()->where('product_id', $p->id)->value('quantity_on_hand'));
    }

    public function test_provider_change_invalidates_signed_preview_checksum(): void
    {
        $p = $this->product('MP-SKU-D', 2);
        $this->provider = ['MP-SKU-D' => [5, 5]];
        $service = app(MorePlusInventorySyncService::class);
        $preview = $service->preview($this->warehouse);
        $this->provider['MP-SKU-D'] = [6, 6];
        config()->set('shipout.production_inventory_apply_enabled', true);
        try {
            $service->apply($this->warehouse, $preview['checksum'], null);
            self::fail('Stale snapshot must fail');
        } catch (RuntimeException $error) {
            self::assertSame('MOREPLUS_INVENTORY_SNAPSHOT_CHANGED_PREVIEW_AGAIN', $error->getMessage());
        }
        self::assertSame(2, WarehouseInventory::query()->where('product_id', $p->id)->value('quantity_on_hand'));
    }

    public function test_existing_warehouse_lot_blocks_physical_stock_overwrite(): void
    {
        $p = $this->product('MP-SKU-E', 3);
        WarehouseInventoryLot::query()->create([
            'warehouse_id' => $this->warehouse->id, 'product_id' => $p->id,
            'stock_status' => 'sellable', 'putaway_date' => '2026-10-01', 'quantity' => 3,
        ]);
        $this->provider = ['MP-SKU-E' => [5, 5]];
        $preview = app(MorePlusInventorySyncService::class)->preview($this->warehouse);
        self::assertSame('LOT_OR_AGING_RECONCILIATION_REQUIRED', $preview['rows'][0]['reason']);
    }

    public function test_absent_provider_sku_is_not_zeroed_on_full_stock_scan(): void
    {
        $missing = $this->product('MP-NOT-RETURNED', 11);
        $this->product('MP-RETURNED', 1);
        $this->provider = ['MP-RETURNED' => [2, 2]];
        $service = app(MorePlusInventorySyncService::class);
        $preview = $service->preview($this->warehouse);
        config()->set('shipout.production_inventory_apply_enabled', true);
        $service->apply($this->warehouse, $preview['checksum'], null);
        self::assertSame(11, WarehouseInventory::query()->where('product_id', $missing->id)->value('quantity_on_hand'));
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['key' => 'mp-uat-'.uniqid(), 'label' => 'MorePlus Inventory UAT']);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user->roles()->attach($role);

        return $user;
    }

    public function test_view_only_and_unauthenticated_users_cannot_sync(): void
    {
        $this->app->instance('env', 'testing');
        $this->post('/inventory/us-la/provider-stock/preview')->assertRedirect();
        $viewer = $this->userWith(['inventory.view']);
        $this->actingAs($viewer)->post('/inventory/us-la/provider-stock/preview')->assertForbidden();
        $this->actingAs($viewer)->post('/inventory/us-la/provider-stock/apply')->assertForbidden();
    }

    public function test_operator_cannot_sync_other_warehouses(): void
    {
        $this->app->instance('env', 'testing');
        $operator = $this->userWith(['inventory.view', 'inventory.operate']);
        $this->actingAs($operator)->post('/inventory/us-ny/provider-stock/preview')->assertNotFound();
        $this->actingAs($operator)->post('/inventory/us-ny/provider-stock/apply')->assertNotFound();
    }

    public function test_operator_apply_requires_matching_server_session_preview(): void
    {
        $this->app->instance('env', 'testing');
        $operator = $this->userWith(['inventory.view', 'inventory.operate']);
        $this->actingAs($operator)->post('/inventory/us-la/provider-stock/apply', [
            'expected_checksum' => str_repeat('f', 64),
        ])->assertSessionHasErrors('moreplus_stock');
        self::assertSame(0, WarehouseInventory::query()->count());
    }

    public function test_provider_audit_updates_last_sync_in_warehouse_page(): void
    {
        $this->product('MP-AUDIT', 1);
        $this->provider = ['MP-AUDIT' => [4, 4]];
        config()->set('shipout.production_inventory_apply_enabled', true);
        $service = app(MorePlusInventorySyncService::class);
        $preview = $service->preview($this->warehouse);
        $service->apply($this->warehouse, $preview['checksum'], null);
        $this->assertDatabaseHas('audit_records', [
            'action' => 'inventory.moreplus_provider_stock_applied',
            'entity_id' => (string) $this->warehouse->id,
        ]);
        $this->app->instance('env', 'testing');
        $viewer = $this->userWith(['inventory.view']);
        $this->actingAs($viewer)->get('/inventory/us-la')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Inventory/WarehouseShow')
                ->where('warehouse.last_provider_inventory_sync_summary.updated', 1)
                ->has('warehouse.last_provider_inventory_sync_at')
        );
    }

    public function test_soft_deleted_warehouse_inventory_is_never_silently_recreated(): void
    {
        $product = $this->product('MP-REMOVED', 3);
        WarehouseInventory::query()->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $product->id)->firstOrFail()->delete();
        $this->provider = ['MP-REMOVED' => [5, 5]];
        $preview = app(MorePlusInventorySyncService::class)->preview($this->warehouse);
        self::assertSame(1, $preview['blocked']);
        self::assertSame('ERP_REMOVED_INVENTORY_REVIEW_REQUIRED', $preview['rows'][0]['reason']);
    }

    public function test_warehouse_shows_verified_provider_sku_without_changing_woo_master_sku(): void
    {
        $product = $this->product('BY489A-Lubes', 701);
        $alias = ProductSkuAlias::query()->create([
            'product_id' => $product->id, 'alias_sku' => 'BY489A',
            'match_key' => 'by489a', 'source_type' => 'inventory_batch',
        ]);
        $alias->forceFill(['oms_verified_at' => now(), 'oms_identity_evidence' => 'UAT signed provider identity approved'])->save();
        $this->app->instance('env', 'testing');
        $viewer = $this->userWith(['inventory.view']);
        $this->actingAs($viewer)->get('/inventory/us-la')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Inventory/WarehouseShow')
                ->where('warehouse.inventories.0.product.sku', 'BY489A-Lubes')
                ->where('warehouse.inventories.0.product.provider_sku', 'BY489A')
        );
        self::assertSame('BY489A-Lubes', $product->fresh()->sku);
    }

    public function test_unverified_provider_sku_is_not_presented_as_confirmed_warehouse_identity(): void
    {
        $product = $this->product('OLD-SKU-UNVERIFIED', 7);
        ProductSkuAlias::query()->create([
            'product_id' => $product->id, 'alias_sku' => 'NEW-SKU-UNVERIFIED',
            'match_key' => 'new-sku-unverified', 'source_type' => 'inventory_batch',
        ]);
        $this->app->instance('env', 'testing');
        $viewer = $this->userWith(['inventory.view']);
        $this->actingAs($viewer)->get('/inventory/us-la')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Inventory/WarehouseShow')
                ->where('warehouse.inventories.0.product.sku', 'OLD-SKU-UNVERIFIED')
                ->where('warehouse.inventories.0.product.provider_sku', null)
        );
    }

    public function test_audit_failure_rolls_back_all_stock_changes_in_one_transaction(): void
    {
        $a = $this->product('MP-ROLLBACK-A', 2);
        $b = $this->product('MP-ROLLBACK-B', 3);
        $this->provider = ['MP-ROLLBACK-A' => [7, 7], 'MP-ROLLBACK-B' => [8, 8]];
        config()->set('shipout.production_inventory_apply_enabled', true);
        $audit = \Mockery::mock(AuditRecorder::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('SYNTHETIC_AUDIT_FAILURE'));
        $service = new MorePlusInventorySyncService(
            app(ShipOutProductionInventoryAudit::class),
            $audit
        );
        $preview = $service->preview($this->warehouse);
        try {
            $service->apply($this->warehouse, $preview['checksum'], null);
            self::fail('Transaction must roll back');
        } catch (RuntimeException $error) {
            self::assertSame('SYNTHETIC_AUDIT_FAILURE', $error->getMessage());
        }
        self::assertSame(2, WarehouseInventory::query()->where('product_id', $a->id)->value('quantity_on_hand'));
        self::assertSame(3, WarehouseInventory::query()->where('product_id', $b->id)->value('quantity_on_hand'));
    }

    public function test_second_confirm_with_old_checksum_cannot_reapply_stock(): void
    {
        $this->product('MP-IDEMPOTENT', 1);
        $this->provider = ['MP-IDEMPOTENT' => [5, 5]];
        config()->set('shipout.production_inventory_apply_enabled', true);
        $service = app(MorePlusInventorySyncService::class);
        $preview = $service->preview($this->warehouse);
        $service->apply($this->warehouse, $preview['checksum'], null);
        $this->expectExceptionMessage('MOREPLUS_INVENTORY_SNAPSHOT_CHANGED_PREVIEW_AGAIN');
        $service->apply($this->warehouse, $preview['checksum'], null);
    }

    public function test_authorized_operator_can_preview_and_apply_through_real_http_routes(): void
    {
        $p = $this->product('MP-WEB-E2E', 1);
        $this->provider = ['MP-WEB-E2E' => [5, 5]];
        config()->set('shipout.production_inventory_apply_enabled', true);
        $operator = $this->userWith(['inventory.view', 'inventory.operate']);
        $this->actingAs($operator)->withHeaders(['Sec-Fetch-Site' => 'same-origin'])
            ->post('/inventory/us-la/provider-stock/preview')->assertRedirect();
        $preview = session('inventory.moreplus_provider_preview.'.$this->warehouse->id);
        self::assertIsArray($preview);
        self::assertSame(1, $preview['ready']);
        self::assertSame(1, WarehouseInventory::query()->where('product_id', $p->id)->value('quantity_on_hand'));
        $this->post('/inventory/us-la/provider-stock/apply', [
            'expected_checksum' => $preview['checksum'],
        ])->assertRedirect();
        self::assertSame(5, WarehouseInventory::query()->where('product_id', $p->id)->value('quantity_on_hand'));
        self::assertNull(session('inventory.moreplus_provider_preview.'.$this->warehouse->id));
    }
}

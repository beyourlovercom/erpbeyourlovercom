<?php

namespace Tests\Feature\Inventory;

use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inventory\Application\MorePlusFifoReconciliationService;
use App\Modules\Inventory\Application\MorePlusInventorySyncService;
use App\Modules\Inventory\Application\WarehouseLastReadStockService;
use Illuminate\Support\Facades\DB;
use Carbon\CarbonImmutable;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventoryLot;
use App\Modules\Inventory\Infrastructure\Models\WarehouseStorageAgingAccrual;
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
        config()->set('shipout.production_fifo_apply_enabled', false);
        config()->set('shipout.production_fifo_approved_skus', []);
        config()->set('shipout.production_fifo_max_units', 0);
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

    public function test_supplier_hold_is_mirrored_without_blocking_and_available_tracks_provider(): void
    {
        $p = $this->product('MP-SKU-C', 3);
        $this->provider = ['MP-SKU-C' => [15, 10]];
        $service = app(MorePlusInventorySyncService::class);
        $preview = $service->preview($this->warehouse);
        self::assertSame(0, $preview['blocked']);
        self::assertSame(1, $preview['ready']);
        self::assertSame('PROVIDER_AVAILABLE_BELOW_ERP_PROJECTED_AVAILABLE', $preview['rows'][0]['accounting_warning']);
        config()->set('shipout.production_inventory_apply_enabled', true);
        $result = $service->apply($this->warehouse, $preview['checksum'], null);
        self::assertSame(1, $result['updated']);
        $inventory = WarehouseInventory::query()->where('product_id', $p->id)->firstOrFail();
        self::assertSame(15, $inventory->quantity_on_hand);
        self::assertSame(10, $inventory->quantity_available);
        self::assertSame(10, $inventory->provider_available_quantity);
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

    public function test_existing_warehouse_lot_warns_accounting_but_never_blocks_stock_mirror(): void
    {
        $p = $this->product('MP-SKU-E', 3);
        WarehouseInventoryLot::query()->create([
            'warehouse_id' => $this->warehouse->id, 'product_id' => $p->id,
            'stock_status' => 'sellable', 'putaway_date' => '2026-10-01', 'quantity' => 3,
        ]);
        $this->provider = ['MP-SKU-E' => [5, 5]];
        $preview = app(MorePlusInventorySyncService::class)->preview($this->warehouse);
        self::assertSame('ready', $preview['rows'][0]['status']);
        self::assertSame('LOT_OR_AGING_RECONCILIATION_REQUIRED', $preview['rows'][0]['accounting_warning']);
        $result = app(MorePlusInventorySyncService::class)->syncProvider($this->warehouse, null);
        self::assertSame(1, $result['updated']);
        self::assertSame(5, WarehouseInventory::query()->where('product_id', $p->id)->value('quantity_on_hand'));
        self::assertSame(3, WarehouseInventoryLot::query()->where('product_id', $p->id)->sum('quantity'));
    }

    public function test_erp_skus_not_in_complete_signed_provider_stock_are_zeroed_without_touching_lots(): void
    {
        $missing = $this->product('MP-NOT-RETURNED', 11);
        $this->product('MP-RETURNED', 1);
        $this->provider = ['MP-RETURNED' => [2, 2]];
        $service = app(MorePlusInventorySyncService::class);
        $preview = $service->preview($this->warehouse);
        config()->set('shipout.production_inventory_apply_enabled', true);
        $service->apply($this->warehouse, $preview['checksum'], null);
        $actual = WarehouseInventory::query()->where('product_id', $missing->id)->firstOrFail();
        self::assertSame(0, $actual->quantity_on_hand);
        self::assertSame(0, $actual->provider_available_quantity);
    }

    public function test_absent_provider_stock_zeroes_erp_mirror_without_erasing_reservations_or_lots(): void
    {
        $old = $this->product('MP-OLD-MISSING', 153, 2);
        WarehouseInventoryLot::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $old->id,
            'stock_status' => 'sellable',
            'putaway_date' => '2026-02-01',
            'quantity' => 153,
        ]);
        $this->product('MP-SIGNED-PRESENT', 1);
        $this->provider = ['MP-SIGNED-PRESENT' => [3, 3]];
        $service = app(MorePlusInventorySyncService::class);
        $preview = $service->preview($this->warehouse);
        self::assertSame(1, $preview['absent_count']);
        self::assertSame(153, $preview['absent_qoh']);
        self::assertTrue($preview['absent_zero_eligible']);
        $service->syncProvider($this->warehouse, null);
        $row = WarehouseInventory::where('product_id', $old->id)->firstOrFail();
        self::assertSame(0, $row->quantity_on_hand);
        self::assertSame(0, $row->provider_available_quantity);
        self::assertSame(2, $row->quantity_reserved);
        self::assertSame(153, WarehouseInventoryLot::query()->where('product_id', $old->id)->sum('quantity'));
    }

    public function test_unverified_provider_identity_prevents_absent_erp_zero(): void
    {
        $old = $this->product('MP-MUST-NOT-ZERO', 11);
        $this->provider = ['UNVERIFIED-PROVIDER-SKU' => [2, 2]];
        $preview = app(MorePlusInventorySyncService::class)->preview($this->warehouse);
        self::assertSame(1, $preview['blocked']);
        self::assertFalse($preview['absent_zero_eligible']);
        app(MorePlusInventorySyncService::class)->syncProvider($this->warehouse, null);
        self::assertSame(11, WarehouseInventory::where('product_id', $old->id)->value('quantity_on_hand'));
    }

    public function test_empty_provider_stock_snapshot_fails_closed_without_zeroing_erp(): void
    {
        $old = $this->product('MP-PRESERVE-IF-EMPTY', 22);
        $this->provider = [];
        try {
            app(MorePlusInventorySyncService::class)->syncProvider($this->warehouse, null);
            self::fail('Empty provider results should not bulk zero the ERP.');
        } catch (RuntimeException $error) {
            self::assertSame('MOREPLUS_PROVIDER_STOCK_EMPTY_REVIEW_REQUIRED', $error->getMessage());
        }
        self::assertSame(22, WarehouseInventory::where('product_id', $old->id)->value('quantity_on_hand'));
    }

    public function test_one_click_sync_ignores_old_apply_flag_and_uses_only_provider_get(): void
    {
        $p = $this->product('MP-LIVE-SYNC', 100, 0);
        WarehouseInventoryLot::query()->create([
            'warehouse_id' => $this->warehouse->id, 'product_id' => $p->id,
            'stock_status' => 'sellable', 'putaway_date' => '2026-10-01', 'quantity' => 100,
        ]);
        $this->provider = ['MP-LIVE-SYNC' => [7, 6]];
        $operator = $this->userWith(['inventory.view', 'inventory.operate']);
        config()->set('shipout.production_inventory_apply_enabled', false);
        $this->actingAs($operator)->withHeaders(['Sec-Fetch-Site' => 'same-origin'])->post('/inventory/us-la/provider-stock/sync')
            ->assertRedirect()->assertSessionHas('success');
        $local = WarehouseInventory::query()->where('product_id', $p->id)->firstOrFail();
        self::assertSame(7, $local->quantity_on_hand);
        self::assertSame(6, $local->quantity_available);
        self::assertSame(100, WarehouseInventoryLot::query()->where('product_id', $p->id)->sum('quantity'));
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request->method() === 'GET');
    }

    public function test_manual_stock_write_is_rejected_for_external_moreplus_warehouse(): void
    {
        $p = $this->product('MP-NO-MANUAL', 7);
        $item = WarehouseInventory::query()->where('product_id', $p->id)->firstOrFail();
        $operator = $this->userWith(['inventory.view', 'inventory.operate']);
        $this->actingAs($operator)->withHeaders(['Sec-Fetch-Site' => 'same-origin'])->patch('/inventory/us-la/stock/'.$item->id.'/cell', [
            'field' => 'quantity_on_hand', 'value' => 88,
            'expected_updated_at' => $item->updated_at->toIso8601String(),
        ])->assertStatus(422);
        self::assertSame(7, $item->fresh()->quantity_on_hand);
    }

    public function test_source_explicit_zero_overwrites_positive_erp_stock(): void
    {
        $p = $this->product('MP-PROVIDER-ZERO', 40);
        $this->provider = ['MP-PROVIDER-ZERO' => [0, 0]];
        app(MorePlusInventorySyncService::class)->syncProvider($this->warehouse, null);
        $row = WarehouseInventory::query()->where('product_id', $p->id)->firstOrFail();
        self::assertSame(0, $row->quantity_on_hand);
        self::assertSame(0, $row->quantity_available);
    }

    public function test_provider_available_change_without_physical_change_is_mirrored(): void
    {
        $p = $this->product('MP-PROVIDER-HOLD-CHANGE', 10);
        $service = app(MorePlusInventorySyncService::class);
        $this->provider = ['MP-PROVIDER-HOLD-CHANGE' => [10, 9]];
        $service->syncProvider($this->warehouse, null);
        $this->provider = ['MP-PROVIDER-HOLD-CHANGE' => [10, 4]];
        $result = $service->syncProvider($this->warehouse, null);
        self::assertSame(1, $result['updated']);
        $row = WarehouseInventory::query()->where('product_id', $p->id)->firstOrFail();
        self::assertSame(10, $row->quantity_on_hand);
        self::assertSame(4, $row->quantity_available);
    }

    public function test_generic_import_cannot_replace_provider_authoritative_moreplus(): void
    {
        $this->product('MP-CANNOT-MANUAL', 7);
        $operator = $this->userWith(['inventory.view', 'inventory.operate']);
        $this->actingAs($operator)->withHeaders(['Sec-Fetch-Site' => 'same-origin'])
            ->postJson('/inventory/sync/preview', [
                'warehouse_id' => $this->warehouse->id,
                'source_type' => 'paste',
                'sync_mode' => 'partial',
                'paste_text' => "SKU\tQty\nMP-CANNOT-MANUAL\t99",
            ])->assertStatus(422);
        self::assertSame(7, WarehouseInventory::query()->where('product_id', Product::where('sku', 'MP-CANNOT-MANUAL')->value('id'))->value('quantity_on_hand'));
    }

    public function test_last_read_cache_records_successful_get_without_changing_main_inventory(): void
    {
        $p = $this->product('MP-REFERENCE-GET', 35);
        $this->provider = ['MP-REFERENCE-GET' => [80, 78]];
        $result = app(MorePlusInventorySyncService::class)->previewWithLastRead($this->warehouse);
        self::assertSame(1, $result['ready']);
        $ref = DB::table('warehouse_stock_read_references')->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $p->id)->first();
        self::assertSame(80, (int)$ref->last_read_raw_qty);
        self::assertSame(80, (int)$ref->last_read_stock_qty);
        self::assertSame('api_read', $ref->last_read_stock_source);
        self::assertSame('read_success', $ref->last_read_stock_status);
        self::assertSame(35, WarehouseInventory::query()->where('product_id',$p->id)->value('quantity_on_hand'));
        Http::assertSentCount(3);
        Http::assertSent(fn ($request)=>$request->method()==='GET');
    }

    public function test_read_is_persisted_when_main_erp_transaction_fails_and_next_read_error_preserves_it(): void
    {
        $p = $this->product('MP-REFERENCE-FAIL', 35);
        $this->provider = ['MP-REFERENCE-FAIL' => [80, 79]];
        $audit = \Mockery::mock(AuditRecorder::class);
        $audit->shouldReceive('record')->andThrow(new RuntimeException('SYNTHETIC_ERP_TRANSACTION_ERROR'));
        $service = new MorePlusInventorySyncService(
            app(ShipOutProductionInventoryAudit::class), $audit,
            app(WarehouseLastReadStockService::class)
        );
        try {
            $service->syncProvider($this->warehouse, null);
            self::fail('ERP mirror must fail in this synthetic test');
        } catch (RuntimeException $e) {
            self::assertSame('SYNTHETIC_ERP_TRANSACTION_ERROR', $e->getMessage());
        }
        $ref = DB::table('warehouse_stock_read_references')->where('product_id', $p->id)->first();
        self::assertSame(80, (int)$ref->last_read_stock_qty);
        self::assertSame('read_success_apply_failed', $ref->last_read_stock_status);
        self::assertSame(35, WarehouseInventory::query()->where('product_id',$p->id)->value('quantity_on_hand'));
        $this->provider = [];
        try {
            app(MorePlusInventorySyncService::class)->syncProvider($this->warehouse, null);
            self::fail('Empty GET should not zero last-read cache');
        } catch (RuntimeException $e) {
            self::assertSame('MOREPLUS_PROVIDER_STOCK_EMPTY_REVIEW_REQUIRED', $e->getMessage());
        }
        $last = DB::table('warehouse_stock_read_references')->where('product_id', $p->id)->first();
        self::assertSame(80, (int)$last->last_read_raw_qty);
        self::assertSame(80, (int)$last->last_read_stock_qty);
        self::assertNotNull($last->last_read_stock_at);
        self::assertSame('read_failed_last_known', $last->last_read_stock_status);
        self::assertSame(['source_read', 'erp_mirror_result', 'source_read_failed'],
            DB::table('warehouse_stock_read_events')->orderBy('id')->pluck('event_type')->all());
    }

    public function test_signed_read_success_followed_by_real_outbound_is_idempotent_then_rebases(): void
    {
        $p = $this->product('MP-REFERENCE-SHIP', 15);
        $lastRead = app(WarehouseLastReadStockService::class);
        $originalTime = CarbonImmutable::getTestNow();
        try {
            CarbonImmutable::setTestNow('2026-11-14 10:00:00');
            $this->provider = ['MP-REFERENCE-SHIP' => [80,80]];
            app(MorePlusInventorySyncService::class)->previewWithLastRead($this->warehouse);
            $proofAt = CarbonImmutable::parse('2026-11-14 10:01:00','UTC');
            $lines = [$p->id=>['product_id'=>$p->id,'provider_sku'=>'MP-REFERENCE-SHIP','quantity'=>3]];
            $first = $lastRead->recordVerifiedLines($this->warehouse,$lines,'OMS-123','OUT-123',$proofAt);
            self::assertSame(1,$first['applied']);
            self::assertSame(77, (int)DB::table('warehouse_stock_read_references')->where('product_id',$p->id)->value('last_read_stock_qty'));
            $again = $lastRead->recordVerifiedLines($this->warehouse,$lines,'OMS-123','OUT-123',$proofAt);
            self::assertSame(1,$again['skipped']);
            self::assertSame(77, (int)DB::table('warehouse_stock_read_references')->where('product_id',$p->id)->value('last_read_stock_qty'));
            CarbonImmutable::setTestNow('2026-11-14 10:05:00');
            $this->provider = ['MP-REFERENCE-SHIP' => [75,75]];
            app(MorePlusInventorySyncService::class)->previewWithLastRead($this->warehouse);
            self::assertSame(75,(int)DB::table('warehouse_stock_read_references')->where('product_id',$p->id)->value('last_read_stock_qty'));
            self::assertSame(0,(int)DB::table('warehouse_stock_read_references')->where('product_id',$p->id)->value('post_read_adjustment_qty'));
            self::assertSame(15,WarehouseInventory::where('product_id',$p->id)->value('quantity_on_hand'));
            $late = $lastRead->recordVerifiedLines($this->warehouse,
                [$p->id=>['product_id'=>$p->id,'provider_sku'=>'MP-REFERENCE-SHIP','quantity'=>2]],
                'OMS-OLDER','OUT-OLDER',CarbonImmutable::parse('2026-11-14 10:03:00','UTC'));
            self::assertSame(1,$late['review']);
            self::assertSame(75,(int)DB::table('warehouse_stock_read_references')->where('product_id',$p->id)->value('last_read_stock_qty'));
            self::assertSame(2,DB::table('warehouse_stock_read_adjustments')->count());
        } finally {
            CarbonImmutable::setTestNow($originalTime);
        }
    }

    public function test_reference_overdraw_is_held_for_review_never_negative(): void
    {
        $p = $this->product('MP-REFERENCE-LOW', 10);
        $this->provider = ['MP-REFERENCE-LOW' => [2, 2]];
        app(MorePlusInventorySyncService::class)->previewWithLastRead($this->warehouse);
        $ref = DB::table('warehouse_stock_read_references')->where('product_id',$p->id)->first();
        $proofAt = CarbonImmutable::parse($ref->last_read_stock_at,'UTC')->addMinute();
        $result = app(WarehouseLastReadStockService::class)->recordVerifiedLines($this->warehouse,
            [$p->id=>['product_id'=>$p->id,'provider_sku'=>'MP-REFERENCE-LOW','quantity'=>3]],
            'OMS-OVER','OUT-OVER',$proofAt);
        self::assertSame(1,$result['review']);
        self::assertSame(2,(int)DB::table('warehouse_stock_read_references')->where('product_id',$p->id)->value('last_read_stock_qty'));
        self::assertSame('needs_review',DB::table('warehouse_stock_read_adjustments')->value('outcome'));
    }

    public function test_last_read_column_payload_stays_separate_from_standard_qoh(): void
    {
        $p = $this->product('MP-REFERENCE-DISPLAY', 12);
        $this->provider = ['MP-REFERENCE-DISPLAY' => [28, 26]];
        app(MorePlusInventorySyncService::class)->previewWithLastRead($this->warehouse);
        $operator=$this->userWith(['inventory.view']);
        $this->actingAs($operator)->get('/inventory/us-la')
            ->assertOk()->assertInertia(fn(Assert $page)=>$page
                ->component('Inventory/WarehouseShow')
                ->where('warehouse.inventories.0.last_read.quantity', 28)
                ->where('warehouse.inventories.0.quantity_on_hand',12)
                ->etc());
    }

    public function test_ambiguous_provider_aliases_keep_raw_read_evidence_without_associating_wrong_product(): void
    {
        $p = $this->product('MP-DUPLICATE-CANONICAL', 17);
        app(WarehouseLastReadStockService::class)->captureMorePlus($this->warehouse, [
            'stock_rows' => [
                ['provider_sku'=>'MP-ALIAS-A', 'canonical_sku'=>'MP-DUPLICATE-CANONICAL',
                    'sku_alias_status'=>'verified', 'standard_quantity'=>22,'available_quantity'=>22],
                ['provider_sku'=>'MP-ALIAS-B', 'canonical_sku'=>'MP-DUPLICATE-CANONICAL',
                    'sku_alias_status'=>'verified', 'standard_quantity'=>12,'available_quantity'=>12],
            ],
        ], CarbonImmutable::now('UTC'));
        self::assertSame(2, DB::table('warehouse_stock_read_references')->count());
        self::assertSame(0, DB::table('warehouse_stock_read_references')->whereNotNull('product_id')->count());
        self::assertSame(34, (int)DB::table('warehouse_stock_read_references')->sum('last_read_raw_qty'));
        self::assertSame(17, WarehouseInventory::where('product_id',$p->id)->value('quantity_on_hand'));
        self::assertSame(2, DB::table('warehouse_stock_read_events')->where('status','sku_unverified')->count());
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

    public function test_verified_provider_stock_restores_archived_erp_mirror_without_duplicate(): void
    {
        $product = $this->product('MP-REMOVED', 3);
        WarehouseInventory::query()->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $product->id)->firstOrFail()->delete();
        $this->provider = ['MP-REMOVED' => [5, 5]];
        $preview = app(MorePlusInventorySyncService::class)->preview($this->warehouse);
        self::assertSame(0, $preview['blocked']);
        self::assertSame(1, $preview['ready']);
        $result = app(MorePlusInventorySyncService::class)->syncProvider($this->warehouse, null);
        self::assertSame(1, $result['updated']);
        $inventory = WarehouseInventory::query()->where('product_id', $product->id)->firstOrFail();
        self::assertSame(5, $inventory->quantity_on_hand);
        self::assertSame(1, WarehouseInventory::withTrashed()->where('product_id', $product->id)->count());
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
            $audit,
            app(WarehouseLastReadStockService::class)
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

    private function fifoProduct(string $sku, int $current, int $physical, int $available, int $reserved = 0): Product
    {
        $product = $this->product($sku, $current, $reserved);
        $this->provider[$sku] = [$physical, $available];
        WarehouseInventoryLot::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $product->id, 'stock_status' => 'sellable',
            'putaway_date' => '2026-01-06', 'quantity' => $current - 4,
        ]);
        WarehouseInventoryLot::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $product->id, 'stock_status' => 'sellable',
            'putaway_date' => '2026-02-12', 'quantity' => 4,
        ]);

        return $product;
    }

    public function test_fifo_preview_plans_oldest_lot_only_and_does_not_write(): void
    {
        $p = $this->fifoProduct('MP-FIFO-01', 10, 7, 7);
        $service = app(MorePlusFifoReconciliationService::class);
        $preview = $service->preview($this->warehouse);
        self::assertSame(1, $preview['ready_fifo']);
        self::assertSame(3, $preview['ready_units']);
        self::assertSame(0, $preview['blocked']);
        self::assertFalse($preview['apply_enabled']);
        self::assertSame('2026-01-06', $preview['rows'][0]['lot_changes'][0]['date']);
        self::assertSame(3, $preview['rows'][0]['lot_changes'][0]['after']);
        self::assertSame(10, WarehouseInventory::query()->where('product_id', $p->id)->value('quantity_on_hand'));
        self::assertSame(10, WarehouseInventoryLot::query()->where('product_id', $p->id)->sum('quantity'));
    }

    public function test_fifo_reconciliation_requires_a_second_separate_apply_switch(): void
    {
        $this->fifoProduct('MP-FIFO-02', 10, 7, 7);
        $service = app(MorePlusFifoReconciliationService::class);
        $preview = $service->preview($this->warehouse);
        config()->set('shipout.production_inventory_apply_enabled', true);
        $this->expectExceptionMessage('MOREPLUS_FIFO_ACCOUNTING_ONLY');
        $service->apply($this->warehouse, $preview['checksum'], null);
    }

    public function test_fifo_accounting_apply_cannot_rewrite_historical_lots_even_with_flags(): void
    {
        $p = $this->fifoProduct('MP-FIFO-03', 10, 7, 7);
        $aging = WarehouseStorageAgingAccrual::query()->create([
            'warehouse_id' => $this->warehouse->id, 'product_id' => $p->id,
            'stock_status' => 'sellable', 'putaway_date' => '2026-01-06',
            'aging_month_index' => 1, 'cycle_started_on' => '2026-04-07',
            'quantity_snapshot' => 6, 'unit_rate_cny' => 1, 'amount_cny' => 6,
            'source_reference' => 'fifo-uat-aging-03', 'accrual_as_of' => '2026-04-07',
            'is_backfill' => true,
        ]);
        $before = $aging->fresh()->getAttributes();
        config()->set('shipout.production_inventory_apply_enabled', true);
        config()->set('shipout.production_fifo_apply_enabled', true);
        config()->set('shipout.production_fifo_approved_skus', ['MP-FIFO-03']);
        config()->set('shipout.production_fifo_max_units', 999);
        $service = app(MorePlusFifoReconciliationService::class);
        $preview = $service->preview($this->warehouse);
        self::assertFalse($preview['apply_enabled']);
        try {
            $service->apply($this->warehouse, $preview['checksum'], null);
            self::fail('Accounting-only FIFO must be a hard stop.');
        } catch (RuntimeException $error) {
            self::assertSame('MOREPLUS_FIFO_ACCOUNTING_ONLY', $error->getMessage());
        }
        self::assertSame(10, WarehouseInventory::query()->where('product_id', $p->id)->value('quantity_on_hand'));
        self::assertSame([6, 4], WarehouseInventoryLot::query()->where('product_id', $p->id)->orderBy('putaway_date')->pluck('quantity')->all());
        self::assertSame($before, $aging->fresh()->getAttributes());
        $this->assertDatabaseMissing('audit_records', ['action' => 'inventory.moreplus_fifo_reconciled']);
    }

    public function test_fifo_excludes_provider_holds_and_erp_reserved_rows(): void
    {
        $a = $this->fifoProduct('MP-FIFO-HOLD', 10, 7, 6);
        $b = $this->fifoProduct('MP-FIFO-RESERVED', 10, 7, 6, 1);
        $preview = app(MorePlusFifoReconciliationService::class)->preview($this->warehouse);
        self::assertSame(0, $preview['ready_fifo']);
        self::assertSame(2, $preview['blocked']);
        self::assertSame(0, $preview['ready_units']);
        self::assertSame(10, WarehouseInventory::query()->where('product_id', $a->id)->value('quantity_on_hand'));
        self::assertSame(10, WarehouseInventory::query()->where('product_id', $b->id)->value('quantity_on_hand'));
    }

    public function test_fifo_refuses_provider_drift_after_preview_before_apply(): void
    {
        $p = $this->fifoProduct('MP-FIFO-DRIFT', 10, 7, 7);
        $service = app(MorePlusFifoReconciliationService::class);
        $preview = $service->preview($this->warehouse);
        $this->provider['MP-FIFO-DRIFT'] = [6, 6];
        config()->set('shipout.production_inventory_apply_enabled', true);
        config()->set('shipout.production_fifo_apply_enabled', true);
        $this->expectExceptionMessage('MOREPLUS_FIFO_ACCOUNTING_ONLY');
        $service->apply($this->warehouse, $preview['checksum'], null);
    }

    public function test_fifo_refuses_lot_drift_after_preview_before_apply(): void
    {
        $p = $this->fifoProduct('MP-FIFO-LOT-DRIFT', 10, 7, 7);
        $service = app(MorePlusFifoReconciliationService::class);
        $preview = $service->preview($this->warehouse);
        WarehouseInventoryLot::query()->where('product_id', $p->id)->orderBy('id')->firstOrFail()->update(['quantity' => 5]);
        config()->set('shipout.production_inventory_apply_enabled', true);
        config()->set('shipout.production_fifo_apply_enabled', true);
        $this->expectExceptionMessage('MOREPLUS_FIFO_ACCOUNTING_ONLY');
        $service->apply($this->warehouse, $preview['checksum'], null);
    }

    public function test_fifo_apply_cannot_call_audit_or_change_inventory(): void
    {
        $p = $this->fifoProduct('MP-FIFO-ROLLBACK', 10, 7, 7);
        $audit = \Mockery::mock(AuditRecorder::class);
        $audit->shouldReceive('record')->never();
        $service = new MorePlusFifoReconciliationService(app(MorePlusInventorySyncService::class), $audit);
        $preview = $service->preview($this->warehouse);
        config()->set('shipout.production_inventory_apply_enabled', true);
        config()->set('shipout.production_fifo_apply_enabled', true);
        try {
            $service->apply($this->warehouse, $preview['checksum'], null);
            self::fail('FIFO is accounting-only.');
        } catch (RuntimeException $error) {
            self::assertSame('MOREPLUS_FIFO_ACCOUNTING_ONLY', $error->getMessage());
        }
        self::assertSame(10, WarehouseInventory::query()->where('product_id', $p->id)->value('quantity_on_hand'));
        self::assertSame(10, WarehouseInventoryLot::query()->where('product_id', $p->id)->sum('quantity'));
    }

    public function test_authorized_warehouse_preview_includes_fifo_plan_without_extra_provider_calls(): void
    {
        $this->fifoProduct('MP-FIFO-HTTP', 10, 7, 7);
        $operator = $this->userWith(['inventory.view', 'inventory.operate']);
        $this->actingAs($operator)->withHeaders(['Sec-Fetch-Site' => 'same-origin'])
            ->post('/inventory/us-la/provider-stock/preview')->assertRedirect();
        $stock = session('inventory.moreplus_provider_preview.'.$this->warehouse->id);
        $fifo = session('inventory.moreplus_fifo_preview.'.$this->warehouse->id);
        self::assertSame(0, $stock['blocked']);
        self::assertSame(1, $stock['ready']);
        self::assertSame(1, $fifo['ready_fifo']);
        self::assertSame(3, $fifo['ready_units']);
        Http::assertSentCount(3);
        $this->get('/inventory/us-la')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Inventory/WarehouseShow')
                ->where('moreplusFifoPreview.ready_fifo', 1)
                ->where('moreplusFifoPreview.ready_units', 3)
                ->where('moreplusFifoPreview.apply_enabled', false)
        );
        // The result is for the immediate redirected GET only, not a sticky
        // Session panel on every future warehouse page visit.
        $this->get('/inventory/us-la')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Inventory/WarehouseShow')
                ->where('moreplusInventoryPreview', null)
                ->where('moreplusFifoPreview', null)
        );
        self::assertSame(10, WarehouseInventory::query()->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', Product::query()->where('sku', 'MP-FIFO-HTTP')->value('id'))
            ->value('quantity_on_hand'));
    }

    public function test_fifo_does_not_apply_even_with_flags_without_owner_approved_sku_scope(): void
    {
        $p = $this->fifoProduct('MP-FIFO-NO-SCOPE', 10, 7, 7);
        config()->set('shipout.production_inventory_apply_enabled', true);
        config()->set('shipout.production_fifo_apply_enabled', true);
        $service = app(MorePlusFifoReconciliationService::class);
        $preview = $service->preview($this->warehouse);
        self::assertFalse($preview['apply_enabled']);
        $this->expectExceptionMessage('MOREPLUS_FIFO_ACCOUNTING_ONLY');
        $service->apply($this->warehouse, $preview['checksum'], null);
    }

    public function test_fifo_owner_sku_allowlist_and_max_unit_ceiling_are_enforced(): void
    {
        $this->fifoProduct('MP-FIFO-CAPPED', 10, 7, 7);
        config()->set('shipout.production_inventory_apply_enabled', true);
        config()->set('shipout.production_fifo_apply_enabled', true);
        config()->set('shipout.production_fifo_approved_skus', ['MP-FIFO-CAPPED']);
        config()->set('shipout.production_fifo_max_units', 2);
        $service = app(MorePlusFifoReconciliationService::class);
        $preview = $service->preview($this->warehouse);
        self::assertFalse($preview['apply_enabled']);
        $this->expectExceptionMessage('MOREPLUS_FIFO_ACCOUNTING_ONLY');
        $service->apply($this->warehouse, $preview['checksum'], null);
    }

    public function test_fifo_owner_approved_sku_list_must_cover_every_candidate(): void
    {
        $this->fifoProduct('MP-FIFO-OUTSIDE-SCOPE', 10, 7, 7);
        config()->set('shipout.production_inventory_apply_enabled', true);
        config()->set('shipout.production_fifo_apply_enabled', true);
        config()->set('shipout.production_fifo_approved_skus', ['DIFFERENT-SKU']);
        config()->set('shipout.production_fifo_max_units', 100);
        $service = app(MorePlusFifoReconciliationService::class);
        $preview = $service->preview($this->warehouse);
        self::assertFalse($preview['apply_enabled']);
        $this->expectExceptionMessage('MOREPLUS_FIFO_ACCOUNTING_ONLY');
        $service->apply($this->warehouse, $preview['checksum'], null);
    }

    public function test_fifo_batch_is_readonly_for_multiple_skus_even_when_owner_flags_enabled(): void
    {
        $a = $this->fifoProduct('MP-FIFO-ATOMIC-A', 10, 7, 7);
        $b = $this->fifoProduct('MP-FIFO-ATOMIC-B', 10, 7, 7);
        $audit = \Mockery::mock(AuditRecorder::class);
        $audit->shouldReceive('record')->never();
        $service = new MorePlusFifoReconciliationService(app(MorePlusInventorySyncService::class), $audit);
        $preview = $service->preview($this->warehouse);
        config()->set('shipout.production_inventory_apply_enabled', true);
        config()->set('shipout.production_fifo_apply_enabled', true);
        config()->set('shipout.production_fifo_approved_skus', ['MP-FIFO-ATOMIC-A', 'MP-FIFO-ATOMIC-B']);
        config()->set('shipout.production_fifo_max_units', 6);
        try {
            $service->apply($this->warehouse, $preview['checksum'], null);
            self::fail('FIFO is accounting-only.');
        } catch (RuntimeException $error) {
            self::assertSame('MOREPLUS_FIFO_ACCOUNTING_ONLY', $error->getMessage());
        }
        foreach ([$a, $b] as $product) {
            self::assertSame(10, WarehouseInventory::query()->where('product_id', $product->id)->value('quantity_on_hand'));
            self::assertSame(10, WarehouseInventoryLot::query()->where('product_id', $product->id)->sum('quantity'));
        }
    }

    public function test_previous_persistent_preview_session_is_not_shown_without_a_new_click(): void
    {
        $operator = $this->userWith(['inventory.view', 'inventory.operate']);
        session([
            'inventory.moreplus_provider_preview.'.$this->warehouse->id => ['ready' => 99],
            'inventory.moreplus_fifo_preview.'.$this->warehouse->id => ['ready_fifo' => 99],
        ]);
        $this->actingAs($operator)->get('/inventory/us-la')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Inventory/WarehouseShow')
                ->where('moreplusInventoryPreview', null)
                ->where('moreplusFifoPreview', null)
        );
    }
}

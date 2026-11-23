<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Logistics\Application\Xlwms\XlwmsInventoryGateway;
use App\Modules\Logistics\Application\Xlwms\XlwmsInventorySyncService;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Sync\Experience\Jobs\RunDeTianyunWarehouseSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class DeTianyunSkuSyncV2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->withoutMiddleware(ThrottleRequests::class);
        config()->set('xlwms.de_manual_sync_enabled', true);
    }

    public function test_read_only_unmatched_and_catalog_missing_are_distinct_without_stock_writes(): void
    {
        Queue::fake();
        $de = $this->warehouse();
        $this->connection($de);
        $actor = $this->actor();
        $this->fakeOms(['DE-NOT-IN-ERP' => 4], ['DE-CATALOG-ONLY']);
        $id = $this->start($de, $actor);
        $this->execute($id);
        $status = $this->actingAs($actor)->getJson($this->statusUrl($de, $id))->assertOk();
        $status->assertJsonPath('status', 'blocked')
            ->assertJsonPath('outcome', 'preview')
            ->assertJsonPath('successful_sku_count', 0)
            ->assertJsonPath('failed_sku_count', 1)
            ->assertJsonPath('missing_sku_count', 1)
            ->assertJsonPath('erp_stock_updated', false);
        $detail = $this->getJson("/sync/warehouses/{$de->id}/sync-details?status=all")
            ->assertOk()->assertJsonCount(2, 'rows')
            ->assertJsonPath('latest_run.outcome', 'preview');
        self::assertSame(0, WarehouseInventory::query()->where('warehouse_id', $de->id)->count());
        self::assertSame(1, DB::table('xlwms_inventory_manual_run_rows')
            ->where('run_id', $id)->where('outcome', 'unmatched')->count());
        self::assertSame(1, DB::table('xlwms_inventory_manual_run_rows')
            ->where('run_id', $id)->where('outcome', 'missing')->count());
    }

    public function test_partial_apply_and_resume_only_unfinished_skus_with_verified_readback(): void
    {
        Queue::fake();
        $de = $this->warehouse();
        $us = Warehouse::query()->where('code', 'US-SM')->firstOrFail();
        $this->connection($de);
        $actor = $this->actor();
        $skuA = 'DE-V2-READY-A';
        $skuB = 'DE-V2-NEEDS-MAPPING-B';
        $productA = Product::query()->create(['sku' => $skuA, 'name' => 'Synthetic A']);
        $this->stock($de, $productA, 5);
        $this->stock($us, $productA, 21);
        $this->fakeOms([$skuA => 8, $skuB => 4], ['DE-CATALOG-NO-LEDGER']);
        $this->enableApply();
        $preview = app(XlwmsInventorySyncService::class)->sync(false, 'DE');
        config()->set('xlwms.de_inventory_first_apply_approved_checksum', $preview['checksum']);

        $firstId = $this->start($de, $actor);
        $this->execute($firstId);
        $this->getJson($this->statusUrl($de, $firstId))
            ->assertOk()->assertJsonPath('status', 'partial_applied')
            ->assertJsonPath('outcome', 'partial_success')
            ->assertJsonPath('successful_sku_count', 1)
            ->assertJsonPath('updated_sku_count', 1)
            ->assertJsonPath('failed_sku_count', 1)
            ->assertJsonPath('missing_sku_count', 1);
        self::assertSame(8, $this->qoh($de, $productA));
        self::assertSame(21, $this->qoh($us, $productA));
        $a = DB::table('xlwms_inventory_manual_run_rows')
            ->where('run_id', $firstId)->where('provider_sku', $skuA)->first();
        self::assertSame('updated', $a->outcome);
        self::assertSame(5, (int) $a->erp_before);
        self::assertSame(8, (int) $a->erp_after);
        self::assertSame(3, (int) $a->delta);
        self::assertSame(1, (int) $a->write_verified);

        // Continue without mapping: source is refreshed, successful A is
        // verified but not written again; B stays pending.
        $retryId = $this->resume($de, $actor, $firstId);
        $this->execute($retryId);
        $this->getJson($this->statusUrl($de, $retryId))
            ->assertOk()->assertJsonPath('outcome', 'partial_success')
            ->assertJsonPath('updated_sku_count', 0)
            ->assertJsonPath('unchanged_sku_count', 1)
            ->assertJsonPath('failed_sku_count', 1)
            ->assertJsonPath('resume_from_run_id', $firstId);
        self::assertSame(8, $this->qoh($de, $productA));

        // Existing ERP master product is now explicitly available. No
        // product is created by the sync implementation.
        $productB = Product::query()->create(['sku' => $skuB, 'name' => 'Synthetic B existing master']);
        $this->stock($de, $productB, 0); // Existing DE warehouse inventory identity, not auto-created by sync.
        $retry2Id = $this->resume($de, $actor, $retryId);
        $this->execute($retry2Id);
        $this->getJson($this->statusUrl($de, $retry2Id))
            ->assertOk()->assertJsonPath('outcome', 'success')
            ->assertJsonPath('successful_sku_count', 2)
            ->assertJsonPath('updated_sku_count', 1)
            ->assertJsonPath('unchanged_sku_count', 1)
            ->assertJsonPath('failed_sku_count', 0)
            ->assertJsonPath('missing_sku_count', 1);
        self::assertSame(8, $this->qoh($de, $productA));
        self::assertSame(4, $this->qoh($de, $productB));
        self::assertSame(21, $this->qoh($us, $productA));
        $refreshId = $this->start($de, $actor);
        $this->execute($refreshId);
        $this->getJson($this->statusUrl($de, $refreshId))
            ->assertOk()->assertJsonPath('outcome', 'success')
            ->assertJsonPath('successful_sku_count', 2)
            ->assertJsonPath('updated_sku_count', 0)
            ->assertJsonPath('unchanged_sku_count', 2);
        self::assertSame(8, $this->qoh($de, $productA));
        self::assertSame(4, $this->qoh($de, $productB));
        $this->postJson("/sync/warehouses/{$de->id}/sync/resume", [
            'resume_from_run_id' => $retry2Id,
        ])->assertStatus(409)->assertJsonPath('error_code', 'XLWMS_DE_RESUME_NOT_NEEDED');
    }

    public function test_failed_apply_and_restricted_resume_do_not_bypass_warehouse_rules(): void
    {
        Queue::fake();
        $de = $this->warehouse();
        $other = Warehouse::query()->where('code', 'US-SM')->firstOrFail();
        $this->connection($de);
        $actor = $this->actor();
        $this->fakeOms(['DE-UNKNOWN' => 10]);
        $this->enableApply();
        $preview = app(XlwmsInventorySyncService::class)->sync(false, 'DE');
        config()->set('xlwms.de_inventory_first_apply_approved_checksum', $preview['checksum']);
        $id = $this->start($de, $actor);
        $this->execute($id);
        $this->getJson($this->statusUrl($de, $id))
            ->assertOk()->assertJsonPath('outcome', 'failed')
            ->assertJsonPath('successful_sku_count', 0)
            ->assertJsonPath('failed_sku_count', 1);
        $this->postJson("/sync/warehouses/{$other->id}/sync/resume", [
            'resume_from_run_id' => $id,
        ])->assertNotFound();
        $this->postJson("/sync/warehouses/{$de->id}/sync/resume", [
            'resume_from_run_id' => 9999,
        ])->assertStatus(409)->assertJsonPath('error_code', 'XLWMS_DE_RESUME_TARGET_INVALID');
        config()->set('xlwms.de_inventory_dev_apply_enabled', false);
        $retry = $this->resume($de, $actor, $id);
        $this->execute($retry);
        $this->getJson($this->statusUrl($de, $retry))
            ->assertOk()->assertJsonPath('mode', 'read_only')
            ->assertJsonPath('outcome', 'preview')
            ->assertJsonPath('erp_stock_updated', false);
        self::assertSame(0, WarehouseInventory::query()->where('warehouse_id', $de->id)->count());
    }

    public function test_continue_cannot_mutate_a_previously_successful_sku_whose_oms_stock_changed(): void
    {
        Queue::fake();
        $de = $this->warehouse();
        $this->connection($de);
        $actor = $this->actor();
        $skuA = 'DE-V2-PROTECTED-A';
        $skuB = 'DE-V2-UNKNOWN-B';
        $product = Product::query()->create(['sku' => $skuA, 'name' => 'Synthetic protected A']);
        $this->stock($de, $product, 5);
        $this->enableApply();
        $this->fakeOms([$skuA => 8, $skuB => 4]);
        $preview = app(XlwmsInventorySyncService::class)->sync(false, 'DE');
        config()->set('xlwms.de_inventory_first_apply_approved_checksum', $preview['checksum']);
        $firstId = $this->start($de, $actor);
        $this->execute($firstId);
        $this->getJson($this->statusUrl($de, $firstId))
            ->assertJsonPath('outcome', 'partial_success')
            ->assertJsonPath('updated_sku_count', 1);
        self::assertSame(8, $this->qoh($de, $product));

        // Provider inventory changed since last success. "Continue" must
        // not treat that change as an unfinished SKU and write it.
        $this->fakeOms([$skuA => 9, $skuB => 4]);
        $retryId = $this->resume($de, $actor, $firstId);
        $this->execute($retryId);
        self::assertSame(8, $this->qoh($de, $product));
        $this->getJson($this->statusUrl($de, $retryId))
            ->assertOk()->assertJsonPath('updated_sku_count', 0)
            ->assertJsonPath('failed_sku_count', 2)
            ->assertJsonPath('outcome', 'failed');
        self::assertSame('OMS_RESUME_PREVIOUS_SUCCESS_STOCK_CHANGED',
            DB::table('xlwms_inventory_manual_run_rows')
                ->where('run_id', $retryId)->where('provider_sku', $skuA)
                ->value('error_code'));
    }

    private function warehouse(): Warehouse
    {
        return Warehouse::query()->where('code', 'DE')->firstOrFail();
    }
    private function connection(Warehouse $de): void
    {
        DB::table('warehouse_openapi_connections')->insert([
            'warehouse_id' => $de->id, 'provider' => 'xlwms',
            'app_key_encrypted' => 'TEST_ONLY_NOT_REAL',
            'app_secret_encrypted' => 'TEST_ONLY_NOT_REAL_SECRET',
            'oms_warehouse_code' => 'Hannover',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    private function actor(): User
    {
        $role = Role::create(['key' => 'de-v2-operator-'.uniqid(), 'label' => 'DE V2']);
        foreach (['sync.view', 'sync.operate'] as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $actor = User::factory()->create();
        $actor->roles()->sync([$role->id]);
        return $actor;
    }
    private function fakeOms(array $stocks, array $catalogOnly = []): void
    {
        $catalog = array_map(static fn (string $sku): array => [
            'sku' => $sku, 'productName' => 'Synthetic '.$sku,
        ], array_merge(array_keys($stocks), $catalogOnly));
        $inventory = [];
        foreach ($stocks as $sku => $qty) {
            $inventory[] = ['sku' => $sku, 'whCode' => 'Hannover',
                'stockType' => 0, 'productTotalAmount' => $qty,
                'productStockDtl' => ['availableAmount' => $qty, 'lockAmount' => 0,
                    'transportAmount' => 0]];
        }
        $gateway = \Mockery::mock(XlwmsInventoryGateway::class);
        $gateway->shouldReceive('productPage')->andReturn([
            'code' => 200, 'data' => ['records' => $catalog, 'total' => count($catalog),
                'page' => 1, 'pageSize' => 10, 'pages' => 1], 'msg' => 'OK',
        ]);
        $gateway->shouldReceive('integratedInventoryPage')->andReturn([
            'code' => 200, 'data' => ['records' => $inventory, 'total' => count($inventory),
                'page' => 1, 'pageSize' => 10, 'pages' => 1], 'msg' => 'OK',
        ]);
        $this->app->instance(XlwmsInventoryGateway::class, $gateway);
    }
    private function enableApply(): void
    {
        config()->set('xlwms.inventory_apply_enabled', true);
        config()->set('xlwms.de_inventory_dev_apply_enabled', true);
        config()->set('xlwms.lot_reconciliation_v2_enabled', true);
        config()->set('inventory_sku_gate.enabled', true);
    }
    private function stock(Warehouse $wh, Product $product, int $qty): void
    {
        WarehouseInventory::query()->create([
            'warehouse_id' => $wh->id, 'product_id' => $product->id,
            'quantity_on_hand' => $qty, 'quantity_reserved' => 0,
        ]);
    }
    private function qoh(Warehouse $wh, Product $product): ?int
    {
        $value = WarehouseInventory::query()
            ->where('warehouse_id', $wh->id)->where('product_id', $product->id)
            ->value('quantity_on_hand');
        return $value === null ? null : (int) $value;
    }
    private function start(Warehouse $wh, User $actor): int
    {
        $res = $this->actingAs($actor)
            ->postJson("/sync/warehouses/{$wh->id}/sync")->assertStatus(202);
        return (int) $res->json('run_id');
    }
    private function resume(Warehouse $wh, User $actor, int $from): int
    {
        $res = $this->actingAs($actor)->postJson("/sync/warehouses/{$wh->id}/sync/resume", [
            'resume_from_run_id' => $from,
        ])->assertStatus(202);
        return (int) $res->json('run_id');
    }
    private function execute(int $id): void
    {
        $this->app->call([new RunDeTianyunWarehouseSync($id), 'handle']);
    }
    private function statusUrl(Warehouse $wh, int $id): string
    {
        return "/sync/warehouses/{$wh->id}/sync/{$id}";
    }
}

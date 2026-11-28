<?php

namespace Tests\Feature\ProductionStability;

use App\Models\User;
use App\Modules\Backup\Application\LocalDatabaseBackupService;
use App\Modules\Finance\Application\ConfirmedLossProjection;
use App\Modules\Inventory\Application\InventoryAvailabilityProjection;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Inventory\Infrastructure\UsNyStockClock;
use App\Modules\Logistics\Application\FulfillmentAllocationSubmissionService;
use App\Modules\Logistics\Application\Xlwms\UsNyAllocationReservation;
use App\Modules\Logistics\Application\Xlwms\UsNyPhysicalStockSyncService;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsInventorySnapshot;
use App\Modules\Orders\Application\OrderIdentityService;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductWarehouseSku;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class MySql8ProductionCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('BYL_MYSQL_STABILITY_ENABLED') !== '1') {
            $this->markTestSkipped('MySQL 8 Production Stability gate is disabled.');
        }

        $connection = DB::connection();
        $database = $connection->getDatabaseName();
        $expectedDatabase = (string) getenv('BYL_MYSQL_STABILITY_DATABASE');

        $this->assertSame('mysql', $connection->getDriverName());
        $this->assertNotSame('', $expectedDatabase);
        $this->assertSame($expectedDatabase, $database);
        $this->assertMatchesRegularExpression('/^(?:byl_p4_|erp_stability_)[a-z0-9_]+$/', $database);

        $version = (string) $connection->scalar('select version()');
        $this->assertStringStartsWith('8.', $version, 'Production compatibility gate requires MySQL 8.x.');

        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    public function test_us_ny_timestamp_and_real_manual_allocation_across_mysql_session_zones(): void
    {
        config(['xlwms.us_ny_inventory_physical_apply_enabled' => true]);
        $ny = Warehouse::where('code', 'US-NY')->firstOrFail();
        $product = Product::create([
            'sku' => 'NY-HOTFIX-CANONICAL', 'name' => 'NY stock fixture', 'is_active' => true,
            'product_type' => 'simple', 'source_type' => 'manual',
        ]);
        ProductWarehouseSku::create([
            'product_id' => $product->id, 'warehouse_id' => $ny->id, 'sku' => 'NY-HOTFIX-PHYSICAL',
            'is_active' => true, 'verified_at' => now(),
        ]);
        $clock = UsNyStockClock::class;
        $original = DB::scalar('SELECT @@session.time_zone');
        DB::statement("SET time_zone = '-08:00'");
        try {
            // Reproduce the original corrupt TIMESTAMP: UTC wall time written in a -08 session.
            $stock = WarehouseInventory::create([
                'warehouse_id' => $ny->id, 'product_id' => $product->id,
                'quantity_on_hand' => 25, 'quantity_reserved' => 0, 'provider_available_quantity' => 25,
                'provider_stock_synced_at' => now(),
            ]);
            $this->assertFalse($clock::fresh($clock::withEpoch($stock->newQuery())->findOrFail($stock->id)));
            $snapshot = $clock::utc(function () use ($ny, $product) {
                $s = XlwmsInventorySnapshot::create([
                    'warehouse_id' => $ny->id, 'wh_code' => 'US01', 'checksum' => str_repeat('b', 64),
                    'status' => 'blocked', 'fetched_at' => now(), 'provider_sku_count' => 1,
                    'mapped_sku_count' => 1, 'unmatched_sku_count' => 0, 'blocked_sku_count' => 0,
                    'summary' => ['v2' => true],
                ]);
                $s->rows()->create(['provider_sku' => 'NY-HOTFIX-PHYSICAL', 'product_id' => $product->id,
                    'resolution_status' => 'matched', 'oms_total' => 25, 'oms_available' => 25, 'oms_locked' => 0]);

                return $s;
            });
            $service = app(UsNyPhysicalStockSyncService::class);
            $service->reconcileSnapshot($snapshot->id, true);
            $this->assertSame('-08:00', DB::scalar('SELECT @@session.time_zone'));
            $projection = app(InventoryAvailabilityProjection::class);
            $this->assertSame(25, $projection->forRows(collect([$stock->fresh()]))[$stock->id]);
            $user = User::factory()->create(['is_active' => true]);
            $order = Order::create([
                'internal_order_number' => 'NY-HOTFIX-ORDER', 'erp_order_id' => 'K26112899-007',
                'source_type' => 'manual', 'order_kind' => 'customer', 'order_date' => now()->toDateString(),
                'order_status' => 'pending_shipment', 'currency' => 'USD',
            ]);
            $order->address()->create(['recipient_name' => 'Synthetic NY', 'phone' => '10000000000',
                'country' => 'US', 'province_state' => 'NY', 'city' => 'New York', 'address_line_1' => '123 Test Ave', 'postal_code' => '10001']);
            $item = $order->items()->create(['product_id' => $product->id, 'sku' => $product->sku,
                'product_summary' => $product->name, 'quantity' => 1, 'unit_price' => '19.99', 'line_revenue' => '19.99']);
            // The actual saveDraft path changes the MySQL session to UTC.
            app(FulfillmentAllocationSubmissionService::class)->submitManual(
                $order, [['order_item_id' => $item->id, 'source_code' => 'US-NY']], null, $user, true);
            $this->assertSame('+00:00', DB::scalar('SELECT @@session.time_zone'));
            $this->assertSame('pending_review', $order->fresh()->workflow_status);
            $this->assertSame(1, $stock->fresh()->quantity_reserved);
            $this->assertSame(24, $projection->forRows(collect([$stock->fresh()]))[$stock->id]);
            $this->assertSame([], app(UsNyAllocationReservation::class)->dispatchBlockers($order->fresh()));
            DB::statement("SET time_zone = '-08:00'");
            $service->reconcileSnapshot($snapshot->id, true);
            $this->assertSame(24, $projection->forRows(collect([$stock->fresh()]))[$stock->id]);
            $this->assertSame(1, $stock->fresh()->quantity_reserved);
            $clock::utc(fn () => $stock->fresh()->forceFill(['provider_stock_synced_at' => now()->subHours(3)])->save());
            $this->assertSame(0, $projection->forRows(collect([$stock->fresh()]))[$stock->id]);
        } finally {
            DB::statement('SET time_zone = ?', [$original]);
        }
    }

    public function test_v2_kol_daily_counter_migration_and_allocation_on_mysql_8(): void
    {
        $this->assertTrue(Schema::hasTable('kol_daily_order_sequences'));
        $this->travelTo(Carbon::parse('2026-09-28 23:59:59', 'Asia/Shanghai'));
        $service = app(OrderIdentityService::class);
        $this->assertSame('K26092801-007', $service->allocateKol());
        $this->assertSame('K26092802-007', $service->allocateKol());
        $this->assertSame(2, (int) DB::table('kol_daily_order_sequences')->where('creation_date', '2026-09-28')->value('last_number'));
        $this->travelTo(Carbon::parse('2026-09-29 00:00:00', 'Asia/Shanghai'));
        $this->assertSame('K26092901-007', $service->allocateKol());
    }

    public function test_manual_backup_exports_real_mysql_schema_and_synthetic_data(): void
    {
        $directory = storage_path('framework/testing/mysql-backup-'.bin2hex(random_bytes(8)));
        config(['backup.local.directory' => $directory]);
        DB::statement('CREATE TABLE backup_uat_marker (id INT PRIMARY KEY, label VARCHAR(100))');
        DB::table('backup_uat_marker')->insert(['id' => 1, 'label' => 'synthetic-backup-marker']);
        try {
            $result = app(LocalDatabaseBackupService::class)->create();
            $bytes = stream_get_contents($result['stream']);
            fclose($result['stream']);
            $this->assertSame($result['sha256'], hash('sha256', $bytes));
            $sql = gzdecode($bytes);
            $this->assertIsString($sql);
            $this->assertStringContainsString('CREATE TABLE `backup_uat_marker`', $sql);
            $this->assertStringContainsString('synthetic-backup-marker', $sql);
            $this->assertStringContainsString('CREATE TABLE `orders`', $sql);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_all_migrations_and_confirmed_loss_scope_execute_on_mysql_8(): void
    {
        $this->assertTrue(Schema::hasTable('orders'));
        $this->assertTrue(Schema::hasTable('order_financials'));
        $this->assertTrue(Schema::hasTable('order_cost_entries'));
        $this->assertTrue(Schema::hasTable('product_shipping_weight_standard_candidates'));

        $count = app(ConfirmedLossProjection::class)
            ->applyConfirmedLossScope(Order::query())
            ->count();

        $this->assertSame(0, $count);
    }
}

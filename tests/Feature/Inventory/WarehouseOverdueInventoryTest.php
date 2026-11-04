<?php

namespace Tests\Feature\Inventory;

use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inventory\Application\WarehouseOverdueInventoryService;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventoryLot;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductSkuAlias;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

final class WarehouseOverdueInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function stock(string $warehouse, string $sku, int $quantity, ?string $inboundAt = null): array
    {
        $wh = Warehouse::query()->where('code', $warehouse)->firstOrFail();
        $p = Product::query()->create([
            'sku' => $sku,
            'name' => '测试商品 '.$sku,
            'product_type' => 'simple',
            'is_active' => true,
        ]);
        WarehouseInventory::query()->create([
            'warehouse_id' => $wh->id,
            'product_id' => $p->id,
            'quantity_on_hand' => $quantity,
            'quantity_reserved' => 0,
            'inbound_at' => $inboundAt,
        ]);

        return [$wh, $p];
    }

    private function lot(Warehouse $wh, Product $p, int $quantity, ?string $date, string $status = 'sellable'): void
    {
        WarehouseInventoryLot::query()->create([
            'warehouse_id' => $wh->id,
            'product_id' => $p->id,
            'quantity' => $quantity,
            'putaway_date' => $date,
            'stock_status' => $status,
        ]);
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['key' => 'overdue-uat-'.uniqid(), 'label' => 'Overdue Inventory UAT']);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user->roles()->attach($role);

        return $user;
    }

    public function test_day_90_is_overdue_but_day_89_is_not_and_each_lot_is_kept(): void
    {
        [$wh, $p] = $this->stock('US-LA', 'OVERDUE-BORDER-90', 10);
        $this->lot($wh, $p, 4, '2026-08-06');
        $this->lot($wh, $p, 6, '2026-08-07');

        $report = app(WarehouseOverdueInventoryService::class)->report(CarbonImmutable::parse('2026-11-04'));
        $rows = array_values(array_filter($report['rows'], fn (array $r): bool => $r['sku'] === 'OVERDUE-BORDER-90'));

        self::assertSame('2026-08-06', $report['cutoff_date']);
        self::assertCount(1, $rows);
        self::assertSame(4, $rows[0]['quantity']);
        self::assertSame(90, $rows[0]['age_days']);
        self::assertSame(0, $rows[0]['overdue_days']);
        self::assertSame(4, $report['summary']['overdue_units']);
    }

    public function test_old_lots_are_capped_by_current_on_hand_without_fabricating_stock(): void
    {
        [$wh, $p] = $this->stock('DE', 'OVERDUE-CAP', 10);
        $this->lot($wh, $p, 7, '2026-01-01');
        $this->lot($wh, $p, 5, '2026-10-01');

        $report = app(WarehouseOverdueInventoryService::class)->report(CarbonImmutable::parse('2026-11-04'));
        $rows = array_values(array_filter($report['rows'], fn (array $r): bool => $r['sku'] === 'OVERDUE-CAP'));

        self::assertCount(1, $rows);
        self::assertSame(5, $rows[0]['quantity']);
        self::assertSame('批次数量与当前库存不符，已按当前库存封顶', $rows[0]['quality']);
        self::assertSame(1, $report['summary']['lot_mismatch_skus']);
        self::assertSame(2, $report['summary']['lot_mismatch_units']);
        self::assertSame(10, WarehouseInventory::query()->where('product_id', $p->id)->value('quantity_on_hand'));
        self::assertSame(12, WarehouseInventoryLot::query()->where('product_id', $p->id)->sum('quantity'));
    }

    public function test_unknown_age_and_cn_stock_are_not_pretended_to_be_overdue(): void
    {
        $this->stock('US-SM', 'UNKNOWN-AGE-OVERDUE', 5);
        [$wh, $p] = $this->stock('FACTORY', 'DOMESTIC-OVERDUE', 8);
        $this->lot($wh, $p, 8, '2026-01-01');

        $report = app(WarehouseOverdueInventoryService::class)->report(CarbonImmutable::parse('2026-11-04'));
        self::assertSame(0, $report['summary']['overdue_units']);
        self::assertSame(5, $report['summary']['unknown_age_units']);
        self::assertSame([], $report['rows']);
    }

    public function test_inbound_date_without_lot_is_visible_but_clearly_flagged(): void
    {
        $this->stock('US-SM', 'SUMMARY-DATED-OVERDUE', 3, '2026-05-01');
        $report = app(WarehouseOverdueInventoryService::class)->report(CarbonImmutable::parse('2026-11-04'));
        self::assertSame(3, $report['summary']['overdue_units']);
        self::assertSame('warehouse_inbound_at', $report['rows'][0]['source']);
        self::assertSame('仅有汇总入库日期，待核对', $report['rows'][0]['quality']);
        self::assertNull($report['rows'][0]['lot_id']);
    }

    public function test_dashboard_and_detail_are_readonly_and_available_to_inventory_view_role(): void
    {
        CarbonImmutable::setTestNow('2026-11-04 12:00:00');
        [$wh, $p] = $this->stock('US-LA', 'VIEW-OVERDUE-90', 9);
        $this->lot($wh, $p, 9, '2026-08-06');

        $viewer = $this->userWith(['inventory.view']);
        $this->withoutVite();
        $this->actingAs($viewer)->get('/inventory')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Inventory/Dashboard')
                ->where('overdue.overdue_units', 9)
                ->where('overdue.overdue_products', 1)
        );
        $this->get('/inventory/overdue')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Inventory/Overdue')
                ->where('summary.overdue_units', 9)
                ->where('rows.total', 1)
                ->where('rows.data.0.quantity', 9)
                ->where('cutoffDate', '2026-08-06')
        );
        $this->get('/inventory/overdue?warehouse=DE')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Inventory/Overdue')
                ->where('rows.total', 0)
                ->where('filteredUnits', 0)
                ->where('summary.overdue_units', 9)
        );
        self::assertSame(9, WarehouseInventory::query()->where('product_id', $p->id)->value('quantity_on_hand'));
    }

    public function test_export_generates_valid_xlsx_with_sample_columns_and_current_filters(): void
    {
        CarbonImmutable::setTestNow('2026-11-04 12:00:00');
        [$wh, $p] = $this->stock('US-NY', 'EXCEL-OVERDUE', 12);
        $p->update(['name' => '=1+1']); // Spreadsheet formula injection must be escaped.
        $this->lot($wh, $p, 12, '2026-01-01');
        [$other, $otherProduct] = $this->stock('DE', 'EXCEL-OTHER', 5);
        $this->lot($other, $otherProduct, 5, '2026-01-01');

        $viewer = $this->userWith(['inventory.view']);
        $response = $this->actingAs($viewer)->get('/inventory/overdue/export?warehouse=US-NY');
        $response->assertOk();
        self::assertStringContainsString("filename*=utf-8''007-%E8%B6%85%E6%9C%9F%E5%BA%93%E5%AD%98-2026-11-04.xlsx",
            (string) $response->headers->get('Content-Disposition'));
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        ob_start();
        $response->baseResponse->sendContent();
        $bytes = ob_get_clean();
        self::assertStringStartsWith('PK', $bytes);
        $path = tempnam(sys_get_temp_dir(), 'overdue-export');
        file_put_contents($path, $bytes);
        try {
            $book = IOFactory::load($path);
            $sheet = $book->getSheetByName('超期库存');
            self::assertNotNull($sheet);
            self::assertSame(['仓别', '组别', '责任人', '入库时间', 'FNSKU', '物料名称', '数量'],
                $sheet->rangeToArray('A1:G1')[0]);
            self::assertSame('EXCEL-OVERDUE', $sheet->getCell('E2')->getValue());
            self::assertSame('=1+1', $sheet->getCell('F2')->getValue());
            self::assertSame('s', $sheet->getCell('F2')->getDataType());
            self::assertSame(12, $sheet->getCell('G2')->getValue());
            self::assertSame('2026-01-01', $sheet->getCell('D2')->getValue());
            self::assertSame(2, $sheet->getHighestRow());
            self::assertNotNull($book->getSheetByName('统计说明'));
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }
    }

    public function test_without_view_permission_all_overdue_endpoints_are_forbidden(): void
    {
        $user = $this->userWith(['inventory.operate']);
        $this->actingAs($user)->get('/inventory/overdue')->assertForbidden();
        $this->get('/inventory/overdue/export')->assertForbidden();
    }

    public function test_same_sku_with_two_expired_inbound_batches_remains_two_rows(): void
    {
        [$wh, $p] = $this->stock('CA', 'MULTI-LOT-OVERDUE', 9);
        $this->lot($wh, $p, 4, '2026-02-01');
        $this->lot($wh, $p, 5, '2026-07-01');
        $report = app(WarehouseOverdueInventoryService::class)->report(CarbonImmutable::parse('2026-11-04'));
        self::assertSame(9, $report['summary']['overdue_units']);
        self::assertSame(1, $report['summary']['overdue_products']);
        self::assertSame(2, $report['summary']['overdue_lot_rows']);
        self::assertSame([4, 5], array_column($report['rows'], 'quantity'));
    }

    public function test_search_filter_uses_warehouse_sku_and_product_name(): void
    {
        CarbonImmutable::setTestNow('2026-11-04 12:00:00');
        [$wh, $p] = $this->stock('DE', 'MATCH-SKU-GREEN', 5);
        $this->lot($wh, $p, 5, '2026-01-01');
        [$wh2, $p2] = $this->stock('DE', 'OTHER-SKU-PINK', 8);
        $this->lot($wh2, $p2, 8, '2026-01-01');
        $viewer = $this->userWith(['inventory.view']);
        $this->withoutVite();
        $this->actingAs($viewer)->get('/inventory/overdue?warehouse=DE&search=green')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Inventory/Overdue')
                ->where('filteredUnits', 5)
                ->where('rows.total', 1)
                ->where('rows.data.0.sku', 'MATCH-SKU-GREEN')
        );
    }

    public function test_moreplus_verified_sku_is_only_displayed_for_us_la_not_other_warehouses(): void
    {
        [$la, $product] = $this->stock('US-LA', 'BY489A-Lubes', 10);
        $this->lot($la, $product, 10, '2026-01-06');

        $ny = Warehouse::query()->where('code', 'US-NY')->firstOrFail();
        WarehouseInventory::query()->create([
            'warehouse_id' => $ny->id,
            'product_id' => $product->id,
            'quantity_on_hand' => 20,
            'quantity_reserved' => 0,
        ]);
        $this->lot($ny, $product, 20, '2026-02-11');

        $alias = ProductSkuAlias::query()->create([
            'product_id' => $product->id,
            'alias_sku' => 'BY489A',
            'match_key' => 'BY489A',
            'source_type' => 'oms',
            'source_reference' => 'test-overdue-oms',
        ]);
        DB::table('product_sku_aliases')->where('id', $alias->id)->update([
            'is_active' => true,
            'oms_verified_at' => '2026-11-04 01:00:00',
            'oms_identity_evidence' => 'test verified provider SKU evidence',
        ]);

        $report = app(WarehouseOverdueInventoryService::class)->report(CarbonImmutable::parse('2026-11-04'));
        $rows = array_values(array_filter($report['rows'], fn (array $row): bool => $row['product_id'] === $product->id));
        self::assertCount(2, $rows);
        $byWarehouse = array_column($rows, 'sku', 'warehouse_code');
        self::assertSame('BY489A', $byWarehouse['US-LA']);
        self::assertSame('BY489A-Lubes', $byWarehouse['US-NY']);
        self::assertSame(30, array_sum(array_column($rows, 'quantity')));
    }
}

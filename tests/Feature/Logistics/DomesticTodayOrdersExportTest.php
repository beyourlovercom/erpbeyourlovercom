<?php

namespace Tests\Feature\Logistics;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

final class DomesticTodayOrdersExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_today_export_includes_all_matching_orders_and_one_row_per_sku_with_numeric_quantity_and_unit_price(): void
    {
        $speeda = $this->domesticOrder('007-TEST-TODAY-1', '4PX', true);
        $speeda->items()->create([
            'sku' => 'MF414A', 'product_summary' => 'Test Item A',
            'quantity' => 2, 'unit_price' => '12.75', 'line_revenue' => '25.50',
        ]);
        $speeda->items()->create([
            'sku' => '=HYPERLINK("https://example.invalid","BAD")',
            'product_summary' => 'Test Item B', 'quantity' => 1,
            'unit_price' => '9.50', 'line_revenue' => '9.50',
        ]);
        $weship = $this->domesticOrder('007-TEST-TODAY-2', 'Weship', false);
        $weship->shipment()->update(['shipment_status' => 'shipped', 'shipped_at' => now()]);
        $weship->items()->create([
            'sku' => 'VX357A', 'product_summary' => 'Test Item C',
            'quantity' => 3, 'unit_price' => '18.00', 'line_revenue' => '54.00',
        ]);

        // The report must follow the exact existing today tab, not all orders by order_date.
        $excludedPending = $this->domesticOrder('007-NOT-REVIEWED', '4PX', false);
        $excludedPending->items()->create(['sku' => 'PENDING', 'product_summary' => 'Pending item', 'quantity' => 1, 'unit_price' => '1.00', 'line_revenue' => '1.00']);
        $excludedOld = $this->domesticOrder('007-YESTERDAY', 'Weship', false);
        $excludedOld->shipment()->update(['shipment_status' => 'shipped', 'shipped_at' => now()->subDay()]);
        $excludedOld->items()->create(['sku' => 'OLD', 'product_summary' => 'Old item', 'quantity' => 1, 'unit_price' => '1.00', 'line_revenue' => '1.00']);

        $this->actingAs($this->viewer())->get('/logistics/china?view=today')
            ->assertOk()->assertInertia(fn ($page) => $page->where('viewCounts.today', 2));

        $response = $this->actingAs($this->viewer())->get('/logistics/china/today/export');
        $response->assertOk();
        self::assertSame('2', $response->headers->get('X-BYL-Exported-Orders'));
        self::assertSame('3', $response->headers->get('X-BYL-Exported-Lines'));
        self::assertStringContainsString('007-domestic-today-', (string) $response->headers->get('Content-Disposition'));

        $path = tempnam(sys_get_temp_dir(), 'today-export-test-');
        file_put_contents($path, $response->getContent());
        try {
            $book = IOFactory::load($path);
            $sheet = $book->getActiveSheet();
            self::assertSame(['订单号','SKU','数量','单价','物流商','承运商单号','币种','商品名称','发货状态'],
                array_map(fn (string $col) => $sheet->getCell($col.'1')->getValue(), range('A','I')));
            self::assertSame(4, $sheet->getHighestDataRow());
            self::assertSame('007-TEST-TODAY-1', $sheet->getCell('A2')->getValue());
            self::assertSame('MF414A', $sheet->getCell('B2')->getValue());
            self::assertSame(2, $sheet->getCell('C2')->getValue());
            self::assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('C2')->getDataType());
            self::assertSame(12.75, $sheet->getCell('D2')->getValue());
            self::assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('D2')->getDataType());
            self::assertSame('速达 / 4PX', $sheet->getCell('E2')->getValue());
            self::assertSame('USD', $sheet->getCell('G2')->getValue());
            self::assertSame('007-TEST-TODAY-1', $sheet->getCell('A3')->getValue());
            self::assertSame('=HYPERLINK("https://example.invalid","BAD")', $sheet->getCell('B3')->getValue());
            self::assertSame(DataType::TYPE_STRING, $sheet->getCell('B3')->getDataType());
            self::assertSame(1, $sheet->getCell('C3')->getValue());
            self::assertSame(9.5, $sheet->getCell('D3')->getValue());
            self::assertSame('007-TEST-TODAY-2', $sheet->getCell('A4')->getValue());
            self::assertSame('VX357A', $sheet->getCell('B4')->getValue());
            self::assertSame(3, $sheet->getCell('C4')->getValue());
            self::assertSame(18.0, $sheet->getCell('D4')->getValue());
            self::assertSame('纬狮 / Weship', $sheet->getCell('E4')->getValue());
            self::assertSame('shipped', $sheet->getCell('I4')->getValue());
            $book->disconnectWorksheets();
        } finally {
            @unlink($path);
        }

        self::assertSame('pending', $speeda->fresh()->shipment->shipment_status);
        self::assertSame('shipped', $weship->fresh()->shipment->shipment_status);
    }

    public function test_today_export_is_authorized_and_empty_download_has_only_headers(): void
    {
        $this->get('/logistics/china/today/export')->assertRedirect('/login');
        $noPermission = User::factory()->create(['is_active' => true]);
        $this->actingAs($noPermission)->get('/logistics/china/today/export')->assertForbidden();
        $response = $this->actingAs($this->viewer())->get('/logistics/china/today/export');
        $response->assertOk();
        self::assertSame('0', $response->headers->get('X-BYL-Exported-Orders'));
        self::assertSame('0', $response->headers->get('X-BYL-Exported-Lines'));
        $path = tempnam(sys_get_temp_dir(), 'today-empty-test-');
        file_put_contents($path, $response->getContent());
        try {
            $book = IOFactory::load($path);
            self::assertSame(1, $book->getActiveSheet()->getHighestDataRow());
            self::assertSame('SKU', $book->getActiveSheet()->getCell('B1')->getValue());
            $book->disconnectWorksheets();
        } finally {
            @unlink($path);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config()->set('inertia.testing.ensure_pages_exist', false);
    }

    private function viewer(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::query()->create(['key' => 'today-export-'.uniqid(), 'label' => 'Today Export']);
        $role->permissions()->attach(Permission::firstOrCreate(['key' => 'logistics.view'], ['label' => 'logistics.view']));
        $user->roles()->attach($role);
        return $user;
    }

    private function domesticOrder(string $number, string $carrier, bool $reviewed): Order
    {
        $order = Order::query()->create([
            'erp_order_id' => $number,
            'internal_order_number' => 'TEST-'.uniqid(),
            'source_type' => 'manual',
            'order_kind' => 'customer',
            'external_order_number' => 'TEST-'.uniqid(),
            'order_date' => '2026-10-30', // intentionally not today
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
        ]);
        $order->shipment()->create([
            'warehouse_id' => Warehouse::query()->where('code','FACTORY')->firstOrFail()->id,
            'shipment_status' => 'pending',
            'warehouse_selection_source' => 'manual',
            'carrier' => $carrier,
            'carrier_selection_source' => 'manual',
        ]);
        if ($reviewed) {
            $order->operationalMarkers()->create(['marker' => 'domestic_reviewed', 'marked_at' => now()]);
        }
        return $order;
    }
}

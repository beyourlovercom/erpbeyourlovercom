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

    public function test_r4_4_today_grid_defaults_on_and_fallback_keeps_exact_same_rows(): void
    {
        $reviewed = $this->domesticOrder('007-R4-4-TODAY-1', '4PX', true);
        $reviewed->items()->create([
            'sku'=>'A1', 'product_summary'=>'Test item 1', 'quantity'=>2,
            'unit_price'=>'12.00', 'line_revenue'=>'24.00',
        ]);
        $shipped = $this->domesticOrder('007-R4-4-TODAY-2', 'Weship', false);
        $shipped->shipment()->update(['shipment_status'=>'shipped','shipped_at'=>now()]);
        $shipped->items()->create([
            'sku'=>'B1','product_summary'=>'Test item 2','quantity'=>1,
            'unit_price'=>'8.00','line_revenue'=>'8.00',
        ]);
        $excluded = $this->domesticOrder('007-R4-4-UNREVIEWED', '4PX', false);
        $user = $this->viewer();

        $this->actingAs($user)->get('/logistics/china?view=today')
            ->assertOk()->assertInertia(fn ($page) => $page
                ->where('globalGridPilotAvailable',true)
                ->where('globalGridPilotEnabled',true)
                ->where('rows.total',2)
                ->where('rows.data.0.erp_order_id','007-R4-4-TODAY-1')
                ->where('rows.data.0.items.0.quantity',2)
                ->where('rows.data.1.erp_order_id','007-R4-4-TODAY-2'));

        $this->actingAs($user)->get('/logistics/china?view=today&global_grid=0')
            ->assertOk()->assertInertia(fn ($page) => $page
                ->where('globalGridPilotEnabled',false)
                ->where('rows.total',2)
                ->where('rows.data.0.erp_order_id','007-R4-4-TODAY-1')
                ->where('rows.data.1.erp_order_id','007-R4-4-TODAY-2'));

        $this->actingAs($user)->get('/logistics/china?view=today&global_grid=1&per_page=20&search=R4-4-TODAY-2')
            ->assertOk()->assertInertia(fn ($page) => $page
                ->where('globalGridPilotEnabled',true)
                ->where('rows.total',1)
                ->where('rows.data.0.erp_order_id','007-R4-4-TODAY-2'));

        // R4-5A: Speeda is enabled independently, Weship and the other
        // four views still use the original table by default.
        $this->actingAs($user)->get('/logistics/china?view=today-4px')
            ->assertOk()->assertInertia(fn ($page) => $page->where('globalGridPilotEnabled',true));
        $this->actingAs($user)->get('/logistics/china?view=today-4px&global_grid=0')
            ->assertOk()->assertInertia(fn ($page) => $page->where('globalGridPilotEnabled',false));
        foreach (['today-weship','exceptions','4px','weship'] as $view) {
            $this->actingAs($user)->get('/logistics/china?view='.$view)
                ->assertOk()->assertInertia(fn ($page) => $page
                    ->where('globalGridPilotEnabled',false));
        }
        self::assertSame('pending', $reviewed->fresh()->shipment->shipment_status);
        self::assertSame('shipped', $shipped->fresh()->shipment->shipment_status);
        self::assertSame('pending', $excluded->fresh()->shipment->shipment_status);
    }

    public function test_r4_5_speeda_grid_and_weship_native_keep_original_provider_query_and_dates(): void
    {
        $speedaPending=$this->domesticOrder('007-R45-4PX-PEND','4PX',true);
        $speedaPending->items()->create(['sku'=>'MF414A','product_summary'=>'test','quantity'=>2,'unit_price'=>'9.00','line_revenue'=>'18.00']);
        $speedaShipped=$this->domesticOrder('007-R45-4PX-SHIP','4PX',false);
        $speedaShipped->shipment()->update(['shipment_status'=>'shipped','shipped_at'=>now()]);
        $weshipPending=$this->domesticOrder('007-R45-WES-PEND','Weship',true);
        $weshipToday=$this->domesticOrder('007-R45-WES-SHIP','Weship',false);
        $weshipToday->shipment()->update(['shipment_status'=>'shipped','shipped_at'=>now()]);
        $unreviewed=$this->domesticOrder('007-R45-4PX-UNREVIEWED','4PX',false);
        $olderShipped=$this->domesticOrder('007-R45-4PX-YESTERDAY','4PX',false);
        $olderShipped->shipment()->update(['shipment_status'=>'shipped','shipped_at'=>now()->subDay()]);
        $actor=$this->viewer();

        foreach (['','&global_grid=1','&global_grid=0'] as $extra) {
            $this->actingAs($actor)->get('/logistics/china?view=today-4px'.$extra)
                ->assertOk()->assertInertia(fn ($page) => $page
                    ->where('rows.total',2)
                    ->where('rows.data.0.erp_order_id','007-R45-4PX-PEND')
                    ->where('rows.data.0.items.0.quantity',2)
                    ->where('rows.data.1.erp_order_id','007-R45-4PX-SHIP')
                    ->where('globalGridPilotEnabled',$extra==='&global_grid=0'?false:true));
        }
        $this->actingAs($actor)->get('/logistics/china?view=today-4px&search=R45-4PX-SHIP&per_page=20')
            ->assertOk()->assertInertia(fn ($page) => $page
                ->where('rows.total',1)->where('rows.per_page',20)
                ->where('rows.data.0.erp_order_id','007-R45-4PX-SHIP'));
        $this->actingAs($actor)->get('/logistics/china?view=today-weship')
            ->assertOk()->assertInertia(fn ($page) => $page
                ->where('globalGridPilotEnabled',false)
                ->where('rows.total',2)
                ->where('rows.data.0.erp_order_id','007-R45-WES-PEND')
                ->where('rows.data.1.erp_order_id','007-R45-WES-SHIP'));

        self::assertSame('pending',$speedaPending->fresh()->shipment->shipment_status);
        self::assertSame('shipped',$speedaShipped->fresh()->shipment->shipment_status);
        self::assertSame('pending',$weshipPending->fresh()->shipment->shipment_status);
        self::assertSame('shipped',$weshipToday->fresh()->shipment->shipment_status);
        self::assertSame('pending',$unreviewed->fresh()->shipment->shipment_status);
        self::assertSame('shipped',$olderShipped->fresh()->shipment->shipment_status);
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

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
            self::assertSame(['订单号','SKU','数量','单价','物流商','物流商单号','币种','商品名称','发货状态'],
                array_map(fn (string $col) => $sheet->getCell($col.'1')->getValue(), range('A','I')));
            self::assertSame(4, $sheet->getHighestDataRow());
            self::assertSame('007-TEST-TODAY-1', $sheet->getCell('A2')->getValue());
            self::assertSame('MF414A', $sheet->getCell('B2')->getValue());
            self::assertSame(2, $sheet->getCell('C2')->getValue());
            self::assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('C2')->getDataType());
            self::assertSame(12.75, $sheet->getCell('D2')->getValue());
            self::assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('D2')->getDataType());
            self::assertSame('速达', $sheet->getCell('E2')->getValue());
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
            self::assertSame('纬狮', $sheet->getCell('E4')->getValue());
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

        // R4-5A/B: both today's provider views default to Grid with
        // independently reversible legacy fallback. The other 3 remain native.
        $this->actingAs($user)->get('/logistics/china?view=today-4px')
            ->assertOk()->assertInertia(fn ($page) => $page->where('globalGridPilotEnabled',true));
        $this->actingAs($user)->get('/logistics/china?view=today-4px&global_grid=0')
            ->assertOk()->assertInertia(fn ($page) => $page->where('globalGridPilotEnabled',false));
        $this->actingAs($user)->get('/logistics/china?view=today-weship')
            ->assertOk()->assertInertia(fn ($page) => $page->where('globalGridPilotEnabled',true));
        $this->actingAs($user)->get('/logistics/china?view=today-weship&global_grid=0')
            ->assertOk()->assertInertia(fn ($page) => $page->where('globalGridPilotEnabled',false));
        $this->actingAs($user)->get('/logistics/china?view=exceptions')
            ->assertOk()->assertInertia(fn ($page) => $page->where('globalGridPilotEnabled',true));
        $this->actingAs($user)->get('/logistics/china?view=4px')
            ->assertOk()->assertInertia(fn ($page) => $page->where('globalGridPilotEnabled',true));
        $this->actingAs($user)->get('/logistics/china?view=weship')
            ->assertOk()->assertInertia(fn ($page) => $page->where('globalGridPilotEnabled',true));
        $this->actingAs($user)->get('/logistics/china?view=weship&global_grid=0')
            ->assertOk()->assertInertia(fn ($page) => $page->where('globalGridPilotEnabled',false));
        self::assertSame('pending', $reviewed->fresh()->shipment->shipment_status);
        self::assertSame('shipped', $shipped->fresh()->shipment->shipment_status);
        self::assertSame('pending', $excluded->fresh()->shipment->shipment_status);
    }

    public function test_r4_5_both_daily_provider_grids_keep_original_queries_and_dates(): void
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
        foreach (['','&global_grid=1','&global_grid=0'] as $extra) {
            $this->actingAs($actor)->get('/logistics/china?view=today-weship'.$extra)
                ->assertOk()->assertInertia(fn ($page) => $page
                    ->where('globalGridPilotEnabled',$extra==='&global_grid=0'?false:true)
                    ->where('rows.total',2)
                    ->where('rows.data.0.erp_order_id','007-R45-WES-PEND')
                    ->where('rows.data.1.erp_order_id','007-R45-WES-SHIP'));
        }
        $this->actingAs($actor)->get('/logistics/china?view=today-weship&per_page=20&search=R45-WES-SHIP')
            ->assertOk()->assertInertia(fn ($page) => $page
                ->where('rows.total',1)->where('rows.per_page',20)
                ->where('rows.data.0.erp_order_id','007-R45-WES-SHIP'));

        self::assertSame('pending',$speedaPending->fresh()->shipment->shipment_status);
        self::assertSame('shipped',$speedaShipped->fresh()->shipment->shipment_status);
        self::assertSame('pending',$weshipPending->fresh()->shipment->shipment_status);
        self::assertSame('shipped',$weshipToday->fresh()->shipment->shipment_status);
        self::assertSame('pending',$unreviewed->fresh()->shipment->shipment_status);
        self::assertSame('shipped',$olderShipped->fresh()->shipment->shipment_status);
    }

    public function test_r4_6_exception_grid_is_read_only_and_matches_the_legacy_filtered_orders(): void
    {
        $carrierException=$this->domesticOrder('007-R46-EXCEPTION','4PX',true);
        $carrierException->shipment()->update([
            'shipment_status'=>'exception',
            'logistics_note'=>'包裹被承运商退回',
        ]);
        $missingShipTime=$this->domesticOrder('007-R46-TIME-CONFLICT','Weship',true);
        $missingShipTime->shipment()->update([
            'shipment_status'=>'shipped','shipped_at'=>null,'tracking_number'=>'TEST-TRACK-1',
        ]);
        $orderShipmentMismatch=$this->domesticOrder('007-R46-STATUS-CONFLICT','4PX',true);
        $orderShipmentMismatch->update(['order_status'=>'shipped']);
        $normal=$this->domesticOrder('007-R46-NORMAL','Weship',true);

        $viewer=$this->viewer();
        $this->actingAs($viewer)->get('/logistics/china?view=exceptions')
            ->assertOk()->assertInertia(fn ($page) => $page
                ->where('globalGridPilotAvailable',true)
                ->where('globalGridPilotEnabled',true)
                ->where('rows.total',3)
                ->where('rows.data.0.erp_order_id','007-R46-EXCEPTION')
                ->where('rows.data.0.exception_type','发货异常')
                ->where('rows.data.0.exception_reason','包裹被承运商退回')
                ->where('rows.data.1.erp_order_id','007-R46-TIME-CONFLICT')
                ->where('rows.data.1.exception_type','进度冲突')
                ->where('rows.data.1.exception_reason','物流已发货，但缺少发货时间')
                ->where('rows.data.2.erp_order_id','007-R46-STATUS-CONFLICT')
                ->where('rows.data.2.exception_type','进度冲突')
                ->where('rows.data.2.exception_reason','订单已标记发货，但物流仍为待发货'));

        $this->actingAs($viewer)->get('/logistics/china?view=exceptions&global_grid=0')
            ->assertOk()->assertInertia(fn ($page) => $page
                ->where('globalGridPilotEnabled',false)
                ->where('rows.total',3)
                ->where('rows.data.0.erp_order_id','007-R46-EXCEPTION')
                ->where('rows.data.1.erp_order_id','007-R46-TIME-CONFLICT')
                ->where('rows.data.2.erp_order_id','007-R46-STATUS-CONFLICT'));

        $this->actingAs($viewer)->get('/logistics/china?view=exceptions&global_grid=1&search=R46-TIME-CONFLICT&per_page=20')
            ->assertOk()->assertInertia(fn ($page) => $page
                ->where('rows.total',1)->where('rows.per_page',20)
                ->where('rows.data.0.erp_order_id','007-R46-TIME-CONFLICT'));

        $this->actingAs($viewer)->get('/logistics/china?view=4px')
            ->assertOk()->assertInertia(fn ($page) => $page->where('globalGridPilotEnabled',true));
        $this->actingAs($viewer)->get('/logistics/china?view=weship')
            ->assertOk()->assertInertia(fn ($page) => $page->where('globalGridPilotEnabled',true));

        self::assertSame('exception',$carrierException->fresh()->shipment->shipment_status);
        self::assertSame('包裹被承运商退回',$carrierException->fresh()->shipment->logistics_note);
        self::assertSame('shipped',$missingShipTime->fresh()->shipment->shipment_status);
        self::assertNull($missingShipTime->fresh()->shipment->shipped_at);
        self::assertSame('pending',$orderShipmentMismatch->fresh()->shipment->shipment_status);
        self::assertSame('shipped',$orderShipmentMismatch->fresh()->order_status->value);
        self::assertSame('pending',$normal->fresh()->shipment->shipment_status);
    }

    public function test_r4_7b_weship_cumulative_history_grid_has_all_original_states_dates_search_and_old_table_fallback(): void
    {
        $pending=$this->domesticOrder('007-R47B-WES-PENDING','Weship',true);
        $pending->items()->create(['sku'=>'BY489A','product_summary'=>'Weship multi-unit','quantity'=>3,
            'unit_price'=>'8.00','line_revenue'=>'24.00']);
        $unreviewed=$this->domesticOrder('007-R47B-WES-UNREVIEWED','Weship',false);
        $oldShipped=$this->domesticOrder('007-R47B-WES-OLD-SHIPPED','Weship',false);
        $oldShipped->update(['order_date'=>now()->subDays(60)->toDateString()]);
        $oldShipped->shipment()->update([
            'shipment_status'=>'shipped','shipped_at'=>now()->subDays(45),
            'tracking_number'=>'R47B-WES-OLDER-TRACK',
        ]);
        $exception=$this->domesticOrder('007-R47B-WES-EXCEPTION','Weship',true);
        $exception->shipment()->update(['shipment_status'=>'exception']);
        $otherCarrier=$this->domesticOrder('007-R47B-4PX-OTHER','4PX',true);
        $viewer=$this->viewer();

        foreach (['','&global_grid=1','&global_grid=0'] as $suffix) {
            $this->actingAs($viewer)->get('/logistics/china?view=weship'.$suffix)
                ->assertOk()->assertInertia(fn ($page) => $page
                    ->component('Logistics/Domestic/Index')
                    ->where('view','weship')
                    ->where('globalGridPilotAvailable',true)
                    ->where('globalGridPilotEnabled',$suffix!=='&global_grid=0')
                    ->where('rows.total',4)
                    ->where('rows.data.0.erp_order_id','007-R47B-WES-OLD-SHIPPED')
                    ->where('rows.data.1.erp_order_id','007-R47B-WES-PENDING')
                    ->where('rows.data.1.items.0.quantity',3)
                    ->where('rows.data.2.erp_order_id','007-R47B-WES-UNREVIEWED')
                    ->where('rows.data.3.erp_order_id','007-R47B-WES-EXCEPTION'));
        }
        $this->actingAs($viewer)->get('/logistics/china?view=weship&search=R47B-WES-OLD-SHIPPED&per_page=20')
            ->assertOk()->assertInertia(fn ($page) => $page
                ->where('globalGridPilotEnabled',true)
                ->where('rows.per_page',20)
                ->where('rows.total',1)
                ->where('rows.data.0.erp_order_id','007-R47B-WES-OLD-SHIPPED'));

        $this->actingAs($viewer)->get('/logistics/china?view=4px')
            ->assertOk()->assertInertia(fn ($page) => $page
                ->where('rows.total',1)
                ->where('rows.data.0.erp_order_id','007-R47B-4PX-OTHER'));

        // R4-7B is presentation-only: no reviewed/ship/tracking business updates.
        self::assertSame('pending',$pending->fresh()->shipment->shipment_status);
        self::assertSame('pending',$unreviewed->fresh()->shipment->shipment_status);
        self::assertSame('shipped',$oldShipped->fresh()->shipment->shipment_status);
        self::assertSame('exception',$exception->fresh()->shipment->shipment_status);
        self::assertSame('pending',$otherCarrier->fresh()->shipment->shipment_status);
    }

    public function test_r4_7a_speeda_history_grid_keeps_original_all_statuses_unlimited_dates_and_legacy_fallback(): void
    {
        $pending=$this->domesticOrder('007-R47-4PX-PENDING','4PX',true);
        $pending->items()->create(['sku'=>'MF414A','product_summary'=>'Two units','quantity'=>2,'unit_price'=>'9.00','line_revenue'=>'18.00']);
        $unreviewed=$this->domesticOrder('007-R47-4PX-UNREVIEWED','4PX',false);
        $oldShipped=$this->domesticOrder('007-R47-4PX-OLD-SHIPPED','4PX',false);
        $oldShipped->update(['order_date'=>now()->subDays(60)->toDateString()]);
        $oldShipped->shipment()->update([
            'shipment_status'=>'shipped','shipped_at'=>now()->subDays(45),'tracking_number'=>'TEST-OLD-TRACK',
        ]);
        $exception=$this->domesticOrder('007-R47-4PX-EXCEPTION','4PX',true);
        $exception->shipment()->update(['shipment_status'=>'exception']);
        $otherCarrier=$this->domesticOrder('007-R47-WESHIP-OTHER','Weship',true);
        $actor=$this->viewer();

        foreach (['','&global_grid=1','&global_grid=0'] as $params) {
            $this->actingAs($actor)->get('/logistics/china?view=4px'.$params)
                ->assertOk()->assertInertia(fn ($page) => $page
                    ->where('globalGridPilotEnabled',$params !== '&global_grid=0')
                    ->where('rows.total',4)
                    ->where('rows.data.0.erp_order_id','007-R47-4PX-OLD-SHIPPED')
                    ->where('rows.data.1.erp_order_id','007-R47-4PX-PENDING')
                    ->where('rows.data.1.items.0.quantity',2)
                    ->where('rows.data.2.erp_order_id','007-R47-4PX-UNREVIEWED')
                    ->where('rows.data.3.erp_order_id','007-R47-4PX-EXCEPTION'));
        }

        $this->actingAs($actor)->get('/logistics/china?view=4px&search=R47-4PX-OLD-SHIPPED&per_page=20')
            ->assertOk()->assertInertia(fn ($page) => $page
                ->where('rows.total',1)->where('rows.per_page',20)
                ->where('rows.data.0.erp_order_id','007-R47-4PX-OLD-SHIPPED'));
        $this->actingAs($actor)->get('/logistics/china?view=weship')
            ->assertOk()->assertInertia(fn ($page) => $page
                ->where('globalGridPilotEnabled',true)
                ->where('rows.total',1)
                ->where('rows.data.0.erp_order_id','007-R47-WESHIP-OTHER'));

        self::assertSame('pending',$pending->fresh()->shipment->shipment_status);
        self::assertSame('pending',$unreviewed->fresh()->shipment->shipment_status);
        self::assertSame('shipped',$oldShipped->fresh()->shipment->shipment_status);
        self::assertSame('exception',$exception->fresh()->shipment->shipment_status);
        self::assertSame('pending',$otherCarrier->fresh()->shipment->shipment_status);
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

    public function test_r4_8_all_nine_views_match_legacy_safely_exclude_archives_and_preserve_cross_view_rows(): void
    {
        $review = $this->domesticOrder('007-R48-REVIEW', '4PX', false);
        $stockout = $this->domesticOrder('007-R48-STOCKOUT', 'Weship', false);
        $stockout->operationalMarkers()->create(['marker' => 'domestic_stockout', 'marked_at' => now()]);
        $pending = $this->domesticOrder('007-R48-PENDING', '4PX', true);
        $today4px = $this->domesticOrder('007-R48-TODAY-4PX', '4PX', true);
        $todayWeship = $this->domesticOrder('007-R48-TODAY-WESHIP', 'Weship', true);
        foreach ([$today4px, $todayWeship] as $today) {
            $today->update(['order_date' => now()->toDateString()]);
            $today->shipment()->update(['shipment_status' => 'shipped', 'shipped_at' => now()]);
        }
        $exception = $this->domesticOrder('007-R48-EXCEPTION', 'Weship', false);
        $exception->shipment()->update(['shipment_status' => 'exception']);
        $old4px = $this->domesticOrder('007-R48-HISTORY-4PX', '4PX', false);
        $oldWeship = $this->domesticOrder('007-R48-HISTORY-WESHIP', 'Weship', false);
        foreach ([$old4px, $oldWeship] as $old) {
            $old->update(['order_date' => now()->subDays(60)->toDateString()]);
            $old->shipment()->update(['shipment_status' => 'shipped', 'shipped_at' => now()->subDays(45)]);
        }
        $archived = $this->domesticOrder('007-R48-ARCHIVED-DO-NOT-SHIP', '4PX', true);
        $archived->update(['operational_archived_at' => now()]);
        $legacyArchive = $this->domesticOrder('007-R48-OLD-ARCHIVE-DO-NOT-SHIP', 'Weship', true);
        // archived_at is intentionally NOT mass assignable on Order; forceFill
        // is required to create a legitimate legacy trash fixture.
        $legacyArchive->forceFill(['archived_at' => now()])->save();
        self::assertNotNull($legacyArchive->fresh()->archived_at);
        $deleted = $this->domesticOrder('007-R48-DELETED-DO-NOT-SHIP', '4PX', true);
        $deleted->delete();
        $user = $this->viewer();

        $views = ['review', 'stockout', 'pending', 'today', 'today-4px',
            'today-weship', 'exceptions', '4px', 'weship'];
        $referenceCounts = null;
        $seen = [];
        foreach ($views as $view) {
            $globalResponse = $this->actingAs($user)->get('/logistics/china?view='.$view.'&global_grid=1');
            $globalResponse->assertOk();
            $legacyResponse = $this->actingAs($user)->get('/logistics/china?view='.$view.'&global_grid=0');
            $legacyResponse->assertOk();
            $defaultResponse = $this->actingAs($user)->get('/logistics/china?view='.$view);
            $defaultResponse->assertOk();

            $globalPage = $globalResponse->viewData('page')['props'];
            $legacyPage = $legacyResponse->viewData('page')['props'];
            $defaultPage = $defaultResponse->viewData('page')['props'];
            self::assertTrue($globalPage['globalGridPilotEnabled'], $view.' forced Grid');
            self::assertFalse($legacyPage['globalGridPilotEnabled'], $view.' legacy fallback');
            self::assertTrue($defaultPage['globalGridPilotEnabled'], $view.' default Grid');
            self::assertSame($view, $globalPage['view']);
            self::assertSame($globalPage['rows']['total'], $legacyPage['rows']['total'], $view.' total');
            self::assertSame($globalPage['rows']['total'], $defaultPage['rows']['total'], $view.' default total');
            $extract = static fn (array $items): array => array_map(
                static fn (array $row): array => [
                    'id' => $row['order_id'], 'number' => $row['erp_order_id'],
                    'carrier' => $row['carrier'], 'tracking' => $row['tracking_number'],
                    'shipment' => $row['shipment_status'], 'review' => $row['review_status'],
                    'items' => $row['items'],
                ], $items);
            $snapshot = $extract($globalPage['rows']['data']);
            self::assertSame($snapshot, $extract($legacyPage['rows']['data']), $view.' legacy row diff');
            self::assertSame($snapshot, $extract($defaultPage['rows']['data']), $view.' default row diff');
            foreach ($snapshot as $row) {
                self::assertNotContains($row['number'], [
                    '007-R48-ARCHIVED-DO-NOT-SHIP',
                    '007-R48-OLD-ARCHIVE-DO-NOT-SHIP',
                    '007-R48-DELETED-DO-NOT-SHIP',
                ], $view.' leaked archived/deleted order');
                $seen[$view][] = $row['number'];
            }
            if ($referenceCounts === null) {
                $referenceCounts = $globalPage['viewCounts'];
            } else {
                self::assertSame($referenceCounts, $globalPage['viewCounts'], $view.' count consistency');
            }
            self::assertSame($globalPage['viewCounts'][$view], $globalPage['rows']['total'], $view.' tab count parity');
        }
        self::assertContains('007-R48-REVIEW', $seen['review'] ?? []);
        self::assertContains('007-R48-STOCKOUT', $seen['stockout'] ?? []);
        self::assertContains('007-R48-PENDING', $seen['pending'] ?? []);
        self::assertContains('007-R48-EXCEPTION', $seen['exceptions'] ?? []);
        self::assertContains('007-R48-HISTORY-4PX', $seen['4px'] ?? []);
        self::assertContains('007-R48-HISTORY-WESHIP', $seen['weship'] ?? []);
        self::assertNotContains('007-R48-HISTORY-4PX', $seen['weship'] ?? []);
        self::assertNotContains('007-R48-HISTORY-WESHIP', $seen['4px'] ?? []);

        foreach (['review','stockout','pending','today','today-4px','today-weship','exceptions','4px','weship'] as $view) {
            foreach ([20,50,100] as $size) {
                $page = $this->actingAs($user)->get('/logistics/china?view='.$view.'&per_page='.$size);
                $page->assertOk()->assertInertia(fn ($inertia) => $inertia
                    ->where('globalGridPilotEnabled', true)
                    ->where('rows.per_page', $size));
            }
        }
        $this->actingAs($user)->get('/logistics/china?view=weship&search=R48-HISTORY-WESHIP')
            ->assertOk()->assertInertia(fn ($page) => $page
                ->where('rows.total', 1)
                ->where('rows.data.0.erp_order_id', '007-R48-HISTORY-WESHIP'));
        $this->actingAs($user)->get('/logistics/china?view=4px&search=R48-HISTORY-WESHIP')
            ->assertOk()->assertInertia(fn ($page) => $page->where('rows.total', 0));

        foreach ([$review,$stockout,$pending,$today4px,$todayWeship,$exception,$old4px,$oldWeship] as $item) {
            self::assertNotSame('007-R48-ARCHIVED-DO-NOT-SHIP', $item->fresh()->erp_order_id);
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

<?php

namespace Tests\Feature\Orders;

use App\Models\Role;
use App\Models\User;
use App\Modules\Logistics\Application\OverseaFiles\ProviderXlsxTableReader;
use App\Modules\Logistics\Application\OverseaFiles\ProviderXlsxWriter;
use App\Modules\Orders\Application\OrderOmsBatchImport;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

final class OrderOmsBatchImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Accept', 'application/json');
    }

    private function userWith(array $permissions): User
    {
        $role = Role::create(['key' => 'oms-batch-'.uniqid(), 'label' => 'OMS Batch UAT']);
        foreach ($permissions as $permission) {
            $role->permissions()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }

    private function xlsx(string $externalNumber = 'R3-IMPORT-0001', string $sku = 'MF414A', string $qty = '2'): UploadedFile
    {
        $entry = array_fill_keys(OrderOmsBatchImport::FIELDS, '');
        $entry['external_order_number'] = $externalNumber;
        $entry['order_date'] = '2026-11-05';
        $entry['recipient_name'] = 'Test Customer';
        $entry['phone'] = '123456789';
        $entry['country'] = 'US';
        $entry['province_state'] = 'NY';
        $entry['city'] = 'New York';
        $entry['address_line_1'] = '1 Test St';
        $entry['postal_code'] = '10001';
        $entry['currency'] = 'USD';
        $entry['sku'] = $sku;
        $entry['quantity'] = $qty;
        $entry['unit_price'] = '79.50';
        $bytes = app(ProviderXlsxWriter::class)->write('批量订单', OrderOmsBatchImport::FIELDS, [array_values($entry)]);

        return UploadedFile::fake()->createWithContent('orders.xlsx', $bytes);
    }


    private function groupedXlsx(bool $conflict = false): UploadedFile
    {
        $parsed = app(ProviderXlsxTableReader::class)->read($this->xlsx());
        $first = array_combine(OrderOmsBatchImport::FIELDS, $parsed['rows'][0]['cells']);
        $second = array_fill_keys(OrderOmsBatchImport::FIELDS, '');
        $second['external_order_number'] = $first['external_order_number'];
        $second['sku'] = 'A1';
        $second['quantity'] = '1';
        $second['unit_price'] = '0.00';
        if ($conflict) $second['city'] = 'Conflicting City';
        $third = $first;
        $third['external_order_number'] = 'R3-IMPORT-0002';
        $third['sku'] = 'A1';
        $third['quantity'] = '3';
        $third['unit_price'] = '29.00';
        return UploadedFile::fake()->createWithContent('order-lines.xlsx',
            app(ProviderXlsxWriter::class)->write('批量订单', OrderOmsBatchImport::FIELDS,
                [array_values($first), array_values($second), array_values($third)]));
    }

    public function test_multiple_sku_rows_with_same_order_number_create_one_canonical_order(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create']);
        Product::query()->create(['sku' => 'MF414A', 'name' => 'Main']);
        Product::query()->create(['sku' => 'A1', 'name' => 'Accessory']);
        $file = $this->groupedXlsx();
        $preview = $this->actingAs($user)->post('/orders/oms/import/preview', [
            'file' => $file,
        ])->assertOk()->json();
        $this->assertSame(2, $preview['count']);
        $this->assertSame(3, $preview['row_count']);
        $this->assertSame(2, $preview['valid_count']);
        $this->assertSame([], $preview['errors']);
        $this->assertCount(3, $preview['preview']);
        $this->assertSame('A1 × 1', $preview['preview'][1]['sku_detail']);
        $this->assertSame(3, $preview['preview'][0]['order_total_quantity']);
        $this->assertDatabaseCount('orders', 0);
        $result = $this->actingAs($user)->post('/orders/oms/import/confirm', [
            'file' => $file, 'token' => $preview['token'], 'confirm' => '1',
        ])->assertOk()->json();
        $this->assertSame(2, $result['created']);
        $first = Order::query()->where('external_order_number', 'R3-IMPORT-0001')->firstOrFail();
        $second = Order::query()->where('external_order_number', 'R3-IMPORT-0002')->firstOrFail();
        $this->assertSame(2, $first->items()->count());
        $this->assertSame(3, (int) $first->items()->sum('quantity'));
        $this->assertSame(1, $second->items()->count());
        $this->assertDatabaseCount('oms_order_import_claims', 2);
        $this->assertDatabaseCount('orders', 2);
    }

    public function test_conflicting_order_level_fields_in_sku_followup_line_reject_the_entire_file(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create']);
        Product::query()->create(['sku' => 'MF414A', 'name' => 'Main']);
        Product::query()->create(['sku' => 'A1', 'name' => 'Accessory']);
        $result = $this->actingAs($user)->post('/orders/oms/import/preview', [
            'file' => $this->groupedXlsx(conflict: true),
        ])->assertOk()->json();
        $this->assertNull($result['token']);
        $this->assertSame(2, $result['count']);
        $this->assertSame(3, $result['row_count']);
        $this->assertSame(1, $result['valid_count']);
        $this->assertStringContainsString('与第 2 行不一致', $result['errors'][0]['message']);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_selected_ids_export_exact_two_orders_and_every_sku_even_when_grid_filter_is_broader(): void
    {
        $viewer = $this->userWith(['orders.view']);
        $created = [];
        foreach (['MF414A', 'A1', 'MF414A'] as $index => $sku) {
            $order = Order::query()->create([
                'source_type' => 'manual', 'order_kind' => 'customer',
                'internal_order_number' => 'SELECT-'.($index + 1),
                'external_order_number' => 'SELECT-EXT-'.($index + 1),
                'erp_order_id' => '007-1106-SELECT'.($index + 1),
                'order_date' => '2026-11-06', 'order_status' => 'pending_shipment',
                'currency' => 'USD', 'owner_id' => $viewer->id,
            ]);
            $order->address()->create(['recipient_name' => 'Selected Customer', 'country' => 'US']);
            $order->items()->create(['sku' => $sku, 'product_summary' => 'Sample '.$sku, 'quantity' => $index + 1, 'unit_price' => '20.00', 'line_revenue' => '20.00']);
            if ($index === 0) {
                $order->items()->create(['sku' => 'A1', 'product_summary' => 'Gift A1', 'quantity' => 1, 'unit_price' => '0.00', 'line_revenue' => '0.00']);
            }
            $created[] = $order;
        }
        $chosen = $created[0]->id.','.$created[1]->id;
        // The current filter matches orders 1 and 3, but the two checked orders are 1 and 2.
        $xlsx = $this->actingAs($viewer)->get('/orders/oms/export?selected_order_ids='.$chosen.'&grid_sku=MF414A');
        $xlsx->assertOk();
        $data = app(ProviderXlsxTableReader::class)->read(
            UploadedFile::fake()->createWithContent('selected.xlsx', $xlsx->getContent()));
        $this->assertCount(3, $data['rows']);
        $numbers = array_column(array_map(fn ($row) => $row['cells'], $data['rows']), 0);
        sort($numbers);
        $this->assertSame(['007-1106-SELECT1','007-1106-SELECT1','007-1106-SELECT2'], $numbers);
        $skus = array_column(array_map(fn ($row) => $row['cells'], $data['rows']), 4);
        sort($skus);
        $this->assertSame(['A1','A1','MF414A'], $skus);
        $csv = $this->actingAs($viewer)->get('/orders/oms/export?selected_order_ids='.$chosen.'&format=csv');
        $csv->assertOk();
        $this->assertCount(4, preg_split('/\\r?\\n/', trim($csv->streamedContent())));
        $this->assertDatabaseCount('orders', 3);
    }

    public function test_selected_order_ids_reject_invalid_duplicate_and_nonexistent_ids(): void
    {
        $viewer = $this->userWith(['orders.view']);
        foreach (['1,2,2', 'not-an-id', '999999999', '1,2,abc'] as $value) {
            $this->actingAs($viewer)->getJson('/orders/oms/export?selected_order_ids='.$value)->assertUnprocessable();
        }
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_kol_only_user_cannot_export_an_unrelated_customer_order_by_selected_id(): void
    {
        $kol = $this->userWith(['orders.view', 'kol.view']);
        $order = Order::query()->create([
            'source_type' => 'manual', 'order_kind' => 'customer',
            'internal_order_number' => 'KOL-UNRELATED',
            'external_order_number' => 'KOL-UNRELATED-EXT',
            'erp_order_id' => '007-1106-KOLPROTECTED',
            'order_date' => '2026-11-06', 'order_status' => 'pending_shipment',
            'currency' => 'USD', 'owner_id' => $kol->id,
        ]);
        $order->address()->create(['recipient_name' => 'Protected Buyer', 'country' => 'US']);
        $this->actingAs($kol)->getJson('/orders/oms/export?selected_order_ids='.$order->id)
            ->assertUnprocessable();
    }

    public function test_one_order_with_two_skus_exports_two_rows_not_one_concatenated_cell(): void
    {
        $user = $this->userWith(['orders.view']);
        $order = Order::query()->create([
            'source_type' => 'manual', 'order_kind' => 'customer', 'internal_order_number' => 'EXP-LINE-1',
            'external_order_number' => 'EXPORT-LINE-1', 'erp_order_id' => '007-1105-40223',
            'order_date' => '2026-11-05', 'order_status' => 'pending_shipment', 'currency' => 'USD',
            'owner_id' => $user->id,
        ]);
        $order->address()->create(['recipient_name'=>'Line Buyer','country'=>'US']);
        $order->items()->create(['sku'=>'MF414A','product_summary'=>'Product One','quantity'=>2,'unit_price'=>'79.00','line_revenue'=>'158.00']);
        $order->items()->create(['sku'=>'A1','product_summary'=>'Gift','quantity'=>1,'unit_price'=>'0.00','line_revenue'=>'0.00']);

        $xlsx = $this->actingAs($user)->get('/orders/oms/export?grid_order_number=40223');
        $xlsx->assertOk();
        $data = app(ProviderXlsxTableReader::class)->read(
            UploadedFile::fake()->createWithContent('export.xlsx', $xlsx->getContent()));
        $this->assertCount(2, $data['rows']);
        $this->assertSame('SKU数量', $data['headers'][5]);
        $this->assertSame('订单总件数', $data['headers'][6]);
        $this->assertSame('MF414A', $data['rows'][0]['cells'][4]);
        $this->assertSame('2', $data['rows'][0]['cells'][5]);
        $this->assertSame('3', $data['rows'][0]['cells'][6]);
        $this->assertSame('A1', $data['rows'][1]['cells'][4]);
        $this->assertSame('1', $data['rows'][1]['cells'][5]);
        $this->assertSame('007-1105-40223', $data['rows'][1]['cells'][0]);

        $skuOnly = $this->actingAs($user)->get('/orders/oms/export?grid_order_number=40223&grid_sku=MF414A');
        $skuOnly->assertOk();
        $skuFiltered = app(ProviderXlsxTableReader::class)->read(
            UploadedFile::fake()->createWithContent('sku_only.xlsx', $skuOnly->getContent()));
        $this->assertCount(1, $skuFiltered['rows']);
        $this->assertSame('MF414A', $skuFiltered['rows'][0]['cells'][4]);
        $this->assertSame('3', $skuFiltered['rows'][0]['cells'][6]); // full-order total retained

        $skuAndWrongQuantity = $this->actingAs($user)->get('/orders/oms/export?grid_sku=MF414A&grid_quantity=1');
        $skuAndWrongQuantity->assertOk();
        $empty = app(ProviderXlsxTableReader::class)->read(
            UploadedFile::fake()->createWithContent('none.xlsx', $skuAndWrongQuantity->getContent()));
        $this->assertCount(0, $empty['rows']);

        $csv = $this->actingAs($user)->get('/orders/oms/export?format=csv&grid_order_number=40223');
        $csv->assertOk();
        $lines = preg_split('/\r?\n/', trim($csv->streamedContent()));
        $this->assertCount(3, $lines);
    }

    public function test_template_download_uses_xlsx_with_matching_header_and_help_sheet(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create']);
        $result = $this->actingAs($user)->get('/orders/oms/import/template');
        $result->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $file = UploadedFile::fake()->createWithContent('template.xlsx', $result->getContent());
        $data = app(ProviderXlsxTableReader::class)->read($file);
        $this->assertSame(OrderOmsBatchImport::FIELDS, $data['headers']);
        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($file->getRealPath());
        $this->assertSame(['批量订单导入', '使用说明', '示例-勿上传'], $book->getSheetNames());
        $example = $book->getSheetByName('示例-勿上传');
        $this->assertSame('DEMO-1001', $example->getCell('A2')->getValue());
        $this->assertSame('DEMO-1001', $example->getCell('A3')->getValue());
        $this->assertSame('MF414A', $example->getCell('M2')->getValue());
        $this->assertSame('A1', $example->getCell('M3')->getValue());
        $this->assertSame('DEMO-1002', $example->getCell('A4')->getValue());
        $book->disconnectWorksheets();
    }

    public function test_preflight_then_confirm_creates_audited_manual_customer_order_once(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create']);
        Product::query()->create(['sku' => 'MF414A', 'name' => 'MF414A Sample', 'is_active' => true]);
        $file = $this->xlsx();
        $result = $this->actingAs($user)->post('/orders/oms/import/preview', [
            'file' => $file,
        ])->assertOk()->json();
        $this->assertSame(1, $result['count']);
        $this->assertSame([], $result['errors']);
        $this->assertNotEmpty($result['token']);
        $this->assertDatabaseCount('orders', 0); // Preview must never create.
        $created = $this->actingAs($user)->post('/orders/oms/import/confirm', [
            'file' => $file, 'token' => $result['token'], 'confirm' => '1',
        ])->assertOk()->json();
        $this->assertSame(1, $created['created']);
        $order = Order::query()->where('external_order_number', 'R3-IMPORT-0001')->firstOrFail();
        $this->assertSame('manual', $order->source_type->value);
        $this->assertSame('customer', $order->order_kind->value);
        $this->assertNotNull($order->erp_order_id);
        $this->assertSame(2, (int) $order->items()->firstOrFail()->quantity);
        $this->assertSame('MF414A', $order->items()->firstOrFail()->sku);
        $this->assertSame('Test Customer', $order->address?->recipient_name);
        $response = $this->actingAs($user)->post('/orders/oms/import/confirm', [
            'file' => $file, 'token' => $result['token'], 'confirm' => '1',
        ]);
        $response->assertUnprocessable();
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_preflight_reports_unknown_sku_and_duplicate_without_any_writes(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create']);
        $result = $this->actingAs($user)->post('/orders/oms/import/preview', ['file' => $this->xlsx(sku: 'INVALID')])->assertOk()->json();
        $this->assertNull($result['token']);
        $this->assertNotEmpty($result['errors']);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_preflight_token_cannot_be_used_by_different_user_or_file(): void
    {
        Product::query()->create(['sku' => 'MF414A', 'name' => 'Test']);
        $first = $this->userWith(['orders.view', 'orders.create']);
        $second = $this->userWith(['orders.view', 'orders.create']);
        $token = $this->actingAs($first)->post('/orders/oms/import/preview', ['file' => $this->xlsx()])->assertOk()->json('token');
        $this->actingAs($second)->post('/orders/oms/import/confirm', [
            'file' => $this->xlsx(), 'token' => $token, 'confirm' => '1',
        ])->assertUnprocessable();
        $this->actingAs($first)->post('/orders/oms/import/confirm', [
            'file' => $this->xlsx(externalNumber: 'CHANGED-ORDER'), 'token' => $token, 'confirm' => '1',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_import_requires_both_view_and_create_permissions(): void
    {
        $viewer = $this->userWith(['orders.view']);
        $this->actingAs($viewer)->get('/orders/oms/import/template')->assertForbidden();
        $this->actingAs($viewer)->post('/orders/oms/import/preview', ['file' => $this->xlsx()])->assertForbidden();
        $this->actingAs($viewer)->post('/orders/oms/import/confirm', [
            'file' => $this->xlsx(), 'token' => 'invalid', 'confirm' => 1,
        ])->assertForbidden();
        $creator = $this->userWith(['orders.create']);
        $this->actingAs($creator)->get('/orders/oms/import/template')->assertForbidden();
        $this->actingAs($creator)->get('/orders/oms/export')->assertForbidden();
    }

    public function test_kol_only_specialist_cannot_bulk_create_customer_orders_even_with_create_permission(): void
    {
        $kolOnly = $this->userWith(['orders.view', 'orders.create', 'kol.view']);
        $this->actingAs($kolOnly)->get('/orders/oms/import/template')->assertForbidden();
        $this->actingAs($kolOnly)->post('/orders/oms/import/preview', ['file' => $this->xlsx()])->assertForbidden();
        $this->actingAs($kolOnly)->post('/orders/oms/import/confirm', [
            'file' => $this->xlsx(), 'token' => 'invalid', 'confirm' => '1',
        ])->assertForbidden();
    }

    public function test_csv_export_is_server_filtered_and_escapes_spreadsheet_formulas(): void
    {
        $user = $this->userWith(['orders.view']);
        $order = Order::query()->create([
            'source_type' => 'manual', 'order_kind' => 'customer', 'internal_order_number' => 'IT-CSV-001',
            'external_order_number' => 'CSV-001', 'erp_order_id' => '007-1105-CSV01',
            'order_date' => '2026-11-05', 'order_status' => 'pending_shipment', 'currency' => 'USD',
            'owner_id' => $user->id, 'order_note' => '=HYPERLINK("evil")',
        ]);
        $order->address()->create(['recipient_name' => 'CSV Tester', 'country' => 'US']);
        $order->items()->create([
            'sku' => 'MF414A', 'product_summary' => 'Sample', 'quantity' => 2,
            'unit_price' => '79.00', 'line_revenue' => '158.00',
        ]);
        $response = $this->actingAs($user)->get('/orders/oms/export?format=csv&grid_sku=MF414A');
        $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $content = $response->streamedContent();
        $this->assertStringContainsString('MF414A', $content);
        $this->assertStringContainsString('SKU数量', $content);
        $this->assertStringContainsString("'=HYPERLINK", $content);
        $this->assertStringNotContainsString('CSV-999', $content);
    }

    public function test_export_download_returns_xlsx_with_sku_quantity_and_business_filter(): void
    {
        $user = $this->userWith(['orders.view']);
        $order = Order::query()->create([
            'source_type' => 'manual', 'order_kind' => 'customer', 'internal_order_number' => 'IT-001',
            'external_order_number' => 'EXPORT-001', 'erp_order_id' => '007-1105-40001',
            'order_date' => '2026-11-05', 'order_status' => 'pending_shipment', 'currency' => 'USD',
            'owner_id' => $user->id,
        ]);
        $order->address()->create(['recipient_name' => 'Exporter', 'country' => 'US']);
        $order->items()->create(['sku' => 'A1', 'product_summary' => 'Product A', 'quantity' => 3,
            'unit_price' => '19.00', 'line_revenue' => '57.00']);
        $res = $this->actingAs($user)->get('/orders/oms/export?grid_sku=A1');
        $res->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $bytes = $res->getContent();
        $data = app(ProviderXlsxTableReader::class)->read(UploadedFile::fake()->createWithContent('export.xlsx', $bytes));
        $this->assertSame('SKU', $data['headers'][4]);
        $this->assertSame('SKU数量', $data['headers'][5]);
        $this->assertSame('A1', $data['rows'][0]['cells'][4]);
        $this->assertSame('3', $data['rows'][0]['cells'][5]);
    }
}

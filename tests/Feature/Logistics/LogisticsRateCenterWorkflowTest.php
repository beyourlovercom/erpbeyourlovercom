<?php

namespace Tests\Feature\Logistics;

use App\Models\Role;
use App\Models\User;
use App\Modules\Finance\Application\FxReferenceSettingsService;
use App\Modules\Logistics\Application\FulfillmentPlanCostEstimator;
use App\Modules\Logistics\Application\LogisticsRateDraftService;
use App\Modules\Logistics\Application\LogisticsRatePostalEvidenceReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

final class LogisticsRateCenterWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
    }

    private function actor(array $permissions): User
    {
        $role = Role::query()->create(['key' => 'rate-test-'.uniqid(), 'label' => 'Rate Test']);
        foreach ($permissions as $p) {
            $role->permissions()->firstOrCreate(['key' => $p], ['label' => $p]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }

    private function fixture(float $firstRate = 3.82, float $fuelPercent = 3, int $dimDivisor = 6000, float $extra = 0.8): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'erp-rate-test-');
        $book = new Spreadsheet;
        $local = $book->getActiveSheet()->setTitle('DE-DHL （本地）');
        $bands = ['0-1KG', '1-2KG', '2-3KG', '3-5KG', '5-10KG', '10-15KG', '15-20KG', '20-25KG', '25-31.5KG'];
        $prices = [$firstRate, 3.97, 4.27, 4.55, 5.03, 5.53, 6.22, 6.81, 7.14];
        foreach ($bands as $i => $band) {
            $local->setCellValue('B'.($i + 5), $band);
            $local->setCellValue('C'.($i + 5), $prices[$i]);
        }
        $local->setCellValue('B17', '燃油费率'.$fuelPercent.'%，燃油附加费=（运费+附加费）*燃油率');
        $local->setCellValue('B18', '实重计费，采用向上进一取整原则');
        $local->setCellValue('B19', '尺寸限制：L＜120cm，W＜60cm，H＜60cm，周长【L+2*(W+H)】＜360cm，包裹最小尺寸：15cm*11cm*1cm;');
        $local->setCellValue('B20', '重量限制：31.5kg');
        $local->setCellValue('C25', 0.19);
        $local->setCellValue('E25', '11 月 1 号至 12 月 31 号期间');
        $handling = $book->createSheet()->setTitle('库内基础服务费用');
        $handling->setCellValue('E12', '计费重以体积重与实重取大值计算，体积系数除以'.$dimDivisor.'。每增加1件加收 €'.$extra.';');
        foreach (['0 <x≤ 2 kg', '2 <x≤ 5 kg', '5<x≤ 10 kg', '10 <x≤ 20 kg', '20 <x≤ 30 kg'] as $i => $band) {
            $handling->setCellValue('C'.($i + 13), $band);
            $handling->setCellValue('D'.($i + 13), [0.8, 1.2, 1.68, 3, 4.2][$i]);
        }
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return new UploadedFile($path, 'synthetic-germany.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function saveFixture(User $actor, float $price = 3.82): int
    {
        $file = $this->fixture($price);
        try {
            $service = app(LogisticsRateDraftService::class);
            $preview = $service->preview($file);

            return $service->save($file, $preview['sha256'], $preview['previous_version_id'], '2025-05-05', $actor->id)['version_id'];
        } finally {
            unlink($file->getPathname());
        }
    }

    private function request(int $id, array $package = []): array
    {
        return ['version_id' => $id, 'warehouse_code' => 'DE', 'carrier' => 'DHL', 'service' => 'domestic', 'country' => 'DE', 'postal_code' => '10115',
            'as_of' => '2026-10-01', 'handling_policy' => 'supplier', 'packages' => [['weight_kg' => 1, 'weight_basis' => 'measured_packaged', 'dimensions_cm' => [20, 15, 10], 'item_count' => 2, 'eligibility' => 'confirmed', ...$package]]];
    }

    public function test_page_permission_and_draft_import_preview_diff_and_download(): void
    {
        $viewer = $this->actor(['logistics.view']);
        $admin = $this->actor(['logistics.view', 'logistics.operate', 'system.permissions.manage']);
        $none = $this->actor([]);
        $this->actingAs($none)->get('/logistics/rates')->assertForbidden();
        $this->actingAs($viewer)->get('/logistics/rates')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Logistics/RateCenter/Index')->where('canImport', false));
        $this->actingAs($viewer)->postJson('/logistics/rates/preview', [])->assertForbidden();
        $file = $this->fixture();
        try {
            $preview = $this->actingAs($admin)->post('/logistics/rates/preview', ['file' => $file], ['Accept' => 'application/json'])->assertOk()
                ->assertJsonPath('band_count', 14)->assertJsonPath('approval_allowed', false)->json();
            $this->assertDatabaseCount('logistics_rate_versions', 0);
            $save = $this->actingAs($admin)->post('/logistics/rates/draft', ['file' => $file, 'sha256' => $preview['sha256'], 'effective_from' => '2025-05-05'], ['Accept' => 'application/json'])->assertOk()->json();
            $id = $save['version_id'];
            $this->assertDatabaseHas('logistics_rate_versions', ['id' => $id, 'status' => 'draft', 'approved_by' => null]);
            $this->assertDatabaseCount('logistics_rate_bands', 14);
            $this->assertDatabaseCount('logistics_rate_components', 2);
            $this->actingAs($admin)->get('/logistics/rates?view=bands&version_id='.$id)->assertOk()->assertInertia(fn (Assert $p) => $p->component('Logistics/RateCenter/Index')->where('rows.total', 14)->where('selectedVersion.status', 'draft'));
            $this->actingAs($viewer)->get('/logistics/rates/'.$id.'/original')->assertOk()->assertDownload('synthetic-germany.xlsx');
            $this->actingAs($admin)->post('/logistics/rates/draft', ['file' => $file, 'sha256' => $preview['sha256']], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('duplicate', true);
            $this->assertDatabaseCount('logistics_rate_versions', 1);
            $changed = $this->fixture(4.12);
            try {
                $diff = app(LogisticsRateDraftService::class)->preview($changed);
                $this->assertSame(1, $diff['counts']['changed']);
                $this->assertSame(15, $diff['counts']['unchanged']);
                $this->assertSame($id, $diff['previous_version_id']);
                $this->assertSame('3.820000', $diff['changes'][0]['old']['fixed']);
                $this->assertSame('4.120000', $diff['changes'][0]['new']['fixed']);
                $this->actingAs($admin)->post('/logistics/rates/draft', ['file' => $changed, 'sha256' => $diff['sha256'], 'previous_version_id' => $id], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('duplicate', false);
                $this->assertDatabaseCount('logistics_rate_versions', 2);
            } finally {
                unlink($changed->getPathname());
            }
        } finally {
            unlink($file->getPathname());
        }
    }

    public function test_versioned_quote_contract_with_source_based_isolated_dhl_fixture(): void
    {
        $actor = $this->actor(['logistics.view', 'logistics.operate', 'system.permissions.manage']);
        $id = $this->saveFixture($actor);
        $estimator = app(FulfillmentPlanCostEstimator::class);
        $request = $this->request($id);
        $this->assertSame('rate_version_not_approved', $estimator->quoteVersioned($request)['reason']);
        // Synthetic test-only approval of a bounded fixture. No real supplier version is activated.
        DB::table('logistics_rate_versions')->where('id', $id)->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $actor->id]);
        foreach (DB::table('logistics_rate_components')->where('version_id', $id)->get() as $component) {
            $rule = json_decode($component->rule_spec, true);
            $rule['coverage_complete'] = true;
            DB::table('logistics_rate_components')->where('id', $component->id)->update(['rule_spec' => json_encode($rule), 'review_status' => 'verified']);
        }
        app(FxReferenceSettingsService::class)->save(['USD' => 7.5, 'EUR' => 8], ['USD' => false, 'EUR' => false], null);
        $quote = $estimator->quoteVersioned($request);
        $this->assertSame('ready', $quote['status']);
        $this->assertEqualsWithDelta(5.5346, $quote['native_totals']['EUR'], 0.000001);
        $this->assertEqualsWithDelta(44.2768, $quote['total_cny'], 0.000001);
        $this->assertEqualsWithDelta(3.9346, $quote['components'][0]['amount'], 0.000001);
        $this->assertEqualsWithDelta(1.6, $quote['components'][1]['amount'], 0.000001);
        $request['handling_policy'] = 'internal';
        $quote = $estimator->quoteVersioned($request);
        $this->assertEqualsWithDelta(4.7346, $quote['native_totals']['EUR'], 0.000001);
        $this->assertEqualsWithDelta(1, $quote['native_totals']['USD'], 0.000001);
        $this->assertEqualsWithDelta(45.3768, $quote['total_cny'], 0.000001);
        $request = $this->request($id, ['weight_kg' => 2.232, 'dimensions_cm' => [39, 25.5, 18.7], 'item_count' => 1]);
        $quote = $estimator->quoteVersioned($request);
        $this->assertSame('ready', $quote['status']);
        $this->assertEqualsWithDelta(3, $quote['components'][0]['billable_weight'], 0.000001);
        $this->assertEqualsWithDelta(3.099525, $quote['components'][1]['dimensional_weight'], 0.000001);
        $this->assertEqualsWithDelta(5.5981, $quote['native_totals']['EUR'], 0.000001);
        $request = $this->request($id, ['weight_kg' => 1, 'weight_basis' => 'bare', 'item_count' => 1]);
        $quote = $estimator->quoteVersioned($request);
        $this->assertEqualsWithDelta(1.2, $quote['components'][0]['gross_weight_kg'], 0.000001);
        $this->assertEqualsWithDelta(2, $quote['components'][0]['billable_weight'], 0.000001);
        $this->assertSame('missing_dimensions', $estimator->quoteVersioned($this->request($id, ['dimensions_cm' => [20, 15, 0]]))['reason']);
        $this->assertSame('eligibility_unconfirmed', $estimator->quoteVersioned($this->request($id, ['eligibility' => 'unknown']))['reason']);
        $this->assertSame('rate_scope_missing', $estimator->quoteVersioned([...$this->request($id), 'warehouse_code' => 'US-NY'])['reason']);
        $this->assertSame('rate_version_outside_effective_dates', $estimator->quoteVersioned([...$this->request($id), 'as_of' => '2025-05-04'])['reason']);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('logistics_rate_versions', 1);
        $this->assertSame('ready', $this->actingAs($actor)->postJson('/logistics/rates/quote', $this->request($id))->assertOk()->json('status'));
    }

    public function test_scoped_approval_rejects_missing_confirmation_and_preserves_history(): void
    {
        $actor = $this->actor(['logistics.view', 'logistics.operate', 'system.permissions.manage']);
        $viewer = $this->actor(['logistics.view']);
        $id = $this->saveFixture($actor);
        $version = DB::table('logistics_rate_versions')->find($id);
        $source = DB::table('logistics_rate_source_files')->find($version->source_file_id);
        $url = '/logistics/rates/'.$id.'/approve-germany-domestic';
        $approval = ['sha256' => $source->sha256, 'evidence' => 'Synthetic supplier scope and rule verification for test only',
            'confirm_contract' => true, 'confirm_rules' => true, 'confirm_restrictions' => true];
        $this->actingAs($viewer)->postJson($url, $approval)->assertForbidden();
        $this->actingAs($actor)->postJson($url, [...$approval, 'confirm_rules' => false])->assertUnprocessable();
        $this->actingAs($actor)->postJson($url, [...$approval, 'sha256' => str_repeat('0', 64)])->assertUnprocessable();
        $this->assertDatabaseHas('logistics_rate_versions', ['id' => $id, 'status' => 'draft']);
        $this->assertSame(0, DB::table('logistics_rate_components')->where('review_status', 'verified')->count());
        app(FxReferenceSettingsService::class)->save(['EUR' => 8, 'USD' => 7.5], ['EUR' => false, 'USD' => false], null);
        $estimator = app(FulfillmentPlanCostEstimator::class);
        $draft = $estimator->quoteDraftTransport($this->request($id));
        $this->assertSame('draft_estimate', $draft['status']);
        $this->assertFalse($draft['eligible_for_ranking']);
        $this->assertNull($draft['total_cny']);
        $this->assertNull($draft['native_totals']);
        $this->assertEqualsWithDelta(3.9346, $draft['reference_native_totals']['EUR'], 0.000001);
        $this->assertCount(1, $draft['components']);
        $this->actingAs($viewer)->postJson('/logistics/rates/quote', [...$this->request($id), 'mode' => 'draft_transport'])->assertOk()->assertJsonPath('status', 'draft_estimate');
        $this->assertSame('rate_version_not_approved', $estimator->quoteVersioned($this->request($id))['reason']);
        $this->actingAs($actor)->postJson($url, $approval)->assertOk()->assertJsonPath('status', 'approved');
        $this->assertSame('ready', $estimator->quoteVersioned($this->request($id))['status']);
        $this->assertSame('draft_rate_version_required', $estimator->quoteDraftTransport($this->request($id))['reason']);
        $this->assertDatabaseHas('logistics_rate_versions', ['id' => $id, 'status' => 'approved', 'approved_by' => $actor->id]);
        $this->actingAs($actor)->postJson($url, $approval)->assertUnprocessable();
        $file = $this->fixture(4.12);
        try {
            $service = app(LogisticsRateDraftService::class);
            $diff = $service->preview($file);
            $this->assertSame(1, $diff['counts']['changed']);
            $newId = $service->save($file, $diff['sha256'], $id, '2026-11-01', $actor->id)['version_id'];
            $newApproval = [...$approval, 'sha256' => $diff['sha256']];
            $this->actingAs($actor)->postJson('/logistics/rates/'.$newId.'/approve-germany-domestic', $newApproval)->assertOk();
            $this->assertDatabaseHas('logistics_rate_versions', ['id' => $id, 'status' => 'approved', 'effective_until' => '2026-10-31']);
            $this->assertSame('rate_version_outside_effective_dates', $estimator->quoteVersioned([...$this->request($id), 'as_of' => '2026-11-01'])['reason']);
            $this->assertSame('ready', $estimator->quoteVersioned([...$this->request($newId), 'as_of' => '2026-11-01'])['status']);
        } finally {
            unlink($file->getPathname());
        }
    }

    public function test_supplier_rule_values_are_read_from_new_workbook_not_frozen_constants(): void
    {
        $actor = $this->actor(['logistics.view', 'logistics.operate', 'system.permissions.manage']);
        $id = $this->saveFixture($actor);
        $file = $this->fixture(3.82, 4, 7000, 0.9);
        try {
            $service = app(LogisticsRateDraftService::class);
            $preview = $service->preview($file);
            $this->assertGreaterThan(0, $preview['counts']['changed']);
            $newId = $service->save($file, $preview['sha256'], $id, '2026-11-01', $actor->id)['version_id'];
            $rows = DB::table('logistics_rate_components')->where('version_id', $newId)->get();
            $this->assertEquals(4, json_decode($rows->firstWhere('component_type', 'transport')->rule_spec, true)['fuel_percent']);
            $handling = $rows->firstWhere('component_type', 'handling');
            $this->assertEquals(7000, json_decode($handling->rule_spec, true)['dim_divisor']);
            $this->assertEqualsWithDelta(0.9, (float) DB::table('logistics_rate_bands')->where('component_id', $handling->id)->first()->per_extra_item_amount, 0.000001);
            $this->assertDatabaseHas('logistics_rate_versions', ['id' => $newId, 'status' => 'draft']);
        } finally {
            unlink($file->getPathname());
        }
    }

    public function test_postal_reader_preserves_text_and_does_not_execute_or_read_formula_cells(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'erp-postal-');
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('邮编分区');
        $sheet->setCellValue('L1', '【澳大利亚】分区邮编');
        $sheet->setCellValueExplicit('M3', '0200', DataType::TYPE_STRING);
        $sheet->setCellValue('M4', '=1+1');
        $sheet->setCellValue('Z3', 'not requested');
        try {
            $writer = new Xlsx($book);
            $writer->setPreCalculateFormulas(false);
            $writer->save($path);
            $result = (new LogisticsRatePostalEvidenceReader)->read($path, '邮编分区', ['L', 'M'], 10);
            $this->assertSame('0200', $result['M3']);
            $this->assertSame('【澳大利亚】分区邮编', $result['L1']);
            $this->assertArrayNotHasKey('M4', $result);
            $this->assertArrayNotHasKey('Z3', $result);
        } finally {
            $book->disconnectWorksheets();
            unlink($path);
        }
    }

    public function test_real_supplier_files_are_preserved_as_drafts_when_local_sources_are_available(): void
    {
        $dir = getenv('BYL_RATE_SOURCE_DIR');
        if (! $dir || ! is_dir($dir)) {
            $this->markTestSkipped('Private original supplier files are not committed to Git.');
        }
        $this->assertSame('testing', app()->environment());
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $actor = $this->actor(['logistics.view', 'logistics.operate', 'system.permissions.manage']);
        $service = app(LogisticsRateDraftService::class);
        $expected = ['tianyun_de' => [34, 46, 0], 'speeda_us' => [29, 1854, 6944], 'speeda_sd' => [389, 875, 28332], 'territory_evidence' => [0, 0, 0]];
        $files = glob($dir.'/*.{xlsx,xls}', GLOB_BRACE);
        $this->assertCount(4, $files);
        foreach ($files as $path) {
            $file = new UploadedFile($path, basename($path), null, null, true);
            $preview = $service->preview($file);
            $this->assertSame($expected[$preview['book_key']], [$preview['component_count'], $preview['band_count'], $preview['zone_count']]);
            $this->assertFalse($preview['approval_allowed']);
            $effective = ['tianyun_de' => '2025-05-05', 'speeda_us' => '2026-05-01', 'speeda_sd' => '2026-11-23', 'territory_evidence' => null][$preview['book_key']];
            $id = $service->save($file, $preview['sha256'], $preview['previous_version_id'], $effective, $actor->id)['version_id'];
            $this->assertDatabaseHas('logistics_rate_versions', ['id' => $id, 'status' => 'draft']);
            $again = $service->preview($file);
            $this->assertSame(0, $again['counts']['changed']);
            $this->assertSame(0, $again['counts']['added']);
            $this->assertSame(0, $again['counts']['removed']);
            $this->assertTrue($service->save($file, $again['sha256'], $again['previous_version_id'], null, $actor->id)['duplicate']);
        }
        $this->assertDatabaseCount('logistics_rate_source_files', 4);
        $this->assertDatabaseCount('logistics_rate_versions', 4);
        $this->assertDatabaseCount('logistics_rate_components', 452);
        $this->assertDatabaseCount('logistics_rate_bands', 2775);
        $this->assertDatabaseCount('logistics_rate_zones', 35276);
        $this->assertSame(0, DB::table('logistics_rate_versions')->where('status', 'approved')->count());
        $international = DB::table('logistics_rate_components')->where('component_key', 'de.dhl.international.fr')->first();
        $this->assertSame('review_required', json_decode($international->rule_spec, true)['basis']);
        $frBand = DB::table('logistics_rate_bands')->where('component_id', $international->id)->first();
        $this->assertEqualsWithDelta(0.5345, (float) $frBand->per_weight_amount, 0.000001);
        $this->assertEqualsWithDelta(8.0205, (float) $frBand->fixed_amount, 0.000001);
        $this->assertStringContainsString('0.001', json_decode($frBand->raw_values, true)['formula_header']);
        $us = DB::table('logistics_rate_components')->where('service', 'sd_standard_special')->where('destination_country', 'US')->first();
        $usBand = DB::table('logistics_rate_bands')->where('component_id', $us->id)->orderBy('sequence')->first();
        $this->assertEqualsWithDelta(134, (float) $usBand->per_weight_amount, 0.000001);
        $this->assertEqualsWithDelta(27, (float) $usBand->fixed_amount, 0.000001);
        $this->assertSame(8000, json_decode($us->rule_spec, true)['dim_divisor']);
        $za = DB::table('logistics_rate_components')->where('service', 'sd_standard_general')->where('destination_country', 'ZA')->first();
        $zaRule = json_decode($za->rule_spec, true);
        $this->assertSame(6000, $zaRule['dim_divisor']);
        $this->assertCount(3, $zaRule['rounding_tiers']);
        $this->assertDatabaseHas('logistics_rate_components', ['warehouse_code' => 'US-NY', 'carrier' => 'USPS', 'service' => 'ground_advantage']);
        $this->assertDatabaseHas('logistics_rate_components', ['warehouse_code' => 'SPEEDA-US-LA', 'carrier' => 'USPS', 'service' => 'ground_advantage']);
        $this->assertSame(0, DB::table('logistics_rate_components')->where('warehouse_code', 'US-LA')->count());
        $this->assertDatabaseHas('logistics_rate_zones', ['service' => 'evidence.au_zone_a', 'postal_start' => '0200', 'zone_label' => '澳大利亚-2区', 'review_status' => 'needs_review']);
        $this->assertDatabaseHas('logistics_rate_zones', ['service' => 'evidence.us_island', 'postal_start' => '00602', 'review_status' => 'needs_review']);
        $this->assertSame('rate_version_not_approved', app(FulfillmentPlanCostEstimator::class)->quoteVersioned([...$this->request((int) $us->version_id), 'warehouse_code' => 'FACTORY', 'carrier' => 'SPEEDA', 'service' => 'sd_standard_special', 'country' => 'US', 'postal_code' => '10001'])['reason']);
        $estimator = app(FulfillmentPlanCostEstimator::class);
        $request = [...$this->request((int) $us->version_id, ['weight_kg' => 0.08, 'dimensions_cm' => [15, 10, 1], 'item_count' => 1]),
            'warehouse_code' => 'FACTORY', 'carrier' => 'SPEEDA', 'service' => 'sd_standard_special', 'country' => 'US', 'postal_code' => '10001', 'as_of' => '2026-11-23'];
        $trial = $estimator->quoteDraftTransport($request);
        $this->assertSame('draft_estimate', $trial['status']);
        $this->assertNull($trial['total_cny']);
        foreach (['sd_economy_battery' => 'A108', 'sd_standard_general' => 'A390', 'sd_economy_general' => 'A119'] as $restrictedService => $cell) {
            $restricted = DB::table('logistics_rate_components')->where('service', $restrictedService)->where('destination_country', 'US')->first();
            $this->assertSame($cell, json_decode($restricted->rule_spec, true)['catalog_restriction_source_cell']);
            $blocked = $estimator->quoteDraftTransport([...$request, 'service' => $restrictedService]);
            $this->assertSame('supplier_catalog_restriction', $blocked['reason']);
            $this->assertNull($blocked['total_cny']);
        }

        $this->assertEqualsWithDelta(37.72, $trial['reference_native_totals']['CNY'], 0.000001);
        $this->assertSame('C3:E3', $trial['components'][0]['source_cells']);
        $zaTrial = $estimator->quoteDraftTransport([...$request, 'service' => 'sd_standard_general', 'country' => 'ZA', 'postal_code' => '8001',
            'packages' => [['weight_kg' => 2.001, 'weight_basis' => 'measured_packaged', 'dimensions_cm' => [20, 15, 10], 'item_count' => 1, 'eligibility' => 'confirmed']]]);
        $this->assertSame('draft_estimate', $zaTrial['status']);
        $this->assertEqualsWithDelta(2.5, $zaTrial['components'][0]['billable_weight'], 0.000001);
        $this->assertDatabaseHas('logistics_rate_versions', ['id' => $us->version_id, 'status' => 'draft']);

    }
}

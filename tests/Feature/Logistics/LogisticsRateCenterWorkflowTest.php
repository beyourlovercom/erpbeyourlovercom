<?php

namespace Tests\Feature\Logistics;

use App\Models\Role;
use App\Models\User;
use App\Modules\Finance\Application\FxReferenceSettingsService;
use App\Modules\Logistics\Application\FulfillmentPlanCostEstimator;
use App\Modules\Logistics\Application\LogisticsRateDraftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
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

    private function fixture(float $firstRate = 3.82): UploadedFile
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
        $handling = $book->createSheet()->setTitle('库内基础服务费用');
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
        $expected = ['tianyun_de' => [2, 14, 0], 'speeda_us' => [26, 1836, 6944], 'speeda_sd' => [11, 44, 0], 'territory_evidence' => [0, 0, 0]];
        $files = glob($dir.'/*.{xlsx,xls}', GLOB_BRACE);
        $this->assertCount(4, $files);
        foreach ($files as $path) {
            $file = new UploadedFile($path, basename($path), null, null, true);
            $preview = $service->preview($file);
            $this->assertSame($expected[$preview['book_key']], [$preview['component_count'], $preview['band_count'], $preview['zone_count']]);
            $this->assertFalse($preview['approval_allowed']);
            $id = $service->save($file, $preview['sha256'], $preview['previous_version_id'], null, $actor->id)['version_id'];
            $this->assertDatabaseHas('logistics_rate_versions', ['id' => $id, 'status' => 'draft']);
            $again = $service->preview($file);
            $this->assertSame(0, $again['counts']['changed']);
            $this->assertSame(0, $again['counts']['added']);
            $this->assertSame(0, $again['counts']['removed']);
            $this->assertTrue($service->save($file, $again['sha256'], $again['previous_version_id'], null, $actor->id)['duplicate']);
        }
        $this->assertDatabaseCount('logistics_rate_source_files',4);
        $this->assertDatabaseCount('logistics_rate_versions',4);
        $this->assertDatabaseCount('logistics_rate_components',39);
        $this->assertDatabaseCount('logistics_rate_bands',1894);
        $this->assertDatabaseCount('logistics_rate_zones',6944);
        $this->assertSame(0,DB::table('logistics_rate_versions')->where('status','approved')->count());
    }
}

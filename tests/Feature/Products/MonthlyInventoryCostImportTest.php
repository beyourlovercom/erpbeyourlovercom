<?php

namespace Tests\Feature\Products;

use App\Modules\Identity\Application\RolePreset;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductCostSource;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

final class MonthlyInventoryCostImportTest extends TestCase
{
    use RefreshDatabase;

    private function operator(array $permissions = ['products.view', 'products.operate']): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['key' => 'cost-import-'.uniqid(), 'label' => 'Cost importer']);
        foreach ($permissions as $permission) {
            $role->permissions()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        }
        $user->roles()->attach($role);

        return $user;
    }

    private function administrator(): User
    {
        $user = $this->operator();
        $role = Role::firstOrCreate(
            ['key' => RolePreset::Administrator->roleKey()],
            ['label' => 'UAT Administrator'],
        );
        $user->roles()->attach($role);

        return $user;
    }

    private function inProduction(callable $callback): void
    {
        $priorEnv = $this->app['env'];
        $this->app['env'] = 'production';
        $this->withoutMiddleware(PreventRequestForgery::class);
        try {
            $callback();
        } finally {
            $this->app['env'] = $priorEnv;
        }
    }

    private function workbook(): UploadedFile
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('10月进销存');
        $sheet->fromArray([
            ['名称', '产品长代码', '期初数量', '期初数值', '入库数量', '期末数量', '成本单价', '原成本单价'],
            ['VA617A（国内新版）', '', 1, 1, 1, 1, 90, 28],
            ['VA617A 包装更新款', '', 1, 1, 1, 1, 90, 29],
        ], null, 'A1');
        $file = tempnam(sys_get_temp_dir(), 'monthly-cost-feature-').'.xlsx';
        try {
            (new Xlsx($book))->save($file);

            return UploadedFile::fake()->createWithContent('warehouse-2026.xlsx', file_get_contents($file));
        } finally {
            $book->disconnectWorksheets();
            @unlink($file);
        }
    }

    private function fakeFx(): void
    {
        Http::fake(['ecb.europa.eu/*' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref">
<Cube><Cube time="2026-11-18"><Cube currency="USD" rate="1.10"/><Cube currency="EUR" rate="1.00"/><Cube currency="CNY" rate="7.81"/><Cube currency="GBP" rate="0.88"/><Cube currency="CAD" rate="1.57"/><Cube currency="AUD" rate="1.73"/><Cube currency="PHP" rate="64.0"/></Cube></Cube>
</gesmes:Envelope>
XML, 200)]);
    }

    public function test_operate_permission_is_required_for_all_import_routes(): void
    {
        $this->get('/products/cost-import')->assertRedirect('/login');
        $this->actingAs($this->operator(['products.view']))
            ->get('/products/cost-import')->assertForbidden();
        $this->postJson('/products/cost-import/preview', [])->assertForbidden();
        $this->postJson('/products/cost-import/plan', [])->assertForbidden();
        $this->postJson('/products/cost-import/confirm', [])->assertForbidden();
    }

    public function test_live_xlsx_preview_resolves_basic_sku_but_does_not_write_cost(): void
    {
        $this->fakeFx();
        Product::create(['source_type' => 'manual', 'product_type' => 'simple', 'sku' => 'VA617A', 'name' => 'ERP VA617A']);
        $this->actingAs($this->operator());
        $this->get('/products/cost-import')->assertOk()->assertInertia(fn ($page) => $page->component('Products/MonthlyCostImport')->where('applyEnabled', false));
        $preview = $this->postJson('/products/cost-import/preview', ['file' => $this->workbook(), 'year' => 2026])
            ->assertOk()->assertJsonPath('summary.groups', 1)->assertJsonPath('summary.price_conflicts', 1)
            ->assertJsonPath('groups.0.base_sku', 'VA617A')->assertJsonPath('groups.0.status', 'blocked');
        $this->assertDatabaseCount('product_cost_sources', 0);

        $fingerprint = $preview->json('fingerprint');
        $group = $preview->json('groups.0');
        $this->postJson('/products/cost-import/plan', [
            'fingerprint' => $fingerprint, 'decisions' => [
                $group['group_key'] => [
                    'source_variant_key' => $group['sources'][0]['source_variant_key'],
                    'manual_cost_cny' => '30.50', 'reason' => '财务核对实际采购单价',
                ],
            ],
        ])->assertOk()->assertJsonPath('summary.ready', 1)->assertJsonPath('summary.canonical_create', 1)
            ->assertJsonPath('groups.0.selected_unit_cost_cny', '30.500000000000')
            ->assertJsonPath('groups.0.usd_reference_cost', '4.2957')
            ->assertJsonPath('can_apply', true);
        $this->assertDatabaseCount('product_cost_sources', 0);
    }

    public function test_confirm_is_forbidden_by_default_even_with_valid_operator_and_no_writes(): void
    {
        config(['erp_cost_import.dev_apply_enabled' => false]);
        $this->actingAs($this->operator())->postJson('/products/cost-import/confirm', [
            'fingerprint' => str_repeat('a', 64),
            'plan_fingerprint' => str_repeat('b', 64),
        ])->assertForbidden();
        $this->assertSame(0, ProductCostSource::query()->count());
    }

    public function test_production_guard_wins_even_if_dev_apply_flag_was_misconfigured(): void
    {
        config(['erp_cost_import.dev_apply_enabled' => true]);
        $priorEnv = $this->app['env'];
        $this->app['env'] = 'production';
        $this->withoutMiddleware(PreventRequestForgery::class);
        try {
            $this->actingAs($this->operator())->postJson('/products/cost-import/confirm', [
                'fingerprint' => str_repeat('a', 64),
                'plan_fingerprint' => str_repeat('b', 64),
            ])->assertForbidden();
            $this->assertDatabaseCount('product_cost_sources', 0);
        } finally {
            $this->app['env'] = $priorEnv;
        }
    }

    public function test_plan_rejects_unissued_upload_fingerprint(): void
    {
        $this->actingAs($this->operator())->postJson('/products/cost-import/plan', [
            'fingerprint' => str_repeat('c', 64),
        ])->assertUnprocessable()->assertJsonValidationErrors('fingerprint');
        $this->assertDatabaseCount('product_cost_sources', 0);
    }

    public function test_approved_dev_confirm_only_writes_canonical_cny_source_with_audit(): void
    {
        config(['erp_cost_import.dev_apply_enabled' => true]);
        $this->fakeFx();
        Product::create(['source_type' => 'manual', 'product_type' => 'simple', 'sku' => 'VA617A', 'name' => 'ERP VA617A']);
        $this->actingAs($this->operator());
        $preview = $this->postJson('/products/cost-import/preview', [
            'file' => $this->workbook(), 'year' => 2026,
        ])->assertOk();
        $group = $preview->json('groups.0');
        $decisions = [$group['group_key'] => [
            'source_variant_key' => $group['sources'][0]['source_variant_key'],
            'manual_cost_cny' => '30.50', 'reason' => '模拟财务核验',
        ]];
        $planned = $this->postJson('/products/cost-import/plan', [
            'fingerprint' => $preview->json('fingerprint'), 'decisions' => $decisions,
        ])->assertOk()->assertJsonPath('can_apply', true);

        $this->postJson('/products/cost-import/confirm', [
            'fingerprint' => $preview->json('fingerprint'),
            'plan_fingerprint' => $planned->json('plan_fingerprint'),
            'decisions' => $decisions,
            'approval_phrase' => '确认导入1条成本',
            'approval_reason' => '已核对工厂采购对账单和成本原始凭证',
        ])->assertOk()->assertJsonPath('created', 1)->assertJsonPath('noop', 0);
        $this->assertDatabaseCount('product_cost_sources', 1);
        $source = ProductCostSource::query()->firstOrFail();
        $this->assertSame('CNY', $source->currency);
        $this->assertSame('30.500000000000', (string) $source->unit_cost);
        $this->assertSame('2026-10-01', $source->effective_from->toDateString());
        $this->assertDatabaseHas('product_cost_sources', [
            'status' => 'confirmed', 'source_type' => 'inventory_cost_book',
        ]);
        $this->assertDatabaseHas('audit_records', ['action' => 'product.cost_import.user_confirmed']);
        $this->assertDatabaseHas('audit_records', [
            'action' => 'product.cost_import.batch_confirmed',
            'entity_id' => $preview->json('fingerprint'),
        ]);
    }

    /** @return array{preview:array,decisions:array,plan:array,product:Product} */
    private function prepareResolvedBatch(): array
    {
        $this->fakeFx();
        $product = Product::create(['source_type' => 'manual', 'product_type' => 'simple', 'sku' => 'VA617A', 'name' => 'ERP VA617A']);
        $preview = $this->postJson('/products/cost-import/preview', [
            'file' => $this->workbook(), 'year' => 2026,
        ])->assertOk()->json();
        $group = $preview['groups'][0];
        $decisions = [$group['group_key'] => [
            'source_variant_key' => $group['sources'][0]['source_variant_key'],
            'manual_cost_cny' => '30.50', 'reason' => '财务已核对工厂采购成本',
        ]];
        $plan = $this->postJson('/products/cost-import/plan', [
            'fingerprint' => $preview['fingerprint'], 'decisions' => $decisions,
        ])->assertOk()->assertJsonPath('can_apply', true)->json();

        return compact('preview', 'decisions', 'plan', 'product');
    }

    public function test_production_gate_requires_real_administrator_even_when_dev_flag_is_enabled(): void
    {
        config([
            'erp_cost_import.dev_apply_enabled' => true,
            'erp_cost_import.production_apply_enabled' => false,
        ]);
        $this->inProduction(function (): void {
            $this->actingAs($this->administrator());
            $this->get('/products/cost-import')->assertOk()
                ->assertInertia(fn ($page) => $page->where('applyEnabled', false)->where('applyMode', 'production'));
            $this->postJson('/products/cost-import/confirm', [])->assertForbidden();

            config(['erp_cost_import.production_apply_enabled' => true]);
            $this->get('/products/cost-import')->assertOk()
                ->assertInertia(fn ($page) => $page->where('applyEnabled', true));
            $this->actingAs($this->operator());
            $this->get('/products/cost-import')->assertOk()
                ->assertInertia(fn ($page) => $page->where('applyEnabled', false));
            $this->postJson('/products/cost-import/confirm', [])->assertForbidden();
            $this->assertDatabaseCount('product_cost_sources', 0);
        });
    }

    public function test_production_administrator_must_approve_batch_count_and_reason_before_write(): void
    {
        config(['erp_cost_import.production_apply_enabled' => true]);
        $this->inProduction(function (): void {
            $this->actingAs($this->administrator());
            $batch = $this->prepareResolvedBatch();
            $payload = [
                'fingerprint' => $batch['preview']['fingerprint'],
                'plan_fingerprint' => $batch['plan']['plan_fingerprint'],
                'decisions' => $batch['decisions'],
            ];

            $this->postJson('/products/cost-import/confirm', $payload)
                ->assertUnprocessable()->assertJsonValidationErrors(['approval_phrase', 'approval_reason']);
            $this->postJson('/products/cost-import/confirm', $payload + [
                'approval_phrase' => '确认导入2条成本',
                'approval_reason' => '已核对本批工厂成本和原始采购凭证',
            ])->assertUnprocessable()->assertJsonValidationErrors('approval_phrase');
            $this->assertDatabaseCount('product_cost_sources', 0);

            $this->postJson('/products/cost-import/confirm', $payload + [
                'approval_phrase' => '确认导入1条成本',
                'approval_reason' => '已核对本批工厂成本和原始采购凭证',
            ])->assertOk()->assertJsonPath('mode', 'production_apply')
                ->assertJsonPath('created', 1)->assertJsonPath('noop', 0);
            $this->assertDatabaseHas('product_cost_sources', [
                'product_id' => $batch['product']->id,
                'currency' => 'CNY', 'status' => 'confirmed',
            ]);
            $source = ProductCostSource::where('product_id', $batch['product']->id)->sole();
            $this->assertSame('30.500000000000', (string) $source->unit_cost);
            $this->assertSame('2026-10-01', $source->effective_from->format('Y-m-d'));
            $this->assertDatabaseHas('audit_records', [
                'action' => 'product.cost_import.batch_confirmed',
                'entity_id' => $batch['preview']['fingerprint'],
                'reason' => 'monthly_cost_import_admin_approved',
            ]);
            $this->postJson('/products/cost-import/confirm', $payload + [
                'approval_phrase' => '确认导入1条成本',
                'approval_reason' => '已核对本批工厂成本和原始采购凭证',
            ])->assertUnprocessable()->assertJsonValidationErrors('fingerprint');
            $this->assertDatabaseCount('product_cost_sources', 1);
        });
    }

    public function test_production_concurrent_or_stale_plan_preserves_existing_historical_cost(): void
    {
        config(['erp_cost_import.production_apply_enabled' => true]);
        $this->inProduction(function (): void {
            $this->actingAs($this->administrator());
            $batch = $this->prepareResolvedBatch();
            $historical = ProductCostSource::create([
                'product_id' => $batch['product']->id,
                'source_key' => 'earlier-confirmed-october',
                'unit_cost' => '27.00', 'currency' => 'CNY',
                'status' => 'confirmed', 'source_type' => 'inventory_cost_book',
                'source_reference' => 'previous-verified.xlsx',
                'effective_from' => '2026-10-01',
                'source_fingerprint' => hash('sha256', 'previous-verified-cost'),
            ]);

            $this->postJson('/products/cost-import/plan', [
                'fingerprint' => $batch['preview']['fingerprint'],
                'decisions' => $batch['decisions'],
            ])->assertOk()->assertJsonPath('can_apply', false)
                ->assertJsonPath('summary.canonical_blocked', 1);

            $this->postJson('/products/cost-import/confirm', [
                'fingerprint' => $batch['preview']['fingerprint'],
                'plan_fingerprint' => $batch['plan']['plan_fingerprint'],
                'decisions' => $batch['decisions'],
                'approval_phrase' => '确认导入1条成本',
                'approval_reason' => '已核对本批工厂成本和原始采购凭证',
            ])->assertUnprocessable()->assertJsonValidationErrors('plan_fingerprint');
            $this->assertSame('27.000000000000', (string) $historical->fresh()->unit_cost);
            $this->assertDatabaseCount('product_cost_sources', 1);
            $this->assertDatabaseMissing('audit_records', ['action' => 'product.cost_import.batch_confirmed']);
        });
    }

    public function test_confirmed_usd_catalog_lists_all_months_with_fx_evidence_and_filters(): void
    {
        $this->fakeFx();
        $product = Product::create(['source_type' => 'manual', 'product_type' => 'simple', 'sku' => 'VA617A', 'name' => 'ERP VA617A']);
        foreach (['2026-09-01' => '28.00', '2026-10-01' => '30.50'] as $date => $cost) {
            ProductCostSource::create([
                'product_id' => $product->id, 'source_key' => 'worksheet|'.$date,
                'unit_cost' => $cost, 'currency' => 'CNY',
                'status' => 'confirmed', 'source_type' => 'inventory_cost_book',
                'source_reference' => 'test.xlsx', 'effective_from' => $date,
                'source_fingerprint' => hash('sha256', $date.$product->id),
            ]);
        }
        $this->actingAs($this->operator());
        $this->getJson('/products/cost-import/catalog')->assertOk()
            ->assertJsonPath('pagination.total', 2)
            ->assertJsonPath('rows.0.sku', 'VA617A')
            ->assertJsonPath('rows.0.source_currency', 'CNY')
            ->assertJsonPath('rows.0.usd_reference_cost', '4.2957')
            ->assertJsonPath('fx.usd_to_cny', '7.10000000');
        $this->getJson('/products/cost-import/catalog?month=2026-09')->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('rows.0.effective_from', '2026-09-01')
            ->assertJsonPath('rows.0.usd_reference_cost', '3.9436');
        $this->assertDatabaseCount('product_cost_sources', 2);
    }
}

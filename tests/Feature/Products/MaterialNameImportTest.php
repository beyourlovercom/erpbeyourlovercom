<?php

namespace Tests\Feature\Products;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

final class MaterialNameImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['key' => 'material-'.uniqid(), 'label' => 'Material UAT']);
        foreach ($permissions as $permission) {
            $role->permissions()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        }
        $user->roles()->attach($role);

        return $user;
    }

    private function product(string $sku, ?string $fullName = null): Product
    {
        return Product::create([
            'source_type' => 'manual', 'product_type' => 'simple',
            'sku' => $sku, 'name' => 'Product '.$sku,
            'material_full_name' => $fullName,
        ]);
    }

    /** @param array<int, array{string, string}> $rows */
    private function workbook(array $rows): UploadedFile
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('物料代码');
        $sheet->setCellValue('A1', '物料代码（旧系统）');
        $sheet->setCellValue('B1', '物料名称');
        foreach ($rows as $index => [$code, $name]) {
            $sheet->setCellValue('A'.($index + 2), $code);
            $sheet->setCellValue('B'.($index + 2), $name);
        }
        $path = tempnam(sys_get_temp_dir(), 'material-').'.xlsx';
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return new UploadedFile($path, 'material.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    public function test_safe_preview_auto_apply_variant_review_and_idempotence(): void
    {
        $by = $this->product('BY489A');
        $bv = $this->product('BV339A');
        $variant = $this->product('VA617A');
        $operator = $this->userWith(['products.view', 'products.operate']);
        $this->actingAs($operator);

        $response = $this->withHeader('Accept', 'application/json')
            ->post('/products/material-names/import/preview', [
                'file' => $this->workbook([
                    ['1BY489A5C0100', 'BEYOURLOVER  35毫升软管润滑油'],
                    ['1BV339AC50260', 'BV339A胸部按摩仪成品紫色含润滑油版'],
                    ['1VA617A500001', 'VA617A 按摩仪中文版'],
                    ['1VA617A500002', 'VA617A Massager English Version'],
                    ['UNKNOWNCODE', '完全未知的物料'],
                ]),
            ]);
        $response->assertOk()->assertJsonPath('total', 5)
            ->assertJsonPath('rows.0.status', 'ready')
            ->assertJsonPath('rows.1.status', 'ready')
            ->assertJsonPath('rows.2.status', 'ambiguous_variant')
            ->assertJsonPath('rows.3.status', 'ambiguous_variant')
            ->assertJsonPath('rows.4.status', 'unmatched');
        $this->assertNull($by->fresh()->material_full_name);
        $fingerprint = $response->json('fingerprint');
        $this->postJson('/products/material-names/import/apply', ['fingerprint' => $fingerprint])
            ->assertOk()->assertJsonPath('updated', 2);
        $this->assertSame('BEYOURLOVER  35毫升软管润滑油', $by->fresh()->material_full_name);
        $this->assertDatabaseHas('audit_records', ['entity_id' => $by->id,
            'action' => 'product.material_full_name.imported']);
        $this->assertSame('BV339A胸部按摩仪成品紫色含润滑油版', $bv->fresh()->material_full_name);
        $this->assertNull($variant->fresh()->material_full_name);
        $this->postJson('/products/material-names/import/apply', ['fingerprint' => $fingerprint])
            ->assertOk()->assertJsonPath('updated', 0);
        $this->postJson('/products/material-names/import/resolve', [
            'fingerprint' => $fingerprint, 'row_index' => 2,
            'product_id' => $variant->id, 'material_full_name' => 'VA617A 按摩仪中文版',
        ])->assertOk()->assertJsonPath('row.status', 'resolved');
        $this->assertSame('VA617A 按摩仪中文版', $variant->fresh()->material_full_name);
        $this->postJson('/products/material-names/import/resolve', [
            'fingerprint' => $fingerprint, 'row_index' => 3,
            'product_id' => $variant->id, 'material_full_name' => 'English override',
        ])->assertUnprocessable()->assertJsonValidationErrors('overwrite_confirmed');
        $this->assertSame('VA617A 按摩仪中文版', $variant->fresh()->material_full_name);
    }

    public function test_existing_values_require_explicit_override_and_viewers_cannot_import(): void
    {
        $product = $this->product('BY489A', '原有正确物料全称');
        $viewer = $this->userWith(['products.view']);
        $this->actingAs($viewer)->get('/products/material-names/import')->assertForbidden();
        $this->post('/products/material-names/import/preview', [
            'file' => $this->workbook([['1BY489A5C0100', 'BEYOURLOVER  35毫升软管润滑油']]),
        ])->assertForbidden();
        $operator = $this->userWith(['products.view', 'products.operate']);
        $this->actingAs($operator)->get('/products/material-names/import')->assertOk();

        $preview = $this->withHeader('Accept', 'application/json')
            ->post('/products/material-names/import/preview', [
                'file' => $this->workbook([['1BY489A5C0100', 'BEYOURLOVER  35毫升软管润滑油']]),
            ]);
        $preview->assertOk()->assertJsonPath('rows.0.status', 'conflict_existing');
        $fingerprint = $preview->json('fingerprint');
        $this->postJson('/products/material-names/import/apply', [
            'fingerprint' => $fingerprint,
        ])->assertOk()->assertJsonPath('updated', 0);
        $this->assertSame('原有正确物料全称', $product->fresh()->material_full_name);
        $this->postJson('/products/material-names/import/resolve', [
            'fingerprint' => $fingerprint, 'row_index' => 0,
            'product_id' => $product->id, 'material_full_name' => '新物料全称',
            'overwrite_confirmed' => true,
        ])->assertOk();
        $this->assertSame('新物料全称', $product->fresh()->material_full_name);
        $this->assertDatabaseHas('audit_records', ['entity_id' => $product->id,
            'action' => 'product.material_full_name.imported',
            'reason' => 'product_material_full_name_manual_row_confirm']);
    }
}

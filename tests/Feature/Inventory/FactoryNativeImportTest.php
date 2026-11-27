<?php

namespace Tests\Feature\Inventory;

use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductMaterialVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

final class FactoryNativeImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_versions_are_updated_once_without_changing_unrelated_stock_or_costs(): void
    {
        $factory=Warehouse::query()->where('code','FACTORY')->firstOrFail();
        $other=Warehouse::query()->where('code','007')->firstOrFail();
        $p=Product::create(['sku'=>'FCTEST','name'=>'Website title','source_type'=>'manual','product_type'=>'simple','is_active'=>true,'standard_production_cost'=>88]);
        $held=Product::create(['sku'=>'HELDTEST','name'=>'Held title','source_type'=>'manual','product_type'=>'simple','is_active'=>true]);
        $actor=$this->actor(['products.operate','inventory.operate','products.view']);
        foreach(['CODE-A','CODE-B'] as $code) {
            $this->actingAs($actor)->putJson('/products/'.$p->id.'/material-versions',[
                'material_code'=>$code,'material_name'=>'old name','same_physical_product_confirmed'=>true,
                'binding_evidence'=>'Factory owner verified exact product and scoped finished goods inventory.',
                'factory_stock_approved'=>true,
            ])->assertOk();
        }
        ProductMaterialVersion::create(['material_code'=>'CODE-HELD','code_key'=>'CODE-HELD','material_name'=>'Held old',
            'product_id'=>$held->id,'binding_source'=>'manual_verified','factory_stock_approved'=>false]);
        foreach([[$factory,$p,99,4],[$other,$p,33,0],[$factory,$held,71,0]] as [$w,$product,$qty,$reserved]) {
            WarehouseInventory::create(['warehouse_id'=>$w->id,'product_id'=>$product->id,'quantity_on_hand'=>$qty,'quantity_reserved'=>$reserved]);
        }
        $path=$this->fixture([['CODE-A','Full name A',7,100],['CODE-B','Full name B',0,200],['CODE-HELD','Held name',9,0],['UNKNOWN','Unmatched name',500,50]]);
        try {
            $view=$this->actor(['inventory.view']);
            $this->actingAs($view)->post('/inventory/sync/factory-native',['file'=>$this->upload($path),'stock_count_at'=>'2026-11-16'],['Accept'=>'application/json'])->assertForbidden();
            $date=now()->subDay()->toDateString();
            $payload=fn()=>['file'=>$this->upload($path),'stock_count_at'=>$date];
            $this->actingAs($actor)->post('/inventory/sync/factory-native',$payload(),['Accept'=>'application/json'])
                ->assertOk()->assertJsonPath('material_count',4)->assertJsonPath('linked',3)
                ->assertJsonPath('unmapped',1)->assertJsonPath('applied_products',1)->assertJsonPath('held_products',1)
                ->assertJsonPath('legacy_preserved_products',1)->assertJsonPath('availability_held_products',0);
            $this->assertDatabaseHas('warehouse_inventory',['warehouse_id'=>$factory->id,'product_id'=>$p->id,'quantity_on_hand'=>7,'quantity_reserved'=>4]);
            $this->assertDatabaseHas('warehouse_inventory',['warehouse_id'=>$other->id,'product_id'=>$p->id,'quantity_on_hand'=>33]);
            $this->assertDatabaseHas('warehouse_inventory',['warehouse_id'=>$factory->id,'product_id'=>$held->id,'quantity_on_hand'=>71,'factory_native_status'=>null]);
            self::assertSame(71,WarehouseInventory::query()->where('warehouse_id',$factory->id)->where('product_id',$held->id)->firstOrFail()->quantity_available);
            $this->assertDatabaseHas('product_material_versions',['code_key'=>'CODE-B','factory_physical_qty'=>0,'warehouse_007_qty'=>200,'material_name'=>'Full name B']);
            $this->assertDatabaseHas('product_material_versions',['code_key'=>'UNKNOWN','product_id'=>null,'factory_physical_qty'=>500]);
            $this->assertDatabaseHas('products',['id'=>$p->id,'name'=>'Website title','standard_production_cost'=>88]);
            $this->post('/inventory/sync/factory-native',$payload(),['Accept'=>'application/json'])->assertOk()->assertJsonPath('duplicate',true);
            $this->assertDatabaseCount('factory_material_snapshots',1);
            $this->post('/inventory/sync/factory-native',['file'=>$this->upload($path),'stock_count_at'=>now()->subDays(2)->toDateString()],['Accept'=>'application/json'])->assertConflict();
            $this->withoutVite()->get('/products/skus?search=CODE-B')->assertOk()->assertInertia(fn($page)=>$page
                ->component('Products/Skus')->where('products.data.0.sku','FCTEST')->has('products.data.0.material_versions',2));
        } finally { @unlink($path); }
    }

    public function test_incomplete_new_snapshot_holds_previously_authoritative_stock_without_zeroing_it(): void
    {
        $factory=Warehouse::query()->where('code','FACTORY')->firstOrFail();
        $p=Product::create(['sku'=>'COVERED','name'=>'Covered','source_type'=>'manual','product_type'=>'simple','is_active'=>true]);
        foreach(['CODE-A','CODE-B'] as $code) ProductMaterialVersion::create([
            'material_code'=>$code,'code_key'=>$code,'material_name'=>'Verified name','product_id'=>$p->id,
            'binding_source'=>'manual_verified','factory_stock_approved'=>true]);
        $actor=$this->actor(['products.operate','inventory.operate','products.view']);
        $this->actingAs($actor);
        $path=$this->fixture([['CODE-A','Full name A',9,0],['CODE-B','Full name B',3,0]]);
        $next=$this->fixture([['CODE-A','Full name A',8,0]]);
        try {
            $this->post('/inventory/sync/factory-native',['file'=>$this->upload($path),'stock_count_at'=>now()->subDay()->toDateString()],['Accept'=>'application/json'])
                ->assertOk()->assertJsonPath('applied_products',1);
            $this->post('/inventory/sync/factory-native',['file'=>$this->upload($next),'stock_count_at'=>now()->toDateString()],['Accept'=>'application/json'])
                ->assertOk()->assertJsonPath('applied_products',0)->assertJsonPath('availability_held_products',1)->assertJsonPath('legacy_preserved_products',0);
            $this->assertDatabaseHas('warehouse_inventory',['warehouse_id'=>$factory->id,'product_id'=>$p->id,'quantity_on_hand'=>12,'factory_native_status'=>'held']);
            self::assertSame(0,WarehouseInventory::query()->where('warehouse_id',$factory->id)->where('product_id',$p->id)->firstOrFail()->quantity_available);
        } finally {@unlink($path);@unlink($next);}
    }

    public function test_first_import_reuses_exact_confirmed_factory_scope_not_other_versions(): void
    {
        $factory=Warehouse::query()->where('code','FACTORY')->firstOrFail();
        $p=Product::create(['sku'=>'HISTORY-A','name'=>'A','source_type'=>'manual','product_type'=>'simple','is_active'=>true]);
        $q=Product::create(['sku'=>'HISTORY-B','name'=>'B','source_type'=>'manual','product_type'=>'simple','is_active'=>true]);
        $actor=$this->actor(['inventory.operate','products.operate','products.view']);
        $stock=app(\App\Modules\Inventory\Application\InventoryBatchSyncService::class);
        $date=now()->subDays(2)->toDateString();
        $batch=$stock->preview($factory,[
            new \App\Modules\Inventory\Application\Sync\NormalizedRow(1,'HISTORY-A','sellable',$date,null,50,rawProductName:'Exact Name（A）'),
            new \App\Modules\Inventory\Application\Sync\NormalizedRow(2,'HISTORY-B','sellable',$date,null,80,rawProductName:'Ambiguous Name'),
        ],'xlsx','partial','legacy.xlsx',$date,$actor);
        $stock->confirm($batch,$actor);
        ProductMaterialVersion::create(['material_code'=>'OTHER-VERSION','code_key'=>'OTHER-VERSION','material_name'=>'Another version','product_id'=>$p->id,'binding_source'=>'manual_verified','factory_stock_approved'=>false]);
        $path=$this->fixture([['EXACT-CODE','Exact Name(A)',0,100],['OTHER-VERSION','Another version',900,0],['DUP-1','Ambiguous Name',30,0],['DUP-2','Ambiguous Name',50,0]]);
        try {
            $this->actingAs($actor)->post('/inventory/sync/factory-native',['file'=>$this->upload($path),'stock_count_at'=>now()->toDateString()],['Accept'=>'application/json'])
                ->assertOk()->assertJsonPath('legacy_scope_approved_materials',1)->assertJsonPath('applied_products',1)->assertJsonPath('unmapped',2);
            $this->assertDatabaseHas('product_material_versions',['code_key'=>'EXACT-CODE','product_id'=>$p->id,'factory_stock_approved'=>true,'binding_source'=>'confirmed_factory_history']);
            $this->assertDatabaseHas('warehouse_inventory',['warehouse_id'=>$factory->id,'product_id'=>$p->id,'quantity_on_hand'=>0,'factory_native_status'=>'verified']);
            $this->assertDatabaseHas('warehouse_inventory',['warehouse_id'=>$factory->id,'product_id'=>$q->id,'quantity_on_hand'=>80,'factory_native_status'=>null]);
            $this->assertDatabaseHas('product_material_versions',['code_key'=>'OTHER-VERSION','factory_stock_approved'=>false,'factory_physical_qty'=>900]);
            $this->assertDatabaseHas('products',['id'=>$p->id,'material_full_name'=>'Exact Name(A)']);
        } finally {@unlink($path);}
    }

    public function test_legacy_confirmation_date_prevents_older_snapshot_overwrite_when_source_date_missing(): void
    {
        $factory=Warehouse::query()->where('code','FACTORY')->firstOrFail();
        $actor=$this->actor(['inventory.operate']);
        DB::table('inventory_sync_batches')->insert(['warehouse_id'=>$factory->id,'source_type'=>'xlsx',
            'sync_mode'=>'partial','status'=>'confirmed','confirmer_user_id'=>$actor->id,'confirmed_at'=>now(),
            'created_at'=>now(),'updated_at'=>now()]);
        $path=$this->fixture([['A','Name A',12,0]]);
        try {
            $this->actingAs($actor)->post('/inventory/sync/factory-native',['file'=>$this->upload($path),'stock_count_at'=>now()->subDay()->toDateString()],['Accept'=>'application/json'])->assertConflict();
            $this->assertDatabaseCount('factory_material_snapshots',0);
        } finally {@unlink($path);}
    }

    public function test_material_identity_and_stock_approval_permissions_are_separate(): void
    {
        $p=Product::create(['sku'=>'PROD-A','name'=>'A','source_type'=>'manual','product_type'=>'simple','is_active'=>true]);
        $q=Product::create(['sku'=>'PROD-B','name'=>'B','source_type'=>'manual','product_type'=>'simple','is_active'=>true]);
        $user=$this->actor(['products.operate']);
        $payload=['material_code'=>'1code','material_name'=>'Full name','same_physical_product_confirmed'=>true,
            'binding_evidence'=>'Explicit physical product identity verification evidence.','factory_stock_approved'=>true];
        $this->actingAs($user)->putJson('/products/'.$p->id.'/material-versions',$payload)->assertForbidden();
        $payload['factory_stock_approved']=false;
        $this->putJson('/products/'.$p->id.'/material-versions',$payload)->assertOk();
        $payload['material_code']='1CODE';
        $this->putJson('/products/'.$q->id.'/material-versions',$payload)->assertConflict();
        $this->assertDatabaseCount('product_material_versions',1);
    }

    public function test_incomplete_totals_fail_before_any_snapshot_or_stock_write(): void
    {
        $path=$this->fixture([['BAD','Incomplete',1,0]],2);
        try {
            $this->actingAs($this->actor(['inventory.operate']))
                ->post('/inventory/sync/factory-native',['file'=>$this->upload($path),'stock_count_at'=>now()->toDateString()],['Accept'=>'application/json'])->assertUnprocessable();
            $this->assertDatabaseCount('factory_material_snapshots',0);
            $this->assertDatabaseCount('product_material_versions',0);
        } finally {@unlink($path);}
    }

    public function test_sku_matrix_shared_grid_server_page_size_and_sort_are_bounded(): void
    {
        foreach (range(1, 25) as $n) Product::create(['sku'=>sprintf('GRID-%03d',$n),'name'=>'UAT title','source_type'=>'manual','product_type'=>'simple','is_active'=>true]);
        $this->withoutVite()->actingAs($this->actor(['products.view']));
        $this->get('/products/skus?search=GRID-&per_page=20&sort=sku&direction=desc')->assertOk()->assertInertia(fn($page)=>$page
            ->component('Products/Skus')->where('products.total',25)->where('products.per_page',20)
            ->where('products.data.0.sku','GRID-025')->has('products.data',20));
        $this->get('/products/skus?search=GRID-&per_page=1000&sort=invalid')->assertOk()->assertInertia(fn($page)=>$page
            ->where('products.per_page',50)->where('products.data.0.sku','GRID-001'));
    }

    public function test_sku_matrix_export_contains_exact_twelve_headers_and_literal_skus(): void
    {
        $p=Product::create(['sku'=>'EXPORT-001','name'=>'Website title','source_type'=>'manual','product_type'=>'simple','is_active'=>true]);
        ProductMaterialVersion::create(['material_code'=>'001CODE','code_key'=>'001CODE','material_name'=>'=literal inventory name',
            'product_id'=>$p->id,'binding_source'=>'manual_verified','factory_stock_approved'=>false]);
        $this->actingAs($this->actor(['inventory.view']))->get('/products/skus/export')->assertForbidden();
        $response=$this->actingAs($this->actor(['products.view']))->get('/products/skus/export?search=001CODE')->assertOk();
        $path=tempnam(sys_get_temp_dir(),'sku-matrix-');
        try {
            file_put_contents($path,$response->streamedContent());
            $book=(new \PhpOffice\PhpSpreadsheet\Reader\Xlsx())->load($path);
            $matrix=$book->getSheetByName('SKU映射中心');
            self::assertSame(['产品图片','ERP SKU','库存表代码','库存表名称','网站 SKU','中国工厂','德国仓','加拿大仓','美西 MorePlus','美东','美世迈仓','别名数量'],$matrix->rangeToArray('A1:L1')[0]);
            self::assertSame('EXPORT-001',$matrix->getCell('B2')->getValue());
            self::assertSame('001CODE',$matrix->getCell('C2')->getValue());
            self::assertSame('=literal inventory name',$matrix->getCell('D2')->getValue());
            self::assertSame('s',$matrix->getCell('D2')->getDataType());
            self::assertNotNull($book->getSheetByName('批量维护'));
            $book->disconnectWorksheets();
        } finally {@unlink($path);}
    }

    public function test_batch_mapping_is_atomic_and_respects_both_permission_boundaries(): void
    {
        $p=Product::create(['sku'=>'BATCH-A','name'=>'A','source_type'=>'manual','product_type'=>'simple','is_active'=>true]);
        $q=Product::create(['sku'=>'BATCH-B','name'=>'B','source_type'=>'manual','product_type'=>'simple','is_active'=>true]);
        $evidence='Confirmed unique physical product identity from source inventory record.';
        $valid=['物料','BATCH-A','0001CODE','Original full name','','',$evidence,'是','否'];
        $conflicting=['物料','BATCH-B','0001code','Wrong owner','','',$evidence,'是','否'];
        $path=$this->mappingFixture([$valid,$conflicting]);
        try {
            $this->actingAs($this->actor(['products.view']))->post('/products/skus/batch',['file'=>$this->upload($path)],['Accept'=>'application/json'])->assertForbidden();
            $this->actingAs($this->actor(['products.operate']))->post('/products/skus/batch',['file'=>$this->upload($path)],['Accept'=>'application/json'])->assertUnprocessable();
            $this->assertDatabaseCount('product_material_versions',0);
        } finally {@unlink($path);}
        $path=$this->mappingFixture([$valid,['仓库','BATCH-A','','','DE','REAL-A',$evidence,'是','']]);
        try {
            $this->post('/products/skus/batch',['file'=>$this->upload($path)],['Accept'=>'application/json'])->assertOk()->assertJsonPath('updated',2);
            $this->assertDatabaseHas('product_material_versions',['product_id'=>$p->id,'code_key'=>'0001CODE','factory_stock_approved'=>false]);
            $this->assertDatabaseHas('product_warehouse_skus',['product_id'=>$p->id,'sku'=>'REAL-A']);
        } finally {@unlink($path);}
        $valid[8]='是';$path=$this->mappingFixture([$valid]);
        try {
            $this->post('/products/skus/batch',['file'=>$this->upload($path)],['Accept'=>'application/json'])->assertForbidden();
            $this->assertDatabaseHas('product_material_versions',['product_id'=>$p->id,'code_key'=>'0001CODE','factory_stock_approved'=>false]);
        } finally {@unlink($path);}
    }

    private function mappingFixture(array $rows): string
    {
        $book=new Spreadsheet();
        $sheet=$book->getActiveSheet();$sheet->setTitle('批量维护');
        $sheet->fromArray(['类型','ERP SKU','库存表代码','库存表名称','仓库代码','仓库 SKU','核实依据','确认同一商品','工厂库存范围已核实']);
        foreach($rows as $i=>$row) foreach($row as $j=>$v)
            $sheet->setCellValueExplicit([$j+1,$i+2],$v,\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $path=tempnam(sys_get_temp_dir(),'sku-batch-');
        (new Xlsx($book))->save($path);$book->disconnectWorksheets();
        return $path;
    }

    private function fixture(array $rows, ?int $factoryTotal=null): string
    {
        $book=new Spreadsheet();
        $sheet=$book->getActiveSheet();$sheet->setTitle('谦禾库存明细');
        $sheet->setCellValue('B1','谦禾库存');
        $sheet->setCellValue('A2','Total');
        $sheet->setCellValue('C2',$factoryTotal??array_sum(array_column($rows,2)));
        $sheet->setCellValue('X2',array_sum(array_column($rows,3)));
        foreach(['A3'=>'物料代码(旧系统）','B3'=>'物料名称','C3'=>'成品库存汇总','X3'=>'B站-007成品仓','D3'=>'FBA库存','J3'=>'未生产订单'] as $cell=>$value) $sheet->setCellValue($cell,$value);
        foreach($rows as $i=>$row) {
            $n=$i+4;
            $sheet->setCellValue('A'.$n,$row[0]);$sheet->setCellValue('B'.$n,$row[1]);$sheet->setCellValue('C'.$n,$row[2]);$sheet->setCellValue('X'.$n,$row[3]);
            $sheet->setCellValue('D'.$n,999);$sheet->setCellValue('J'.$n,999);
        }
        $book->createSheet()->setTitle('未生产订单')->setCellValue('A1','9999');
        $path=tempnam(sys_get_temp_dir(),'factory-native-');
        (new Xlsx($book))->save($path);$book->disconnectWorksheets();
        return $path;
    }

    private function upload(string $path): UploadedFile
    {
        return new UploadedFile($path,'factory.xlsx','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',null,true);
    }

    private function actor(array $keys): User
    {
        $user=User::factory()->create(['is_active'=>true]);
        $role=Role::create(['key'=>'factory-native-'.uniqid(),'label'=>'Factory Native']);
        foreach($keys as $key) $role->permissions()->syncWithoutDetaching([Permission::firstOrCreate(['key'=>$key],['label'=>$key])->id]);
        $user->roles()->attach($role);return $user;
    }
}

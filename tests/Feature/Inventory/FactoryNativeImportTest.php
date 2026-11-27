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
                ->assertJsonPath('unmapped',1)->assertJsonPath('applied_products',1)->assertJsonPath('held_products',1);
            $this->assertDatabaseHas('warehouse_inventory',['warehouse_id'=>$factory->id,'product_id'=>$p->id,'quantity_on_hand'=>7,'quantity_reserved'=>4]);
            $this->assertDatabaseHas('warehouse_inventory',['warehouse_id'=>$other->id,'product_id'=>$p->id,'quantity_on_hand'=>33]);
            $this->assertDatabaseHas('warehouse_inventory',['warehouse_id'=>$factory->id,'product_id'=>$held->id,'quantity_on_hand'=>71,'factory_native_status'=>'held']);
            self::assertSame(0,WarehouseInventory::query()->where('warehouse_id',$factory->id)->where('product_id',$held->id)->firstOrFail()->quantity_available);
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

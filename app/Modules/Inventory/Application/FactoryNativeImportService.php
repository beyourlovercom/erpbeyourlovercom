<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Application\Sync\NormalizedRow;
use App\Modules\Inventory\Application\Sync\XlsxAdapter;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductWarehouseSku;
use App\Modules\Products\Application\SkuIdentityResolver;
use App\Modules\Products\Infrastructure\Models\ProductCostSource;
use App\Modules\Products\Infrastructure\Models\ProductMaterialVersion;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

final class FactoryNativeImportService
{
    public function __construct(private readonly XlsxAdapter $parser,
        private readonly InventoryBatchSyncService $stock, private readonly AuditRecorder $audit,
        private readonly SkuIdentityResolver $identities) {}

    public function import(UploadedFile $file, string $date, Authenticatable $actor): array
    {
        $rows = $this->parser->parseFactoryWorkbook($file);
        abort_if($rows === [], 422, '未找到工厂原始库存工作表');
        $hash = hash_file('sha256', $file->getRealPath());
        abort_if($hash === false, 422, '无法计算库存文件校验值');
        return DB::transaction(function () use ($rows, $hash, $file, $date, $actor): array {
            $warehouse = Warehouse::query()->whereRaw('UPPER(code) = ?', ['FACTORY'])
                ->where('is_active',true)->lockForUpdate()->firstOrFail();
            $replay=DB::table('factory_material_snapshots')->where('file_sha256',$hash)->first();
            abort_if($replay && $replay->stock_count_at!==$date,409,'相同库存原表不能以另一个日期重复登记');
            $latest = DB::table('factory_material_snapshots')->orderByDesc('stock_count_at')->first();
            if ($latest && $latest->stock_count_at === $date) {
                abort_unless(hash_equals($latest->file_sha256,$hash),409,'同一库存日期已导入不同文件，禁止覆盖');
                return [...json_decode($latest->result,true,512,JSON_THROW_ON_ERROR),'duplicate'=>true];
            }
            abort_if($latest && $latest->stock_count_at > $date,409,'文件日期早于最近工厂库存快照');
            $priorDate = DB::table('inventory_sync_batches')->where('warehouse_id',$warehouse->id)
                ->where('status','confirmed')->selectRaw('MAX(COALESCE(stock_count_at, confirmed_at)) as latest_source_date')->first()?->latest_source_date;
            abort_if($priorDate && substr((string)$priorDate,0,10)>$date,409,'文件日期早于最近已确认工厂库存');
            // Bootstrap only from the last confirmed legacy factory batch. An exact,
            // unique complete material name and current verified physical SKU are
            // required. This is evidence of the existing stock scope, not an
            // expansion to unrelated versions or other departments.
            $legacyScopes=$latest ? [] : $this->legacyScopes($warehouse,$rows,$date);
            $id = DB::table('factory_material_snapshots')->insertGetId([
                'stock_count_at'=>$date,'file_sha256'=>$hash,'filename'=>mb_substr($file->getClientOriginalName(),0,255),
                'imported_by'=>$actor->getAuthIdentifier(),'material_count'=>count($rows),
                'factory_physical_qty'=>array_sum(array_column($rows,'factory_qty')),
                'warehouse_007_qty'=>array_sum(array_column($rows,'warehouse_007_qty')),
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            // Exact, previously confirmed cost-source code proves identity only,
            // not permission to use other departments' finished goods.
            $costOwners = [];
            foreach(ProductCostSource::query()->where('status','confirmed')->where('source_type','inventory_cost_book')
                ->get(['product_id','source_key']) as $cost) {
                $key = ProductMaterialVersion::keyFor($cost->source_key);
                $costOwners[$key][(int)$cost->product_id]=true;
            }
            $versions = ProductMaterialVersion::query()->lockForUpdate()->get()->keyBy('code_key');
            $touched=[];$linked=0;$unmapped=0;$conflicts=0;$legacyApproved=0;
            foreach($rows as $row) {
                $key=ProductMaterialVersion::keyFor($row['material_code']);
                abort_if(mb_strlen($key)>128 || mb_strlen($row['material_name'])>10000,422,'物料字段超出长度限制');
                $version=$versions->get($key) ?? new ProductMaterialVersion(['code_key'=>$key,'material_code'=>$row['material_code']]);
                $scope=$legacyScopes[$key]??null;
                $owners=array_keys($costOwners[$key] ?? []);
                if ($scope) $owners=array_values(array_unique([...$owners,$scope['product_id']]));
                $conflict=count($owners)>1 || ($version->product_id && $owners!==[] && !in_array((int)$version->product_id,$owners,true));
                if ($conflict) {
                    $conflicts++;
                } elseif (!$version->product_id && count($owners)===1) {
                    $product=Product::query()->find($owners[0]);
                    if ($product) {
                        $version->product_id=$product->id;
                        $version->binding_source=$scope?'confirmed_factory_history':'confirmed_cost_code';
                        $version->binding_evidence=$scope?$scope['evidence']:'exact confirmed inventory_cost_book source_key';
                        $version->verified_at=now();
                    }
                }
                if (!$conflict && $scope && (int)$version->product_id===$scope['product_id']
                    && !$version->factory_stock_approved && $version->factory_stock_evidence===null) {
                    $version->factory_stock_approved=true;
                    $version->factory_stock_evidence=$scope['evidence'];
                    $legacyApproved++;
                }
                $version->fill(['material_name'=>$row['material_name'],'factory_physical_qty'=>$row['factory_qty'],
                    'warehouse_007_qty'=>$row['warehouse_007_qty'],'last_snapshot_id'=>$id]);
                $version->save();
                $approved= !$conflict && $version->product_id && $version->factory_stock_approved;
                DB::table('factory_material_snapshot_rows')->insert([
                    'snapshot_id'=>$id,'material_version_id'=>$version->id,'source_row'=>$row['source_row'],
                    'material_code'=>$row['material_code'],'material_name'=>$row['material_name'],
                    'factory_qty'=>$row['factory_qty'],'warehouse_007_qty'=>$row['warehouse_007_qty'],
                    'product_id'=>$conflict?null:$version->product_id,'stock_approved'=>(bool)$approved,
                ]);
                if ($version->product_id && !$conflict) {
                    $linked++; $touched[(int)$version->product_id]=true;
                } else { $unmapped++; }
            }
            $applied=0;$held=0;$stockRows=[];$appliedIds=[];$heldIds=[];
            $legacyIds=[];
            // The first source-catalog import is not a stock revocation. Only
            // products already using native-authoritative stock can be held
            // when the newly observed material coverage becomes incomplete.
            $nativeAuthority=WarehouseInventory::query()->where('warehouse_id',$warehouse->id)
                ->whereIn('factory_native_status',['verified','held'])->pluck('product_id')
                ->map(fn($id)=>(int)$id)->flip();
            $knownIds=ProductMaterialVersion::query()->whereNotNull('product_id')->distinct()->pluck('product_id')->map(fn($id)=>(int)$id)->all();
            foreach(array_unique([...array_keys($touched),...$knownIds]) as $productId) {
                $product=Product::query()->lockForUpdate()->find($productId);
                if (!$product || !$product->is_active || !$product->sku || strcasecmp($product->sku,'gift')===0) {
                    $held++;
                    if ($nativeAuthority->has($productId)) $heldIds[]=$productId;
                    else $legacyIds[]=$productId;
                    continue;
                }
                $materials=ProductMaterialVersion::query()->where('product_id',$productId)->get();
                $current=DB::table('factory_material_snapshot_rows')->where('snapshot_id',$id)->where('product_id',$productId)->get();
                // Prefer the sole currently observed, approved material scope.
                // Do not arbitrarily pick a name from several approved versions.
                $approvedMaterials=$materials->filter(fn($m)=>$m->factory_stock_approved);
                $approvedRows=$current->filter(fn($r)=>$r->stock_approved);
                $displayMaterial=null;
                if ($approvedMaterials->count()===1 && $approvedRows->count()===1
                    && (int)$approvedMaterials->first()->id===(int)$approvedRows->first()->material_version_id) {
                    $displayMaterial=$approvedMaterials->first();
                } elseif ($materials->count()===1 && $current->count()===1) {
                    $displayMaterial=$materials->first();
                }
                if ($displayMaterial) {
                    $before=$product->material_full_name;
                    $product->forceFill(['material_full_name'=>$displayMaterial->material_name])->save();
                    if($before!==$product->material_full_name) $this->audit->record(AuditActor::user($actor,AuditSource::Web),
                        'product.factory_material_name_synced',Product::class,$productId,
                        ['material_full_name'=>$before],['material_full_name'=>$product->material_full_name],'factory_native_import');
                }
                // Only complete, explicitly approved product scopes may replace stock.
                // Missing coverage holds previously native-authoritative stock only; legacy stock remains unchanged.
                if ($approvedMaterials->isEmpty() || $approvedMaterials->count()!==$approvedRows->count()
                    || $approvedMaterials->pluck('id')->diff($approvedRows->pluck('material_version_id'))->isNotEmpty()) {
                    $held++;
                    if ($nativeAuthority->has($productId)) $heldIds[]=$productId;
                    else $legacyIds[]=$productId;
                    continue;
                }
                $qty=(int)$approvedRows->sum('factory_qty');
                $stockRows[]=new NormalizedRow($applied+1,$product->sku,'sellable',$date,null,$qty,
                    rawProductName:$product->material_full_name,rawWarehouseName:'FACTORY',rawQuantity:(string)$qty);
                $applied++; $appliedIds[]=$productId;
            }
            $batchId=null;
            if ($stockRows!==[]) {
                $batch=$this->stock->preview($warehouse,$stockRows,'xlsx','partial',$file->getClientOriginalName(),$date,$actor);
                abort_if($batch->preview_error_rows>0,422,'已核实的库存商品匹配发生变化，导入已回滚');
                $batchId=$this->stock->confirm($batch,$actor)->getKey();
            }
            WarehouseInventory::query()->where('warehouse_id',$warehouse->id)->whereIn('product_id',$heldIds)
                ->update(['factory_native_status'=>'held','factory_native_snapshot_id'=>$id]);
            WarehouseInventory::query()->where('warehouse_id',$warehouse->id)->whereIn('product_id',$appliedIds)
                ->update(['factory_native_status'=>'verified','factory_native_snapshot_id'=>$id]);
            $result=['snapshot_id'=>$id,'material_count'=>count($rows),'linked'=>$linked,'unmapped'=>$unmapped,
                'conflicts'=>$conflicts,'applied_products'=>$applied,'held_products'=>$held,
                'factory_physical_qty'=>array_sum(array_column($rows,'factory_qty')),
                'warehouse_007_qty'=>array_sum(array_column($rows,'warehouse_007_qty')),
                'stock_batch_id'=>$batchId,'duplicate'=>false,
                'legacy_preserved_products'=>count($legacyIds),'availability_held_products'=>count($heldIds),
                'legacy_scope_approved_materials'=>$legacyApproved];
            DB::table('factory_material_snapshots')->where('id',$id)->update(['result'=>json_encode($result,JSON_THROW_ON_ERROR),'updated_at'=>now()]);
            $this->audit->record(AuditActor::user($actor,AuditSource::Web),'inventory.factory_native_imported',
                'factory_material_snapshot',$id,null,$result,'factory_native_import');
            return $result;
        });
    }

    /** Exact historical material-name matching, never model-prefix or quantity matching. */
    private function legacyScopes(Warehouse $warehouse,array $rows,string $date): array
    {
        $batch=DB::table('inventory_sync_batches')->where('warehouse_id',$warehouse->id)
            ->where('status','confirmed')->whereNotNull('confirmed_at')
            ->orderByDesc('confirmed_at')->orderByDesc('id')->first();
        if (!$batch || !$batch->confirmer_user_id || substr((string)($batch->stock_count_at??$batch->confirmed_at),0,10)>$date) return [];
        $normalize=static fn(string $name):string=>mb_strtoupper(preg_replace('/\s+/u','',strtr(trim($name),['（'=>'(', '）'=>')','＋'=>'+'])), 'UTF-8');
        $names=[];
        foreach ($rows as $row) $names[$normalize($row['material_name'])][]=$row;
        $history=DB::table('inventory_sync_rows')->where('sync_batch_id',$batch->id)
            ->whereNotNull('product_id')->whereNotNull('raw_product_name')
            ->where('validation_status','ok')->where('stock_status','sellable')->get();
        $byProduct=$history->groupBy('product_id');
        $byName=$history->groupBy(fn($r)=>$normalize((string)$r->raw_product_name));
        $scopes=[];
        foreach ($history as $r) {
            $name=$normalize((string)$r->raw_product_name);
            if ($name==='' || count($names[$name]??[])!==1 || $byName[$name]->count()!==1 || $byProduct[$r->product_id]->count()!==1) continue;
            $p=Product::query()->where('is_active',true)->find($r->product_id);
            if (!$p || !$p->sku || strcasecmp($p->sku,'gift')===0 || !filled($r->raw_sku)) continue;
            $raw=trim((string)$r->raw_sku);
            $resolved=$this->identities->resolve($raw);
            if ($resolved->isResolved() && (int)$resolved->product->getKey()!==(int)$p->id) continue;
            $verifiedFactory=ProductWarehouseSku::query()->where('warehouse_id',$warehouse->id)
                ->where('product_id',$p->id)->where('is_active',true)->whereNotNull('verified_at')
                ->whereRaw('UPPER(TRIM(sku)) = ?',[mb_strtoupper($raw)])->exists();
            if (strcasecmp($raw,$p->sku)!==0 && !$verifiedFactory) continue;
            $key=ProductMaterialVersion::keyFor($names[$name][0]['material_code']);
            $scopes[$key]=['product_id'=>(int)$p->id,'evidence'=>'confirmed_factory_batch:'.$batch->id.'; row:'.$r->id.'; unique exact material name; current verified SKU:'.$raw];
        }
        return $scopes;
    }
}

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
use App\Modules\Products\Application\MaterialNameSkuMatcher;
use App\Modules\Products\Infrastructure\Models\ProductCostSource;
use App\Modules\Products\Infrastructure\Models\ProductMaterialVersion;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

final class FactoryNativeImportService
{
    public function __construct(private readonly XlsxAdapter $parser,
        private readonly InventoryBatchSyncService $stock, private readonly AuditRecorder $audit,
        private readonly SkuIdentityResolver $identities, private readonly MaterialNameSkuMatcher $nameMatcher) {}

    public function import(UploadedFile $file, string $date, Authenticatable $actor, string $mode = 'full', bool $preview = false, ?string $token = null): array
    {
        $rows = $this->parser->parseFactoryWorkbook($file);
        abort_if($rows === [], 422, '未找到工厂原始库存工作表');
        $hash = hash_file('sha256', $file->getRealPath());
        abort_if($hash === false, 422, '无法计算库存文件校验值');
        return DB::transaction(function () use ($rows, $hash, $file, $date, $actor, $mode, $preview, $token): array {
            $warehouse = Warehouse::query()->whereRaw('UPPER(code) = ?', ['FACTORY'])
                ->where('is_active',true)->lockForUpdate()->firstOrFail();
            $replay=DB::table('factory_material_snapshots')->where('file_sha256',$hash)->first();
            abort_if($replay && $replay->stock_count_at!==$date,409,'相同库存原表不能以另一个日期重复登记');
            $latest = DB::table('factory_material_snapshots')->orderByDesc('stock_count_at')->first();
            if ($latest && $latest->stock_count_at === $date) {
                abort_unless(hash_equals($latest->file_sha256,$hash),409,'同一库存日期已导入不同文件，禁止覆盖');
                return [...['changes'=>[],'summary'=>[],'sync_mode'=>$mode],...json_decode($latest->result,true,512,JSON_THROW_ON_ERROR),'duplicate'=>true,'preview_token'=>null];
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
            // Exact, previously confirmed cost-source code proves identity only,
            // not permission to use other departments' finished goods.
            $costOwners = [];
            foreach(ProductCostSource::query()->where('status','confirmed')->where('source_type','inventory_cost_book')
                ->get(['product_id','source_key']) as $cost) {
                $key = ProductMaterialVersion::keyFor($cost->source_key);
                $costOwners[$key][(int)$cost->product_id]=true;
            }
            $versions = ProductMaterialVersion::query()->orderBy('code_key')->lockForUpdate()->get()->keyBy('code_key');
            $nameIndex=$this->nameMatcher->index();
            $products=Product::query()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $changes=[];$planned=[];$currentByProduct=[];
            $addChange=static function(string $scope,string $sku,string $code,string $field,$before,$after,string $status,?string $note=null) use (&$changes):void {
                $changes[]=['scope'=>$scope,'sku'=>$sku,'material_code'=>$code,'field'=>$field,'before'=>$before,'after'=>$after,'status'=>$status,'note'=>$note];
            };
            $touched=[];$linked=0;$unmapped=0;$conflicts=0;$legacyApproved=0;$nameMatched=0;
            foreach($rows as $row) {
                $key=ProductMaterialVersion::keyFor($row['material_code']);
                abort_if(mb_strlen($key)>128 || mb_strlen($row['material_name'])>10000,422,'物料字段超出长度限制');
                $version=$versions->get($key) ?? new ProductMaterialVersion(['code_key'=>$key,'material_code'=>$row['material_code']]);
                $before=$version->exists?$version->getAttributes():[];
                $scope=$legacyScopes[$key]??null;
                $owners=array_keys($costOwners[$key] ?? []);
                if ($scope) $owners=array_values(array_unique([...$owners,$scope['product_id']]));
                $conflict=count($owners)>1 || ($version->product_id && $owners!==[] && !in_array((int)$version->product_id,$owners,true));
                if ($conflict) {
                    $conflicts++;
                } elseif (!$version->product_id && count($owners)===1) {
                    $product=$products->get($owners[0]);
                    if ($product) {
                        $version->product_id=$product->id;
                        $version->binding_source=$scope?'confirmed_factory_history':'confirmed_cost_code';
                        $version->binding_evidence=$scope?$scope['evidence']:'exact confirmed inventory_cost_book source_key';
                        $version->verified_at=now();
                    }
                }
                // Name-prefix matching is an identity fallback, not stock-scope approval.
                if (!$conflict && !$version->product_id && $owners===[]) {
                    $match=$this->nameMatcher->resolve($row['material_name'],$nameIndex);
                    if ($match) {
                        $version->fill(['product_id'=>$match['product_id'],'binding_source'=>'material_name_sku_exact',
                            'binding_evidence'=>$this->nameMatcher->evidence($match),'verified_by'=>$actor->getAuthIdentifier(),'verified_at'=>now()]);
                        $nameMatched++;
                    }
                }
                if (!$conflict && $scope && (int)$version->product_id===$scope['product_id']
                    && !$version->factory_stock_approved && $version->factory_stock_evidence===null) {
                    $version->factory_stock_approved=true;
                    $version->factory_stock_evidence=$scope['evidence'];
                    $legacyApproved++;
                }
                $version->fill(['material_name'=>$row['material_name'],'factory_physical_qty'=>$row['factory_qty'],
                    'warehouse_007_qty'=>$row['warehouse_007_qty']]);
                $approved= !$conflict && $version->product_id && $version->factory_stock_approved;
                $planned[$key]=['version'=>$version,'row'=>$row,'approved'=>(bool)$approved,'conflict'=>$conflict];
                $versions->put($key,$version);
                $sku=$conflict?'待核实':($products->get($version->product_id)?->sku??'未匹配');
                $status=$before===[]?'added':'modified';
                foreach(['material_name'=>'库存名称','factory_physical_qty'=>'工厂原表库存','warehouse_007_qty'=>'007原表库存（仅记录）','product_id'=>'ERP SKU 关联','factory_stock_approved'=>'工厂库存范围'] as $field=>$label) {
                    $old=$before[$field]??null;
                    $new=$version->getAttribute($field);
                    if ($field==='product_id') {$old=$products->get($old)?->sku;$new=$products->get($new)?->sku;}
                    if ($field==='factory_stock_approved') {$old=$before===[]?null:((bool)$old?'已核准':'未核准');$new=$new?'已核准':'未核准';}
                    if (in_array($field,['factory_physical_qty','warehouse_007_qty'],true)) {$old=$old===null?null:(int)$old;$new=(int)$new;}
                    if ($old!==$new) $addChange('物料',$sku,$row['material_code'],$label,$old,$new,$status);
                }
                if ($conflict || !$version->product_id) $addChange('物料',$sku,$row['material_code'],'商品匹配',null,null,'blocked',$conflict?'商品身份冲突，库存不写入':'未匹配 ERP SKU，仅保留原表');
                if ($version->product_id && !$conflict) $currentByProduct[(int)$version->product_id][$key]=$planned[$key];
                if ($version->product_id && !$conflict) {
                    $linked++; $touched[(int)$version->product_id]=true;
                } else { $unmapped++; }
            }
            $applied=0;$held=0;$stockRows=[];$appliedIds=[];$heldIds=[];$legacyIds=[];$names=[];
            $inventory=WarehouseInventory::query()->where('warehouse_id',$warehouse->id)->lockForUpdate()->get()->keyBy('product_id');
            $byProduct=$versions->filter(fn($m)=>$m->product_id)->groupBy('product_id');
            $knownIds=$byProduct->keys()->all();
            $targets=$mode==='partial'?array_keys($touched):array_unique([...array_keys($touched),...$knownIds]);
            sort($targets,SORT_NUMERIC);
            foreach($targets as $productId) {
                $product=$products->get($productId);
                $existing=$inventory->get($productId);
                $qtyBefore=$existing?->quantity_on_hand;
                $sku=$product?->sku??('商品 #'.$productId);
                $oldState=$existing?->factory_native_status;
                $materials=$byProduct->get($productId,collect());
                $current=collect($currentByProduct[$productId]??[]);
                $approvedMaterials=$materials->filter(fn($m)=>$m->factory_stock_approved);
                $approvedRows=$current->filter(fn($r)=>$r['approved']);
                $displayMaterial=null;
                if ($approvedMaterials->count()===1 && $approvedRows->count()===1
                    && $approvedMaterials->first()->code_key===$approvedRows->first()['version']->code_key) {
                    $displayMaterial=$approvedMaterials->first();
                } elseif ($materials->count()===1 && $current->count()===1) {
                    $displayMaterial=$materials->first();
                }
                if ($product && $product->is_active && $displayMaterial && $product->material_full_name!==$displayMaterial->material_name) {
                    $names[$productId]=$displayMaterial->material_name;
                    $addChange('ERP SKU',$sku,'','库存显示名称',$product->material_full_name,$displayMaterial->material_name,'modified');
                }
                $complete=$product && $product->is_active && $product->sku && strcasecmp($product->sku,'gift')!==0
                    && $approvedMaterials->isNotEmpty() && $approvedMaterials->count()===$approvedRows->count()
                    && $approvedMaterials->pluck('code_key')->diff($approvedRows->map(fn($r)=>$r['version']->code_key))->isEmpty();
                if (!$complete) {
                    $held++;
                    if ($mode==='full' && in_array($oldState,['verified','held'],true)) {
                        $heldIds[]=$productId;
                        $addChange('ERP SKU',$sku,'','库存可用状态',$oldState,'held','blocked','完整快照覆盖不足，保留数量并暂停新库存可用性');
                    } else {
                        $legacyIds[]=$productId;
                        $addChange('ERP SKU',$sku,'','工厂库存',$qtyBefore,$qtyBefore,'blocked','未核准或物料覆盖不足，保留现有库存');
                    }
                    continue;
                }
                $qty=(int)$approvedRows->sum(fn($r)=>$r['row']['factory_qty']);
                $resolved=$this->identities->resolve($product->sku);
                abort_unless($resolved->isResolved() && (int)$resolved->product->getKey()===(int)$productId,422,'已核实的库存商品匹配发生变化');
                $stockRows[]=new NormalizedRow($applied+1,$product->sku,'sellable',$date,null,$qty,
                    rawProductName:$names[$productId]??$product->material_full_name,rawWarehouseName:'FACTORY',rawQuantity:(string)$qty);
                $applied++; $appliedIds[]=$productId;
                $addChange('ERP SKU',$sku,'','工厂库存',$qtyBefore,$qty,$qtyBefore===null?'added':($qtyBefore===$qty?'unchanged':($qty===0?'zeroed':'modified')),
                    '保留预留数量 '.($existing?->quantity_reserved??0));
                if ($oldState!=='verified') $addChange('ERP SKU',$sku,'','库存可用状态',$oldState,'verified','modified');
            }
            foreach($versions as $key=>$v) {
                if (isset($planned[$key])) continue;
                if ($mode==='full') $addChange('物料',$products->get($v->product_id)?->sku??'未匹配',$v->material_code,'原表缺失',null,null,'blocked','未出现在原表，保留历史，不删除物料版本');
            }
            $summary=['added'=>0,'modified'=>0,'zeroed'=>0,'blocked'=>0,'unchanged'=>0];
            foreach($changes as $change) $summary[$change['status']]++;
            $result=['material_count'=>count($rows),'linked'=>$linked,'unmapped'=>$unmapped,'conflicts'=>$conflicts,
                'applied_products'=>$applied,'held_products'=>$held,'factory_physical_qty'=>array_sum(array_column($rows,'factory_qty')),
                'warehouse_007_qty'=>array_sum(array_column($rows,'warehouse_007_qty')),'duplicate'=>false,
                'legacy_preserved_products'=>count($legacyIds),'availability_held_products'=>count($heldIds),
                'legacy_scope_approved_materials'=>$legacyApproved,'name_auto_linked'=>$nameMatched,
                'sync_mode'=>$mode,'changes'=>$changes,'summary'=>$summary];
            // Bind approval to exact file, actor, date, mode and calculated before/after values.
            // No database mutation occurs before this boundary.
            $state=$inventory->map(fn($i)=>[$i->product_id,$i->quantity_on_hand,$i->quantity_reserved,$i->factory_native_status])->sortKeys()->values()->all();
            $identities=array_map(fn($entry)=>[$entry['version']->code_key,$entry['version']->product_id,
                $entry['version']->binding_source,$entry['approved'],$entry['conflict']],$planned);
            $digest=hash_hmac('sha256',json_encode([$hash,$date,$mode,$actor->getAuthIdentifier(),$changes,$state,$identities,$latest?->id],JSON_THROW_ON_ERROR),config('app.key'));
            if ($preview) return [...$result,'preview_token'=>$digest];
            abort_unless($token!==null && hash_equals($digest,$token),409,'导入预览已变化，请重新预览后确认');
            $id = DB::table('factory_material_snapshots')->insertGetId([
                'stock_count_at'=>$date,'file_sha256'=>$hash,'filename'=>mb_substr($file->getClientOriginalName(),0,255),
                'imported_by'=>$actor->getAuthIdentifier(),'material_count'=>count($rows),
                'factory_physical_qty'=>array_sum(array_column($rows,'factory_qty')),
                'warehouse_007_qty'=>array_sum(array_column($rows,'warehouse_007_qty')),
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            foreach($planned as $entry) {
                $version=$entry['version'];$row=$entry['row'];
                $version->last_snapshot_id=$id;$version->save();
                DB::table('factory_material_snapshot_rows')->insert([
                    'snapshot_id'=>$id,'material_version_id'=>$version->id,'source_row'=>$row['source_row'],
                    'material_code'=>$row['material_code'],'material_name'=>$row['material_name'],
                    'factory_qty'=>$row['factory_qty'],'warehouse_007_qty'=>$row['warehouse_007_qty'],
                    'product_id'=>$entry['conflict']?null:$version->product_id,'stock_approved'=>$entry['approved'],
                ]);
            }
            foreach($names as $productId=>$name) {
                $product=$products->get($productId);$before=$product->material_full_name;
                $product->forceFill(['material_full_name'=>$name])->save();
                $this->audit->record(AuditActor::user($actor,AuditSource::Web),'product.factory_material_name_synced',Product::class,$productId,
                    ['material_full_name'=>$before],['material_full_name'=>$name],'factory_native_import');
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
            $result=[...$result,'snapshot_id'=>$id,'stock_batch_id'=>$batchId];
            DB::table('factory_material_snapshots')->where('id',$id)->update(['result'=>json_encode($result,JSON_THROW_ON_ERROR),'updated_at'=>now()]);
            $this->audit->record(AuditActor::user($actor,AuditSource::Web),'inventory.factory_native_imported',
                'factory_material_snapshot',$id,null,array_diff_key($result,['changes'=>true]),'factory_native_import');
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

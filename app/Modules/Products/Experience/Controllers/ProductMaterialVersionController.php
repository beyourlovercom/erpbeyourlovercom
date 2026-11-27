<?php

namespace App\Modules\Products\Experience\Controllers;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductCostSource;
use App\Modules\Products\Infrastructure\Models\ProductMaterialVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ProductMaterialVersionController
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function upsert(Request $request, int $productId): JsonResponse
    {
        $data=$request->validate([
            'material_code'=>['required','string','max:128'],
            'material_name'=>['required','string','max:10000'],
            'same_physical_product_confirmed'=>['accepted'],
            'binding_evidence'=>['required','string','min:20','max:1000'],
            'factory_stock_approved'=>['required','boolean'],
        ]);
        return $this->applyVerified($request, $productId, $data);
    }

    /** Same validated mutation contract for single and batch maintenance. */
    public function applyVerified(Request $request, int $productId, array $data): JsonResponse
    {
        if ($data['factory_stock_approved']) {
            abort_unless($request->user()->can('inventory.operate'),403,'没有工厂库存范围核实权限');
        }
        return DB::transaction(function () use($request,$productId,$data): JsonResponse {
            Warehouse::query()->whereRaw('UPPER(code) = ?', ['FACTORY'])->lockForUpdate()->firstOrFail();
            $product=Product::query()->where('is_active',true)->lockForUpdate()->findOrFail($productId);
            abort_if(!$product->sku || strcasecmp($product->sku,'gift')===0,422,'占位商品不能绑定实际物料');
            $key=ProductMaterialVersion::keyFor($data['material_code']);
            abort_if($key==='' || $key==='GIFT' || mb_strlen($key)>128 || trim($data['material_name'])==='',422,'库存代码和名称不能为空');
            $version=ProductMaterialVersion::query()->where('code_key',$key)->lockForUpdate()->first();
            abort_if($version?->product_id && (int)$version->product_id!==$productId,409,'物料代码已经归属其他 ERP 商品');
            $owners=ProductCostSource::query()->where('status','confirmed')->where('source_type','inventory_cost_book')
                ->whereRaw('UPPER(TRIM(source_key)) = ?',[$key])->distinct()->pluck('product_id')->map(fn($id)=>(int)$id)->all();
            abort_if(count($owners)>1 || ($owners!==[] && $owners!==[$productId]),409,'物料代码与已确认成本档案冲突');
            abort_if($version && $version->factory_stock_approved !== (bool)$data['factory_stock_approved'] && !$request->user()->can('inventory.operate'),403,'没有修改工厂库存范围权限');
            $version??=new ProductMaterialVersion(['material_code'=>trim($data['material_code']),'code_key'=>$key]);
            $before=$version->exists?$version->only(['product_id','material_name','binding_source','factory_stock_approved']):null;
            $version->fill(['material_name'=>$data['material_name'],'product_id'=>$productId,'binding_source'=>'manual_verified',
                'binding_evidence'=>trim($data['binding_evidence']),'factory_stock_approved'=>(bool)$data['factory_stock_approved'],
                'verified_by'=>$request->user()->getAuthIdentifier(),'verified_at'=>now()])->save();
            if ($product->materialVersions()->count()===1) {
                $product->forceFill(['material_full_name'=>$version->material_name])->save();
            }
            $this->audit->record(AuditActor::user($request->user(),AuditSource::Web),
                'product.material_version_verified',Product::class,$productId,$before,
                ['material_code'=>$version->material_code,'material_name'=>$version->material_name,'factory_stock_approved'=>$version->factory_stock_approved],
                'factory_material_identity_verified');
            return response()->json(['material'=>$version,'message'=>'物料映射已核实；下次有效工厂库存快照将按核准范围同步']);
        });
    }
}

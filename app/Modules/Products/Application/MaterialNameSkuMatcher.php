<?php

namespace App\Modules\Products\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductCostSource;
use App\Modules\Products\Infrastructure\Models\ProductMaterialVersion;
use App\Modules\Products\Infrastructure\Models\ProductSkuAlias;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

final class MaterialNameSkuMatcher
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function candidate(string $name): ?string
    {
        if (!preg_match('/^[\s\x{FEFF}]*([A-Za-z0-9]+(?:[-_.][A-Za-z0-9]+)*)/u', $name, $m)) return null;
        // Composite identifiers are not a single product identity.
        if (preg_match('/^\s*[+&\/]/u',substr($name,strlen($m[0])))) return null;
        $token=mb_strtoupper($m[1]);
        // Never truncate an attached suffix or guess a color/variant from the base.
        if (!preg_match('/[A-Z]/',$token) || !preg_match('/[0-9]/',$token)) return null;
        return $token;
    }

    /** Both canonical and active alias identities count; a collision never resolves by precedence. */
    public function index(): array
    {
        $map=[];
        $products=Product::query()->whereNotNull('sku')->get(['id','sku','is_active'])->keyBy('id');
        foreach($products as $p) {
            $key=ProductMaterialVersion::keyFor($p->sku);
            if ($key!=='') $map[$key][(int)$p->id]=(bool)$p->is_active;
        }
        foreach(ProductSkuAlias::query()->where('is_active',true)->get(['product_id','alias_sku']) as $a) {
            $p=$products->get($a->product_id);
            if ($p) $map[ProductMaterialVersion::keyFor($a->alias_sku)][(int)$p->id]=(bool)$p->is_active;
        }
        return $map;
    }

    public function resolve(string $name,array $index): ?array
    {
        $token=$this->candidate($name);
        $owners=$token===null?[]:($index[$token]??[]);
        if (count($owners)!==1 || reset($owners)!==true) return null;
        return ['product_id'=>(int)array_key_first($owners),'token'=>$token];
    }

    public function evidence(array $match): string
    {
        return 'Owner-approved leading material name SKU exact match: '.$match['token'].'; unique active product/alias; identity only, no stock scope approval';
    }

    /** Reconcile the already imported catalog, independent of file replay and inventory writes. */
    public function reconcile(Authenticatable $actor): array
    {
        abort_unless($actor->can('products.operate'),403);
        return DB::transaction(function()use($actor){
            Warehouse::query()->whereRaw('UPPER(code) = ?',['FACTORY'])->lockForUpdate()->firstOrFail();
            $index=$this->index();
            $costs=[];
            foreach(ProductCostSource::query()->where('status','confirmed')->where('source_type','inventory_cost_book')->get(['product_id','source_key']) as $c)
                $costs[ProductMaterialVersion::keyFor($c->source_key)][(int)$c->product_id]=true;
            $matched=0;$remaining=0;$conflicts=0;$affected=[];
            foreach(ProductMaterialVersion::query()->whereNull('product_id')->lockForUpdate()->get() as $v) {
                $match=$this->resolve($v->material_name,$index);
                $owners=array_keys($costs[$v->code_key]??[]);
                if (count($owners)>1 || ($match && $owners!==[] && !in_array($match['product_id'],$owners,true))) {
                    $conflicts++;$remaining++;continue;
                }
                if (!$match) {$remaining++;continue;}
                $v->fill(['product_id'=>$match['product_id'],'binding_source'=>'material_name_sku_exact',
                    'binding_evidence'=>$this->evidence($match),'verified_by'=>$actor->getAuthIdentifier(),'verified_at'=>now()])->save();
                $affected[$match['product_id']]=true;
                $this->audit->record(AuditActor::user($actor,AuditSource::Web),'product.material_name_sku_auto_linked',
                    ProductMaterialVersion::class,$v->id,['product_id'=>null],['product_id'=>$v->product_id,'material_code'=>$v->material_code,'token'=>$match['token']], 'material_name_sku_exact');
                $matched++;
            }
            foreach(array_keys($affected) as $id) {
                $p=Product::query()->lockForUpdate()->find($id);
                if (!$p || filled($p->material_full_name)) continue;
                $versions=$p->materialVersions()->get();
                if ($versions->count()===1) $p->forceFill(['material_full_name'=>$versions->first()->material_name])->save();
            }
            return ['matched'=>$matched,'remaining'=>$remaining,'conflicts'=>$conflicts,'products'=>count($affected)];
        });
    }
}

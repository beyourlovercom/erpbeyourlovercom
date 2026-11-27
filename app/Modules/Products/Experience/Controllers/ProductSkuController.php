<?php

namespace App\Modules\Products\Experience\Controllers;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductMaterialVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

final class ProductSkuController
{
    public function index(Request $request): Response
    {
        $filters=$request->only(['search']);
        $search=trim((string)($filters['search']??''));
        $query=Product::query()->with(['materialVersions','warehouseSkus','media','providerSnapshots'])
            ->withCount('skuAliases');
        if ($search!=='') {
            $pattern='%'.$search.'%';
            $query->where(function($q) use($pattern) {
                $q->where('sku','like',$pattern)->orWhere('material_full_name','like',$pattern)
                    ->orWhereHas('materialVersions',fn($m)=>$m->where('material_code','like',$pattern)->orWhere('material_name','like',$pattern))
                    ->orWhereHas('warehouseSkus',fn($m)=>$m->where('sku','like',$pattern))
                    ->orWhereHas('providerSnapshots',fn($m)=>$m->where('provider_sku','like',$pattern))
                    ->orWhereHas('skuAliases',fn($m)=>$m->where('alias_sku','like',$pattern));
            });
        }
        $warehouses=Warehouse::query()->where('is_active',true)->get()->keyBy(fn($w)=>strtoupper($w->code));
        $codes=['FACTORY','DE','CA','US-LA','US-NY','US-SM'];
        $products=$query->orderBy('sku')->orderBy('id')->paginate(50)->withQueryString();
        $products->through(function(Product $p) use($warehouses,$codes): array {
            $mappings=[];
            foreach($codes as $code) {
                $warehouse=$warehouses->get($code);
                $m=$warehouse ? $p->warehouseSkus->firstWhere('warehouse_id',$warehouse->id) : null;
                $verified=$m && $m->is_active && $m->verified_at;
                $fallback=$code==='FACTORY' && $warehouse && !$verified && $p->is_active && $p->sku && strcasecmp($p->sku,'gift')!==0;
                $mappings[$code]=['warehouse_id'=>$warehouse?->id,'sku'=>$verified?$m->sku:($fallback?$p->sku:null),
                    'status'=>$verified?'verified':($fallback?'factory_default':($m?'inactive':'missing'))];
            }
            $media=$p->media->sortByDesc('is_primary')->first();
            $image=null;
            if ($media && $media->storage_path && Storage::disk('local')->exists($media->storage_path)) $image=route('products.media.show',$media->id);
            elseif ($media && filter_var($media->source_url,FILTER_VALIDATE_URL) && str_starts_with($media->source_url,'https://')) $image=$media->source_url;
            return ['id'=>$p->id,'sku'=>$p->sku,'name'=>$p->name,'source_type'=>$p->source_type,'is_active'=>(bool)$p->is_active,
                'material_name'=>$p->material_full_name,'image_url'=>$image,
                'material_versions'=>$p->materialVersions->map(fn($m)=>$m->only(['id','material_code','material_name','factory_stock_approved','factory_physical_qty']))->values(),
                'website_skus'=>$p->providerSnapshots->where('provider','woocommerce')->pluck('provider_sku')->filter()->unique()->values(),
                'mappings'=>$mappings,'alias_count'=>$p->sku_aliases_count];
        });
        return Inertia::render('Products/Skus',[
            'products'=>$products->toArray(),'filters'=>$filters,
            'unmappedMaterialCount'=>ProductMaterialVersion::query()->whereNull('product_id')->count(),
            'canOperate'=>(bool)$request->user()?->can('products.operate'),
            'canApproveStock'=>(bool)$request->user()?->can('inventory.operate'),
            'unmappedMaterials'=>ProductMaterialVersion::query()->whereNull('product_id')
                ->when($search!=='',fn($q)=>$q->where(fn($q)=>$q->where('material_code','like','%'.$search.'%')->orWhere('material_name','like','%'.$search.'%')))
                ->orderBy('material_code')->limit(50)->get(['id','material_code','material_name','factory_physical_qty']),
            'productOptions'=>$request->user()?->can('products.operate')?Product::query()->where('is_active',true)->whereNotNull('sku')->orderBy('sku')->get(['id','sku']):[],

        ]);
    }
}

<?php

namespace App\Modules\Products\Application;

use App\Modules\Inventory\Application\ProductPackagingStandardReader;
use App\Modules\Inventory\Infrastructure\Models\PackagingMeasurementRecord;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductParameterSubmission;
use App\Modules\Products\Infrastructure\Models\ProductWeightEvidence;
use Illuminate\Support\Facades\Storage;

final class ProductLogisticsProjection
{
    public function __construct(private readonly ProductPackagingStandardReader $standards) {}

    private function products(array $ids)
    {
        $connection = (string) config('services.woocommerce.connection_key', 'beyourlover.com');
        return Product::query()->businessCatalog()->whereIn('id', $ids)
            ->with(['packagingProfile', 'shippingWeightEvidence', 'media' => fn ($q) => $q->where(fn ($q) => $q
                ->where(['provider'=>'erp_manual','connection_key'=>'local'])
                ->orWhere(fn ($q) => $q->where(['provider'=>'woocommerce','connection_key'=>$connection])))
                ->orderByDesc('is_primary')])
            ->orderBy('sku')->get();
    }

    public function rows(array $ids): array
    {
        $products = $this->products($ids);
        $ids = $products->modelKeys();
        $standards = $this->standards->forProductIds($ids);
        $approved = ProductParameterSubmission::query()->whereIn('product_id', $ids)
            ->where('status', 'approved')->orderByDesc('reviewed_at')->orderByDesc('id')->get()->groupBy('product_id');
        return $products->map(function (Product $p) use ($standards, $approved): array {
            $history = $approved->get($p->id, collect());
            $box = $history->first(fn ($r) => $this->dimensions($r->measurements ?? [], 'color_box_') !== null);
            $reference = $history->first(fn ($r) => $this->dimensions($r->measurements ?? [], 'package_') !== null);
            $gross = $history->first(fn ($r) => $this->positive($r->measurements['color_box_gross_kg'] ?? null) !== null);
            $carton = $history->first(fn ($r) => $this->dimensions($r->measurements ?? [], 'carton_') !== null);
            $cartonMeasures = $carton?->measurements ?? [];
            $profile = $p->packagingProfile;
            $profile = $profile?->is_active ? $profile : null;
            $note = (string) ($profile?->scenario ?? '');
            $outer = $this->dimensions($cartonMeasures, 'carton_');
            $estimatedOuter = null;
            if (preg_match('/外箱([0-9.]+)[×x*]([0-9.]+)[×x*]([0-9.]+)\\s*mm/u', $note, $m)) {
                $values = array_map(fn ($v) => $this->positive($v), array_slice($m, 1, 3));
                if (!in_array(null, $values, true)) $estimatedOuter = array_map(fn ($v) => $v / 10, $values);
            }
            $standard = $standards[$p->id] ?? null;
            $ship = $standard ? $this->dimensions($standard, 'packaging_') : null;
            $shipWeight = $this->positive($standard['packaged_weight_kg'] ?? null);
            $legacy = $p->shippingWeightEvidence;
            $retailReference = $legacy && (int)$legacy->product_id===(int)$p->id && $legacy->status==='adopted' && $this->isRetailReference($legacy) ? $this->positive($legacy->observed_weight_kg) : null;
            $boxDims = $this->dimensions($box?->measurements ?? [], 'color_box_');
            $media = $p->media->firstWhere('provider', 'erp_manual') ?? $p->media->first();
            $image = null;
            if ($media?->storage_path && Storage::disk('local')->exists($media->storage_path)) $image = route('products.media.show', $media->id);
            elseif ($media && filter_var($media->source_url, FILTER_VALIDATE_URL) && str_starts_with($media->source_url,'https://')) $image = $media->source_url;
            return [
                'id'=>$p->id, 'sku'=>$p->sku, 'material_name'=>$p->material_full_name ?: $p->name, 'image_url'=>$image,
                'retail_dimensions'=>$this->display($boxDims), 'retail_volume_cm3'=>$boxDims ? array_product($boxDims) : null,
                'retail_weight_kg'=>$this->positive($gross?->measurements['color_box_gross_kg'] ?? null) ?? $retailReference,
                'retail_weight_source'=>$gross ? '工厂已审核填报' : ($retailReference !== null ? '腾讯文档（历史彩盒，非新增实测）' : null),
                'unclassified_package_dimensions'=>$this->display($this->dimensions($reference?->measurements ?? [], 'package_')),
                'shipping_dimensions'=>$this->display($ship), 'shipping_volume_cm3'=>$ship ? array_product($ship) : null,
                'shipping_weight_kg'=>$shipWeight, 'shipping_measurement_id'=>$standard['measurement_record_id'] ?? null,
                'packaging_method'=>$standard['packaging_method_label'] ?? $standard['packaging_method_key'] ?? null,
                'shipping_status'=>$ship && $shipWeight ? '已采纳' : '待测量',
                'shipping_measured_at'=>isset($standard['measured_at']) ? \Illuminate\Support\Carbon::parse($standard['measured_at'])->timezone('Asia/Shanghai')->format('Y-m-d H:i:s') : null,
                'volume_weight_kg'=>null, 'billable_weight_kg'=>null, 'billing_basis'=>'未选择计费渠道',
                'reference_weight_kg'=>$this->positive($p->shipping_weight_kg),
                'carton_dimensions'=>$this->display($outer), 'carton_cbm'=>$outer ? array_product($outer)/1e6 : null,
                'carton_weight_kg'=>$this->positive($cartonMeasures['carton_gross_weight_kg'] ?? null),
                'units_per_carton'=>$this->positive($cartonMeasures['units_per_carton'] ?? $profile?->units_per_carton),
                'estimated_carton_dimensions'=>$this->display($estimatedOuter),
                'estimated_carton_cbm'=>$estimatedOuter ? array_product($estimatedOuter)/1e6 : null,
                'retail_evidence_id'=>$box?->id, 'retail_weight_evidence_id'=>$gross?->id,
                'reference_evidence_id'=>$reference?->id, 'carton_evidence_id'=>$carton?->id,
            ];
        })->all();
    }

    public function sources(array $ids): array
    {
        $products = $this->products($ids)->keyBy('id');
        $ids = $products->keys()->all();
        $standards = $this->standards->forProductIds($ids);
        $rows = [];
        foreach (PackagingMeasurementRecord::query()->whereIn('product_id',$ids)->withCount('media')->orderByDesc('id')->get() as $r) {
            $p = $products[$r->product_id];
            $rows[] = ['id'=>'pack-'.$r->id, 'sku'=>$p->sku, 'material_name'=>$p->material_full_name ?: $p->name,
                'source'=>'工厂包装实测', 'kind'=>'物流包装', 'weight_kg'=>$this->positive($r->packaged_weight_kg),
                'dimensions'=>$this->display($this->dimensions($r->getAttributes(),'packaging_')),
                'method'=>$r->packaging_method_key, 'status'=>$r->status, 'current'=>($standards[$p->id]['measurement_record_id'] ?? null)===$r->id,
                'observed_at'=>$r->measured_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i:s'), 'reviewed_at'=>$r->reviewed_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
                'media_count'=>$r->media_count, 'reference'=>'包装测量 #'.$r->id, 'href'=>route('inventory.packaging.show',$r->id)];
        }
        foreach (ProductWeightEvidence::query()->whereIn('product_id',$ids)->where('weight_kind','shipping_reference_weight')->orderByDesc('id')->get() as $r) {
            $p = $products[$r->product_id];
            $rows[] = ['id'=>'weight-'.$r->id,'sku'=>$p->sku,'material_name'=>$p->material_full_name ?: $p->name,
                'source'=>$this->isRetailReference($r) ? '腾讯文档（历史彩盒）' : $r->source_type,'kind'=>$this->isRetailReference($r) ? '彩盒毛重（历史）' : '运输参考重量','weight_kg'=>$this->positive($r->observed_weight_kg),
                'dimensions'=>null,'method'=>null,'status'=>$r->status,'current'=>$r->status==='adopted' && (int)$p->shipping_weight_evidence_id===(int)$r->id,
                'observed_at'=>$r->observed_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i:s'),'reviewed_at'=>$r->reviewed_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
                'media_count'=>null,'reference'=>$r->source_reference,'href'=>route('products.weight-evidence.index',['search'=>$p->sku])];
        }
        $selected = collect($this->rows($ids))->keyBy('id');
        foreach (ProductParameterSubmission::query()->whereIn('product_id',$ids)->whereIn('status',['submitted','approved','rejected'])->withCount('media')->orderByDesc('id')->get() as $r) {
            $p = $products[$r->product_id]; $m = $r->measurements ?? [];
            $row = $selected[$p->id];
            foreach ([
                ['retail','彩盒尺寸',$this->dimensions($m,'color_box_'),null,'retail_evidence_id'],
                ['gross','彩盒毛重',null,$this->positive($m['color_box_gross_kg'] ?? null),'retail_weight_evidence_id'],
                ['package','单件包装（未分类）',$this->dimensions($m,'package_'),null,'reference_evidence_id'],
                ['carton','整箱包装',$this->dimensions($m,'carton_'),$this->positive($m['carton_gross_weight_kg'] ?? null),'carton_evidence_id'],
                ['courier','快递参考重量',null,$this->positive($m['courier_gross_kg'] ?? null),null],
            ] as [$key,$kind,$dims,$weight,$selectedKey]) {
                if ($dims===null && $weight===null) continue;
                $rows[] = ['id'=>'factory-'.$r->id.'-'.$key,'sku'=>$p->sku,'material_name'=>$p->material_full_name ?: $p->name,
                    'source'=>'工厂参数填报','kind'=>$kind,'weight_kg'=>$weight,'dimensions'=>$this->display($dims),'method'=>null,
                    'status'=>$r->status,'current'=>$selectedKey !== null && $row[$selectedKey]===$r->id,
                    'observed_at'=>$r->submitted_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i:s'),'reviewed_at'=>$r->reviewed_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
                    'media_count'=>$r->media_count,'reference'=>'工厂填报 #'.$r->id,'href'=>route('products.show',$p->id)];
            }
        }
        return $rows;
    }

    private function isRetailReference(ProductWeightEvidence $e): bool
    {
        return $e->weight_kind === 'shipping_reference_weight'
            && $e->source_type === 'other_documented'
            && str_contains((string)$e->source_reference, 'Tencent docs')
            && str_contains((string)$e->source_note, '腾讯文档毛重/彩盒KG');
    }

    private function positive(mixed $v): ?float { return is_numeric($v) && is_finite((float)$v) && (float)$v>0 ? (float)$v : null; }
    private function dimensions(array $values, string $prefix): ?array
    {
        $dims = array_map(fn ($axis) => $this->positive($values[$prefix.$axis.'_cm'] ?? null), ['length','width','height']);
        return in_array(null,$dims,true) ? null : $dims;
    }
    private function display(?array $dims): ?string { return $dims ? implode('×',$dims) : null; }
}

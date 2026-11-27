<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Logistics\Application\FulfillmentPlanCostEstimator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-only adapter; A4 owns the rate engine and B owns packaging optimization. */
final class InventoryProductRatePreview
{
    public function __construct(
        private readonly ProductPackagingStandardReader $standards,
        private readonly FulfillmentPlanCostEstimator $rates,
    ) {}

    public function scopes(): array
    {
        if (!Schema::hasTable('logistics_rate_versions') || !Schema::hasTable('logistics_rate_components')) return [];
        return DB::table('logistics_rate_components as c')->join('logistics_rate_versions as v','v.id','=','c.version_id')
            ->where('v.status','approved')->whereNotNull('v.approved_at')->whereNotNull('v.approved_by')
            ->where('c.review_status','verified')->where('c.component_type','transport')->whereNotNull('c.warehouse_code')->whereNotNull('c.destination_country')
            ->select('c.version_id','c.warehouse_code','c.carrier','c.service','c.destination_country','v.effective_from','v.effective_until')
            ->distinct()->orderBy('c.warehouse_code')->orderBy('c.carrier')->orderBy('c.service')->orderBy('c.destination_country')->orderByDesc('c.version_id')
            ->get()->map(fn ($r) => (array)$r)->all();
    }

    public function preview(int $productId, array $input): array
    {
        $record = $this->standards->forProductIds([$productId])[$productId] ?? null;
        $blocked = fn (string $reason) => ['status'=>'unavailable','reason'=>$reason,'components'=>[],
            'native_totals'=>null,'total_cny'=>null,'estimate_only'=>true,'measurement_record_id'=>$record['measurement_record_id'] ?? null];
        if (!$record || (int)$record['measurement_record_id'] !== (int)$input['measurement_record_id']) return $blocked('measurement_missing_or_changed');
        $dims = array_map(fn ($axis) => $record['packaging_'.$axis.'_cm'] ?? null, ['length','width','height']);
        $weight = $record['packaged_weight_kg'] ?? null;
        foreach ([$weight,...$dims] as $v) if (!is_numeric($v) || !is_finite((float)$v) || (float)$v <= 0) return $blocked('measurement_incomplete');
        if (!Schema::hasTable('logistics_rate_versions')) return $blocked('rate_center_not_available');
        // Explicit one-SKU confirmation by the requesting employee, not automated product eligibility or dispatch approval.
        $quote = $this->rates->quoteVersioned([
            'version_id'=>(int)$input['version_id'],'warehouse_code'=>$input['warehouse_code'],
            'carrier'=>$input['carrier'],'service'=>$input['service'],'country'=>$input['country'],
            'postal_code'=>$input['postal_code'] ?? '','as_of'=>$input['as_of'],'handling_policy'=>'internal',
            'packages'=>[['weight_kg'=>(float)$weight,'weight_basis'=>'measured_packaged','dimensions_cm'=>array_map('floatval',$dims),
                'item_count'=>1,'eligibility'=>$input['eligibility_confirmed'] ? 'confirmed' : 'unknown']],
        ]);
        // A4 returns per-component native weights; only unit conversion here, never recompute charging rules.
        $transport = collect($quote['components'] ?? [])->firstWhere('component_type','transport');
        $factor = match ($transport['weight_unit'] ?? null) {'kg'=>1.0,'lb'=>0.45359237,'oz'=>0.45359237/16,default=>null};
        $kg = fn ($value) => $factor !== null && is_numeric($value) ? round((float)$value*$factor,6) : null;
        $basis = match ($transport['rule']['basis'] ?? null) {'actual'=>'实重','max'=>'实重/体积重取大','conditional_max'=>'条件体积重',default=>null};
        return [...$quote,'measurement_record_id'=>$record['measurement_record_id'],
            'volume_weight_kg'=>$kg($transport['dimensional_weight'] ?? null),
            'billable_weight_kg'=>$kg($transport['billable_weight'] ?? null),
            'billing_basis'=>$basis,'quote_scope'=>array_intersect_key($input,array_flip(['version_id','warehouse_code','carrier','service','country','postal_code','as_of']))];
    }
}

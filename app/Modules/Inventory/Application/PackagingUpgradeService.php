<?php
declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Infrastructure\Models\PackagingMeasurementRecord;
use App\Modules\Products\Application\PackagingPresetCatalog;

final class PackagingUpgradeService
{
    public function __construct(private readonly PackagingOptimizationGeometry $geometry,
        private readonly PackagingOptimizationWorkspace $workspace,
        private readonly PackagingOptimizationQuoteComparison $quotes,
        private readonly PackagingPresetCatalog $presets) {}

    public function measurement(PackagingMeasurementRecord $r): array
    {
        return ['record_id'=>$r->id,'method'=>$r->packaging_method_key,'weight_kg'=>$r->packaged_weight_kg===null?null:(float)$r->packaged_weight_kg,
            'length_cm'=>$r->packaging_length_cm===null?null:(float)$r->packaging_length_cm,'width_cm'=>$r->packaging_width_cm===null?null:(float)$r->packaging_width_cm,
            'height_cm'=>$r->packaging_height_cm===null?null:(float)$r->packaging_height_cm];
    }

    public function plan(PackagingMeasurementRecord $base,string $method,int $divisor): array
    {
        $old=$this->measurement($base);
        $geometry=$this->geometry->calculate($old,'max_actual_volumetric',$divisor);
        $candidate=PackagingMeasurementRecord::query()->where('product_id',$base->product_id)->where('status','approved')
            ->where('packaging_method_key',$method)->whereKeyNot($base->id)->where('packaged_weight_kg','>',0)
            ->where('packaging_length_cm','>',0)->where('packaging_width_cm','>',0)->where('packaging_height_cm','>',0)
            ->where(fn($q)=>$q->whereNull('photo_requirement_reason')->orWhere(fn($p)=>$p->whereHas('media',fn($m)=>$m->where('kind','weight'))->whereHas('media',fn($m)=>$m->where('kind','measurement'))))
            ->latest('id')->first();
        return ['baseline'=>$old,'proposed'=>['method'=>$method,'length_cm'=>$old['length_cm'],'width_cm'=>$old['width_cm'],
            'height_cm'=>$geometry['candidate_height_cm'],'weight_kg'=>$old['weight_kg'],'reference_only'=>true],
            'prior_candidate'=>$candidate?$this->measurement($candidate):null,'reference_divisor'=>$divisor,'confirmed_at'=>now()->toIso8601String()];
    }

    public function comparison(PackagingMeasurementRecord $r): ?array
    {
        if (!$r->upgrade_from_record_id || !is_array($r->upgrade_plan)) return null;
        $plan=$r->upgrade_plan;$old=$plan['baseline'];$actual=$this->measurement($r);
        $volume=fn($v)=>min($v['length_cm']??0,$v['width_cm']??0,$v['height_cm']??0)>0?$v['length_cm']*$v['width_cm']*$v['height_cm']:null;
        $before=$volume($old);$after=$volume($actual);
        return ['baseline'=>$old,'proposed'=>$plan['proposed'],'prior_candidate'=>$plan['prior_candidate']??null,'actual'=>$actual,
            'reference_divisor'=>$plan['reference_divisor'],'confirmed_at'=>$plan['confirmed_at'],
            'volume_reduction_fraction'=>$before&&$after!==null?1-$after/$before:null,
            'weight_saving_kg'=>$actual['weight_kg']>0?round($old['weight_kg']-$actual['weight_kg'],6):null];
    }

    public function quote(PackagingMeasurementRecord $r,array $filters): ?array
    {
        if (!$r->upgrade_from_record_id || !($filters['confirmed']??false) || empty($filters['route_id'])) return null;
        $c=$this->comparison($r);
        if (!$c || min($c['actual']['weight_kg']??0,$c['actual']['length_cm']??0,$c['actual']['width_cm']??0,$c['actual']['height_cm']??0)<=0) return null;
        $date=now('Asia/Shanghai')->toDateString();
        $country=$filters['country'];
        $route=$this->workspace->routes($country,$date)->firstWhere('component_id',(int)$filters['route_id']);
        if (!$route) return null;
        $pack=fn($v)=>['items'=>[$r->product_id=>1],'weight_kg'=>$v['weight_kg'],'dimensions_cm'=>[$v['length_cm'],$v['width_cm'],$v['height_cm']],'weight_basis'=>'measured_packaged','eligibility'=>'confirmed'];
        $context=['version_id'=>(int)$route->version_id,'warehouse_code'=>$route->warehouse_code,'carrier'=>$route->carrier,'service'=>$route->service,'country'=>$country,'postal_code'=>$filters['postal']??'','as_of'=>$date,'handling_policy'=>$filters['handling']??'supplier'];
        $q=$this->quotes->compare($context,[$pack($c['baseline'])],[$r->packaging_method_key=>[$pack($c['actual'])]],$route->status==='draft');
        $result=$q['candidates'][$r->packaging_method_key]??null;
        return ['mode'=>$q['mode'],'version_id'=>$context['version_id'],'as_of'=>$date,'route'=>[$route->warehouse_code,$route->carrier,$route->service,$country],'postal_code'=>$context['postal_code'],'handling_policy'=>$context['handling_policy'],
            'before'=>$q['baseline'],'after'=>$result,'eligible_for_ranking'=>false,'estimate_only'=>true];
    }
}

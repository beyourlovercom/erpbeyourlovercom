<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Infrastructure\Models\PackagingMeasurementRecord;
use App\Modules\Products\Application\PackagingPresetCatalog;
use App\Modules\Products\Infrastructure\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class PackagingOptimizationWorkspace
{
    public function __construct(
        private readonly PackagingOptimizationCoverageReader $coverage,
        private readonly PackagingOptimizationComparison $geometry,
        private readonly PackagingOptimizationQuoteComparison $quotes,
        private readonly PackagingOptimizationRouteSamples $samples,
        private readonly PackagingPresetCatalog $presets,
    ) {}

    public function listing(array $filters): array
    {
        $query = Product::query()->businessCatalog()->where('is_active',true)->orderBy('sku')->orderBy('id');
        if ($search = trim($filters['search'] ?? '')) {
            $query->where(fn($q)=>$q->where('sku','like','%'.$search.'%')->orWhere('name','like','%'.$search.'%')->orWhere('material_full_name','like','%'.$search.'%'));
        }
        $rows=collect();
        $stats=['total'=>0,'complete'=>0,'missing'=>0,'review'=>0];
        $query->chunk(250,function($products) use (&$rows,&$stats,$filters){
            $covered=$this->coverage->forProductIds($products->pluck('id')->all());
            foreach ($products as $product) {
                $row=$covered[$product->id]??null;
                if (!$row) continue;
                $stats['total']++;
                $stats[$row['needs_measurement']?'missing':'complete']++;
                if($row['awaiting_review']) $stats['review']++;
                if (($filters['tab'] ?? '')==='standards' && $row['needs_measurement']) continue;
                if (($filters['coverage'] ?? '')==='missing' && !$row['needs_measurement']) continue;
                if (($filters['coverage'] ?? '')==='complete' && $row['needs_measurement']) continue;
                if (($filters['coverage'] ?? '')==='review' && !$row['awaiting_review']) continue;
                unset($row['input']['evidence']);
                $row['price_status']='requires_verified_route';
                $rows->push($row);
            }
        });
        $size=(int)($filters['per_page']??20);
        $page=LengthAwarePaginator::resolveCurrentPage();
        $result=new LengthAwarePaginator($rows->forPage($page,$size)->values(),$rows->count(),$size,$page,['path'=>LengthAwarePaginator::resolveCurrentPath()]);
        $result->appends($filters);
        return ['rows'=>$result,'stats'=>$stats];
    }

    public function detail(int $productId,array $filters,bool $canQuote): array
    {
        $row=$this->coverage->forProductIds([$productId])[$productId]??null;
        abort_unless($row,404);
        $input=$row['input'];
        $presets=$this->presets->all();
        $methods=array_column($presets,'label','key');
        $records=PackagingMeasurementRecord::query()->where('product_id',$productId)->where('status','approved')
            ->whereIn('packaging_method_key',array_keys($methods))->where('packaged_weight_kg','>',0)
            ->where('packaging_length_cm','>',0)->where('packaging_width_cm','>',0)->where('packaging_height_cm','>',0)
            ->where(fn($q)=>$q->whereNull('photo_requirement_reason')->orWhere(fn($p)=>$p->whereHas('media',fn($m)=>$m->where('kind','weight'))->whereHas('media',fn($m)=>$m->where('kind','measurement'))))
            ->latest('id')->get()->unique('packaging_method_key')->keyBy('packaging_method_key');
        $candidates=[];$packages=[];
        foreach($records as $method=>$r) {
            $candidates[$method]=['weight_kg'=>(float)$r->packaged_weight_kg,'length_cm'=>(float)$r->packaging_length_cm,'width_cm'=>(float)$r->packaging_width_cm,'height_cm'=>(float)$r->packaging_height_cm,'record_id'=>$r->id];
            $packages[$method]=[$this->package($productId,$candidates[$method])];
        }
        $country=$filters['country']??'US';
        $date=now('Asia/Shanghai')->toDateString();
        $routes=$canQuote?$this->routes($country,$date):collect();
        $selected=$routes->firstWhere('component_id',(int)($filters['route_id']??0));
        $rule='max_actual_volumetric';$divisor=(int)($filters['divisor']??6000);$geometryReference='hypothetical';
        if($selected) {
            $spec=json_decode((string)$selected->rule_spec,true)?:[];
            $rule=($spec['basis']??null)==='actual'?'actual_only':((($spec['basis']??null)==='max' && ($spec['volume_unit']??null)==='cm3_kg')?'max_actual_volumetric':'unknown');
            $divisor=$rule==='max_actual_volumetric'?(float)($spec['dim_divisor']??0):null;
            $geometryReference='selected_route';
        }
        $comparison=$this->geometry->compare($input,$candidates,$rule,$divisor);
        $quote=null;
        if($selected && ($filters['confirmed']??false) && $input['status']==='ready') {
            $route=['version_id'=>(int)$selected->version_id,'warehouse_code'=>$selected->warehouse_code,'carrier'=>$selected->carrier,'service'=>$selected->service,'country'=>$country,'postal_code'=>$filters['postal']??'','as_of'=>$date,'handling_policy'=>$filters['handling']??'supplier'];
            $quote=$this->quotes->compare($route,[$this->package($productId,$input)],$packages,$selected->status==='draft');
            foreach($quote['candidates'] as &$q) $q['eligible_for_ranking']=false;
            unset($q);
        }
        $sample=$this->samples->forProduct($productId,CarbonImmutable::now('Asia/Shanghai'),90);
        $coverage=['observed'=>$sample['observed_shipments'],'candidate'=>$sample['candidate_shipments'],'excluded'=>$sample['excluded_shipments'],'monthly_saving_cny'=>null,'eligible_for_ranking'=>false];
        $standardId=$input['evidence']['measurement_record_id']??null;
        unset($row['input']['evidence']);
        return ['product'=>$row,'methods'=>$methods,'candidates'=>$candidates,'geometry'=>$comparison,'geometry_reference'=>$geometryReference,'divisor'=>$divisor,'rule'=>$rule,'routes'=>$routes->map(fn($r)=>['id'=>(int)$r->component_id,'version_id'=>(int)$r->version_id,'status'=>$r->status,'warehouse'=>$r->warehouse_code,'carrier'=>$r->carrier,'service'=>$r->service])->values(),'quote'=>$quote,'history'=>$coverage,'standard_record_id'=>$standardId];
    }

    private function package(int $productId,array $input): array
    {
        return ['items'=>[$productId=>1],'weight_kg'=>$input['weight_kg'],'dimensions_cm'=>[$input['length_cm'],$input['width_cm'],$input['height_cm']],'weight_basis'=>'measured_packaged','eligibility'=>'confirmed'];
    }

    public function routes(string $country,string $date)
    {
        $effective=DB::table('logistics_rate_versions')->whereIn('status',['approved','draft'])->whereNotNull('effective_from')->where('effective_from','<=',$date)
            ->where(fn($q)=>$q->whereNull('effective_until')->orWhere('effective_until','>=',$date))
            ->orderByDesc('version_number')->orderByDesc('id')->get(['id','book_id','status']);
        $ids=$effective->where('status','approved')->pluck('id')->concat($effective->where('status','draft')->groupBy('book_id')->map(fn($b)=>$b->first()->id))->values();
        $rows=DB::table('logistics_rate_components as c')->join('logistics_rate_versions as v','v.id','=','c.version_id')
            ->whereIn('v.id',$ids)->where('c.destination_country',$country)->where('c.component_type','transport')
            ->orderBy('c.id')->get(['c.id as component_id','c.version_id','c.warehouse_code','c.carrier','c.service','c.rule_spec','v.status']);
        return $rows->groupBy(fn($r)=>$r->warehouse_code.'|'.$r->carrier.'|'.$r->service)->map(function($group){
            $approved=$group->where('status','approved');
            $pool=$approved->isNotEmpty()?$approved:$group;
            // More than one applicable version or conflicting geometry is not selectable.
            return $pool->pluck('version_id')->unique()->count()===1 && $pool->pluck('rule_spec')->unique()->count()===1 ? $pool->first():null;
        })->filter()->values();
    }
}

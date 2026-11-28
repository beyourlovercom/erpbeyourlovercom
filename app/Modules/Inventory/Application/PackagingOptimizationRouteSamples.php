<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** B4 conservative historical cohort, not a claim that an item snapshot proves a parcel.
 * Internal only: route/postal context is for A4, never a public employee data feed.
 */
final class PackagingOptimizationRouteSamples
{
    public function forProduct(int $productId, CarbonImmutable $endExclusive, int $days = 90): array
    {
        if ($productId <= 0 || $days < 1 || $days > 365) throw new \InvalidArgumentException('Invalid cohort');
        $start = $endExclusive->subDays($days);
        $base = DB::table('shipments as s')
            ->join('orders as o', 'o.id', '=', 's.order_id')
            ->whereIn('s.shipment_status', ['shipped', 'completed'])
            ->where('s.shipped_at', '>=', $start)->where('s.shipped_at', '<', $endExclusive)
            ->whereNull('o.deleted_at')->whereIn('o.order_status', ['shipped','completed','completed_delivered'])
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('order_items as i')->whereColumn('i.order_id','o.id')->where('i.product_id',$productId));
        $observed = (clone $base)->count('s.id');
        $rows = (clone $base)
            ->join('order_items as i','i.order_id','=','o.id')->where('i.product_id',$productId)->where('i.quantity',1)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('order_items as other')->whereColumn('other.order_id','o.id')->whereColumn('other.id','!=','i.id'))
            ->where('o.order_kind','customer')->where('o.source_type','woocommerce')
            ->whereNull('o.fulfillment_parent_id')->whereNull('o.fulfillment_origin_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('orders as child')->whereColumn('child.fulfillment_parent_id','o.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('shipment_attempts as a')->whereColumn('a.order_id','o.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('xlwms_reshipments as x')->whereColumn('x.original_order_id','o.id'))
            ->join('order_financials as f','f.order_id','=','o.id')->whereNotNull('f.paid_at')
            ->whereIn('f.provider_order_status',['processing','completed'])->where('f.refund_amount',0)
            ->join('order_addresses as a','a.order_id','=','o.id')->join('warehouses as w','w.id','=','s.warehouse_id')
            ->join('shipment_item_packaging_snapshots as p',function ($j) { $j->on('p.shipment_id','=','s.id')->on('p.order_item_id','=','i.id')->on('p.product_id','=','i.product_id'); })
            ->whereNotNull('s.tracking_number')->where('s.tracking_number','!=','')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('shipments as duplicate')->whereColumn('duplicate.tracking_number','s.tracking_number')->whereColumn('duplicate.carrier','s.carrier')->whereColumn('duplicate.id','!=','s.id'))
            ->whereNotNull('s.carrier')->where('s.carrier','!=','')->whereNotNull('s.service')->where('s.service','!=','')
            ->whereNotNull('w.code')->where('w.code','!=','')->whereNotNull('a.country')->where('a.country','!=','')
            ->where('p.packaged_weight_kg','>',0)->where('p.packaging_length_cm','>',0)->where('p.packaging_width_cm','>',0)->where('p.packaging_height_cm','>',0)
            ->get(['s.id as shipment_id','w.code as warehouse_code','s.carrier','s.service','a.country','a.postal_code','p.packaging_measurement_record_id','p.packaged_weight_kg','p.packaging_length_cm','p.packaging_width_cm','p.packaging_height_cm']);
        $groups = [];
        foreach ($rows as $row) {
            $key = hash('sha256',json_encode([$row->warehouse_code,$row->carrier,$row->service,$row->country,$row->postal_code,$row->packaging_measurement_record_id,$row->packaged_weight_kg,$row->packaging_length_cm,$row->packaging_width_cm,$row->packaging_height_cm],JSON_THROW_ON_ERROR));
            $groups[$key] ??= ['route'=>['warehouse_code'=>$row->warehouse_code,'carrier'=>$row->carrier,'service'=>$row->service,'country'=>$row->country,'postal_code'=>$row->postal_code],
                'historical_measurement_record_id'=>$row->packaging_measurement_record_id,
                'snapshot'=>['weight_kg'=>(float)$row->packaged_weight_kg,'dimensions_cm'=>[(float)$row->packaging_length_cm,(float)$row->packaging_width_cm,(float)$row->packaging_height_cm]],
                'shipment_count'=>0,'parcel_identity_verified'=>false];
            $groups[$key]['shipment_count']++;
        }
        return ['product_id'=>$productId,'start'=>$start->toIso8601String(),'end_exclusive'=>$endExclusive->toIso8601String(),'days'=>$days,
            'observed_shipments'=>$observed,'candidate_shipments'=>$rows->count(),'excluded_shipments'=>$observed-$rows->count(),
            'cohorts'=>$groups,'status'=>'requires_parcel_and_route_verification'];
    }
}

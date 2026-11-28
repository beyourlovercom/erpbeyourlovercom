<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

final class InventoryProductRateFixture
{
    public static function create(int $actorId, string $status='approved', string $unit='kg'): int
    {
        $seed = uniqid('inv-rate-',true);
        $source=DB::table('logistics_rate_source_files')->insertGetId(['sha256'=>hash('sha256',$seed),'original_filename'=>'synthetic.xlsx','private_storage_path'=>'test-only','mime_type'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','byte_size'=>1]);
        $book=DB::table('logistics_rate_books')->insertGetId(['key'=>$seed,'supplier_name'=>'Synthetic','display_name'=>'Synthetic rate']);
        $id=DB::table('logistics_rate_versions')->insertGetId(['book_id'=>$book,'source_file_id'=>$source,'version_number'=>1,'status'=>$status,'effective_from'=>'2025-01-01','approved_at'=>$status==='approved'?now():null,'approved_by'=>$status==='approved'?$actorId:null]);
        $c=DB::table('logistics_rate_components')->insertGetId([
            'version_id'=>$id,'component_key'=>'synthetic-transport','warehouse_code'=>'FACTORY','carrier'=>'SYNTHETIC','service'=>'max-dim','destination_country'=>'US',
            'component_type'=>'transport','currency'=>'CNY','weight_unit'=>$unit,'pricing_mode'=>'fixed_band',
            'rule_spec'=>json_encode(['basis'=>'max','dim_divisor'=>5000,'volume_unit'=>'cm3_kg','minimum'=>0.5,'increment'=>0.5,'coverage_complete'=>true,'handling_included'=>true]),
            'source_sheet'=>'Synthetic','source_cells'=>'A1:B2','review_status'=>$status==='approved'?'verified':'needs_review',
        ]);
        DB::table('logistics_rate_bands')->insert(['component_id'=>$c,'sequence'=>1,'min_weight'=>0,'max_weight'=>100,'min_inclusive'=>true,'max_inclusive'=>true,'fixed_amount'=>20,'source_cells'=>'A2:B2']);
        return $id;
    }
}

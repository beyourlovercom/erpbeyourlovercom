<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use App\Modules\Inventory\Application\PackagingOptimizationMonthlyProjection;
use PHPUnit\Framework\TestCase;

final class PackagingOptimizationMonthlyProjectionTest extends TestCase
{
    public function test_only_verified_approved_distinct_samples_contribute_and_partial_coverage_is_not_rankable(): void
    {
        $s=new PackagingOptimizationMonthlyProjection;
        $ready=['status'=>'ready','eligible_for_ranking'=>true,'logistics_saving_cny'=>10];
        $c=['cohort_key'=>'one','shipment_count'=>9,'parcel_identity_verified'=>true,'candidate_protection_verified'=>true,'candidate_measurement_record_id'=>100,'comparison'=>$ready];
        $d=['cohort_key'=>'two','shipment_count'=>3,'parcel_identity_verified'=>true,'candidate_protection_verified'=>true,'candidate_measurement_record_id'=>100,'comparison'=>[...$ready,'logistics_saving_cny'=>-5]];
        $draft=['cohort_key'=>'draft','shipment_count'=>6,'parcel_identity_verified'=>true,'comparison'=>['status'=>'draft_estimate','eligible_for_ranking'=>false,'reference_transport_saving_cny'=>100]];
        $unknown=['cohort_key'=>'unknown','shipment_count'=>5,'parcel_identity_verified'=>false,'comparison'=>$ready];
        $r=$s->summarize([$c,$d,$draft,$unknown],90,23);
        self::assertSame(12,$r['covered_shipments']);
        self::assertSame(25.0,$r['monthly_logistics_saving_cny']);
        self::assertFalse($r['eligible_for_ranking']);
        self::assertSame('partial_sample',$r['status']);
        self::assertNull($r['monthly_net_saving_cny']);
        self::assertTrue($s->summarize([$c,$d],90,12)['eligible_for_ranking']);
        self::assertNull($s->summarize([$draft,$unknown],90,11)['monthly_logistics_saving_cny']);
        $a=$s->summarize([$c,$d],90,12);
        $b=$s->summarize([$c],90,9);
        $rank=$s->rank([101=>$a,102=>$b,103=>$r]);
        self::assertSame([102,101],array_keys($rank['ranked']));
        self::assertSame([103],array_keys($rank['excluded']));
        $this->expectException(\InvalidArgumentException::class);
        $s->summarize([$c,$c],90,18);
    }
}

<?php

namespace Tests\Unit\Products;

use App\Modules\Products\Application\VerifiedOmsSkuSelector;
use App\Modules\Products\Application\VerifiedOmsSnapshotPreflight;
use PHPUnit\Framework\TestCase;

final class VerifiedOmsSnapshotPreflightTest extends TestCase
{
    private const NOW = 1793016000;

    private function snapshot(array $change = []): array
    {
        return array_replace_recursive([
            'warehouse_id'=>42, 'wh_code'=>'OMS-US-SM', 'status'=>'applied',
            'summary'=>['v2'=>true,'v2_applied'=>true,'oms_rows'=>1],
            'unmatched_sku_count'=>0,'blocked_sku_count'=>0,'provider_sku_count'=>1,
            'last_checked_at'=>gmdate('c', self::NOW - 5),
        ], $change);
    }

    private function row(string $sku = 'ABC-WH', int $id = 9, int $stock = 7): array
    {
        return ['provider_sku'=>$sku,'product_id'=>$id,'resolution_status'=>'matched',
            'oms_total'=>$stock+1,'oms_available'=>$stock,'oms_locked'=>1];
    }

    private function alias(string $sku = 'ABC-WH', int $id=9): array
    {
        return ['product_id'=>$id,'alias_sku'=>$sku,'is_active'=>true,
            'oms_verified_at'=>'2026-10-26T09:00:00+00:00',
            'oms_identity_evidence'=>'Matching model, color and package label verified.'];
    }

    private function evaluate(
        array $snapshot = [], ?array $rows = null, ?array $lines = null,
        ?array $aliases = null, ?array $liveApproved = null, int $now=self::NOW
    ): array {
        $selector = new VerifiedOmsSnapshotPreflight(new VerifiedOmsSkuSelector());
        return $selector->forOrder(42, 'OMS-US-SM', $snapshot ?: $this->snapshot(),
            $rows ?? [$this->row()],
            $lines ?? [['product_id'=>9,'canonical_sku'=>'CANONICAL-WH','quantity'=>2]],
            $aliases ?? [9=>[$this->alias()]], $liveApproved ?? ['ABC-WH'], $now);
    }

    public function test_approved_alias_selects_unique_reconciled_oms_sku(): void
    {
        $actual = $this->evaluate();
        self::assertSame('ready', $actual['status']);
        self::assertSame('ABC-WH', $actual['items'][0]['oms_sku']);
        self::assertSame('CANONICAL-WH', $actual['items'][0]['canonical_sku']);
        self::assertSame(2, $actual['items'][0]['quantity']);
    }

    public function test_multiple_order_lines_aggregate_stock_before_selection(): void
    {
        $lines = [
            ['product_id'=>9,'canonical_sku'=>'CANONICAL-WH','quantity'=>4],
            ['product_id'=>9,'canonical_sku'=>'CANONICAL-WH','quantity'=>4],
        ];
        self::assertSame('OMS_STOCK_INSUFFICIENT', $this->evaluate(lines:$lines)['code']);
    }

    public function test_canonical_can_match_without_alias(): void
    {
        $actual=$this->evaluate(rows:[$this->row('CANONICAL-WH')], aliases:[],liveApproved:['CANONICAL-WH']);
        self::assertSame('ready',$actual['status']);
        self::assertSame('CANONICAL-WH',$actual['items'][0]['oms_sku']);
    }

    public function test_unverified_alias_and_other_product_identity_fail_closed(): void
    {
        $alias=$this->alias();$alias['oms_verified_at']=null;
        self::assertSame('OMS_SKU_NOT_FOUND',$this->evaluate(aliases:[9=>[$alias]])['code']);
        self::assertSame('OMS_PHYSICAL_IDENTITY_UNVERIFIED',$this->evaluate(rows:[$this->row('ABC-WH',10)])['code']);
    }

    public function test_cross_product_alias_injection_is_blocked(): void
    {
        $fake = $this->alias('ABC-WH', 11);
        self::assertSame('OMS_ALIAS_PRODUCT_MISMATCH', $this->evaluate(aliases:[9=>[$fake]])['code']);
    }

    public function test_snapshot_status_v2_lot_reconciliation_and_scope_required(): void
    {
        self::assertSame('OMS_SNAPSHOT_NOT_RECONCILED',$this->evaluate(snapshot:$this->snapshot(['status'=>'read_only']))['code']);
        self::assertSame('OMS_SNAPSHOT_NOT_RECONCILED',$this->evaluate(snapshot:$this->snapshot(['summary'=>['v2_applied'=>false]]))['code']);
        self::assertSame('OMS_SNAPSHOT_NOT_RECONCILED',$this->evaluate(snapshot:$this->snapshot(['blocked_sku_count'=>1]))['code']);
        self::assertSame('OMS_SNAPSHOT_WAREHOUSE_MISMATCH',$this->evaluate(snapshot:$this->snapshot(['wh_code'=>'OTHER-WH']))['code']);
        self::assertSame('OMS_SNAPSHOT_WAREHOUSE_MISMATCH',$this->evaluate(snapshot:$this->snapshot(['warehouse_id'=>43]))['code']);
    }

    public function test_stale_future_or_missing_snapshot_read_blocks(): void
    {
        foreach ([self::NOW-121,self::NOW+1] as $time) {
            self::assertSame('OMS_SNAPSHOT_NOT_FRESH', $this->evaluate(snapshot:$this->snapshot(['last_checked_at'=>gmdate('c',$time)]))['code']);
        }
        self::assertSame('OMS_SNAPSHOT_NOT_FRESH',$this->evaluate(snapshot:$this->snapshot(['last_checked_at'=>'invalid']))['code']);
    }

    public function test_catalog_completeness_pagination_and_duplicate_keys_are_verified(): void
    {
        self::assertSame('OMS_SNAPSHOT_INCOMPLETE',$this->evaluate(snapshot:$this->snapshot(['provider_sku_count'=>2]))['code']);
        $s=$this->snapshot(['provider_sku_count'=>2,'summary'=>['oms_rows'=>2]]);
        self::assertSame('OMS_SNAPSHOT_DUPLICATE_SKU',$this->evaluate(snapshot:$s,rows:[$this->row(),$this->row('abc-wh')])['code']);
        self::assertSame('OMS_SNAPSHOT_ROW_COUNT_MISMATCH',$this->evaluate(snapshot:$this->snapshot(['summary'=>['oms_rows'=>2]]))['code']);
    }

    public function test_product_is_ambiguous_when_both_main_sku_and_alias_exist_in_oms(): void
    {
        $s=$this->snapshot(['provider_sku_count'=>2,'summary'=>['oms_rows'=>2]]);
        self::assertSame('OMS_SKU_AMBIGUOUS',$this->evaluate(snapshot:$s,rows:[$this->row(),$this->row('CANONICAL-WH')],liveApproved:['ABC-WH','CANONICAL-WH'])['code']);
    }

    public function test_catalog_only_is_not_interpreted_as_zero_or_valid_stock(): void
    {
        $row=['provider_sku'=>'ABC-WH','product_id'=>null,'resolution_status'=>'catalog_only',
            'oms_total'=>null,'oms_available'=>null,'oms_locked'=>null];
        $s=$this->snapshot(['summary'=>['oms_rows'=>0]]);
        self::assertSame('OMS_PHYSICAL_IDENTITY_UNVERIFIED',$this->evaluate(snapshot:$s,rows:[$row])['code']);
    }

    public function test_fresh_vendor_approval_and_stock_are_mandatory(): void
    {
        self::assertSame('OMS_SKU_UNAPPROVED',$this->evaluate(liveApproved:['OTHER'])['code']);
        self::assertSame('OMS_ORDER_SKU_PREFLIGHT_INCOMPLETE',$this->evaluate(liveApproved:[])['code']);
        $row=$this->row();$row['oms_available']=99;
        self::assertSame('OMS_SNAPSHOT_STOCK_INVALID',$this->evaluate(rows:[$row])['code']);
    }

    public function test_cross_product_collision_is_detected_before_dispatch(): void
    {
        $rows=[$this->row('ABC-WH',9),$this->row('OTHER-ALIAS',10)];
        $s=$this->snapshot(['provider_sku_count'=>2,'summary'=>['oms_rows'=>2]]);
        $lines=[['product_id'=>9,'canonical_sku'=>'CANONICAL-WH','quantity'=>1],
            ['product_id'=>10,'canonical_sku'=>'OTHER-CANONICAL','quantity'=>1]];
        $aliases=[9=>[$this->alias()],10=>[$this->alias('OTHER-ALIAS',10)]];
        self::assertSame('ready', $this->evaluate(snapshot:$s,rows:$rows,lines:$lines,aliases:$aliases,liveApproved:['ABC-WH','OTHER-ALIAS'])['status']);
        $rows[1]['provider_sku']='ABC-WH';
        self::assertSame('OMS_SNAPSHOT_DUPLICATE_SKU', $this->evaluate(snapshot:$s,rows:$rows,lines:$lines,aliases:$aliases,liveApproved:['ABC-WH'])['code']);
    }
}

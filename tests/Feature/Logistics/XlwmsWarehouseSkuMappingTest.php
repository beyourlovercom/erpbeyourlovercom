<?php

namespace Tests\Feature\Logistics;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Application\Xlwms\WarehouseSkuResolver;
use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseSkuMapping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class XlwmsWarehouseSkuMappingTest extends TestCase
{
    use RefreshDatabase;

    private function warehouse(string $code = 'US-SM'): Warehouse
    {
        return Warehouse::query()->firstOrCreate(['code' => $code], [
            'name' => $code, 'slug' => strtolower($code),
            'type' => 'warehouse', 'is_active' => true,
        ]);
    }

    private function mapping(int $id, string $status, bool $evidence = false): WarehouseSkuMapping
    {
        return WarehouseSkuMapping::query()->create([
            'warehouse_id'=>$id, 'provider'=>'xlwms',
            'erp_sku'=>'VP757A','erp_sku_norm'=>'VP757A',
            'oms_sku'=>'VP757B', 'status'=>$status,
            'evidence_note'=>$evidence ? 'Product identity checked against manufacturer model and warehouse barcode.' : null,
            'confirmed_at'=>$evidence ? now() : null,
            'confirmed_by'=>$evidence ? 1001 : null,
        ]);
    }

    public function test_no_exception_preserves_exact_sku_without_any_mapping(): void
    {
        $w=$this->warehouse();
        $result=app(WarehouseSkuResolver::class)->resolve($w->id,['MW820A','VX609A']);
        $this->assertSame(['MW820A'=>'MW820A','VX609A'=>'VX609A'],$result['resolved']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame('identity',$result['rows'][0]['source']);
    }

    public function test_draft_candidate_never_changes_fulfillment_payload(): void
    {
        $w=$this->warehouse();
        $this->mapping($w->id,'draft');
        $result=app(WarehouseSkuResolver::class)->resolve($w->id,['VP757A']);
        $this->assertSame('VP757A',$result['resolved']['VP757A']);
        $this->assertContains('sku_mapping_pending_confirmation:VP757A',$result['blockers']);
        $this->assertSame('VP757B',$result['rows'][0]['oms_sku']);
    }

    public function test_confirmed_exception_changes_outbound_only(): void
    {
        $w=$this->warehouse();
        $this->mapping($w->id,'active',true);
        $result=app(WarehouseSkuResolver::class)->resolve($w->id,['VP757A','MW820A']);
        $this->assertSame('VP757B',$result['resolved']['VP757A']);
        $this->assertSame('MW820A',$result['resolved']['MW820A']);
        $this->assertSame('active',$result['rows'][0]['status']);
        $this->assertSame([],$result['blockers']);
    }

    public function test_active_flag_without_proof_is_blocked_not_used(): void
    {
        $w=$this->warehouse();
        $this->mapping($w->id,'active',false);
        $result=app(WarehouseSkuResolver::class)->resolve($w->id,['VP757A']);
        $this->assertSame('VP757A',$result['resolved']['VP757A']);
        $this->assertContains('sku_mapping_missing_evidence:VP757A',$result['blockers']);
    }

    public function test_mapping_never_bleeds_into_a_different_warehouse(): void
    {
        $w=$this->warehouse();
        $w2=$this->warehouse('US-LA');
        $this->mapping($w->id,'active',true);
        $result=app(WarehouseSkuResolver::class)->resolve($w2->id,['VP757A']);
        $this->assertSame('VP757A',$result['resolved']['VP757A']);
        $this->assertSame([],$result['blockers']);
    }
}

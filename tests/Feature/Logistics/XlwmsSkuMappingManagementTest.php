<?php

namespace Tests\Feature\Logistics;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Application\Xlwms\WarehouseSkuMappingManager;
use App\Modules\Logistics\Application\Xlwms\WarehouseSkuResolver;
use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseOpenApiConnection;
use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseSkuMapping;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class XlwmsSkuMappingManagementTest extends TestCase
{
    use RefreshDatabase;

    private function setupData(): array
    {
        $warehouse=Warehouse::query()->firstOrCreate(['code'=>'US-SM'], [
            'slug'=>'us-3sm','name'=>'世迈仓','type'=>'warehouse','is_active'=>true,
        ]);
        Product::query()->firstOrCreate(['sku'=>'VP757A'], [
            'name'=>'Provisional ERP model','product_type'=>'simple','is_active'=>false,
        ]);
        $role=Role::query()->firstOrCreate(['key'=>'sku-mapping-admin'],['label'=>'Admin']);
        $permission=Permission::query()->firstOrCreate(['key'=>'system.permissions.manage'],['label'=>'Admin']);
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $actor=User::factory()->create(['is_active'=>true]);
        $actor->roles()->attach($role);
        return [$warehouse,$actor];
    }

    public function test_propose_from_admin_creates_draft_never_effective_for_dispatch(): void
    {
        [$w,$actor]=$this->setupData();
        Http::fake();
        $this->actingAs($actor)->postJson('/settings/warehouses/us-3sm/openapi/xlwms/sku-mappings',[
            'erp_sku'=>'VP757A','oms_sku'=>'VP757B','note'=>'Candidate only until real product identity confirmed',
        ])->assertOk()->assertJsonPath('status','draft')->assertJsonPath('affects_outbound',false);
        $map=WarehouseSkuMapping::query()->sole();
        $this->assertNull($map->confirmed_at);
        $this->assertNull($map->confirmed_by);
        $this->assertSame('VP757A',app(WarehouseSkuResolver::class)->resolve($w->id,['VP757A'])['resolved']['VP757A']);
        Http::assertNothingSent();
    }

    public function test_only_exact_manual_confirmation_and_remote_approved_sku_can_activate(): void
    {
        [$w,$actor]=$this->setupData();
        $manager=app(WarehouseSkuMappingManager::class);
        $record=$manager->propose($w->id,'VP757A','VP757B','Physical identity not yet confirmed',$actor->id);
        $evidence='Physical product model and actual warehouse barcode were checked side by side.';
        $expected='CONFIRM_SKU_EQUIVALENCE:VP757A=VP757B';

        WarehouseOpenApiConnection::query()->create([
            'warehouse_id'=>$w->id,'provider'=>'xlwms','app_key_encrypted'=>'test-key',
            'app_secret_encrypted'=>'test-secret','oms_warehouse_code'=>'OMS-US-SM'
        ]);
        Http::fake(['https://api.xlwms.com/*' => Http::response([
            'code'=>200,'data'=>['records'=>[['sku'=>'VP757B','approveStatus'=>2]]],
        ])]);
        $this->actingAs($actor)->postJson("/settings/warehouses/us-3sm/openapi/xlwms/sku-mappings/{$record->id}/confirm",[
            'confirmation'=>'CONFIRM_SKU_EQUIVALENCE:VP757A=VP757A',
            'evidence'=>$evidence,
        ])->assertStatus(422);
        $this->assertSame('draft',$record->fresh()->status);
        Http::assertNothingSent();

        $this->actingAs($actor)->postJson("/settings/warehouses/us-3sm/openapi/xlwms/sku-mappings/{$record->id}/confirm",[
            'confirmation'=>$expected,'evidence'=>$evidence,
        ])->assertOk()->assertJsonPath('status','active');
        $this->assertSame('VP757B',app(WarehouseSkuResolver::class)->resolve($w->id,['VP757A'])['resolved']['VP757A']);
        $this->assertSame((int)$actor->id,(int)$record->fresh()->confirmed_by);
        Http::assertSentCount(1);
        Http::assertNotSent(fn($req)=>str_contains($req->url(),'outboundOrder/create'));
    }

    public function test_remote_missing_sku_fails_closed_and_does_not_activate(): void
    {
        [$w,$actor]=$this->setupData();
        $map=app(WarehouseSkuMappingManager::class)->propose($w->id,'VP757A','VP757B',
            'Physical identity still requires independent evidence',$actor->id);
        WarehouseOpenApiConnection::query()->create([
            'warehouse_id'=>$w->id,'provider'=>'xlwms','app_key_encrypted'=>'test-key',
            'app_secret_encrypted'=>'test-secret','oms_warehouse_code'=>'OMS-US-SM'
        ]);
        Http::fake(['https://api.xlwms.com/*'=>Http::response(['code'=>200,'data'=>['records'=>[]]])]);
        try {
            app(WarehouseSkuMappingManager::class)->confirm($w->id,$map->id,$actor->id,
                'CONFIRM_SKU_EQUIVALENCE:VP757A=VP757B',
                'Physical product model and barcode cross-check evidence provided.');
            $this->fail('Missing OMS SKU must fail');
        } catch (\RuntimeException $e) {
            $this->assertSame('OMS_SKU_NOT_APPROVED_OR_MISSING',$e->getMessage());
        }
        $this->assertSame('draft',$map->fresh()->status);
    }

    public function test_disabling_active_map_blocks_sending_instead_of_silent_identity_fallback(): void
    {
        [$w,$actor]=$this->setupData();
        $map=WarehouseSkuMapping::query()->create([
            'warehouse_id'=>$w->id,'provider'=>'xlwms','erp_sku'=>'VP757A',
            'erp_sku_norm'=>'VP757A','oms_sku'=>'VP757B','status'=>'active',
            'confirmed_by'=>$actor->id,'confirmed_at'=>now(),
            'evidence_note'=>'Physical item and barcode proven by warehouse records.',
        ]);
        app(WarehouseSkuMappingManager::class)->disable($w->id,$map->id,$actor->id);
        $result=app(WarehouseSkuResolver::class)->resolve($w->id,['VP757A']);
        $this->assertSame('VP757A',$result['resolved']['VP757A']);
        $this->assertContains('sku_mapping_pending_confirmation:VP757A',$result['blockers']);
    }

    public function test_non_admin_cannot_read_or_write_warehouse_maps(): void
    {
        [$w]=$this->setupData();
        $user=User::factory()->create(['is_active'=>true]);
        $this->actingAs($user)->getJson('/settings/warehouses/us-3sm/openapi/xlwms/sku-mappings')
            ->assertForbidden();
        $this->actingAs($user)->postJson('/settings/warehouses/us-3sm/openapi/xlwms/sku-mappings',[
            'erp_sku'=>'VP757A','oms_sku'=>'VP757B','note'=>'Candidate mapping pending confirmation',
        ])->assertForbidden();
        $this->assertDatabaseCount('warehouse_sku_mappings',0);
    }
}

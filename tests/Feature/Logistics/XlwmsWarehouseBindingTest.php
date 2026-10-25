<?php

namespace Tests\Feature\Logistics;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseOpenApiConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class XlwmsWarehouseBindingTest extends TestCase
{
    use RefreshDatabase;

    private function prepare(): User
    {
        $warehouse=Warehouse::query()->firstOrCreate(['code'=>'US-SM'], [
            'slug'=>'us-3sm','name'=>'世迈仓','type'=>'warehouse','is_active'=>true,
        ]);
        WarehouseOpenApiConnection::query()->create([
            'warehouse_id'=>$warehouse->id,'provider'=>'xlwms',
            'app_key_encrypted'=>'TEST_KEY_123456',
            'app_secret_encrypted'=>'TEST_SECRET_12345',
            'oms_warehouse_code'=>null,
        ]);
        $role=Role::query()->firstOrCreate(['key'=>'xlwms_binding_administrator'], ['label'=>'Testing']);
        $p=Permission::query()->firstOrCreate(['key'=>'system.permissions.manage'],['label'=>'Testing']);
        $role->permissions()->syncWithoutDetaching([$p->id]);
        $u=User::factory()->create(['is_active'=>true]);
        $u->roles()->attach($role);
        return $u;
    }

    public function test_admin_can_list_and_bind_only_oms_authorized_warehouse_code(): void
    {
        $admin=$this->prepare();
        Http::fake(['https://api.xlwms.com/*'=>Http::response([
            'code'=>200,'data'=>[
                ['whCode'=>'OMS-CHOSEN','whNameCn'=>'世迈美国仓','countryCode'=>'US'],
                ['whCode'=>'OMS-OTHER','whNameCn'=>'另外仓库','countryCode'=>'DE'],
            ]
        ])]);
        $this->actingAs($admin)->getJson('/settings/warehouses/us-3sm/openapi/xlwms/warehouses')
            ->assertOk()->assertJsonPath('warehouses.0.code','OMS-CHOSEN');
        $this->actingAs($admin)->postJson('/settings/warehouses/us-3sm/openapi/xlwms/bind',[
            'wh_code'=>'OMS-NONEXISTENT'
        ])->assertStatus(422);
        $this->assertNull(WarehouseOpenApiConnection::query()->sole()->oms_warehouse_code);

        $this->actingAs($admin)->postJson('/settings/warehouses/us-3sm/openapi/xlwms/bind',[
            'wh_code'=>'OMS-CHOSEN'
        ])->assertOk()->assertJsonPath('wh_code','OMS-CHOSEN');
        $this->assertSame('OMS-CHOSEN',WarehouseOpenApiConnection::query()->sole()->oms_warehouse_code);
        Http::assertNotSent(fn($r):bool=>str_contains($r->url(),'outboundOrder/create'));
    }

    public function test_non_admin_cannot_list_or_bind(): void
    {
        $this->prepare();
        Http::fake();
        $actor=User::factory()->create(['is_active'=>true]);
        $this->actingAs($actor)
            ->getJson('/settings/warehouses/us-3sm/openapi/xlwms/warehouses')
            ->assertForbidden();
        $this->actingAs($actor)
            ->postJson('/settings/warehouses/us-3sm/openapi/xlwms/bind',['wh_code'=>'OMS-CHOSEN'])
            ->assertForbidden();
        Http::assertNothingSent();
    }
}

<?php

namespace Tests\Feature\Logistics;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseOpenApiConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class XlwmsWarehouseCredentialSaveTest extends TestCase
{
    use RefreshDatabase;

    private function actor(bool $admin): User
    {
        $role=Role::firstOrCreate(['key' => $admin?'xlwms-admin-save':'xlwms-operator-save'], ['label' => 'Test']);
        if ($admin) {
            $perm=Permission::firstOrCreate(['key'=>'system.permissions.manage'], ['label'=>'Manage']);
            $role->permissions()->syncWithoutDetaching([$perm->id]);
        }
        $user=User::factory()->create(['is_active'=>true]);
        $user->roles()->attach($role);
        return $user;
    }

    private function warehouse(): Warehouse
    {
        return Warehouse::query()->firstOrCreate(['code'=>'US-SM'], [
            'slug'=>'us-sm','name'=>'世迈仓','is_active'=>true,'type'=>'oversea'
        ]);
    }

    private function input(): array
    {
        return ['app_key'=>'test-key-987654','app_secret'=>'test-secret-abcdef'];
    }

    public function test_provider_credentials_cannot_be_written_to_a_different_warehouse(): void
    {
        Warehouse::query()->firstOrCreate(['code'=>'DE'], ['slug'=>'de','name'=>'德国仓','is_active'=>true,'type'=>'oversea']);
        config()->set('xlwms.manual_probe_enabled', true);
        Http::fake();
        $this->actingAs($this->actor(true))
            ->postJson('/settings/warehouses/de/openapi/xlwms', $this->input())
            ->assertNotFound();
        $this->assertDatabaseCount('warehouse_openapi_connections', 0);
        Http::assertNothingSent();
    }

    public function test_credentials_save_requires_administrator(): void
    {
        $this->warehouse();
        config()->set('xlwms.manual_probe_enabled', true);
        Http::fake();
        $this->actingAs($this->actor(false))
            ->postJson('/settings/warehouses/us-sm/openapi/xlwms', $this->input())
            ->assertForbidden();
        $this->assertDatabaseCount('warehouse_openapi_connections', 0);
        Http::assertNothingSent();
    }

    public function test_fail_closed_switch_does_not_store_or_call_vendor(): void
    {
        $this->warehouse();
        config()->set('xlwms.manual_probe_enabled', false);
        Http::fake();
        $this->actingAs($this->actor(true))
            ->postJson('/settings/warehouses/us-sm/openapi/xlwms', $this->input())
            ->assertForbidden()->assertJsonPath('status', 'disabled');
        Http::assertNothingSent();
        $this->assertDatabaseCount('warehouse_openapi_connections', 0);
    }

    public function test_successful_live_probe_encrypts_keys_and_never_infers_warehouse_binding(): void
    {
        $warehouse=$this->warehouse();
        config()->set('xlwms.manual_probe_enabled', true);
        Http::fake(['https://api.xlwms.com/*' => Http::response([
            'code'=>200,'data'=>[['warehouseCode'=>'REMOTE-UNCONFIRMED']]
        ])]);
        $this->actingAs($this->actor(true))
            ->postJson('/settings/warehouses/us-sm/openapi/xlwms', $this->input())
            ->assertOk()->assertJsonPath('status', 'saved_verified')
            ->assertJsonPath('remote_warehouse_bound', false)
            ->assertDontSee('test-secret-abcdef')->assertDontSee('test-key-987654');

        $entry=WarehouseOpenApiConnection::query()->sole();
        $this->assertSame($warehouse->id, $entry->warehouse_id);
        $this->assertSame('test-key-987654', $entry->app_key_encrypted);
        $this->assertSame('test-secret-abcdef', $entry->app_secret_encrypted);
        $this->assertNull($entry->oms_warehouse_code);
        $this->assertNotNull($entry->last_verified_at);
        $raw=DB::table('warehouse_openapi_connections')->first();
        $this->assertStringNotContainsString('test-secret-abcdef', $raw->app_secret_encrypted);
        $this->assertStringNotContainsString('test-key-987654', $raw->app_key_encrypted);
        $this->assertStringNotContainsString('test-secret-abcdef', json_encode(session()->all()));
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($req):bool=>str_contains($req->url(),'outboundOrder/create'));
    }

    public function test_rejected_credentials_do_not_save_anything(): void
    {
        $this->warehouse();
        config()->set('xlwms.manual_probe_enabled', true);
        Http::fake(['https://api.xlwms.com/*'=>Http::response(['code'=>40101,'message'=>'Bad signature'])]);
        $this->actingAs($this->actor(true))
            ->postJson('/settings/warehouses/us-sm/openapi/xlwms', $this->input())
            ->assertStatus(422)->assertJsonPath('status', 'business_rejected');
        $this->assertDatabaseCount('warehouse_openapi_connections',0);
    }
}

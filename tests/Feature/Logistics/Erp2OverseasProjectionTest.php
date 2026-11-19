<?php

namespace Tests\Feature\Logistics;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsDispatchAttempt;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class Erp2OverseasProjectionTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/erp2/v1/logistics/overseas';

    protected function setUp(): void
    {
        parent::setUp();
        config(['erp2_readonly.enabled' => true]);
    }

    public function test_overseas_projection_is_shipment_root_and_canonicalizes_warehouse_aliases(): void
    {
        $user = $this->userWith('logistics.view');
        $sm = $this->warehouse('US-SM');
        $root = $this->order('SYNTHETIC-SPLIT-ROOT');
        $expected = [];
        foreach (['A', 'B', 'C'] as $suffix) {
            $child = $this->order('SYNTHETIC-SPLIT-'.$suffix, $root, $suffix);
            $child->shipment()->create(['warehouse_id' => $sm->id, 'shipment_status' => 'pending']);
            $child->items()->create([
                'product_summary' => 'Synthetic '.$suffix, 'sku' => 'SOURCE-'.$suffix,
                'source_sku' => 'ORIGINAL-'.$suffix, 'resolved_fulfillment_sku' => 'RESOLVED-'.$suffix,
                'final_sku' => null, 'quantity' => 1, 'unit_price' => '0.00', 'line_revenue' => '0.00',
            ]);
            $expected[$suffix] = $child;
        }

        $before = [Order::query()->count(), Shipment::query()->count(), DB::table('oversea_warehouse_reviews')->count()];
        foreach (['us-3sm', 'us-sm', 'us-sj', 'usa-sj', 'US-SM'] as $alias) {
            $this->actingAs($user)->getJson(self::URL.'?warehouse='.$alias.'&view=review&per_page=20')
                ->assertOk()->assertHeader('Content-Type', 'application/json')->assertHeader('Cache-Control', 'no-store, private')
                ->assertJsonPath('meta.total', 3)
                ->assertJsonPath('data.0.fulfillment_suffix', 'C')
                ->assertJsonPath('data.0.items.0.sku', 'SOURCE-C')
                ->assertJsonPath('data.0.items.0.original_sku', 'SOURCE-C')
                ->assertJsonPath('data.0.items.0.source_sku', 'ORIGINAL-C')
                ->assertJsonPath('data.0.items.0.resolved_sku', 'RESOLVED-C')
                ->assertJsonPath('data.0.items.0.final_sku', null);
        }
        $after = [Order::query()->count(), Shipment::query()->count(), DB::table('oversea_warehouse_reviews')->count()];
        self::assertSame($before, $after);
        self::assertNotSame((int) $root->id, (int) $expected['A']->id);
    }

    public function test_overseas_list_excludes_cancelled_archived_and_historical_and_does_not_leak_into_other_queues(): void
    {
        $user = $this->userWith('logistics.view');
        $ny = $this->warehouse('US-NY');
        $visible = $this->order('SYNTHETIC-OVERSEA-VISIBLE');
        $visible->shipment()->create(['warehouse_id' => $ny->id, 'shipment_status' => 'pending']);
        $cancelled = $this->order('SYNTHETIC-OVERSEA-CANCELLED', null, null, 'cancelled');
        $cancelled->shipment()->create(['warehouse_id' => $ny->id, 'shipment_status' => 'pending']);
        $archived = $this->order('SYNTHETIC-OVERSEA-ARCHIVED');
        $archived->update(['operational_archived_at' => now()]);
        $archived->shipment()->create(['warehouse_id' => $ny->id, 'shipment_status' => 'pending']);
        $historical = $this->order('SYNTHETIC-OVERSEA-HISTORICAL');
        $historical->externalReferences()->create(['provider' => 'historical_logistics', 'connection_key' => 'synthetic', 'reference' => 'HIST-1']);
        $historical->shipment()->create(['warehouse_id' => $ny->id, 'shipment_status' => 'pending']);

        $this->actingAs($user)->getJson(self::URL.'?warehouse=us-ny&view=review')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.order_number', 'SYNTHETIC-OVERSEA-VISIBLE');
        $this->getJson('/api/erp2/v1/logistics/pending-allocation?search=SYNTHETIC-OVERSEA-VISIBLE')
            ->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/erp2/v1/logistics/domestic/pending?search=SYNTHETIC-OVERSEA-VISIBLE')
            ->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_projection_preserves_3sm_oms_draft_issued_and_exception_queue_semantics(): void
    {
        $user = $this->userWith('logistics.view');
        $sm = $this->warehouse('US-SM');
        $orders = [];
        foreach ([['DRAFT', '0'], ['ISSUED', '2'], ['CANCELLED', '4']] as [$label, $status]) {
            $order = $this->order('SYNTHETIC-OMS-'.$label);
            $shipment = $order->shipment()->create(['warehouse_id' => $sm->id, 'shipment_status' => 'pending']);
            DB::table('oversea_warehouse_reviews')->insert([
                'order_id' => $order->id, 'submitted_warehouse_id' => $sm->id, 'reviewed_warehouse_id' => $sm->id,
                'status' => 'approved', 'progress' => 'pending_review', 'created_at' => now(), 'updated_at' => now(),
            ]);
            XlwmsDispatchAttempt::query()->create([
                'order_id' => $order->id, 'warehouse_id' => $sm->id, 'third_order_no' => 'SYNTH-'.$label,
                'state' => $status === '0' ? 'oms_draft_created' : 'submitted_oms',
                'request_fingerprint' => str_repeat('a', 64), 'oms_order_no' => 'OMS-'.$label,
                'oms_status_code' => $status, 'oms_status_checked_at' => now(),
            ]);
            $orders[$label] = [$order, $shipment, $status];
        }

        $this->actingAs($user)->getJson(self::URL.'?warehouse=us-sj&view=pending')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.oms.status_code', '0');
        $this->getJson(self::URL.'?warehouse=usa-sj&view=issued')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.oms.status_code', '2');
        $this->getJson(self::URL.'?warehouse=us-sm&view=exceptions')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.oms.status_code', '4');
        self::assertCount(3, $orders);
    }

    public function test_overseas_permission_feature_environment_and_query_gates(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $this->actingAs($this->userWith('kol.view'))->get(self::URL)->assertForbidden();
        $user = $this->userWith('logistics.view');
        config(['erp2_readonly.enabled' => false]);
        $this->actingAs($user)->get(self::URL)->assertNotFound();
        config(['erp2_readonly.enabled' => true]);
        $this->app['env'] = 'production';
        $this->get(self::URL)->assertNotFound();
        $this->app['env'] = 'testing';
        $this->get(self::URL.'?warehouse=invalid')->assertStatus(422);
        $this->get(self::URL.'?view=made-up')->assertStatus(422);
        $this->get(self::URL.'?sort=orders.secret')->assertStatus(422);
        $this->get(self::URL.'?page=0')->assertStatus(422);
        $this->get(self::URL.'?per_page=10')->assertStatus(422);
        $this->get(self::URL.'?unexpected=1')->assertStatus(422);
        $this->post(self::URL)->assertStatus(405);
        $this->head(self::URL)->assertOk();
    }

    private function warehouse(string $code): Warehouse
    {
        $slug = strtolower(str_replace('-', '', $code));
        return Warehouse::query()->updateOrCreate(['code' => $code], [
            'slug' => $slug, 'name' => 'Synthetic '.$code, 'type' => 'warehouse', 'is_active' => true, 'priority' => 1,
        ]);
    }

    private function order(string $number, ?Order $parent = null, ?string $suffix = null, string $status = 'pending_shipment'): Order
    {
        return Order::query()->create([
            'internal_order_number' => 'INT-'.$number, 'erp_order_id' => $number,
            'source_type' => 'manual', 'order_kind' => $parent ? 'fulfillment_derived' : 'customer',
            'external_order_number' => $number, 'order_date' => '2026-11-12',
            'order_status' => $status, 'currency' => 'USD',
            'fulfillment_parent_id' => $parent?->id, 'fulfillment_suffix' => $suffix,
        ]);
    }

    private function userWith(string $permission): User
    {
        $role = Role::query()->create(['key' => 'erp2-oversea-'.uniqid(), 'label' => 'ERP2 overseas']);
        $model = Permission::query()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        $role->permissions()->attach($model);
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }
}

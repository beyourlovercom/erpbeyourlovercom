<?php

namespace Tests\Feature\Logistics;

use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Application\ShipOut\ShipOutProductionBatchService;
use App\Modules\Logistics\Application\ShipOut\ShipOutProductionCanaryReconcileService;
use App\Modules\Logistics\Application\ShipOut\ShipOutProductionOutboundPacer;
use App\Modules\Logistics\Experience\Jobs\ProcessShipOutProductionBatch;
use App\Modules\Logistics\Infrastructure\Models\OverseaWarehouseReview;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class ShipOutProductionBatchTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::query()->firstOrCreate(['key' => 'super_admin'], ['label' => 'Super Admin']);
        $user->roles()->syncWithoutDetaching([$role->id]);
        config()->set('shipout.production_v1_operator_user_ids', $user->id.',999001,999002');
        config()->set('shipout.production_v1_min_order_id', 1);

        return $user;
    }

    private function approvedOrder(User $user): Order
    {
        $warehouse = Warehouse::query()->firstOrCreate(['code' => 'US-LA'], [
            'slug' => 'us-la', 'name' => 'MorePlus',
            'type' => 'warehouse', 'is_active' => true, 'priority' => 10,
        ]);
        $order = Order::query()->create([
            'internal_order_number' => 'BATCH-DEV-1',
            'erp_order_id' => '007-1109-c42141',
            'source_type' => 'manual_other',
            'order_kind' => 'customer',
            'external_order_number' => 'BATCH-ORIGINAL-1',
            'order_date' => '2026-11-09',
            'order_status' => 'pending_shipment',
        ]);
        $order->address()->create([
            'recipient_name' => 'Dev test', 'country' => 'US',
            'province_state' => 'CA', 'city' => 'Test',
            'address_line_1' => '100 Test Street', 'postal_code' => '90210',
        ]);
        $order->shipment()->create([
            'warehouse_id' => $warehouse->id,
            'shipment_status' => 'pending',
            'warehouse_selection_source' => 'manual',
            'carrier' => 'USPS',
        ]);
        OverseaWarehouseReview::query()->create([
            'order_id' => $order->id,
            'submitted_warehouse_id' => $warehouse->id,
            'reviewed_warehouse_id' => $warehouse->id,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'status' => 'approved',
        ]);

        return $order;
    }

    public function test_dev_batch_preview_start_and_status_never_contact_provider(): void
    {
        Http::preventStrayRequests();
        $user = $this->operator();
        $order = $this->approvedOrder($user);
        $this->actingAs($user);

        $preview = $this->getJson('/logistics/oversea/us-moreplus/shipout/batch/preview')
            ->assertOk()->assertJsonPath('count', 1)->assertJsonPath('dry_run', true)
            ->json();

        $start = $this->postJson('/logistics/oversea/us-moreplus/shipout/batch/start', [
            'fingerprint' => $preview['fingerprint'], 'confirmation' => 'CONFIRM_MOREPLUS_BATCH_1',
        ])->assertStatus(202)->assertJsonPath('dry_run', true);

        $batch = $start->json('batch_id');
        $this->getJson("/logistics/oversea/us-moreplus/shipout/batch/{$batch}/status")
            ->assertOk()->assertJsonPath('status', 'completed')
            ->assertJsonPath('items.0.status', 'simulated')
            ->assertJsonPath('items.0.order_id', $order->id);
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_active_batch_blocks_second_start_without_queueing(): void
    {
        $user = $this->operator();
        $this->approvedOrder($user);
        $preview = app(ShipOutProductionBatchService::class)->preview();

        DB::table('shipout_production_batches')->insert([
            'id' => '00000000-0000-4000-8000-000000000011',
            'operator_user_id' => $user->id, 'status' => 'running',
            'dry_run' => true, 'total' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($user);
        $this->postJson('/logistics/oversea/us-moreplus/shipout/batch/start', [
            'fingerprint' => $preview['fingerprint'],
            'confirmation' => 'CONFIRM_MOREPLUS_BATCH_1',
        ])->assertStatus(409)->assertJsonPath('error_code', 'SHIPOUT_BATCH_ALREADY_RUNNING');
        $this->assertDatabaseCount('shipout_production_batches', 1);
    }

    public function test_stale_fingerprint_must_not_create_a_batch(): void
    {
        $user = $this->operator();
        $this->approvedOrder($user);
        $this->actingAs($user);
        $this->postJson('/logistics/oversea/us-moreplus/shipout/batch/start', [
            'fingerprint' => str_repeat('a', 64),
            'confirmation' => 'CONFIRM_MOREPLUS_BATCH_1',
        ])->assertStatus(409)->assertJsonPath('error_code', 'SHIPOUT_BATCH_STALE');
        $this->assertDatabaseCount('shipout_production_batches', 0);
    }

    public function test_production_batch_has_no_candidate_without_exact_canary_id(): void
    {
        $user = $this->operator();
        $order = $this->approvedOrder($user);
        $old = $this->app['env'];
        $this->app->instance('env', 'production');
        try {
            config()->set('shipout.production_v1_batch_canary_order_id', 0);
            $this->assertSame([], app(ShipOutProductionBatchService::class)->candidateIds());
            config()->set('shipout.production_v1_batch_canary_order_id', $order->id + 1);
            $this->assertSame([], app(ShipOutProductionBatchService::class)->candidateIds());
            config()->set('shipout.production_v1_batch_canary_order_id', $order->id);
            $this->assertSame([$order->id], app(ShipOutProductionBatchService::class)->candidateIds());
        } finally {
            $this->app->instance('env', $old);
        }
    }

    public function test_production_batch_queue_is_separate_from_standard_orders(): void
    {
        $this->assertSame('shipout-production-batch',
            ProcessShipOutProductionBatch::QUEUE);
    }

    public function test_production_throttle_prefixes_are_independent(): void
    {
        $routes = app('router')->getRoutes();
        $get = fn (string $name) => implode('|', $routes->getByName($name)->gatherMiddleware());
        $this->assertStringContainsString('shipout-prod-preflight:', $get('logistics.shipout.production.preflight'));
        $this->assertStringContainsString('shipout-prod-send:', $get('logistics.shipout.production.send'));
        $this->assertStringContainsString('shipout-prod-status:', $get('logistics.shipout.production.status'));
        $this->assertStringContainsString('shipout-batch-start:', $get('logistics.shipout.production.batch.start'));
    }

    public function test_global_submission_pace_fails_before_second_post(): void
    {
        app(ShipOutProductionOutboundPacer::class)->claim();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SHIPOUT_V1_SUBMISSION_RATE_LIMITED');
        app(ShipOutProductionOutboundPacer::class)->claim();
    }

    public function test_no_attempt_can_read_real_oms_without_submission(): void
    {
        Http::preventStrayRequests();
        $user = $this->operator();
        $order = $this->approvedOrder($user);
        $old = $this->app['env'];
        $this->app->instance('env', 'production');
        try {
            config()->set([
                'shipout.production_read_enabled' => true,
                'shipout.production_warehouse_id' => '1265864414750314497',
                'shipout.production_client_org_id' => '57893',
                'shipout.production_app_key' => 'test-key',
                'shipout.production_app_secret' => 'test-secret',
                'shipout.production_bearer' => 'test-token',
            ]);
            Http::fake(['open.shipout.com/*' => Http::response([
                'result' => 'OK', 'data' => ['total' => 0, 'records' => []],
            ], 200)]);
            $result = app(ShipOutProductionCanaryReconcileService::class)->inspect($order->id);
            $this->assertSame('NO_ERP_ATTEMPT_AND_NO_OMS_ORDER', $result['reconciliation']);
            $this->assertSame('never_submitted', $result['attempt_status']);
            Http::assertSentCount(1);
            Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
        } finally {
            $this->app->instance('env', $old);
        }
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
    }
}

<?php

namespace Tests\Feature\Logistics;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Application\ShipOut\ShipOutProductionBatchService;
use App\Modules\Logistics\Application\ShipOut\ShipOutProductionCanaryDispatchService;
use App\Modules\Logistics\Application\ShipOut\ShipOutProductionCanaryReconcileService;
use App\Modules\Logistics\Application\ShipOut\ShipOutProductionDispatchState;
use App\Modules\Logistics\Application\ShipOut\ShipOutProductionOutboundPacer;
use App\Modules\Logistics\Application\ShipOut\ShipOutProductionV1OperatorAccess;
use App\Modules\Logistics\Experience\Jobs\ProcessShipOutProductionBatch;
use App\Modules\Logistics\Infrastructure\Models\OverseaWarehouseReview;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
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

    private function approvedOrder(User $user, string $suffix = '1'): Order
    {
        $warehouse = Warehouse::query()->firstOrCreate(['code' => 'US-LA'], [
            'slug' => 'us-la', 'name' => 'MorePlus',
            'type' => 'warehouse', 'is_active' => true, 'priority' => 10,
        ]);
        $order = Order::query()->create([
            'internal_order_number' => 'BATCH-DEV-'.$suffix,
            'erp_order_id' => '007-1109-c4214'.$suffix,
            'source_type' => 'manual_other',
            'order_kind' => 'customer',
            'external_order_number' => 'BATCH-ORIGINAL-'.$suffix,
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

    public function test_production_administrator_can_read_paused_button_and_prior_batch_but_cannot_submit(): void
    {
        $role = Role::query()->firstOrCreate(
            ['key' => 'role_preset_administrator'], ['label' => '管理员']
        );
        foreach (['logistics.view', 'logistics.operate'] as $key) {
            $permission = Permission::query()->firstOrCreate(
                ['key' => $key], ['label' => $key]
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $admin = User::factory()->create(['is_active' => true, 'permission_mode' => 'role']);
        $admin->roles()->attach($role);
        $order = $this->approvedOrder($admin, '8');
        config()->set([
            'shipout.production_read_enabled' => true,
            'shipout.production_v1_batch_enabled' => false,
            'shipout.production_v1_batch_owner_approved' => false,
            'shipout.production_v1_dispatch_enabled' => true,
            'shipout.production_v1_owner_approved' => true,
            'shipout.production_v1_operator_user_ids' => $admin->id.',999001,999002',
            'shipout.production_v1_min_order_id' => 1,
            'shipout.production_v1_batch_canary_order_id' => $order->id,
        ]);
        DB::table('shipout_production_batches')->insert([
            'id' => '00000000-0000-4000-8000-000000000190',
            'operator_user_id' => $admin->id,
            'status' => 'completed_partial', 'dry_run' => false, 'total' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $old = $this->app['env'];
        $this->app->instance('env', 'production');
        try {
            Http::preventStrayRequests();
            // GitHub backend jobs do not build the Production Vite manifest.
            $this->withoutVite();
            $this->withoutMiddleware(PreventRequestForgery::class);
            $this->actingAs($admin);
            $this->get('/logistics/oversea/us-moreplus?view=pending')
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('shipoutProductionBatchUi', true)
                    ->where('shipoutProductionBatchPaused', true)
                    ->where('canOperate', true)
                    ->where('canReview', true)
                );

            $preview = $this->getJson('/logistics/oversea/us-moreplus/shipout/batch/preview')
                ->assertOk()->assertJsonPath('count', 1)->json();
            $this->getJson('/logistics/oversea/us-moreplus/shipout/batch/00000000-0000-4000-8000-000000000190/status')
                ->assertOk()->assertJsonPath('status', 'completed_partial');
            $this->postJson('/logistics/oversea/us-moreplus/shipout/batch/start', [
                'fingerprint' => $preview['fingerprint'], 'confirmation' => 'CONFIRM_MOREPLUS_BATCH_1',
            ])->assertForbidden();

            $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
            $this->assertDatabaseCount('shipout_production_batches', 1);
            Http::assertNothingSent();
        } finally {
            $this->app->instance('env', $old);
        }
    }

    public function test_selected_only_submits_explicit_ids_and_skips_other_approved_rows(): void
    {
        Http::preventStrayRequests();
        $user = $this->operator();
        $first = $this->approvedOrder($user, '1');
        $other = $this->approvedOrder($user, '2');
        $this->actingAs($user);

        $selected = $this->getJson('/logistics/oversea/us-moreplus/shipout/batch/preview?scope=selected&order_ids='.$first->id)
            ->assertOk()->assertJsonPath('scope', 'selected')
            ->assertJsonPath('scan_count', 1)->assertJsonPath('count', 1)
            ->assertJsonPath('excluded_count', 0)->json();
        $reply = $this->postJson('/logistics/oversea/us-moreplus/shipout/batch/start', [
            'fingerprint' => $selected['fingerprint'],
            'confirmation' => 'CONFIRM_MOREPLUS_BATCH_1',
            'scope' => 'selected',
            'order_ids' => (string) $first->id,
        ])->assertStatus(202)->assertJsonPath('total', 1);
        $this->getJson('/logistics/oversea/us-moreplus/shipout/batch/'.$reply->json('batch_id').'/status')
            ->assertOk()->assertJsonPath('items.0.order_id', $first->id)->assertJsonCount(1, 'items');
        $this->assertDatabaseMissing('shipout_production_batch_items', ['order_id' => $other->id]);
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_all_scope_scans_stockout_and_unreviewed_rows_but_only_queues_safe_candidates(): void
    {
        Http::preventStrayRequests();
        $user = $this->operator();
        $ready = $this->approvedOrder($user, '1');
        $stockout = $this->approvedOrder($user, '2');
        $stockout->operationalMarkers()->create([
            'marker' => 'stockout', 'marked_at' => now(),
        ]);
        // Even if review is approved, a genuine stockout marker must not
        // be submitted under the operator's 'all pending' confirmation.
        $stockout->forceFill(['workflow_status' => 'out_of_stock'])->save();
        $this->actingAs($user);
        $preview = $this->getJson('/logistics/oversea/us-moreplus/shipout/batch/preview?scope=all')
            ->assertOk()->assertJsonPath('scope', 'all')
            ->assertJsonPath('scan_count', 2)->assertJsonPath('count', 1)
            ->assertJsonPath('excluded_count', 1)->json();

        $reply = $this->postJson('/logistics/oversea/us-moreplus/shipout/batch/start', [
            'fingerprint' => $preview['fingerprint'],
            'confirmation' => 'CONFIRM_MOREPLUS_BATCH_1',
            'scope' => 'all',
            'order_ids' => '',
        ])->assertStatus(202)->assertJsonPath('total', 1);

        $this->assertDatabaseHas('shipout_production_batch_items', [
            'batch_id' => $reply->json('batch_id'), 'order_id' => $ready->id,
        ]);
        $this->assertDatabaseMissing('shipout_production_batch_items', [
            'batch_id' => $reply->json('batch_id'), 'order_id' => $stockout->id,
        ]);
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_all_scope_fingerprint_expires_when_another_stockout_is_added(): void
    {
        Http::preventStrayRequests();
        $user = $this->operator();
        $this->approvedOrder($user, '1');
        $this->actingAs($user);
        $before = $this->getJson('/logistics/oversea/us-moreplus/shipout/batch/preview?scope=all')
            ->assertOk()->assertJsonPath('count', 1)->assertJsonPath('scan_count', 1)->json();
        $outOfStock = $this->approvedOrder($user, '2');
        $outOfStock->forceFill(['workflow_status' => 'out_of_stock'])->save();
        $after = $this->getJson('/logistics/oversea/us-moreplus/shipout/batch/preview?scope=all')
            ->assertOk()->assertJsonPath('count', 1)->assertJsonPath('scan_count', 2)->json();
        $this->assertNotSame($before['fingerprint'], $after['fingerprint']);
        $this->postJson('/logistics/oversea/us-moreplus/shipout/batch/start', [
            'fingerprint' => $before['fingerprint'],
            'confirmation' => 'CONFIRM_MOREPLUS_BATCH_1',
            'scope' => 'all',
            'order_ids' => '',
        ])->assertStatus(409)->assertJsonPath('error_code', 'SHIPOUT_BATCH_STALE');
        $this->assertDatabaseCount('shipout_production_batches', 0);
        Http::assertNothingSent();
    }

    public function test_invalid_selected_order_ids_and_stale_selection_never_create_or_post(): void
    {
        Http::preventStrayRequests();
        $user = $this->operator();
        $first = $this->approvedOrder($user, '1');
        $other = $this->approvedOrder($user, '2');
        $this->actingAs($user);
        $this->getJson('/logistics/oversea/us-moreplus/shipout/batch/preview?scope=selected&order_ids='.$first->id.','.$first->id)
            ->assertStatus(422)->assertJsonPath('error_code', 'SHIPOUT_BATCH_SELECTION_INVALID');
        $this->getJson('/logistics/oversea/us-moreplus/shipout/batch/preview?scope=all&order_ids='.$first->id)
            ->assertStatus(422)->assertJsonPath('error_code', 'SHIPOUT_BATCH_SELECTION_INVALID');

        $firstPreview = $this->getJson('/logistics/oversea/us-moreplus/shipout/batch/preview?scope=selected&order_ids='.$first->id)
            ->assertOk()->json();
        $this->postJson('/logistics/oversea/us-moreplus/shipout/batch/start', [
            'fingerprint' => $firstPreview['fingerprint'],
            'confirmation' => 'CONFIRM_MOREPLUS_BATCH_1',
            'scope' => 'selected',
            'order_ids' => (string) $other->id,
        ])->assertStatus(409)->assertJsonPath('error_code', 'SHIPOUT_BATCH_STALE');
        $this->assertDatabaseCount('shipout_production_batches', 0);
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
        Http::assertNothingSent();
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

    public function test_paused_batch_prevents_new_batch_from_reclaiming_queued_orders(): void
    {
        $user = $this->operator();
        $this->approvedOrder($user);
        $preview = app(ShipOutProductionBatchService::class)->preview();
        DB::table('shipout_production_batches')->insert([
            'id' => '00000000-0000-4000-8000-000000000111',
            'operator_user_id' => $user->id, 'status' => 'paused',
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

    public function test_worker_crash_queues_next_different_order_only_when_exact_gates_remain_on(): void
    {
        $user = $this->operator();
        $bad = $this->approvedOrder($user, '1');
        $good = $this->approvedOrder($user, '2');
        $bid = '00000000-0000-4000-8000-000000000123';
        DB::table('shipout_production_batches')->insert([
            'id' => $bid, 'operator_user_id' => $user->id,
            'status' => 'running', 'dry_run' => false, 'total' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('shipout_production_batch_items')->insert([
            ['batch_id' => $bid, 'order_id' => $bad->id, 'order_no' => $bad->erp_order_id,
                'status' => 'processing', 'created_at' => now(), 'updated_at' => now()],
            ['batch_id' => $bid, 'order_id' => $good->id, 'order_no' => $good->erp_order_id,
                'status' => 'queued', 'created_at' => now(), 'updated_at' => now()],
        ]);
        config()->set([
            'queue.default' => 'redis',
            'shipout.production_v1_batch_enabled' => true,
            'shipout.production_v1_batch_owner_approved' => true,
        ]);
        Queue::fake();
        (new ProcessShipOutProductionBatch($bid))->failed(new RuntimeException('SYNTHETIC_WORKER_FAILED'));
        $this->assertDatabaseHas('shipout_production_batch_items', [
            'batch_id' => $bid, 'order_id' => $bad->id, 'status' => 'uncertain',
        ]);
        $this->assertDatabaseHas('shipout_production_batch_items', [
            'batch_id' => $bid, 'order_id' => $good->id, 'status' => 'queued',
        ]);
        $this->assertDatabaseHas('shipout_production_batches', [
            'id' => $bid, 'status' => 'running',
        ]);
        Queue::assertPushed(ProcessShipOutProductionBatch::class, 1);
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
        Http::preventStrayRequests();
        Http::assertNothingSent();
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

    public function test_exact_multi_order_allowlist_continues_after_quarantine_without_resubmission(): void
    {
        $user = $this->operator();
        $bad = $this->approvedOrder($user, '1');
        $good = $this->approvedOrder($user, '2');
        $originalEnv = $this->app['env'];
        $this->app->instance('env', 'production');
        try {
            config()->set([
                'shipout.production_v1_batch_canary_order_id' => 0,
                'shipout.production_v1_batch_approved_order_ids' => $bad->id.','.$good->id,
            ]);
            $this->assertSame([$bad->id, $good->id], app(ShipOutProductionBatchService::class)->candidateIds());

            DB::table('shipout_production_batches')->insert([
                'id' => '00000000-0000-4000-8000-000000000121',
                'operator_user_id' => $user->id, 'status' => 'completed_partial',
                'dry_run' => false, 'total' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('shipout_production_batch_items')->insert([
                'batch_id' => '00000000-0000-4000-8000-000000000121',
                'order_id' => $bad->id, 'order_no' => $bad->erp_order_id,
                'status' => 'provider_cancelled', 'result_code' => 'PROVIDER_DUPLICATE_ORDER',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->assertSame([$good->id], app(ShipOutProductionBatchService::class)->candidateIds());
            $this->assertSame([$good->id], ShipOutProductionBatchService::productionApprovedOrderIds() === []
                ? [] : app(ShipOutProductionBatchService::class)->candidateIds());

            DB::table('shipout_dispatch_attempts')->insert([
                'order_id' => $good->id, 'order_no' => 'C42142-007',
                'provider_warehouse_id' => ShipOutProductionDispatchState::MOREPLUS_PROVIDER_WAREHOUSE_ID,
                'payload_hash' => str_repeat('a', 64), 'status' => 'acknowledged',
                'provider_order_id' => 'OMS-EXISTING-ACK', 'result_code' => 'ASYNC_ACCEPTED_QUERY_REQUIRED',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->assertSame([], app(ShipOutProductionBatchService::class)->candidateIds());
            config()->set('shipout.production_v1_batch_approved_order_ids', '1,*');
            $this->assertSame([], ShipOutProductionBatchService::productionApprovedOrderIds());
            config()->set('shipout.production_v1_batch_approved_order_ids', '');
            $this->assertSame([], ShipOutProductionBatchService::productionApprovedOrderIds());
            config()->set('shipout.production_v1_batch_canary_order_id', $good->id);
            $this->assertSame([$good->id], ShipOutProductionBatchService::productionApprovedOrderIds());
            $this->assertSame([], app(ShipOutProductionBatchService::class)->candidateIds());
        } finally {
            $this->app->instance('env', $originalEnv);
        }
    }

    public function test_oms_quarantine_label_overrides_earlier_api_ack(): void
    {
        $ack = (object) [
            'provider_warehouse_id' => ShipOutProductionDispatchState::MOREPLUS_PROVIDER_WAREHOUSE_ID,
            'status' => 'acknowledged', 'provider_order_id' => 'OMS-ONCE',
        ];
        $failed = (object) [
            'status' => 'provider_cancelled', 'result_code' => 'PROVIDER_DUPLICATE_ORDER',
        ];
        $projection = ShipOutProductionDispatchState::from($ack, $failed);
        $this->assertTrue($projection['attempted']);
        $this->assertFalse($projection['is_oms_created']);
        $this->assertSame('oms_quarantined', $projection['status']);
        $this->assertStringContainsString('重复订单', $projection['label']);
        $this->assertStringContainsString('禁止自动重发', $projection['label']);
        $this->assertSame('PROVIDER_DUPLICATE_ORDER',
            ShipOutProductionDispatchState::warehouseDispatch($ack, 'pending', $failed)['failure_code']);
    }

    public function test_failed_worker_isolates_current_order_without_clearing_other_queued_items(): void
    {
        $user = $this->operator();
        $bad = $this->approvedOrder($user, '1');
        $good = $this->approvedOrder($user, '2');
        $bid = '00000000-0000-4000-8000-000000000122';
        DB::table('shipout_production_batches')->insert([
            'id' => $bid, 'operator_user_id' => $user->id,
            'status' => 'running', 'dry_run' => true, 'total' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('shipout_production_batch_items')->insert([
            ['batch_id' => $bid, 'order_id' => $bad->id, 'order_no' => $bad->erp_order_id,
                'status' => 'processing', 'created_at' => now(), 'updated_at' => now()],
            ['batch_id' => $bid, 'order_id' => $good->id, 'order_no' => $good->erp_order_id,
                'status' => 'queued', 'created_at' => now(), 'updated_at' => now()],
        ]);
        // No real provider call; a crashed worker must not retry the first order.
        (new ProcessShipOutProductionBatch($bid))->failed(new RuntimeException('SYNTHETIC_WORKER_CRASH'));
        $this->assertDatabaseHas('shipout_production_batch_items', [
            'batch_id' => $bid, 'order_id' => $bad->id, 'status' => 'uncertain',
        ]);
        $this->assertDatabaseHas('shipout_production_batch_items', [
            'batch_id' => $bid, 'order_id' => $good->id, 'status' => 'queued',
        ]);
        // Explicit manual worker resume in isolated Dev: safe order continues
        // without re-claiming the quarantined first order.
        DB::table('shipout_production_batches')->where('id', $bid)
            ->update(['status' => 'running']);
        Http::preventStrayRequests();
        auth()->login($user);
        (new ProcessShipOutProductionBatch($bid))->handle(
            app(ShipOutProductionCanaryDispatchService::class),
            app(ShipOutProductionV1OperatorAccess::class),
        );
        $this->assertDatabaseHas('shipout_production_batch_items', [
            'batch_id' => $bid, 'order_id' => $bad->id, 'status' => 'uncertain',
        ]);
        $this->assertDatabaseHas('shipout_production_batch_items', [
            'batch_id' => $bid, 'order_id' => $good->id, 'status' => 'simulated',
        ]);
        $this->assertDatabaseHas('shipout_production_batches', [
            'id' => $bid, 'status' => 'completed_partial',
        ]);
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_async_ack_does_not_count_as_a_verified_batch_result(): void
    {
        $user = $this->operator();
        $order = $this->approvedOrder($user);
        $id = '00000000-0000-4000-8000-000000000097';
        DB::table('shipout_production_batches')->insert([
            'id' => $id, 'operator_user_id' => $user->id,
            'status' => 'awaiting_oms', 'dry_run' => false,
            'total' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('shipout_production_batch_items')->insert([
            'batch_id' => $id, 'order_id' => $order->id,
            'order_no' => '007-1109-c42141',
            'status' => 'pending_oms', 'result_code' => 'ASYNC_ACCEPTED_QUERY_REQUIRED',
            'provider_order_id' => 'OMS-ACK-NOT-FINAL',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $result = app(ShipOutProductionBatchService::class)->status($id);
        $this->assertSame(0, $result['processed']);
        $this->assertSame('awaiting_oms', $result['status']);
        $this->assertSame('pending_oms', $result['items'][0]->status);

        DB::table('shipout_production_batch_items')->where('batch_id', $id)
            ->update(['status' => 'provider_cancelled', 'result_code' => 'PROVIDER_DUPLICATE_ORDER']);
        $this->assertSame(1, app(ShipOutProductionBatchService::class)->status($id)['processed']);
    }

    public function test_immutable_post_ack_is_not_a_verified_oms_creation(): void
    {
        $ack = (object) [
            'provider_warehouse_id' => '1265864414750314497',
            'status' => 'acknowledged',
            'provider_order_id' => 'OMS-ACK-ONLY-123',
        ];
        $projection = ShipOutProductionDispatchState::from($ack);
        $this->assertTrue($projection['attempted']);
        $this->assertFalse($projection['is_oms_created']);
        $this->assertSame('oms_ack_pending', $projection['status']);
        $this->assertSame('OMS-ACK-ONLY-123', $projection['provider_order_id']);
        $this->assertStringContainsString('待 OMS 核实', $projection['label']);

        $warehouse = ShipOutProductionDispatchState::warehouseDispatch($ack, 'pending');
        $this->assertSame('oms_ack_pending', $warehouse['status']);
        $this->assertStringNotContainsString('已发货', $warehouse['label']);
        $this->assertFalse($warehouse['can_retry_local_block']);
    }

    public function test_all_batch_terminal_status_codes_fit_mysql_varchar_24(): void
    {
        // Database migration creates shipout_production_batches.status VARCHAR(24).
        // Previous completed_with_exceptions had 25 characters and failed real MySQL.
        foreach (['queued', 'running', 'awaiting_oms', 'paused',
            'completed', 'completed_partial'] as $status) {
            $this->assertLessThanOrEqual(24, strlen($status), $status);
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

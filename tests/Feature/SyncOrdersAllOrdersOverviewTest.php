<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Sync\Application\Contracts\WooCommerceTrackingBatchInspector;
use App\Modules\Sync\Application\Contracts\WooCommerceTrackingGateway;
use App\Modules\Sync\Application\OrderSyncProgressProjector;
use App\Modules\Sync\Application\OrderSyncProgressSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class SyncOrdersAllOrdersOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_orders_are_listed_even_without_manual_sync_and_paginated_instead_of_loaded_all(): void
    {
        $user = $this->viewer();
        for ($i = 1; $i <= 56; $i++) {
            $this->makeOrder('manual', 'ALL-ORDERS-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT));
        }

        $this->actingAs($user)->get('/sync/orders?per_page=20')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Sync/Orders')
                ->has('orders', 20)
                ->where('pagination.total', 56)
                ->where('pagination.current_page', 1)
                ->where('pagination.last_page', 3)
                ->where('pagination.per_page', 20)
                ->where('orders.0.progress.code', 'unsynced')
                ->where('orders.0.can_sync', false)
            );

        $this->actingAs($user)->get('/sync/orders?per_page=20&page=3')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders', 16)
                ->where('pagination.total', 56)
                ->where('pagination.from', 41)
                ->where('pagination.to', 56)
            );
    }

    public function test_every_active_source_is_searchable_while_archived_orders_are_hidden(): void
    {
        $user = $this->viewer();
        $woo = $this->makeOrder('woocommerce', 'WOO-OVERVIEW-1');
        $imported = $this->makeOrder('website', 'IMPORTED-ORIGINAL-132');
        $this->makeOrder('kol', 'KOL-OVERVIEW-1');
        $this->makeOrder('manual_other', 'PURCHASE-OVERVIEW-1');
        $this->makeOrder('manual', 'MANUAL-OVERVIEW-1');
        $imported->forceFill(['operational_archived_at' => now()])->save();

        $this->actingAs($user)->get('/sync/orders')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders', 4)
                ->where('pagination.total', 4)
            );

        $this->actingAs($user)->get('/sync/orders?search=IMPORTED-ORIGINAL-132')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders', 0)
                ->where('pagination.total', 0)
            );

        $this->actingAs($user)->get('/sync/orders?source=woocommerce')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders', 1)
                ->where('orders.0.id', $woo->id)
                ->where('filters.source', 'woocommerce')
            );

        $this->actingAs($user)->get('/sync/orders?source=not_a_source')->assertSessionHasErrors('source');
    }

    public function test_child_of_soft_deleted_parent_is_hidden_then_restored_to_original_woo_identity(): void
    {
        $user = $this->viewer();
        $parent = $this->makeOrder('woocommerce', 'WOO-ROOT-ARCHIVED');
        $child = $this->makeOrder('manual', 'SPLIT-CHILD-B');
        $child->forceFill(['fulfillment_parent_id' => $parent->id, 'fulfillment_suffix' => 'B'])->save();
        $parent->delete();

        $this->actingAs($user)->get('/sync/orders?search=SPLIT-CHILD-B')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders', 0)
                ->where('pagination.total', 0)
            );
        $parent->restore();

        $this->actingAs($user)->get('/sync/orders?search=SPLIT-CHILD-B')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders', 1)
                ->where('pagination.total', 1)
                ->where('orders.0.id', $child->id)
                ->where('orders.0.root_order_id', $parent->id)
                ->where('orders.0.source_type', 'woocommerce')
                ->where('orders.0.fulfillment_suffix', 'B')
                ->where('orders.0.archived', false)
            );
    }

    public function test_archive_soft_delete_operational_archive_and_archived_parent_are_excluded_from_all_sync_modes(): void
    {
        Cache::flush();
        $user = $this->viewer();

        $normal = $this->makeOrder('woocommerce', 'ACTIVE-ONLY-OK');
        $normal->manualSync()->create(['website_sync_status' => 'failed']);

        $legacy = $this->makeOrder('kol', 'ACTIVE-ONLY-LEGACY-ARCHIVED');
        $legacy->forceFill(['archived_at' => now()])->save();
        $legacy->manualSync()->create(['website_sync_status' => 'failed']);

        $operational = $this->makeOrder('website', 'ACTIVE-ONLY-OPERATIONAL-ARCHIVED');
        $operational->forceFill(['operational_archived_at' => now()])->save();
        $operational->manualSync()->create(['website_sync_status' => 'failed']);

        $deleted = $this->makeOrder('manual_other', 'ACTIVE-ONLY-SOFT-DELETED');
        $deleted->manualSync()->create(['website_sync_status' => 'failed']);
        $deleted->delete();

        $root = $this->makeOrder('woocommerce', 'ACTIVE-ONLY-ROOT');
        $child = $this->makeOrder('manual', 'ACTIVE-ONLY-CHILD');
        $child->forceFill(['fulfillment_parent_id' => $root->id, 'fulfillment_suffix' => 'B'])->save();
        $child->manualSync()->create(['website_sync_status' => 'failed']);
        $root->forceFill(['operational_archived_at' => now()])->save();

        $this->actingAs($user)->get('/sync/orders')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pagination.total', 1)
                ->has('orders', 1)
                ->where('orders.0.id', $normal->id)
            );
        $this->actingAs($user)->get('/sync/orders?global_grid=0')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pagination.total', 1)
                ->where('orders.0.id', $normal->id)
            );
        foreach ([$legacy, $operational, $deleted, $root, $child] as $hidden) {
            $this->actingAs($user)->get('/sync/orders?search='.$hidden->erp_order_id)
                ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('pagination.total', 0)->has('orders', 0));
        }

        $snapshot = app(OrderSyncProgressSnapshot::class)->refresh();
        self::assertSame(1, $snapshot['total']);
        self::assertSame(1, $snapshot['candidates']);
        $this->actingAs($user)->get('/sync/orders?grid_progress=failed')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pagination.total', 1)
                ->where('progressFilterSnapshot.total', 1)
                ->where('orders.0.id', $normal->id)
            );

        $this->actingAs($user)->get('/sync/orders?grid_progress=failed&search=ACTIVE-ONLY-SOFT-DELETED')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('pagination.total', 0)->has('orders', 0)
            );
    }

    public function test_ag_grid_default_and_legacy_fallback_keep_complete_order_coverage(): void
    {
        $user = $this->viewer();
        $one = $this->makeOrder('woocommerce', 'AG-GRID-ROOT-101');
        $two = $this->makeOrder('manual_other', 'AG-GRID-OTHER-103');
        $this->makeOrder('kol', 'AG-GRID-KOL-102');
        $one->forceFill(['order_date' => '2026-11-09'])->save();
        $two->forceFill(['order_date' => '2026-11-11'])->save();

        $this->actingAs($user)->get('/sync/orders')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Sync/Orders')
                ->where('globalGridPilotAvailable', true)
                ->where('globalGridPilotEnabled', true)
                ->where('pagination.total', 3)
            );

        $this->actingAs($user)->get('/sync/orders?global_grid=0')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('globalGridPilotAvailable', true)
                ->where('globalGridPilotEnabled', false)
                ->where('pagination.total', 3)
            );

        $result = $this->actingAs($user)->get('/sync/orders?global_grid=1&sort=date&direction=asc&per_page=20');
        $result->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('globalGridPilotEnabled', true)
                ->where('pagination.total', 3)
                ->where('pagination.per_page', 20)
                ->where('filters.sort', 'date')
                ->where('filters.direction', 'asc')
                ->has('orders', 3)
            );
        $rows = $result->viewData('page')['props']['orders'];
        self::assertSame($one->id, $rows[0]['id']);
        self::assertSame($two->id, $rows[1]['id']);

        $this->actingAs($user)->get('/sync/orders?global_grid=1&search=AG-GRID-OTHER-103&source=manual_other')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('globalGridPilotEnabled', true)
            ->where('filters.source', 'manual_other')
            ->where('filters.search', 'AG-GRID-OTHER-103')
            ->has('orders', 1)
            ->where('orders.0.id', $two->id)
            );
        $this->assertDatabaseCount('manual_syncs', 0);
    }

    public function test_ag_grid_rejects_unbounded_page_size_and_arbitrary_sql_sorting(): void
    {
        $user = $this->viewer();
        $this->makeOrder('manual', 'AG-GRID-VALIDATION');
        $this->actingAs($user)->get('/sync/orders?global_grid=1&per_page=all')
            ->assertSessionHasErrors('per_page');
        $this->actingAs($user)->get('/sync/orders?global_grid=1&sort=tracking_sync_status')
            ->assertSessionHasErrors('sort');
        $this->actingAs($user)->get('/sync/orders?global_grid=1&sort=order_number&direction=foobar')
            ->assertSessionHasErrors('direction');
    }

    public function test_without_background_index_status_filter_fails_closed_without_remote_calls(): void
    {
        Cache::flush();
        $user = $this->viewer();
        $this->makeOrder('woocommerce', 'INDEX-NOT-READY');

        $this->actingAs($user)->get('/sync/orders?grid_progress=failed')
            ->assertRedirect()->assertSessionHasErrors('grid_progress');
        $this->assertDatabaseCount('manual_syncs', 0);
    }

    public function test_status_filter_rejects_expired_background_snapshot(): void
    {
        Cache::flush();
        $user = $this->viewer();
        $this->makeOrder('woocommerce', 'INDEX-CACHE-EXPIRY');
        app(OrderSyncProgressSnapshot::class)->refresh();
        $this->actingAs($user)->get('/sync/orders?grid_progress=unsynced')
            ->assertOk();

        $this->travel(13)->minutes();
        try {
            $this->actingAs($user)->get('/sync/orders?grid_progress=unsynced')
                ->assertRedirect()->assertSessionHasErrors('grid_progress');
        } finally {
            $this->travelBack();
        }
    }

    public function test_second_header_status_filter_is_server_wide_and_not_limited_to_current_page(): void
    {
        Cache::flush();
        $user = $this->viewer();

        for ($i = 1; $i <= 55; $i++) {
            $this->makeOrder('manual', 'FILTER-UNSYNCED-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT));
        }

        $failed = $this->makeOrder('woocommerce', 'FILTER-FAILED-121');
        $failed->manualSync()->create(['website_sync_status' => 'failed']);

        $synced = $this->makeOrder('woocommerce', 'FILTER-SYNCED-122');
        $syncedTracking = 'FILTER-SYNCED-TRACK-122';
        $synced->shipment()->create([
            'shipment_status' => 'shipped', 'carrier' => 'USPS',
            'tracking_number' => $syncedTracking, 'shipped_at' => now(),
        ]);
        $synced->manualSync()->create([
            'website_sync_status' => 'completed', 'synced_at' => now(),
            'sync_note' => 'verified_tracking_sha256:'.hash('sha256', $syncedTracking).'; verified.',
        ]);
        $synced->operationalMarkers()->create(['marker' => 'woo_manual_tracking_verified', 'marked_at' => now()]);

        $notified = $this->makeOrder('woocommerce', 'FILTER-NOTIFIED-123');
        $notifiedTracking = 'FILTER-NOTIFIED-TRACK-123';
        $notified->shipment()->create([
            'shipment_status' => 'shipped', 'carrier' => 'USPS',
            'tracking_number' => $notifiedTracking, 'shipped_at' => now(),
        ]);
        $notified->manualSync()->create([
            'website_sync_status' => 'completed', 'synced_at' => now(),
            'sync_note' => 'verified_tracking_sha256:'.hash('sha256', $notifiedTracking).'; Customer Note #941 已接受。',
        ]);
        $notified->operationalMarkers()->create(['marker' => 'woo_manual_tracking_verified', 'marked_at' => now()]);

        app(OrderSyncProgressSnapshot::class)->refresh();
        $base = '/sync/orders?per_page=20&grid_progress=';
        $this->actingAs($user)->get($base.'unsynced')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pagination.total', 55)
                ->has('orders', 20)
                ->where('orders.0.progress.code', 'unsynced')
                ->where('progressFilterSnapshot.total', 58)
                ->where('filters.grid_progress', 'unsynced')
            );

        foreach (['failed' => $failed, 'synced' => $synced, 'notified' => $notified] as $code => $expected) {
            $this->actingAs($user)->get($base.$code)->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('pagination.total', 1)
                    ->has('orders', 1)
                    ->where('orders.0.id', $expected->id)
                    ->where('orders.0.progress.code', $code)
                    ->where('filters.grid_progress', $code)
                );
        }

        $this->actingAs($user)->get('/sync/orders?grid_progress=unknown')
            ->assertSessionHasErrors('grid_progress');
    }

    public function test_unavailable_woo_readback_fails_closed_instead_of_returning_false_complete_status(): void
    {
        Cache::flush();
        $user = $this->viewer();
        $order = $this->makeOrder('woocommerce', 'SYNC-REMOTE-FAIL-GATE');
        $order->externalReferences()->create([
            'provider' => 'woocommerce', 'connection_key' => 'uat-batch-mock', 'reference' => '12345',
        ]);
        $order->shipment()->create([
            'shipment_status' => 'shipped', 'carrier' => 'USPS',
            'tracking_number' => 'UAT-FAIL-TRACK', 'shipped_at' => now(),
        ]);

        $inspector = new class implements WooCommerceTrackingBatchInspector, WooCommerceTrackingGateway
        {
            public function descriptor(): array
            {
                return ['configured' => true, 'url' => 'https://mock.example', 'connection_key' => 'uat-batch-mock'];
            }

            public function inspectTrackingBatch(array $providerOrderIds): array
            {
                throw new \RuntimeException('simulated WooCommerce read outage');
            }

            public function inspectOrderStatus(int $providerOrderId): string
            {
                return 'processing';
            }

            public function inspectTracking(int $providerOrderId): array
            {
                return ['tracking_number' => '', 'provider' => '', 'has_tracking' => false];
            }

            public function syncTracking(int $providerOrderId, string $providerSlug, string $trackingNumber, string $shipDate, array $lineItems = []): array
            {
                throw new \RuntimeException('write must not happen');
            }

            public function syncOrderStatus(int $providerOrderId, string $status): array
            {
                throw new \RuntimeException('write must not happen');
            }
        };
        app()->instance(WooCommerceTrackingGateway::class, $inspector);

        try {
            app(OrderSyncProgressSnapshot::class)->refresh();
            self::fail('A Woo read failure must never create an incomplete snapshot');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('WooCommerce', $exception->getMessage());
        }
        $this->actingAs($user)->get('/sync/orders?grid_progress=unsynced')
            ->assertRedirect()->assertSessionHasErrors('grid_progress');
        $this->assertDatabaseCount('manual_syncs', 0);
    }

    public function test_column_filters_use_complete_database_and_combine_with_status(): void
    {
        Cache::flush();
        $user = $this->viewer();
        for ($i = 1; $i <= 51; $i++) {
            $this->makeOrder('manual', 'FILTER-COMBO-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT));
        }
        $failed = $this->makeOrder('woocommerce', 'FILTER-COMBO-TARGET');
        $failed->manualSync()->create(['website_sync_status' => 'failed']);

        app(OrderSyncProgressSnapshot::class)->refresh();
        $this->actingAs($user)->get('/sync/orders?grid_order_number=TARGET&grid_progress=failed&per_page=20')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('pagination.total', 1)
            ->has('orders', 1)
            ->where('orders.0.id', $failed->id)
            );

        $this->actingAs($user)->get('/sync/orders?grid_order_number=TARGET&grid_progress=synced')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('pagination.total', 0)
            ->has('orders', 0)
            );

        // Human-facing Chinese column search must match persisted English
        // source codes instead of returning a false empty result.
        $kol = $this->makeOrder('kol', 'KOL-FILTER-CN');
        $this->actingAs($user)->get('/sync/orders?grid_source='.rawurlencode('网红'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pagination.total', 1)
                ->has('orders', 1)
                ->where('orders.0.id', $kol->id)
            );
    }

    public function test_verified_remote_tracking_and_customer_note_receipt_have_distinct_statuses(): void
    {
        $projector = app(OrderSyncProgressProjector::class);
        $order = $this->makeOrder('woocommerce', 'NOTIFY-RECEIPT');
        $order->shipment()->create([
            'shipment_status' => 'shipped',
            'tracking_number' => 'SYNC-NOTIFY-123',
            'carrier' => 'USPS',
            'shipped_at' => now(),
        ]);
        $number = 'SYNC-NOTIFY-123';
        $order->manualSync()->create([
            'website_sync_status' => 'completed',
            'synced_at' => now(),
            'sync_note' => 'verified_tracking_sha256:'.hash('sha256', $number).'; 官网物流核验。 Customer Note #941 已接受。',
        ]);
        $order->operationalMarkers()->create(['marker' => 'woo_manual_tracking_verified', 'marked_at' => now()]);

        $loaded = $order->fresh(['shipment', 'manualSync', 'operationalMarkers']);
        self::assertSame('notified', $projector->forOrder($loaded, ['status' => 'completed'], true)['code']);

        $loaded->manualSync->forceFill(['sync_note' => 'ERP 人工标记完成'])->save();
        self::assertSame('synced', $projector->forOrder($loaded->fresh(['shipment', 'manualSync', 'operationalMarkers']), ['status' => 'completed'], true)['code']);

        self::assertSame('unsynced', $projector->forOrder($loaded, [
            'status' => 'pending', 'reason' => 'ERP 手工标记完成，没有官网验证记录',
        ], true)['code']);

        self::assertSame('failed', $projector->forOrder($loaded, [
            'status' => 'conflict', 'reason' => '官网承运商不一致',
        ], true)['code']);
    }

    public function test_operator_sees_sync_website_eligibility_and_explanation_for_non_woo_or_unshipped_orders(): void
    {
        config(['tracking_sync_v2.manual_mode' => 'general']);
        $role = Role::create(['key' => 'role_preset_logistics', 'label' => '物流']);
        foreach (['sync.view', 'sync.operate', 'logistics.view', 'logistics.operate'] as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $operator = User::factory()->create(['is_active' => true, 'permission_mode' => 'role']);
        $operator->roles()->attach($role);

        $eligible = $this->makeOrder('woocommerce', 'SYNC-BUTTON-READY');
        $eligible->externalReferences()->create([
            'provider' => 'woocommerce', 'connection_key' => 'test-shop', 'reference' => '30041',
        ]);
        $eligible->shipment()->create([
            'shipment_status' => 'shipped', 'carrier' => 'USPS', 'tracking_number' => 'SYNC-READY-TRACK',
            'shipped_at' => now(),
        ]);
        $nonWoo = $this->makeOrder('manual_other', 'SYNC-BUTTON-NONWOO');
        $pending = $this->makeOrder('woocommerce', 'SYNC-BUTTON-NOT-SHIPPED');
        $pending->externalReferences()->create([
            'provider' => 'woocommerce', 'connection_key' => 'test-shop', 'reference' => '30042',
        ]);
        $pending->shipment()->create([
            'shipment_status' => 'pending', 'carrier' => 'USPS', 'tracking_number' => 'SYNC-PENDING-TRACK',
        ]);

        $response = $this->actingAs($operator)->get('/sync/orders')->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('canOperate', true)
            ->has('orders', 3)
        );

        $orders = collect($response->viewData('page')['props']['orders'])->keyBy('id');
        self::assertTrue($orders[$eligible->id]['can_sync']);
        self::assertNull($orders[$eligible->id]['sync_block_reason']);
        self::assertFalse($orders[$nonWoo->id]['can_sync']);
        self::assertSame(
            '该订单未关联 WooCommerce 官网订单，不能直接同步网站。',
            $orders[$nonWoo->id]['sync_block_reason']
        );
        self::assertFalse($orders[$pending->id]['can_sync']);
        self::assertStringContainsString('尚未完成发货', $orders[$pending->id]['sync_block_reason']);
        // This is only a read; no ERP sync row may be created implicitly.
        $this->assertDatabaseCount('manual_syncs', 0);
    }

    public function test_read_only_sync_view_cannot_write_and_payload_endpoint_has_valid_source_type(): void
    {
        $user = $this->viewer();
        $order = $this->makeOrder('manual', 'READONLY-123');
        $order->shipment()->create(['shipment_status' => 'shipped', 'carrier' => 'USPS', 'tracking_number' => 'TEST-TRACK']);

        $this->actingAs($user)->get('/sync/orders')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canOperate', false)
                ->where('orders.0.can_sync', false)
                ->where('orders.0.progress.code', 'unsynced')
            );
        $this->actingAs($user)->post('/sync/orders/'.$order->id.'/complete')->assertForbidden();
        $this->actingAs($user)->get('/sync/orders/'.$order->id.'/payload')->assertOk()
            ->assertJsonPath('source_type', 'manual');
        $this->assertDatabaseMissing('manual_syncs', ['order_id' => $order->id]);
    }

    private function makeOrder(string $source, string $external): Order
    {
        return Order::create([
            'internal_order_number' => 'ALL-SYNC-'.uniqid(),
            'erp_order_id' => '007-'.strtoupper(substr($external, 0, 28)).'-'.uniqid(),
            'source_type' => $source,
            'external_order_number' => $external,
            'order_kind' => 'customer',
            'order_date' => now()->toDateString(),
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
        ]);
    }

    private function viewer(): User
    {
        $role = Role::create(['key' => 'sync-overview-'.uniqid(), 'label' => 'Sync overview reader']);
        $permission = Permission::firstOrCreate(['key' => 'sync.view'], ['label' => 'View sync']);
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);

        return $user;
    }
}

<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Sync\Application\OrderSyncProgressProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_every_source_and_original_order_number_is_searchable_and_archived_orders_stay_visible(): void
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
                ->has('orders', 5)
                ->where('pagination.total', 5)
            );

        $this->actingAs($user)->get('/sync/orders?search=IMPORTED-ORIGINAL-132')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders', 1)
                ->where('orders.0.id', $imported->id)
                ->where('orders.0.external_order_number', 'IMPORTED-ORIGINAL-132')
                ->where('orders.0.archived', true)
            );

        $this->actingAs($user)->get('/sync/orders?source=woocommerce')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders', 1)
                ->where('orders.0.id', $woo->id)
                ->where('filters.source', 'woocommerce')
            );

        $this->actingAs($user)->get('/sync/orders?source=not_a_source')->assertSessionHasErrors('source');
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

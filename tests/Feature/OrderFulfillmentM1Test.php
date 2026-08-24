<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Modules\Orders\Application\OrderKindBackfill;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class OrderFulfillmentM1Test extends TestCase
{
    use RefreshDatabase;

    public function test_customer_create_defaults_and_preserves_the_business_order_number(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create']);

        $this->actingAs($user)->get('/customers/orders/create')->assertInertia(
            fn ($page) => $page->where('defaultOrderKind', 'customer')
        );

        $this->actingAs($user)->post('/customers/orders', $this->payload('  BIZ-0001  '))->assertRedirect();

        $order = Order::query()->firstOrFail();
        $this->assertSame('customer', $order->order_kind->value);
        $this->assertSame('BIZ-0001', $order->external_order_number, 'operator business number must be preserved exactly, only trimmed');
        $this->assertNotSame($order->external_order_number, $order->internal_order_number, 'the ERP technical number must never overwrite the business number');
    }

    public function test_kol_create_defaults_to_kol_fulfillment(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create']);

        $this->actingAs($user)->get('/kol/orders/create')->assertInertia(
            fn ($page) => $page->where('defaultOrderKind', 'kol_fulfillment')
        );

        $this->actingAs($user)->post('/kol/orders', $this->payload('KOL-BIZ-0001', 'kol_fulfillment'))->assertRedirect('/kol/orders/1');
        $order = Order::query()->firstOrFail();
        $this->assertSame('kol_fulfillment', $order->order_kind->value);
    }

    public function test_duplicate_business_order_number_is_rejected(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create']);
        $this->actingAs($user)->post('/customers/orders', $this->payload('DUP-001'))->assertRedirect();
        $this->actingAs($user)->post('/customers/orders', $this->payload('DUP-001'))
            ->assertSessionHasErrors('external_order_number');
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_customer_and_kol_lists_are_scoped_server_side_with_no_cross_leak(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create']);
        $this->actingAs($user)->post('/customers/orders', $this->payload('CUS-1'))->assertRedirect();
        $this->actingAs($user)->post('/kol/orders', $this->payload('KOL-1', 'kol_fulfillment'))->assertRedirect();

        $customerOrder = Order::query()->where('order_kind', 'customer')->firstOrFail();
        $kolOrder = Order::query()->where('order_kind', 'kol_fulfillment')->firstOrFail();

        $this->actingAs($user)->get('/customers/orders')->assertInertia(
            fn ($page) => $page->where('orders.total', 1)->where('orders.data.0.external_order_number', 'CUS-1')
        );
        $this->actingAs($user)->get('/kol/orders')->assertInertia(
            fn ($page) => $page->where('orders.total', 1)->where('orders.data.0.external_order_number', 'KOL-1')
        );

        // Direct URL manipulation must not leak the other kind's order detail.
        $this->actingAs($user)->get('/customers/orders/'.$kolOrder->id)->assertNotFound();
        $this->actingAs($user)->get('/kol/orders/'.$customerOrder->id)->assertNotFound();
    }

    public function test_kol_orders_surface_requires_orders_permission_not_the_prototype_placeholder(): void
    {
        $prototypeOnly = $this->userWith(['dashboard.view', 'prototype.view'], 'prototype-only');
        $this->actingAs($prototypeOnly)->get('/kol/orders')->assertForbidden();
        $this->actingAs($prototypeOnly)->post('/kol/orders', $this->payload('X', 'kol_fulfillment'))->assertForbidden();
    }

    public function test_pending_shipment_queue_is_shared_across_both_order_kinds_and_labelled(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create', 'logistics.view', 'logistics.operate']);
        $this->actingAs($user)->post('/customers/orders', $this->payload('CUS-2'))->assertRedirect();
        $this->actingAs($user)->post('/kol/orders', $this->payload('KOL-2', 'kol_fulfillment'))->assertRedirect();

        $this->actingAs($user)->get('/logistics/pending')->assertInertia(
            fn ($page) => $page->where('orders.total', 2)
        );
    }

    public function test_order_list_is_paginated_server_side(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create']);
        for ($i = 0; $i < 55; $i++) {
            $this->actingAs($user)->post('/customers/orders', $this->payload('PAG-'.$i))->assertRedirect();
        }

        $this->actingAs($user)->get('/customers/orders')->assertInertia(
            fn ($page) => $page->where('orders.total', 55)->where('orders.per_page', 50)->has('orders.data', 50)
        );
    }

    public function test_legacy_manual_other_rows_are_not_guessed_during_order_kind_backfill(): void
    {
        DB::table('users')->insert(['id' => 1, 'name' => 'Legacy Owner', 'email' => 'legacy@example.test', 'password' => 'x', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $websiteId = DB::table('orders')->insertGetId(['internal_order_number' => 'LEG-WEB-1', 'source_type' => 'website', 'external_order_number' => 'LEG-WEB-1', 'order_date' => now(), 'owner_id' => 1, 'order_status' => 'pending_shipment', 'created_at' => now(), 'updated_at' => now()]);
        $kolId = DB::table('orders')->insertGetId(['internal_order_number' => 'LEG-KOL-1', 'source_type' => 'kol', 'external_order_number' => 'LEG-KOL-1', 'order_date' => now(), 'owner_id' => 1, 'order_status' => 'pending_shipment', 'created_at' => now(), 'updated_at' => now()]);
        $manualId = DB::table('orders')->insertGetId(['internal_order_number' => 'LEG-MAN-1', 'source_type' => 'manual_other', 'external_order_number' => 'LEG-MAN-1', 'order_date' => now(), 'owner_id' => 1, 'order_status' => 'pending_shipment', 'created_at' => now(), 'updated_at' => now()]);

        $result = OrderKindBackfill::apply();

        $this->assertSame('customer', DB::table('orders')->where('id', $websiteId)->value('order_kind'));
        $this->assertSame('kol_fulfillment', DB::table('orders')->where('id', $kolId)->value('order_kind'));
        $this->assertNull(DB::table('orders')->where('id', $manualId)->value('order_kind'), 'ambiguous manual_other legacy rows must not be guessed');
        $this->assertContains($manualId, $result['unclassified']);
    }

    private function payload(string $externalOrderNumber, string $orderKind = 'customer'): array
    {
        return [
            'order_kind' => $orderKind,
            'source_type' => 'website', 'external_order_number' => $externalOrderNumber, 'order_date' => '2026-08-24',
            'recipient_name' => 'Synthetic Recipient', 'phone' => '+1 555 0100', 'country' => 'US',
            'province_state' => 'CA', 'city' => 'Los Angeles', 'address_line_1' => '100 Synthetic Street',
            'postal_code' => '90001', 'items' => [
                ['product_summary' => 'Synthetic Product', 'sku' => 'SYN-1', 'quantity' => 1, 'unit_price' => '19.99'],
            ],
        ];
    }

    private function userWith(array $permissions, string $roleKey = null): User
    {
        $role = Role::create(['key' => $roleKey ?? 'm1-'.uniqid(), 'label' => 'M1 Test Role']);
        foreach ($permissions as $permission) {
            $role->permissions()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}

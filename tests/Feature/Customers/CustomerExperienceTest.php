<?php

namespace Tests\Feature\Customers;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Customers\Infrastructure\Models\CustomerAddress;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class CustomerExperienceTest extends TestCase
{
    use RefreshDatabase;

    // ─── Dashboard ────────────────────────────────────────────────────────────

    public function test_customers_dashboard_shows_real_metrics(): void
    {
        $user = $this->userWith(['customers.view']);

        DB::table('customers')->insert(['name' => 'Old Customer', 'source_type' => 'manual', 'created_at' => now()->subYear(), 'updated_at' => now()->subYear()]);
        DB::table('customers')->insert(['name' => 'New This Month', 'source_type' => 'manual', 'created_at' => now(), 'updated_at' => now()]);

        $customerId = DB::table('customers')->where('name', 'New This Month')->value('id');
        $orderId = DB::table('orders')->insertGetId([
            'internal_order_number' => 'DASH-001', 'source_type' => 'manual', 'order_kind' => 'customer',
            'external_order_number' => 'DASH-EXT-1', 'order_date' => now()->toDateString(),
            'order_status' => 'shipped', 'customer_id' => $customerId,
            'currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId, 'product_summary' => 'P', 'sku' => 'S1', 'quantity' => 1,
            'unit_price' => 100, 'line_revenue' => 100, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($user)->get('/customers')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customers/Dashboard')
                ->where('metrics.total_customers', 2)
                ->where('metrics.new_this_month', 1)
                ->where('metrics.orders_this_month', 1)
                ->has('metrics.revenue_this_month', 1)
                ->where('metrics.revenue_this_month.0.currency', 'USD')
                ->where('metrics.revenue_this_month.0.revenue', 100));
    }

    public function test_customers_dashboard_multi_currency_revenue_is_separated(): void
    {
        $user = $this->userWith(['customers.view']);
        $c = Customer::query()->create(['name' => 'MC', 'source_type' => 'manual']);

        foreach (['USD' => 200, 'EUR' => 150] as $currency => $amount) {
            $oid = DB::table('orders')->insertGetId([
                'internal_order_number' => "MC-{$currency}", 'source_type' => 'manual', 'order_kind' => 'customer',
                'external_order_number' => "MC-EXT-{$currency}", 'order_date' => now()->toDateString(),
                'order_status' => 'shipped', 'customer_id' => $c->id,
                'currency' => $currency, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('order_items')->insert([
                'order_id' => $oid, 'product_summary' => 'P', 'sku' => "S-{$currency}", 'quantity' => 1,
                'unit_price' => $amount, 'line_revenue' => $amount, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->actingAs($user)->get('/customers')
            ->assertInertia(fn (Assert $page) => $page
                ->has('metrics.revenue_this_month', 2));
    }

    // ─── Addresses ────────────────────────────────────────────────────────────

    public function test_create_customer_with_billing_and_shipping_addresses(): void
    {
        $user = $this->userWith(['customers.view', 'customers.operate']);

        $this->actingAs($user)->post('/customers/library', [
            'name' => 'Addr Test', 'source_type' => 'manual',
            'billing_address' => [
                'name' => 'Bill To', 'city' => 'New York', 'country_code' => 'US',
                'address_line_1' => '1 Bill St', 'postal_code' => '10001',
                'company' => '', 'phone' => '', 'province_state' => '', 'address_line_2' => '',
            ],
            'shipping_address' => [
                'name' => 'Ship To', 'city' => 'Los Angeles', 'country_code' => 'US',
                'address_line_1' => '2 Ship Ave', 'postal_code' => '90001',
                'company' => '', 'phone' => '', 'province_state' => '', 'address_line_2' => '',
            ],
        ])->assertRedirect();

        $customer = Customer::query()->firstOrFail();
        $this->assertDatabaseHas('customer_addresses', ['customer_id' => $customer->id, 'address_type' => 'billing', 'city' => 'New York']);
        $this->assertDatabaseHas('customer_addresses', ['customer_id' => $customer->id, 'address_type' => 'shipping', 'city' => 'Los Angeles']);
    }

    public function test_edit_updates_addresses_transactionally(): void
    {
        $user = $this->userWith(['customers.view', 'customers.operate']);
        $customer = Customer::query()->create(['name' => 'Edit Addr', 'source_type' => 'manual']);
        CustomerAddress::query()->create(['customer_id' => $customer->id, 'address_type' => 'billing', 'city' => 'OldCity']);

        $this->actingAs($user)->patch("/customers/library/{$customer->id}", [
            'name' => 'Edit Addr', 'source_type' => 'manual',
            'billing_address' => [
                'name' => '', 'city' => 'NewCity', 'country_code' => 'US',
                'address_line_1' => '', 'postal_code' => '', 'company' => '', 'phone' => '', 'province_state' => '', 'address_line_2' => '',
            ],
        ])->assertRedirect();

        $this->assertDatabaseHas('customer_addresses', ['customer_id' => $customer->id, 'address_type' => 'billing', 'city' => 'NewCity']);
    }

    public function test_show_includes_addresses_and_unique_constraint_enforced(): void
    {
        $user = $this->userWith(['customers.view', 'customers.operate']);
        $customer = Customer::query()->create(['name' => 'Show Addr', 'source_type' => 'manual']);
        CustomerAddress::query()->create(['customer_id' => $customer->id, 'address_type' => 'billing', 'city' => 'Boston']);

        $this->actingAs($user)->get("/customers/library/{$customer->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customers/Library/Show')
                ->where('billingAddress.city', 'Boston')
                ->where('shippingAddress', null));

        $this->expectException(\Illuminate\Database\QueryException::class);
        CustomerAddress::query()->create(['customer_id' => $customer->id, 'address_type' => 'billing', 'city' => 'Duplicate']);
    }

    // ─── Pagination ───────────────────────────────────────────────────────────

    public function test_customer_list_is_server_side_paginated(): void
    {
        $user = $this->userWith(['customers.view']);
        for ($i = 1; $i <= 3; $i++) {
            Customer::query()->create(['name' => "Customer {$i}", 'source_type' => 'manual']);
        }

        $this->actingAs($user)->get('/customers/library?page=1')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customers/Library/Index')
                ->has('customers.data')
                ->has('customers.current_page')
                ->has('customers.last_page')
                ->has('customers.total'));
    }

    // ─── Shipment projection ──────────────────────────────────────────────────

    public function test_show_projects_shipments_through_customer_orders(): void
    {
        $user = $this->userWith(['customers.view', 'customers.operate']);
        $customer = Customer::query()->create(['name' => 'Shipment Test', 'source_type' => 'manual']);

        $orderId = DB::table('orders')->insertGetId([
            'internal_order_number' => 'SHIP-001', 'source_type' => 'manual', 'order_kind' => 'customer',
            'external_order_number' => 'SHIP-EXT-1', 'order_date' => now()->toDateString(),
            'order_status' => 'shipped', 'customer_id' => $customer->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        (new Shipment())->forceFill([
            'order_id' => $orderId, 'shipment_status' => 'shipped', 'carrier' => 'DHL',
            'tracking_number' => 'DHL-123', 'shipped_at' => now()->subHour(),
        ])->save();

        $this->actingAs($user)->get("/customers/library/{$customer->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('shipments', 1)
                ->where('shipments.0.carrier', 'DHL')
                ->where('shipments.0.tracking_number', 'DHL-123')
                ->where('metrics.shipment_count', 1));
    }

    public function test_unlinked_orders_do_not_contribute_shipments(): void
    {
        $user = $this->userWith(['customers.view']);
        $customer = Customer::query()->create(['name' => 'No Ship', 'source_type' => 'manual']);
        $other = Customer::query()->create(['name' => 'Other', 'source_type' => 'manual']);

        $otherOrderId = DB::table('orders')->insertGetId([
            'internal_order_number' => 'OTHER-001', 'source_type' => 'manual', 'order_kind' => 'customer',
            'external_order_number' => 'OTHER-EXT-1', 'order_date' => now()->toDateString(),
            'order_status' => 'shipped', 'customer_id' => $other->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        (new Shipment())->forceFill([
            'order_id' => $otherOrderId, 'shipment_status' => 'shipped', 'carrier' => 'FedEx',
            'tracking_number' => 'FEDEX-999', 'shipped_at' => now()->subHour(),
        ])->save();

        $this->actingAs($user)->get("/customers/library/{$customer->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->has('shipments', 0)
                ->where('metrics.shipment_count', 0));
    }

    // ─── Library stats ────────────────────────────────────────────────────────

    public function test_library_index_exposes_real_stats_including_unavailable_cards(): void
    {
        $user = $this->userWith(['customers.view']);
        $c = Customer::query()->create(['name' => 'Stats Test', 'source_type' => 'manual']);
        $orderId = DB::table('orders')->insertGetId([
            'internal_order_number' => 'STAT-001', 'source_type' => 'manual', 'order_kind' => 'customer',
            'external_order_number' => 'STAT-EXT-1', 'order_date' => now()->toDateString(),
            'order_status' => 'shipped', 'customer_id' => $c->id,
            'currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId, 'product_summary' => 'P', 'sku' => 'S1', 'quantity' => 1,
            'unit_price' => 80, 'line_revenue' => 80, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($user)->get('/customers/library')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customers/Library/Index')
                ->has('stats')
                ->where('stats.total_customers', 1)
                ->where('stats.new_this_month', 1)
                ->where('stats.orders_this_month', 1)
                ->has('stats.revenue_this_month', 1)
                ->where('stats.revenue_this_month.0.currency', 'USD')
                ->where('stats.revenue_this_month.0.revenue', 80)
                ->where('stats.subscriber_count_available', false)
                ->where('stats.open_support_tickets_available', false));
    }

    public function test_library_table_rows_include_lifetime_revenue_and_latest_order_date(): void
    {
        $user = $this->userWith(['customers.view']);
        $c = Customer::query()->create(['name' => 'Row Metrics', 'source_type' => 'manual']);
        $orderId = DB::table('orders')->insertGetId([
            'internal_order_number' => 'ROW-001', 'source_type' => 'manual', 'order_kind' => 'customer',
            'external_order_number' => 'ROW-EXT-1', 'order_date' => '2026-07-15',
            'order_status' => 'shipped', 'customer_id' => $c->id,
            'currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId, 'product_summary' => 'P', 'sku' => 'S1', 'quantity' => 1,
            'unit_price' => 120, 'line_revenue' => 120, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($user)->get('/customers/library')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('customers.data.0.latest_order_date', '2026-07-15')
                ->has('customers.data.0.lifetime_revenue', 1)
                ->where('customers.data.0.lifetime_revenue.0.currency', 'USD')
                ->where('customers.data.0.lifetime_revenue.0.revenue', 120));
    }

    public function test_library_row_lifetime_revenue_excludes_unlinked_orders(): void
    {
        $user = $this->userWith(['customers.view']);
        $c = Customer::query()->create(['name' => 'Linked Only', 'source_type' => 'manual']);
        DB::table('orders')->insert([
            'internal_order_number' => 'UNLINKED-ROW', 'source_type' => 'manual', 'order_kind' => 'customer',
            'external_order_number' => 'UNLINKED-EXT', 'order_date' => now()->toDateString(),
            'order_status' => 'shipped', 'customer_id' => null,
            'currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($user)->get('/customers/library')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('customers.data.0.lifetime_revenue', [])
                ->where('customers.data.0.latest_order_date', null));
    }

    // ─── shipment_count independence from display limit ───────────────────────

    public function test_shipment_count_reflects_all_shipments_not_just_display_limit(): void
    {
        $user = $this->userWith(['customers.view']);
        $customer = Customer::query()->create(['name' => 'Many Ships', 'source_type' => 'manual']);

        // Create 25 orders each with one shipment (unique constraint on shipments.order_id)
        for ($i = 1; $i <= 25; $i++) {
            $orderId = DB::table('orders')->insertGetId([
                'internal_order_number' => "MANY-{$i}", 'source_type' => 'manual', 'order_kind' => 'customer',
                'external_order_number' => "MANY-EXT-{$i}", 'order_date' => now()->toDateString(),
                'order_status' => 'shipped', 'customer_id' => $customer->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            (new Shipment())->forceFill([
                'order_id' => $orderId, 'shipment_status' => 'shipped', 'carrier' => 'DHL',
                'tracking_number' => "DHL-{$i}", 'shipped_at' => now()->subMinutes($i),
            ])->save();
        }

        $this->actingAs($user)->get("/customers/library/{$customer->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('metrics.shipment_count', 25)
                ->has('shipments', 20)); // display capped at 20
    }

    // ─── Blank address deletion ───────────────────────────────────────────────

    public function test_submitting_blank_address_deletes_existing_record(): void
    {
        $user = $this->userWith(['customers.view', 'customers.operate']);
        $customer = Customer::query()->create(['name' => 'Del Addr', 'source_type' => 'manual']);
        CustomerAddress::query()->create(['customer_id' => $customer->id, 'address_type' => 'billing', 'city' => 'ToDelete']);

        $this->assertDatabaseHas('customer_addresses', ['customer_id' => $customer->id, 'address_type' => 'billing']);

        $this->actingAs($user)->patch("/customers/library/{$customer->id}", [
            'name' => 'Del Addr', 'source_type' => 'manual',
            'billing_address' => [
                'name' => '', 'city' => '', 'country_code' => '',
                'address_line_1' => '', 'address_line_2' => '',
                'company' => '', 'phone' => '', 'province_state' => '', 'postal_code' => '',
            ],
        ])->assertRedirect();

        $this->assertDatabaseMissing('customer_addresses', ['customer_id' => $customer->id, 'address_type' => 'billing']);
    }

    // ─── Purchase history shipment status ─────────────────────────────────────

    public function test_recent_orders_include_shipment_status(): void
    {
        $user = $this->userWith(['customers.view']);
        $customer = Customer::query()->create(['name' => 'Ship Status Test', 'source_type' => 'manual']);

        $orderId = DB::table('orders')->insertGetId([
            'internal_order_number' => 'SS-001', 'source_type' => 'manual', 'order_kind' => 'customer',
            'external_order_number' => 'SS-EXT-1', 'order_date' => now()->toDateString(),
            'order_status' => 'shipped', 'customer_id' => $customer->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        (new Shipment())->forceFill([
            'order_id' => $orderId, 'shipment_status' => 'delivered', 'carrier' => 'UPS',
            'tracking_number' => 'UPS-123', 'shipped_at' => now()->subDay(),
        ])->save();

        $this->actingAs($user)->get("/customers/library/{$customer->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('recentOrders', 1)
                ->where('recentOrders.0.shipment_status', 'delivered'));
    }

    // ─── Import/Export permissions ────────────────────────────────────────────

    public function test_import_export_buttons_visible_only_to_authorized_users(): void
    {
        $admin = $this->userWith(['customers.view', 'customers.import', 'customers.export', 'system.permissions.manage']);
        $regular = $this->userWith(['customers.view'], 'customer_reader2');

        $this->actingAs($admin)->get('/customers/library')
            ->assertInertia(fn (Assert $page) => $page
                ->where('canImport', true)
                ->where('canExport', true));

        $this->actingAs($regular)->get('/customers/library')
            ->assertInertia(fn (Assert $page) => $page
                ->where('canImport', false)
                ->where('canExport', false));
    }

    public function test_reserved_import_endpoint_requires_permission(): void
    {
        $noImport = $this->userWith(['customers.view', 'customers.operate'], 'no_import');
        $this->actingAs($noImport)->post('/customers/library/import')->assertForbidden();
    }

    public function test_reserved_export_endpoint_requires_permission(): void
    {
        $noExport = $this->userWith(['customers.view', 'customers.operate'], 'no_export');
        $this->actingAs($noExport)->get('/customers/library/export')->assertForbidden();
    }

    public function test_authorized_import_returns_not_implemented(): void
    {
        $admin = $this->userWith(['customers.view', 'customers.operate', 'customers.import', 'system.permissions.manage'], 'has_import');
        $this->actingAs($admin)->post('/customers/library/import')->assertStatus(501);
    }

    public function test_authorized_export_returns_not_implemented(): void
    {
        $admin = $this->userWith(['customers.view', 'customers.export', 'system.permissions.manage'], 'has_export');
        $this->actingAs($admin)->get('/customers/library/export')->assertStatus(501);
    }

    public function test_non_admin_presets_do_not_receive_import_export_by_default(): void
    {
        $salesUser = $this->userWith(['customers.view', 'customers.operate'], 'sales_no_import');

        $this->actingAs($salesUser)->get('/customers/library')
            ->assertInertia(fn (Assert $page) => $page
                ->where('canImport', false)
                ->where('canExport', false));
    }

    // ─── Dashboard canImport/canExport ────────────────────────────────────────

    public function test_dashboard_shows_import_export_only_to_authorized(): void
    {
        $admin = $this->userWith(['customers.view', 'customers.import', 'customers.export', 'system.permissions.manage'], 'dash_admin');
        $viewer = $this->userWith(['customers.view'], 'dash_viewer');

        $this->actingAs($admin)->get('/customers')
            ->assertInertia(fn (Assert $page) => $page->where('canImport', true)->where('canExport', true));

        $this->actingAs($viewer)->get('/customers')
            ->assertInertia(fn (Assert $page) => $page->where('canImport', false)->where('canExport', false));
    }

    // ─── Administrator-only gate: import/export requires system.permissions.manage ──

    public function test_import_export_buttons_false_without_system_permissions_manage(): void
    {
        // User has customers.import/export but lacks system.permissions.manage
        $user = $this->userWith(['customers.view', 'customers.import', 'customers.export'], 'import_export_no_admin');

        $this->actingAs($user)->get('/customers/library')
            ->assertInertia(fn (Assert $page) => $page
                ->where('canImport', false)
                ->where('canExport', false));

        $this->actingAs($user)->get('/customers')
            ->assertInertia(fn (Assert $page) => $page
                ->where('canImport', false)
                ->where('canExport', false));
    }

    public function test_import_endpoint_403_when_lacking_system_permissions_manage(): void
    {
        $user = $this->userWith(['customers.view', 'customers.import'], 'import_no_admin_gate');
        $this->actingAs($user)->post('/customers/library/import')->assertForbidden();
    }

    public function test_export_endpoint_403_when_lacking_system_permissions_manage(): void
    {
        $user = $this->userWith(['customers.view', 'customers.export'], 'export_no_admin_gate');
        $this->actingAs($user)->get('/customers/library/export')->assertForbidden();
    }

    public function test_import_endpoint_501_when_both_permissions_present(): void
    {
        $user = $this->userWith(['customers.view', 'customers.import', 'system.permissions.manage'], 'import_both_gates');
        $this->actingAs($user)->post('/customers/library/import')->assertStatus(501);
    }

    public function test_export_endpoint_501_when_both_permissions_present(): void
    {
        $user = $this->userWith(['customers.view', 'customers.export', 'system.permissions.manage'], 'export_both_gates');
        $this->actingAs($user)->get('/customers/library/export')->assertStatus(501);
    }

    // ─── RMB estimate in library stats payload ────────────────────────────────

    public function test_library_stats_includes_rmb_estimate_payload(): void
    {
        Cache::forget('ecb_fx_snapshot');
        Http::fake(['https://www.ecb.europa.eu/*' => Http::response(
            $this->ecbXml(['CNY' => '7.8', 'USD' => '1.1']), 200
        )]);

        $user = $this->userWith(['customers.view'], 'rmb_test_user');
        $c = Customer::query()->create(['name' => 'RMB Test', 'source_type' => 'manual']);
        $oid = DB::table('orders')->insertGetId([
            'internal_order_number' => 'RMB-001', 'source_type' => 'manual', 'order_kind' => 'customer',
            'external_order_number' => 'RMB-EXT-1', 'order_date' => now()->toDateString(),
            'order_status' => 'shipped', 'customer_id' => $c->id,
            'currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $oid, 'product_summary' => 'P', 'sku' => 'S-RMB', 'quantity' => 1,
            'unit_price' => 110, 'line_revenue' => 110, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($user)->get('/customers/library')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('stats.rmb_estimate')
                ->where('stats.rmb_estimate.available', true)
                ->where('stats.rmb_estimate.source', 'ECB')
                ->has('stats.rmb_estimate.amount_cny')
                ->has('stats.rmb_estimate.reference_date')
                ->has('stats.rmb_estimate.stale')
                ->has('stats.rmb_estimate.conversions'));
    }

    public function test_library_stats_rmb_estimate_unavailable_for_unsupported_currency(): void
    {
        Cache::forget('ecb_fx_snapshot');
        // Only CNY in XML, no GBP
        Http::fake(['https://www.ecb.europa.eu/*' => Http::response(
            $this->ecbXml(['CNY' => '7.8']), 200
        )]);

        $user = $this->userWith(['customers.view'], 'rmb_unsupported_user');
        $c = Customer::query()->create(['name' => 'GBP Test', 'source_type' => 'manual']);
        $oid = DB::table('orders')->insertGetId([
            'internal_order_number' => 'GBP-001', 'source_type' => 'manual', 'order_kind' => 'customer',
            'external_order_number' => 'GBP-EXT-1', 'order_date' => now()->toDateString(),
            'order_status' => 'shipped', 'customer_id' => $c->id,
            'currency' => 'GBP', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $oid, 'product_summary' => 'P', 'sku' => 'S-GBP', 'quantity' => 1,
            'unit_price' => 50, 'line_revenue' => 50, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($user)->get('/customers/library')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.rmb_estimate.available', false));
    }

    // ─── Customer-order export gate (UAT amendment) ───────────────────────────

    public function test_orders_view_user_gets_403_on_customer_order_export(): void
    {
        $user = $this->userWith(['orders.view'], 'orders_view_only');
        $this->actingAs($user)->get('/customers/orders/export')->assertForbidden();
    }

    public function test_customers_export_without_permissions_manage_gets_403_on_customer_order_export(): void
    {
        $user = $this->userWith(['orders.view', 'customers.export'], 'customers_export_no_admin');
        $this->actingAs($user)->get('/customers/orders/export')->assertForbidden();
    }

    public function test_admin_with_both_permissions_can_access_customer_order_export(): void
    {
        $user = $this->userWith(['orders.view', 'customers.export', 'system.permissions.manage'], 'customer_order_export_admin');
        $this->actingAs($user)->get('/customers/orders/export')->assertOk();
    }

    public function test_orders_index_customer_kind_canExport_false_without_both_permissions(): void
    {
        $user = $this->userWith(['orders.view', 'customers.view'], 'orders_view_no_export');
        $this->actingAs($user)->get('/customers/orders')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Index')
                ->where('canExport', false));
    }

    public function test_orders_index_customer_kind_canExport_true_with_both_permissions(): void
    {
        $user = $this->userWith(['orders.view', 'customers.export', 'system.permissions.manage'], 'orders_export_admin');
        $this->actingAs($user)->get('/customers/orders')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Index')
                ->where('canExport', true));
    }

    private function ecbXml(array $rates = [], string $date = '2026-08-28'): string
    {
        $rateXml = '';
        foreach ($rates as $currency => $rate) {
            $rateXml .= "<Cube currency=\"{$currency}\" rate=\"{$rate}\"/>";
        }
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01"
                 xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref">
    <Cube><Cube time="{$date}">{$rateXml}</Cube></Cube>
</gesmes:Envelope>
XML;
    }

    private function userWith(array $permissions, string $roleKey = 'customer_operator'): User
    {
        $role = Role::create(['key' => $roleKey, 'label' => $roleKey]);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }
}

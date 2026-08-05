<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\AuditRecord;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErpNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $permissionKeys = [
            'prototype.view', 'dashboard.view', 'architecture.foundation.view', 'logistics.view', 'logistics.operate',
            'sync.view', 'sync.operate', 'customers.view', 'kol.view', 'inventory.view', 'procurement.view',
            'products.view', 'guides.view', 'accounting.view', 'system.permissions.manage', 'orders.view',
            'orders.create', 'orders.edit', 'analysis.view',
        ];
        $permissions = collect($permissionKeys)->mapWithKeys(fn (string $key): array => [$key => Permission::create(['key' => $key, 'label' => $key])]);
        $role = Role::create(['key' => 'admin', 'label' => 'Admin']);
        $role->permissions()->attach($permissions->pluck('id')->all());
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        $this->actingAs($user);
    }
    public function test_every_declared_navigation_path_is_available(): void
    {
        foreach ($this->paths(config('erp_navigation')) as $path) $this->get($path)->assertOk();
    }

    public function test_shell_renders_required_modules_without_affiliate(): void
    {
        $response = $this->get('/logistics/pending');
        $response->assertInertia(fn (Assert $page) => $page->component('Logistics/Index')->has('shellNavigation', 11));
    }

    public function test_accounting_third_level_navigation_is_rendered(): void
    {
        $this->get('/accounting/expense-analysis/2026-execution')->assertOk()->assertSee('费用分析表')->assertSee('2026执行');
    }

    public function test_order_list_and_detail_render_the_phase_two_skeleton(): void
    {
        $this->post('/customers/orders', [
            'source_type' => 'kol', 'order_date' => '2026-08-05', 'recipient_name' => 'Synthetic KOL', 'phone' => '+1 555 0101',
            'country' => 'US', 'province_state' => 'CA', 'city' => 'Los Angeles', 'address_line_1' => 'Synthetic Street',
            'items' => [['product_summary' => 'Synthetic KOL Product', 'sku' => 'KOL-1', 'quantity' => 1, 'unit_price' => '10.00']],
        ])->assertRedirect();
        $order = Order::query()->firstOrFail();
        $this->get('/kol/orders')->assertInertia(fn (Assert $page) => $page->component('Orders/Index')->where('orders.0.internal_order_number', $order->internal_order_number));
        $this->get('/kol/orders/'.$order->id)->assertInertia(fn (Assert $page) => $page->component('Orders/Show')->where('order.internal_order_number', $order->internal_order_number));
        $this->get('/kol/orders/999999')->assertNotFound();
    }

    public function test_architecture_foundation_is_an_inertia_page_with_safe_props(): void
    {
        $this->get('/architecture/foundation')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Architecture/Foundation')
                ->where('title', 'BYL ERP Foundation')
                ->where('status', 'ARCHITECTURE VALIDATION')
                ->missing('user')
                ->missing('orders'));
    }

    public function test_guest_root_redirects_to_login_and_authenticated_root_to_dashboard(): void
    {
        auth()->logout();
        $this->get('/')->assertInertia(fn (Assert $page) => $page->component('Auth/Login'));
        $this->actingAs(User::first())->get('/')->assertRedirectToRoute('dashboard');
    }

    public function test_login_rejects_inactive_user_and_logs_out_authenticated_user(): void
    {
        auth()->logout();
        $user = User::first();
        $user->update(['is_active' => false]);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $user->update(['is_active' => true, 'password' => Hash::make('password')]);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $this->assertDatabaseHas('audit_records', ['action' => 'auth.login']);
        $this->post('/logout')->assertRedirect('/');
        $this->assertDatabaseHas('audit_records', ['action' => 'auth.logout']);
    }

    public function test_successful_login_regenerates_the_session_id(): void
    {
        auth()->logout();
        $user = User::first();
        $user->update(['password' => Hash::make('password')]);
        $before = $this->app['session']->getId();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/dashboard');
        $this->assertNotSame($before, $this->app['session']->getId());
    }

    public function test_login_is_rate_limited_after_five_failed_attempts(): void
    {
        auth()->logout();
        $user = User::first();
        RateLimiter::clear(strtolower($user->email).'|127.0.0.1');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
        }
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertStatus(429);
    }

    private function paths(array $items): array
    {
        $paths = [];
        foreach ($items as $item) {
            if (isset($item['path'])) $paths[] = $item['path'];
            if (isset($item['children'])) $paths = [...$paths, ...$this->paths($item['children'])];
        }
        return $paths;
    }
}

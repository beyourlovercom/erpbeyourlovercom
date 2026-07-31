<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\AuditRecord;
use Illuminate\Support\Facades\Hash;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErpNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $permission = Permission::create(['key' => 'prototype.view', 'label' => 'Prototype']);
        $dashboardPermission = Permission::create(['key' => 'dashboard.view', 'label' => 'Dashboard']);
        $foundationPermission = Permission::create(['key' => 'architecture.foundation.view', 'label' => 'Foundation']);
        $role = Role::create(['key' => 'admin', 'label' => 'Admin']);
        $role->permissions()->attach([$permission->id, $dashboardPermission->id, $foundationPermission->id]);
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
        foreach (['物流', '客户', '网红', '库存', '外采', '产品', '指南', '会计', '设置'] as $label) $response->assertSee($label);
        $response->assertDontSee('Affiliate OS');
    }

    public function test_accounting_third_level_navigation_is_rendered(): void
    {
        $this->get('/accounting/expense-analysis/2026-execution')->assertOk()->assertSee('费用分析表')->assertSee('2026执行');
    }

    public function test_order_list_and_detail_render_the_phase_two_skeleton(): void
    {
        $this->get('/kol/orders')->assertOk()->assertSee('网红订单')->assertSee('KOL26073001-007');
        $this->get('/kol/orders/KOL26073001-007')->assertOk()->assertSee('客户详情')->assertSee('物流信息拆分')->assertSee('物流发货')->assertSee('操作记录');
        $this->get('/kol/orders/INVALID-ORDER')->assertNotFound();
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

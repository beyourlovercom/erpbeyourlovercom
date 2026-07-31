<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(array $permissions, string $roleKey = 'operator'): User
    {
        $role = Role::create(['key' => $roleKey, 'label' => $roleKey]);
        foreach ($permissions as $key) $role->permissions()->create(['key' => $key, 'label' => $key]);
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }

    public function test_guests_and_unauthorized_users_are_blocked(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $user = $this->userWith(['prototype.view']);
        $this->actingAs($user)->get('/dashboard')->assertForbidden();
        $this->actingAs($user)->get('/logistics/pending')->assertOk();
        $this->actingAs($user)->get('/architecture/foundation')->assertForbidden();
    }

    public function test_super_admin_bypasses_named_permissions(): void
    {
        $user = $this->userWith([], 'super_admin');
        $this->actingAs($user)->get('/dashboard')->assertOk();
        $this->actingAs($user)->get('/architecture/foundation')->assertOk();
    }

    public function test_provisioning_validates_input_and_never_persists_password(): void
    {
        Role::create(['key' => 'operator', 'label' => 'Operator']);
        $this->artisan('erp:user:create')
            ->expectsQuestion('Name', '')
            ->expectsQuestion('Email', 'bad-email')
            ->expectsQuestion('Password', 'short')
            ->expectsQuestion('Confirm password', 'different')
            ->expectsChoice('Role', 'operator', ['super_admin', 'admin', 'manager', 'operator'])
            ->assertExitCode(1);
        $this->assertDatabaseCount('users', 0);
        $this->artisan('erp:user:create')
            ->expectsQuestion('Name', 'Owner')
            ->expectsQuestion('Email', 'owner@example.test')
            ->expectsQuestion('Password', 'a-strong-password-123')
            ->expectsQuestion('Confirm password', 'a-strong-password-123')
            ->expectsChoice('Role', 'operator', ['super_admin', 'admin', 'manager', 'operator'])
            ->assertExitCode(0);
        $this->assertDatabaseHas('users', ['email' => 'owner@example.test']);
        $this->assertDatabaseMissing('audit_records', ['after->password' => 'a-strong-password-123']);
    }
}

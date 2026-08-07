<?php

namespace Tests\Feature\Auth;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthHomeContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_receive_the_same_login_component_at_root_and_login(): void
    {
        $this->get('/')->assertOk()->assertSee('"component":"Auth\\/Login"', false);
        $this->get('/login')->assertOk()->assertSee('"component":"Auth\\/Login"', false);
    }

    public function test_authenticated_users_are_redirected_from_root_and_login_to_dashboard(): void
    {
        $user = $this->dashboardUser();

        $this->actingAs($user)->get('/')->assertRedirect('/dashboard');
        $this->actingAs($user)->get('/login')->assertRedirect('/dashboard');
    }

    public function test_login_is_persistent_without_a_remember_request_parameter(): void
    {
        $user = $this->dashboardUser();
        $user->update(['password' => Hash::make('test-password')]);
        $remembered = false;
        Event::listen(Login::class, function (Login $event) use (&$remembered): void {
            $remembered = $event->remember;
        });

        $response = $this->post('/login', ['email' => $user->email, 'password' => 'test-password']);

        $response->assertRedirect('/dashboard');
        $this->assertTrue($remembered);
        $this->assertSame(60 * 24 * 30, config('auth.guards.web.remember'));
        $this->assertNotNull($user->fresh()->remember_token);
    }

    public function test_inactive_user_loses_an_existing_authenticated_session(): void
    {
        $user = $this->dashboardUser();
        $this->actingAs($user);
        $user->update(['is_active' => false]);

        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    private function dashboardUser(): User
    {
        $role = Role::create(['key' => 'auth-home-contract', 'label' => 'Auth Home Contract']);
        $permission = Permission::create(['key' => 'dashboard.view', 'label' => 'Dashboard view']);
        $role->permissions()->attach($permission);
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}

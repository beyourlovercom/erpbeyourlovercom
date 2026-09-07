<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RequestDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_response_has_diagnostic_headers(): void
    {
        $user = $this->userWith(['dashboard.view']);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertHeader('X-Request-ID');
        $response->assertHeader('X-Query-Count');
        $response->assertHeader('X-Query-Time-Ms');
        $response->assertHeader('X-Server-Time-Ms');
    }

    public function test_request_id_is_a_valid_uuid(): void
    {
        $user = $this->userWith(['dashboard.view']);

        $response = $this->actingAs($user)->get('/dashboard');

        $requestId = $response->headers->get('X-Request-ID');
        $this->assertTrue(Str::isUuid($requestId), "X-Request-ID must be a valid UUID, got: {$requestId}");
    }

    public function test_supplied_valid_request_id_header_is_reused(): void
    {
        $user = $this->userWith(['dashboard.view']);
        $uuid = (string) Str::uuid();

        $response = $this->actingAs($user)
            ->withHeaders(['X-Request-ID' => $uuid])
            ->get('/dashboard');

        $response->assertHeader('X-Request-ID', $uuid);
    }

    public function test_invalid_request_id_header_is_replaced_with_new_uuid(): void
    {
        $user = $this->userWith(['dashboard.view']);

        $response = $this->actingAs($user)
            ->withHeaders(['X-Request-ID' => 'not-a-uuid'])
            ->get('/dashboard');

        $requestId = $response->headers->get('X-Request-ID');
        $this->assertTrue(Str::isUuid($requestId));
        $this->assertNotEquals('not-a-uuid', $requestId);
    }

    public function test_query_count_header_is_numeric(): void
    {
        $user = $this->userWith(['dashboard.view']);

        $response = $this->actingAs($user)->get('/dashboard');

        $queryCount = $response->headers->get('X-Query-Count');
        $this->assertIsNumeric($queryCount);
        $this->assertGreaterThanOrEqual(0, (int) $queryCount);
    }

    private function userWith(array $permissions): User
    {
        $role = Role::create(['key' => 'diag-'.uniqid(), 'label' => 'Diagnostics Test']);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->attach($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}

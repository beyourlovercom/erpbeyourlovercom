<?php

namespace Tests\Feature\Console;

use App\Models\AuditRecord;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateErpUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_confirmation_mismatch_rolls_back_without_provisioning_audit(): void
    {
        Role::create(['key' => 'operator', 'label' => 'Operator']);
        $this->artisan('erp:user:create')->expectsQuestion('Name', 'Owner')->expectsQuestion('Email', 'owner@example.test')
            ->expectsQuestion('Password', 'independent-secret-123')->expectsQuestion('Confirm password', 'different-secret-123')
            ->expectsChoice('Role', 'operator', ['super_admin', 'admin', 'manager', 'operator'])->assertExitCode(1);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('role_user', 0);
        $this->assertDatabaseMissing('audit_records', ['action' => 'user.created']);
    }

    public function test_provisioning_secret_is_never_logged_or_persisted_in_plaintext(): void
    {
        Role::create(['key' => 'operator', 'label' => 'Operator']);
        $secret = 'BYL-TEST-SECRET-NOT-LOGGED-987!';
        $this->artisan('erp:user:create')->expectsQuestion('Name', 'Owner')->expectsQuestion('Email', 'owner@example.test')
            ->expectsQuestion('Password', $secret)->expectsQuestion('Confirm password', $secret)
            ->expectsChoice('Role', 'operator', ['super_admin', 'admin', 'manager', 'operator'])->assertExitCode(0);
        $user = User::where('email', 'owner@example.test')->firstOrFail();
        $this->assertTrue(Hash::check($secret, $user->password));
        $this->assertNotSame($secret, $user->password);
        $audit = AuditRecord::where('action', 'user.created')->firstOrFail();
        $this->assertNull($audit->actor_id);
        $this->assertSame('erp:user:create', $audit->source);
        $this->assertSame('administrator_provisioning', $audit->reason);
        $this->assertStringNotContainsString($secret, json_encode(AuditRecord::all()->toArray()));
    }
}

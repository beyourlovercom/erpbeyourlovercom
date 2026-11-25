<?php

namespace Tests\Support;

use App\Modules\Identity\Application\RolePreset;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;

trait OrderContactFixture
{
    private function fixtureContactId(): int
    {
        $role = Role::firstOrCreate(['key' => RolePreset::KolSpecialist->roleKey()], ['label' => 'Synthetic KOL contact']);
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return (int) $user->id;
    }
}

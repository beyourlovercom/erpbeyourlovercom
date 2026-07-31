<?php

namespace App\Console\Commands;

use App\Models\AuditRecord;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CreateErpUser extends Command
{
    protected $signature = 'erp:user:create';
    protected $description = 'Create an internal ERP user with an approved role';

    public function handle(): int
    {
        $name = $this->ask('Name');
        $email = strtolower($this->ask('Email'));
        $password = $this->secret('Password');
        $roleKey = $this->choice('Role', ['super_admin', 'admin', 'manager', 'operator']);
        if (User::where('email', $email)->exists()) { $this->error('Email already exists.'); return self::FAILURE; }
        DB::transaction(function () use ($name, $email, $password, $roleKey): void { $user = User::create(['name' => $name, 'email' => $email, 'password' => Hash::make($password), 'is_active' => true]); $user->roles()->attach(Role::where('key', $roleKey)->firstOrFail()); AuditRecord::create(['actor_id' => null, 'action' => 'user.created', 'entity_type' => User::class, 'entity_id' => (string) $user->id, 'after' => ['name' => $name, 'email' => $email, 'role' => $roleKey], 'source' => 'erp:user:create', 'created_at' => now()]); });
        $this->info('ERP user created.'); return self::SUCCESS;
    }
}

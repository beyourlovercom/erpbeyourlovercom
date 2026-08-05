<?php

namespace App\Modules\Identity\Experience\Console\Commands;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CreateErpUser extends Command
{
    protected $signature = 'erp:user:create';

    protected $description = 'Create an internal ERP user with an approved role';

    public function handle(AuditRecorder $audit): int
    {
        $name = $this->ask('Name');
        $email = strtolower(trim($this->ask('Email')));
        $password = $this->secret('Password');
        $password_confirmation = $this->secret('Confirm password');
        $roleKey = $this->choice('Role', ['super_admin', 'admin', 'manager', 'operator']);
        $validator = Validator::make(compact('name', 'email', 'password', 'password_confirmation', 'roleKey'), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
            'password_confirmation' => ['required', 'same:password'],
            'roleKey' => ['required', Rule::in(['super_admin', 'admin', 'manager', 'operator'])],
        ], ['password.confirmed' => 'Password confirmation does not match.']);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        DB::transaction(function () use ($audit, $name, $email, $password, $roleKey): void {
            $user = User::create(['name' => $name, 'email' => $email, 'password' => Hash::make($password), 'is_active' => true]);
            $user->roles()->attach(Role::where('key', $roleKey)->firstOrFail());
            $audit->record(
                AuditActor::system(AuditSource::UserProvisioning),
                'user.created',
                \App\Models\User::class,
                $user->id,
                after: ['name' => $name, 'email' => $email, 'role' => $roleKey],
                reason: 'administrator_provisioning',
            );
        });
        $this->info('ERP user created.');

        return self::SUCCESS;
    }
}

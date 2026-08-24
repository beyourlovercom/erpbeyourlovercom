<?php

namespace App\Modules\Identity\Infrastructure\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'is_active', 'permission_mode'];

    protected $hidden = ['password', 'remember_token'];

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class);
    }

    public function customPermissions()
    {
        return $this->belongsToMany(Permission::class, 'permission_user');
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->roles()->where('key', 'super_admin')->exists()) {
            return true;
        }

        if ($this->permission_mode === 'custom') {
            return $this->customPermissions()->where('key', $permission)->exists();
        }

        return $this->roles()->whereHas('permissions', fn ($query) => $query->where('key', $permission))->exists();
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }
}

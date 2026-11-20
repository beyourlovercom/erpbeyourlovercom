<?php

namespace App\Modules\Kol\Application\OwnerMapping;

use App\Modules\Identity\Application\RolePreset;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

final class KolOwnerUserMatcher
{
    /**
     * Resolve only unique, exact ERP identities for active KOL staff.
     *
     * @param  EloquentCollection<int, User>  $eligibleUsers
     * @return array{status: 'matched'|'ambiguous'|'not_found', user: ?User}
     */
    public function match(string $sourceOwner, EloquentCollection $eligibleUsers): array
    {
        $needle = $this->normalize($sourceOwner);
        if ($needle === '') {
            return ['status' => 'not_found', 'user' => null];
        }

        $roleKeys = [RolePreset::KolSpecialist->roleKey(), RolePreset::KolManager->roleKey()];
        $matches = [];
        foreach ($eligibleUsers as $user) {
            $hasExactIdentity = false;
            foreach (['name', 'contact_name', 'email'] as $field) {
                if ($this->normalize((string) $user->{$field}) === $needle) {
                    $hasExactIdentity = true;
                    break;
                }
            }

            if (! $hasExactIdentity || ! $user->is_active || ! $user->roles->contains(
                fn ($role): bool => in_array($role->key, $roleKeys, true)
            ) || ! $user->can('kol.view')) {
                continue;
            }
            $matches[(int) $user->id] = $user;
        }

        if (count($matches) > 1) {
            return ['status' => 'ambiguous', 'user' => null];
        }

        $user = array_values($matches)[0] ?? null;

        return ['status' => $user ? 'matched' : 'not_found', 'user' => $user];
    }

    private function normalize(string $value): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($value)));
    }
}

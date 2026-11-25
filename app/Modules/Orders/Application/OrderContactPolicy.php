<?php

namespace App\Modules\Orders\Application;

use App\Modules\Identity\Application\RolePreset;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class OrderContactPolicy
{
    public static function eligible(OrderKind $kind): Builder
    {
        $query = User::query()->where('is_active', true);
        if ($kind === OrderKind::KolFulfillment) {
            return $query->whereHas('roles', fn (Builder $q) => $q->whereIn('key', [
                RolePreset::KolSpecialist->roleKey(), RolePreset::KolManager->roleKey(),
            ]))->whereDoesntHave('roles', fn (Builder $q) => $q->whereIn('key', [
                RolePreset::Operations->roleKey(), RolePreset::Logistics->roleKey(),
            ]));
        }
        if ($kind === OrderKind::Customer) {
            return $query->whereHas('roles', fn (Builder $q) => $q->where('key', RolePreset::Operations->roleKey()));
        }

        return $query->whereRaw('1 = 0');
    }

    public static function canEdit(\Illuminate\Contracts\Auth\Authenticatable $actor): bool
    {
        if (! $actor->can('orders.edit')) return false;
        if ($actor->can('system.permissions.manage')) return true;
        $id = (int) $actor->getAuthIdentifier();
        return self::isEligible(OrderKind::Customer, $id) || self::isEligible(OrderKind::KolFulfillment, $id);
    }

    public static function options(OrderKind $kind): array
    {
        return self::eligible($kind)->orderBy('name')->orderBy('id')->get(['id', 'name'])
            ->map(fn (User $user) => ['value' => $user->id, 'label' => $user->name])->all();
    }

    /** Manual assignment is independent of the default department and order ownership. */
    public static function manualOptions(): array
    {
        return User::query()->where('is_active', true)->orderBy('name')->orderBy('id')->get(['id', 'name'])
            ->map(fn (User $user) => ['value' => $user->id, 'label' => $user->name])->all();
    }

    public static function isManualEligible(?int $id): bool
    {
        return $id !== null && User::query()->where('is_active', true)->whereKey($id)->exists();
    }

    public static function isEligible(OrderKind $kind, ?int $id): bool
    {
        return $id !== null && self::eligible($kind)->whereKey($id)->exists();
    }

    public static function operationsDefault(?int $ownerId = null): ?int
    {
        $configured = (int) config('order_contact.operations_user_id');
        if ($configured > 0) {
            return self::isEligible(OrderKind::Customer, $configured) ? $configured : null;
        }
        $ids = self::eligible(OrderKind::Customer)->orderBy('id')->pluck('id');
        if ($ids->count() === 1) {
            return (int) $ids->first();
        }

        return $ownerId && $ids->contains($ownerId) ? $ownerId : null;
    }
}

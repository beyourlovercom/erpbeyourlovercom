<?php

namespace App\Modules\Orders\Application;

use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A KOL specialist has owner-only order access, including generic order URLs.
 * Manager and other independently authorized operational roles retain their
 * established full-order read scopes.
 */
final class KolOrderAccessScope
{
    public static function isOwnerOnly(Authenticatable $user): bool
    {
        return $user->can('kol.view')
            && ! $user->can('kol.views.manage')
            && ! $user->can('customers.view')
            && ! $user->can('logistics.view')
            && ! $user->can('accounting.view')
            && ! $user->can('system.permissions.manage');
    }

    public static function scopeFilters(Authenticatable $user, array $filters): array
    {
        if (self::isOwnerOnly($user)) {
            $filters['order_kind'] = OrderKind::KolFulfillment->value;
            $filters['owner_id'] = (int) $user->getAuthIdentifier();
        }

        return $filters;
    }

    public static function authorizeCreate(Authenticatable $user, OrderKind $kind): void
    {
        if (self::isOwnerOnly($user)) {
            abort_unless($kind === OrderKind::KolFulfillment, 403);
        }
    }

    public static function authorize(Authenticatable $user, Order $order): void
    {
        if (! self::isOwnerOnly($user)) {
            return;
        }

        abort_unless(
            $order->order_kind === OrderKind::KolFulfillment
            && (int) $order->owner_id === (int) $user->getAuthIdentifier(),
            403
        );
    }
}

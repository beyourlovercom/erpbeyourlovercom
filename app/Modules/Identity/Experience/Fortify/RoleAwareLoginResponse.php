<?php

namespace App\Modules\Identity\Experience\Fortify;

use App\Modules\Identity\Application\AuthorizationKeys;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Fortify;

final class RoleAwareLoginResponse implements LoginResponseContract
{
    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return response()->json(['two_factor' => false]);
        }

        $user = $request->user();
        $fallback = $user?->can(AuthorizationKeys::InventoryPackagingSubmit->value)
            && ! $user?->can(AuthorizationKeys::DashboardView->value)
                ? route('inventory.packaging.index')
                : Fortify::redirects('login');

        return redirect()->intended($fallback);
    }
}

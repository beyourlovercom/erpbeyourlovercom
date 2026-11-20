<?php

namespace App\Modules\Identity\Experience\Controllers;

use App\Modules\Identity\Experience\Middleware\ApplyLocalAdminBypass;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Shell\Application\ErpShellNavigation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Minimal self-only identity projection for the ERP2 Dev verification bridge.
 * Does not take a user ID, query orders, change permissions or issue tokens.
 */
final class OriginalErpIdentityController
{
    public function __invoke(Request $request, ErpShellNavigation $navigation): JsonResponse
    {
        // A local-only test bypass must never masquerade as a genuine staff session.
        abort_if($request->attributes->get(ApplyLocalAdminBypass::REQUEST_MARKER) === true, 403);

        $user = $request->user();
        abort_unless($user instanceof User && $user->is_active, 403);

        // Query saved grants on every request; never return static preset defaults.
        $roles = $user->roles()->with('permissions:id,key')->get();
        $superAdmin = $roles->contains('key', 'super_admin');
        $mode = $user->permission_mode === 'custom' ? 'custom' : 'role';
        $effectiveKeys = $superAdmin
            ? ['*']
            : ($mode === 'custom'
                ? $user->customPermissions()->pluck('key')->all()
                : $roles->flatMap(fn ($role) => $role->permissions->pluck('key'))->all());

        return response()->json([
            'source' => 'ERP_ORIGINAL_LARAVEL_PRODUCTION_SESSION_V1',
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'permissionMode' => $mode,
            'roleKeys' => $roles->pluck('key')->unique()->sort()->values()->all(),
            'effectivePermissionKeys' => collect($effectiveKeys)->unique()->sort()->values()->all(),
            'navigation' => $navigation->for($user),
        ])->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Vary', 'Cookie')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}

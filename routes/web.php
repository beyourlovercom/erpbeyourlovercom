<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Modules\Identity\Experience\Middleware\ApplyLocalAdminBypass;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Shell\Application\ErpShellNavigation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Route;

Route::get('/architecture/foundation', fn () => Inertia::render('Architecture/Foundation', [
    'title' => 'BYL ERP Foundation',
    'status' => 'ARCHITECTURE VALIDATION',
]))->middleware([HandleInertiaRequests::class, 'auth', 'can:architecture.foundation.view'])->name('architecture.foundation');

// OMS Grid R0–R2: authenticated non-Production visual and interaction UAT only.
if (in_array(app()->environment(), ['local', 'staging', 'development', 'testing'], true)) {
    // ERP2 P0: one authenticated Laravel identity/ACL source, Dev only.
    // Never accept a client-provided staff ID/role and never expose credentials.
    Route::get('/_erp2/identity', function (Request $request, ErpShellNavigation $navigation) {
        abort_if($request->attributes->get(ApplyLocalAdminBypass::REQUEST_MARKER) === true, 403);

        $user = $request->user();
        abort_unless($user instanceof User && $user->is_active, 403);

        $roles = $user->roles()->with('permissions:id,key')->get();
        $superAdmin = $roles->contains('key', 'super_admin');
        $effectiveKeys = $superAdmin
            ? ['*']
            : ($user->permission_mode === 'custom'
                ? $user->customPermissions()->pluck('key')->all()
                : $roles->flatMap(fn ($role) => $role->permissions->pluck('key'))->all());

        return response()->json([
            'source' => 'ERP_CANONICAL_LARAVEL_DEV_SESSION_V1',
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'permissionMode' => $user->permission_mode === 'custom' ? 'custom' : 'role',
            'roleKeys' => $roles->pluck('key')->unique()->sort()->values()->all(),
            'effectivePermissionKeys' => collect($effectiveKeys)->unique()->sort()->values()->all(),
            'navigation' => $navigation->for($user),
        ])->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Vary', 'Cookie');
    })->middleware(['auth'])->name('erp2.dev.identity');

    Route::get('/_uat/oms-grid', fn () => Inertia::render('Architecture/OmsGridPreview'))
        ->middleware(['auth'])->name('architecture.oms-grid-uat');
}

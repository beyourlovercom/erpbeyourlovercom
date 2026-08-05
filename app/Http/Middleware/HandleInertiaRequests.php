<?php

namespace App\Http\Middleware;

use App\Modules\Shell\Application\ErpShellNavigation;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => ['user' => $user?->only(['id', 'name', 'email'])],
            'shellNavigation' => $user ? app(ErpShellNavigation::class)->for($user) : [],
        ];
    }
}

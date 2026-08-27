<?php

namespace App\Modules\Kol\Experience\Controllers;

use App\Modules\Kol\Application\KolScope;
use App\Modules\Kol\Application\KolService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class KolOverviewController
{
    public function __construct(private readonly KolService $kols) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $ownerScopeId = KolScope::effectiveOwnerId($user);
        $overview = $this->kols->overview($ownerScopeId);

        return Inertia::render('Kol/Overview', [
            'overview' => $overview,
            'canOperate' => KolScope::canOperate($user),
            'canManageViews' => KolScope::canManageViews($user),
        ]);
    }
}

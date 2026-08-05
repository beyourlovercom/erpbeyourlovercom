<?php

namespace App\Modules\Shell\Experience\Controllers;

use App\Modules\Shell\Application\LegacyErpNavigationPaths;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

final class ShellPlaceholderController
{
    public function __construct(private readonly LegacyErpNavigationPaths $legacyPaths) {}

    public function __invoke(array $module): InertiaResponse|Response|View
    {
        if (Gate::allows($module['permission'])) {
            return Inertia::render('Shell/Placeholder', [
                'module' => ['key' => $module['key'], 'label' => $module['label']],
            ]);
        }

        if (Gate::allows('prototype.view') && in_array($this->legacyPaths->normalize($module['path']), $this->legacyPaths->all(config('erp_navigation')), true)) {
            return app(ErpShellController::class)->show($module['key'], trim($module['path'], '/'));
        }

        abort(403);
    }
}

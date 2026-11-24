<?php

namespace App\Modules\Kol\Experience\Controllers;

use App\Modules\Kol\Application\SmartInput\DevFixtureBridge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class KolSmartInputController
{
    public function __invoke(Request $request, DevFixtureBridge $bridge): JsonResponse
    {
        abort_unless(DevFixtureBridge::enabled(), 404);
        abort_unless($request->user()->can('kol.operate') || $request->user()->can('orders.create'), 403);
        $data = $request->validate([
            'fixture_id' => ['required', 'string', Rule::in(array_column(DevFixtureBridge::fixtures(), 'id'))],
            'text' => ['prohibited'], 'raw' => ['prohibited'],
        ]);
        return response()->json($bridge->suggest($data['fixture_id']))->header('Cache-Control', 'no-store');
    }
}

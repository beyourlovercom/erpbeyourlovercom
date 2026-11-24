<?php

namespace App\Modules\Kol\Experience\Controllers;

use App\Modules\Kol\Application\SmartInput\DevShapeBridge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class KolSmartShapeController
{
    public function __invoke(Request $request, DevShapeBridge $bridge): JsonResponse
    {
        abort_unless(DevShapeBridge::enabled(),404);
        abort_unless($request->user()->can('kol.operate')||$request->user()->can('orders.create'),403);
        abort_unless($request->isJson(),415);
        $payload=$request->json()->all();
        abort_unless(DevShapeBridge::valid($payload),422,'只接受字段类型与行位置，不接受原始资料。');
        return response()->json($bridge->suggest($payload))->header('Cache-Control','no-store');
    }
}

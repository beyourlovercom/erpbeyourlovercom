<?php

namespace App\Modules\Logistics\Experience\Controllers;

use App\Modules\Logistics\Application\Xlwms\SingleOrderDispatch;
use App\Modules\Logistics\Application\LogisticsExecutionAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class XlwmsSingleOrderController
{
    public function show(int $order, SingleOrderDispatch $service, LogisticsExecutionAccess $access, Request $request): Response
    {
        return Inertia::render('Logistics/Oversea/XlwmsOrder', [
            'preview' => $service->preview($order),
            'canDispatch' => $access->allows($request->user())
                && (bool) config('xlwms.live_dispatch_enabled',false),
        ]);
    }

    public function send(int $order, Request $request, SingleOrderDispatch $service): JsonResponse
    {
        $data = $request->validate([
            'payload_hash' => ['required','string','size:64','regex:/^[0-9a-f]{64}$/'],
            'confirmation' => ['required','string','max:100'],
        ]);
        abort_unless(hash_equals('SUBMIT_US_SM_ORDER_'.$order,$data['confirmation']),422,'单笔下发确认不一致');
        $result = $service->dispatch($order,$data['payload_hash'],(int)$request->user()->id);
        return response()->json($result)->header('Cache-Control','no-store, private');
    }

    public function reconcile(int $order, SingleOrderDispatch $service): JsonResponse
    {
        // Detail check is safe and does not retry order creation.
        return response()->json($service->reconcile($order))
            ->header('Cache-Control','no-store, private');
    }
}

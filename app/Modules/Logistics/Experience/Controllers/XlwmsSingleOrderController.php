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
                && (bool) config('xlwms.live_dispatch_enabled',false)
                && (int) config('xlwms.live_order_id', 0) === $order,
        ]);
    }

    /** Minimal safe preflight for Dede's existing warehouse table row. */
    public function inlinePreflight(int $order, SingleOrderDispatch $service): JsonResponse
    {
        $preview = $service->preview($order);
        return response()->json([
            'order_id' => $preview['order_id'],
            'erp_order_id' => $preview['erp_order_id'],
            'blockers' => $preview['blockers'],
            'ready' => $preview['ready_for_remote_check'],
            'payload_hash' => $preview['payload_hash'],
            'oms_warehouse' => $preview['payload']['whCode'],
            'items' => $preview['payload']['productList'],
            'already_attempted' => $preview['attempt'] !== null,
        ])->header('Cache-Control','no-store, private');
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

    public function status(int $order, SingleOrderDispatch $service): JsonResponse
    {
        return response()->json($service->checkStatus($order))
            ->header('Cache-Control','no-store, private');
    }

    public function reconcile(int $order, SingleOrderDispatch $service): JsonResponse
    {
        // Detail check is safe and does not retry order creation.
        return response()->json($service->reconcile($order))
            ->header('Cache-Control','no-store, private');
    }
}

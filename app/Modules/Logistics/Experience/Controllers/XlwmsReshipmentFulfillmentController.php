<?php
namespace App\Modules\Logistics\Experience\Controllers;

use App\Modules\Logistics\Application\Xlwms\ReshipmentFulfillmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class XlwmsReshipmentFulfillmentController
{
    public function preflight(int $order, string $requestId, ReshipmentFulfillmentService $service): JsonResponse
    {
        try {
            return response()->json($service->preview($order,$requestId))->header('Cache-Control','no-store, private');
        } catch (RuntimeException $e) {
            return response()->json(['code'=>$e->getMessage()],409)->header('Cache-Control','no-store, private');
        }
    }

    public function apply(int $order, string $requestId, Request $request, ReshipmentFulfillmentService $service): JsonResponse
    {
        $validated=$request->validate(['review_token'=>['required','string','regex:/^[0-9a-f]{64}$/']]);
        try {
            return response()->json($service->apply($order,$requestId,$validated['review_token'],$request->user()))
                ->header('Cache-Control','no-store, private');
        } catch (RuntimeException $e) {
            return response()->json(['code'=>$e->getMessage()],409)->header('Cache-Control','no-store, private');
        }
    }
}

<?php
namespace App\Modules\Logistics\Experience\Controllers;
use App\Modules\Logistics\Application\Xlwms\ReshipmentPreview;
use App\Modules\Logistics\Application\Xlwms\ReshipmentIntentService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
final class XlwmsReshipmentController {
    public function requestIntent(int $order, Request $request, ReshipmentIntentService $service): JsonResponse {
        $input = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:180'],
            'confirm_original_shipped' => ['required', 'accepted'],
        ]);
        try {
            $result = $service->request($order, (int) $request->user()->id, $input['reason']);
            return response()->json($result, 201)->header('Cache-Control', 'no-store, private');
        } catch (\RuntimeException $e) {
            return response()->json(['code' => $e->getMessage()], 409)
                ->header('Cache-Control', 'no-store, private');
        }
    }

    public function preflight(int $order,ReshipmentPreview $service): JsonResponse {
        return response()->json($service->forOrder($order))
            ->header('Cache-Control','no-store, private');
    }
}

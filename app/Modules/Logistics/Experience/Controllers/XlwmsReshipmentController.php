<?php
namespace App\Modules\Logistics\Experience\Controllers;
use App\Modules\Logistics\Application\Xlwms\ReshipmentPreview;
use Illuminate\Http\JsonResponse;
final class XlwmsReshipmentController {
    public function preflight(int $order,ReshipmentPreview $service): JsonResponse {
        return response()->json($service->forOrder($order))
            ->header('Cache-Control','no-store, private');
    }
}

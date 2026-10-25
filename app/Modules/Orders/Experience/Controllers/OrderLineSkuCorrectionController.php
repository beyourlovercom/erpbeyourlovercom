<?php

namespace App\Modules\Orders\Experience\Controllers;

use App\Modules\Orders\Application\OrderLineSkuCorrectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OrderLineSkuCorrectionController
{
    public function update(
        int $order,
        int $item,
        Request $request,
        OrderLineSkuCorrectionService $corrections
    ): JsonResponse {
        $data = $request->validate([
            'expected_sku' => ['required', 'string', 'max:128'],
            'target_sku' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/'],
        ]);
        return response()->json($corrections->correct(
            $order, $item,
            trim($data['expected_sku']), trim($data['target_sku']),
            $request->user()
        ))->header('Cache-Control', 'no-store, private');
    }
}

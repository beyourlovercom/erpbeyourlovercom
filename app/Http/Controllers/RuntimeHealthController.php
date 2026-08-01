<?php

namespace App\Http\Controllers;

use App\Support\Runtime\RuntimeHealthService;
use Illuminate\Http\JsonResponse;

class RuntimeHealthController extends Controller
{
    public function __invoke(RuntimeHealthService $health): JsonResponse
    {
        $result = $health->check();

        return response()->json($result, $result['status'] === 'ok' ? 200 : 503);
    }
}

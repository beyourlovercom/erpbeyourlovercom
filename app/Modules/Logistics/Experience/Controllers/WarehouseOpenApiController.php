<?php

namespace App\Modules\Logistics\Experience\Controllers;

use App\Modules\Inventory\Application\InventoryService;
use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseOpenApiConnection;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsCredentialProbe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Per-warehouse, administrator-only API configuration. No outbound operations.
 */
final class WarehouseOpenApiController
{
    public function save(
        Request $request,
        string $warehouse,
        InventoryService $inventory,
        XlwmsCredentialProbe $probe
    ): JsonResponse {
        $response = static fn (array $data, int $code = 200): JsonResponse =>
            response()->json($data, $code)->header('Cache-Control', 'no-store, private')
                ->header('Pragma', 'no-cache')->header('Referrer-Policy', 'no-referrer');

        if (! (bool) config('xlwms.manual_probe_enabled', false)) {
            return $response(['status' => 'disabled'], 403);
        }

        $validator = Validator::make($request->all(), [
            'app_key' => ['required', 'string', 'min:8', 'max:256'],
            'app_secret' => ['required', 'string', 'min:8', 'max:256'],
        ]);
        if ($validator->fails()) {
            // Never use Laravel redirect validation (which flashes submitted secrets).
            return $response(['status' => 'invalid_credentials_format'], 422);
        }

        $data = $validator->validated();
        $appKey = trim($data['app_key']);
        $appSecret = trim($data['app_secret']);
        $w = $inventory->warehouse($warehouse);
        $result = $probe->verify($appKey, $appSecret);
        if ($result['status'] !== 'verified') {
            return $response($result, 422);
        }

        $entry = WarehouseOpenApiConnection::query()->firstOrNew([
            'warehouse_id' => (int) $w->id,
            'provider' => 'xlwms',
        ]);
        $entry->app_key_encrypted = $appKey;
        $entry->app_secret_encrypted = $appSecret;
        // Never infer actual OMS warehouse code from the ERP warehouse name.
        $entry->oms_warehouse_code = null;
        $entry->last_verified_at = now();
        $entry->save();

        return $response([
            'status' => 'saved_verified',
            'http_status' => $result['http_status'],
            'code' => $result['code'],
            'warehouse_count' => $result['warehouse_count'],
            'remote_warehouse_bound' => false,
            'auto_dispatch_enabled' => false,
        ]);
    }
}

<?php

namespace App\Modules\Logistics\Experience\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Logistics\Application\CarrierServiceCatalog;
use App\Modules\Logistics\Application\OverseaManualShipmentService;
use App\Modules\Logistics\Application\OverseaWarehouseReviewService;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Operator-confirmed ERP-only batch shipment for warehouses with an existing
 * manual confirmation contract. Preview never writes orders; apply is one-use,
 * per-order isolated and always calls the existing shipping domain service.
 *
 * MorePlus / US-LA intentionally stays on the verified OMS/outbound proof path.
 */
final class OverseaBulkShipmentController extends Controller
{
    public function preview(Request $request, CarrierServiceCatalog $catalog): JsonResponse
    {
        $data = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:50'],
            'order_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);
        $ids = array_values(array_map('intval', $data['order_ids']));
        $results = $this->inspect($ids, $catalog);
        $token = Str::random(48);
        $fingerprints = [];
        foreach ($results as $result) {
            if ($result['eligible']) {
                $fingerprints[$result['order_id']] = $result['fingerprint'];
            }
            unset($result['fingerprint']);
        }
        // No order, shipment, provider, or Woo writes on this endpoint.
        Cache::put($this->previewCacheKey((int) $request->user()->getAuthIdentifier(), $token), [
            'ids' => $ids,
            'fingerprints' => $fingerprints,
        ], now()->addMinutes(10));

        return response()->json([
            'token' => $token, 'expires_in_seconds' => 600,
            'total' => count($ids),
            'eligible_count' => count($fingerprints),
            'blocked_count' => count($ids) - count($fingerprints),
            'results' => array_map(static function (array $row): array {
                unset($row['fingerprint']);

                return $row;
            }, $results),
            'warning' => '仅对仓库已真实出库且 Tracking 已保存的订单确认；MorePlus 必须使用 OMS/真实出库单专属流程。',
        ]);
    }

    public function apply(
        Request $request,
        CarrierServiceCatalog $catalog,
        OverseaManualShipmentService $shipments,
    ): JsonResponse {
        $data = $request->validate([
            'token' => ['required', 'string', 'size:48', 'regex:/\A[A-Za-z0-9]+\z/'],
            'confirmation' => ['required', 'in:CONFIRM_REAL_WAREHOUSE_DISPATCH'],
        ]);
        $key = $this->previewCacheKey((int) $request->user()->getAuthIdentifier(), $data['token']);

        // Consume the preview once. Another click, tab or user cannot reuse it.
        $preview = Cache::lock($key.':claim', 15)->block(3, fn () => Cache::pull($key));
        abort_unless(is_array($preview) && isset($preview['ids'], $preview['fingerprints']), 409,
            '预检已失效或已提交。请先核对发货结果，避免重复确认。');

        $ids = $preview['ids'];
        abort_unless(is_array($ids) && count($ids) >= 1 && count($ids) <= 50, 409, '预检订单列表无效。');
        $eligibleFingerprints = $preview['fingerprints'];
        $results = [];

        foreach ($ids as $id) {
            $id = (int) $id;
            $entry = $this->inspectOne($id, $catalog);
            unset($entry['fingerprint']);
            if (! isset($eligibleFingerprints[$id]) || ! $entry['eligible']) {
                $results[] = [
                    ...$entry, 'ok' => false, 'status' => 'skipped',
                    'message' => $entry['eligible'] ? '未通过原预检，不允许新增发货' : $entry['message'],
                ];
                continue;
            }

            $fresh = $this->inspectOne($id, $catalog);
            if (! $fresh['eligible'] || ! hash_equals((string) $eligibleFingerprints[$id], (string) ($fresh['fingerprint'] ?? ''))) {
                $results[] = [
                    'order_id' => $id, 'order_no' => $fresh['order_no'],
                    'warehouse' => $fresh['warehouse'], 'ok' => false, 'status' => 'skipped',
                    'message' => '订单或 Tracking 在预检后变化，请单独核对',
                ];
                continue;
            }

            try {
                $order = Order::query()->findOrFail($id);
                // Existing single-order service performs locking, payment and
                // audit safety checks; never copy their write logic here.
                $result = $fresh['warehouse'] === 'US-SM'
                    ? $shipments->confirmUsSm($order, (string) $fresh['tracking_number'], $request->user())
                    : $shipments->confirmNonOms($order, (string) $fresh['tracking_number'], $request->user());

                $syncStatus = (string) data_get($result, 'sync.status', '');
                $results[] = [
                    'order_id' => $id, 'order_no' => $fresh['order_no'],
                    'warehouse' => $fresh['warehouse'], 'ok' => true, 'status' => 'shipped',
                    'message' => $syncStatus === 'handoff_failed'
                        ? 'ERP 已发货，但同步登记失败；请到同步总表核对，不要重复发货'
                        : 'ERP 已发货，官网由同步模块处理',
                ];
            } catch (Throwable $error) {
                // A late exception may occur after the shipment was saved.
                // Never retry automatically or represent this as safely unsent.
                report($error);
                $reason = $error instanceof HttpExceptionInterface
                    ? trim($error->getMessage()) : '';
                $results[] = [
                    'order_id' => $id, 'order_no' => $fresh['order_no'],
                    'warehouse' => $fresh['warehouse'], 'ok' => false,
                    'status' => 'attention',
                    'message' => $reason ?: '发货状态未确认，请查看订单及同步回执，切勿直接重复提交',
                ];
            }
        }

        return response()->json([
            'total' => count($ids),
            'success_count' => count(array_filter($results, static fn (array $r): bool => $r['ok'])),
            'failed_count' => count(array_filter($results, static fn (array $r): bool => ! $r['ok'])),
            'results' => $results,
            'auto_retry' => false, 'woo_completed' => false,
        ]);
    }

    private function inspect(array $ids, CarrierServiceCatalog $catalog): array
    {
        return array_map(fn (int $id): array => $this->inspectOne($id, $catalog), $ids);
    }

    private function inspectOne(int $id, CarrierServiceCatalog $catalog): array
    {
        $order = Order::query()->with(['shipment.warehouse', 'shipment.overseaReview', 'externalReferences'])
            ->find($id);
        $shipment = $order?->shipment;
        $warehouse = OverseaWarehouseReviewService::canonicalWarehouseCode($shipment?->warehouse?->code);
        $tracking = trim((string) $shipment?->tracking_number);
        $carrier = trim((string) $shipment?->carrier);
        $service = trim((string) $shipment?->service);
        $review = $shipment?->overseaReview;
        $message = null;

        if (! $order || ! $shipment) {
            $message = '订单或物流单不存在';
        } elseif ($warehouse === 'US-LA') {
            $message = 'MorePlus 必须使用 OMS/真实出库单专属流程，禁止普通批量发货';
        } elseif (! in_array($warehouse, ['US-NY', 'DE', 'CA', 'US-SM'], true)) {
            $message = '该仓库不支持普通批量确认发货';
        } elseif ($order->trashed() || $order->archived_at !== null || $order->isOperationallyArchived()
            || $order->order_status?->value !== 'pending_shipment'
            || $shipment->shipment_status !== 'pending' || $shipment->shipped_at !== null) {
            $message = '订单已发货、已归档或不在待发货状态';
        } elseif ($order->fulfillmentChildren()->exists()
            || $order->externalReferences->contains('provider', 'historical_logistics')) {
            $message = '拆分或历史物流订单须单独核对';
        } elseif ($review?->status !== 'approved'
            || (int) $review->reviewed_warehouse_id !== (int) $shipment->warehouse_id) {
            $message = '当前仓库尚未审核通过';
        } elseif ($tracking === '' || strlen($tracking) > 128
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/+\-]*\z/D', $tracking) !== 1) {
            $message = '缺少有效的真实 Tracking，请先填写并保存';
        } elseif (Shipment::query()->where('tracking_number', $tracking)->where('order_id', '!=', $id)->exists()) {
            $message = 'Tracking 已与其他订单关联，禁止重复发货';
        } elseif ($carrier === '' || ($warehouse !== 'US-SM' && $service === '')) {
            $message = '请先保存物流商与服务渠道';
        } elseif ($warehouse !== 'US-SM' && ! $catalog->activeServices($carrier)
            ->contains('service_value', $service)) {
            $message = '物流商或服务渠道无效';
        }

        $fingerprint = hash('sha256', implode('|', [
            $order?->updated_at?->toISOString(), $order?->order_status?->value,
            $order?->archived_at, $order?->operational_archived_at,
            $shipment?->updated_at?->toISOString(), $shipment?->shipment_status,
            $shipment?->warehouse_id, $shipment?->shipped_at,
            $carrier, $service, $tracking,
            $review?->updated_at?->toISOString(), $review?->status, $review?->reviewed_warehouse_id,
        ]));

        return [
            'order_id' => $id,
            'order_no' => (string) ($order?->erp_order_id ?? $id),
            'warehouse' => $warehouse,
            'tracking_number' => $tracking,
            'eligible' => $message === null,
            'message' => $message ?? '预检通过（请人工确认仓库确已真实出库）',
            'fingerprint' => $fingerprint,
        ];
    }

    private function previewCacheKey(int $actorId, string $token): string
    {
        return 'oversea:bulk-confirm-shipment:'.$actorId.':'.$token;
    }
}

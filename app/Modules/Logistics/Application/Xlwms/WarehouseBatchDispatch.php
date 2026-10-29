<?php

namespace App\Modules\Logistics\Application\Xlwms;

use App\Modules\Logistics\Application\ShipmentService;
use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseOpenApiConnection;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsDispatchAttempt;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsOrderGateway;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Support\Facades\DB;

final class WarehouseBatchDispatch
{
    private const MAX_PER_CLICK = 25;
    private const WAREHOUSE_CODE = 'US-SM';

    public function __construct(
        private readonly SingleOrderPreview $preview,
        private readonly XlwmsOrderGateway $oms,
        private readonly ShipmentService $shipments,
    ) {}

    /** @return array{count:int,results:list<array{order_id:int,code:string,reason?:string}>} */
    public function run(int $actorId): array
    {
        $warehouse = \App\Modules\Inventory\Infrastructure\Models\Warehouse::query()->where('code', self::WAREHOUSE_CODE)->first();
        if (! $warehouse) return ['count' => 0, 'results' => []];
        $query = Order::query()->where('order_status', 'pending_shipment')
            ->whereHas('shipment', fn ($q) => $q->where('warehouse_id', $warehouse->id)->where('shipment_status', 'pending'))
            ->whereHas('shipment.overseaReview', fn ($q) => $q->where('status', 'approved')->where('reviewed_warehouse_id', $warehouse->id))
            ->with(['address', 'items', 'shipment.warehouse', 'shipment.carrierService', 'shipment.overseaReview'])
            ->orderBy('id');
        // In an approved single-order Canary, select ONLY that order. Without
        // this filter, oldest-25 pagination can omit the authorized order.
        $canaryId = (int) config('xlwms.live_order_id', 0);
        if ((bool) config('xlwms.warehouse_batch_dispatch_enabled', false)
            && (bool) config('xlwms.live_dispatch_enabled', false) && $canaryId > 0) {
            $query->whereKey($canaryId);
        }
        $orders = $query->limit(self::MAX_PER_CLICK)->get();
        $results = [];
        foreach ($orders as $order) $results[] = $this->one($order, (int) $warehouse->id, $actorId);
        return ['count' => count($results), 'results' => $results];
    }

    /** @return array{order_id:int,code:string,reason:string} */
    private function one(Order $order, int $warehouseId, int $actorId): array
    {
        $orderId = (int) $order->id;
        try {
            // Fresh stock is a create gate, never a read-only status gate.
            $preview = $this->preview->forOrder($order, false);
        } catch (\Throwable) {
            $preview = ['blockers' => ['preflight_unavailable'], 'oms_reference' => 'BYL-ERP-'.$orderId, 'payload_hash' => hash('sha256', 'BYL-ERP-'.$orderId), 'payload' => []];
        }
        $third = (string) ($preview['oms_reference'] ?? 'BYL-ERP-'.$orderId);
        $fingerprint = (string) ($preview['payload_hash'] ?? hash('sha256', $third));
        [$attempt, $claimed] = $this->claim($orderId, $warehouseId, $third, $fingerprint, $actorId);

        // Only the first claimant may continue. A concurrent/double click can
        // never pass through this guard into another provider create.
        if (! $claimed && $attempt->state === 'batch_processing') {
            return $this->result($orderId, 'in_progress', '已有同步请求正在处理');
        }

        $finish = function (string $state, string $code, string $reason, array $extra = []) use ($attempt, $orderId): array {
            $attempt->forceFill(array_merge(['state' => $state, 'failure_code' => $code, 'last_checked_at' => now()], $extra))->save();
            return $this->result($orderId, $code, $reason);
        };
        $connection = WarehouseOpenApiConnection::query()->where('warehouse_id', $warehouseId)->where('provider', 'xlwms')->first();
        if (! $connection || blank($connection->oms_warehouse_code)) return $finish('batch_blocked', 'blocked', 'OMS 仓库未绑定');

        $payload = (array) ($preview['payload'] ?? []);
        $items = (array) ($payload['productList'] ?? []);
        $skus = array_values(array_filter(array_map('strval', array_column($items, 'sku'))));
        $platform = (string) ($payload['platformOrderNo'] ?? '');

        // Every attempt, including an uncertain prior create, gets only an
        // exact read-only duplicate lookup. An empty result never authorizes a
        // blind create after an ambiguous request.
        try {
            $existing = $this->oms->findExistingExact($connection, $third, $platform,
                [(string) ($payload['referOrderNo'] ?? ''), (string) $order->external_order_number], $items);
        } catch (\Throwable $e) {
            if ($attempt->oms_order_no || in_array($attempt->state, ['batch_uncertain', 'batch_request_in_flight', 'batch_created'], true)) {
                return $finish('batch_uncertain', 'uncertain', '历史建单结果未确认，查重失败，禁止自动重发');
            }
            return $finish('batch_error', $this->safeCode($e), 'OMS 只读查重失败，未重发');
        }
        $remoteNo = (string) ($existing[0]['outboundOrderNo'] ?? $existing[0]['orderNo'] ?? '');
        // A positive OMS identity hit without its native order number is not
        // absence. Never fall through to create a duplicate remote order.
        if ($existing !== [] && $remoteNo === '') {
            return $finish('batch_identity_blocked', 'missing_order_id', 'OMS 已有匹配订单但缺少出库单号，禁止重新创建');
        }
        if ($existing !== [] && $remoteNo !== '') {
            $attempt->forceFill(['state' => 'batch_existing', 'oms_order_no' => $remoteNo,
                'duplicate_match_field' => $existing[0]['_erp_match_field'] ?? null,
                'failure_code' => null, 'last_checked_at' => now()])->save();
            return $this->reconcileExisting($order, $attempt, $connection, $remoteNo, $third, $platform, $orderId, $finish);
        }
        if ($attempt->oms_order_no || in_array($attempt->state, ['batch_uncertain', 'batch_request_in_flight'], true)) {
            return $finish('batch_uncertain', 'uncertain', 'OMS 结果待核实，禁止自动重发');
        }

        // Creation-only switches and full V2 preflight are deliberately after
        // the safe exact OMS lookup so existing orders can be read back while
        // outbound creation is disabled.
        if (! $claimed) {
            return $finish('batch_blocked', 'blocked', '已存在同步记录，须人工核对后才能重新建单');
        }
        if (! (bool) config('xlwms.warehouse_batch_dispatch_enabled', false)
            || ! (bool) config('xlwms.live_dispatch_enabled', false)) {
            return $finish('batch_blocked', 'create_disabled', '新建 OMS 出库已关闭');
        }
        if ((int) $order->shipment?->warehouse_id !== $warehouseId) return $finish('batch_blocked', 'blocked', '仓库身份不匹配');
        // During Canary, enabling the two broad switches is insufficient.
        // Every CREATE must match the exact independently Owner-approved order.
        if ((int) config('xlwms.live_order_id', 0) < 1 || (int) config('xlwms.live_order_id', 0) !== $orderId) {
            return $finish('batch_blocked', 'canary_order_not_authorized', '真实下发未获得当前单笔订单授权');
        }

        // Recompute the complete CREATE preflight after read-only lookup.
        // The first preflight intentionally skipped stock-freshness checks so
        // an existing OMS order could still be reconciled safely.
        try {
            $createPreview = $this->preview->forOrder($order, true);
        } catch (\Throwable) {
            return $finish('batch_blocked', 'preflight_blocked', '新建前校验不可用');
        }
        if (($createPreview['blockers'] ?? []) !== []) {
            return $finish('batch_blocked', 'preflight_blocked', implode('、', (array) $createPreview['blockers']));
        }
        if (! hash_equals($fingerprint, (string) ($createPreview['payload_hash'] ?? ''))) {
            return $finish('batch_blocked', 'preflight_blocked', '订单内容已变化，请重新核对');
        }
        $payload = (array) ($createPreview['payload'] ?? []);
        $skus = array_values(array_filter(array_map('strval', array_column((array) ($payload['productList'] ?? []), 'sku'))));

        try {
            $this->oms->assertCatalogReady($connection, (string) $payload['whCode'], (string) $payload['logisticsChannel'], $skus);
            // Final local transition immediately before the sole outbound write.
            $attempt->forceFill(['state' => 'batch_request_in_flight', 'last_checked_at' => now()])->save();
            $created = $this->oms->createSingle($connection, $payload);
            $attempt->forceFill(['state' => 'batch_created', 'oms_order_no' => $created['order_no'], 'failure_code' => null, 'last_checked_at' => now()])->save();
            return $this->result($orderId, 'created', '已创建 OMS 出库单');
        } catch (\Throwable $e) {
            $uncertain = $attempt->state === 'batch_request_in_flight';
            return $finish($uncertain ? 'batch_uncertain' : 'batch_error', $uncertain ? 'uncertain' : 'error', $uncertain ? 'OMS 创建结果待核实，禁止重发' : 'OMS 创建未完成');
        }
    }

    /** @return array{0:XlwmsDispatchAttempt,1:bool} */
    private function claim(int $orderId, int $warehouseId, string $third, string $fingerprint, int $actorId): array
    {
        return DB::transaction(function () use ($orderId, $warehouseId, $third, $fingerprint, $actorId): array {
            $existing = XlwmsDispatchAttempt::query()->where('order_id', $orderId)->lockForUpdate()->first();
            if ($existing) {
                // Only a proven PRE-CREATE local block may be re-claimed.
                // An uncertain create, OMS identity conflict or prior known
                // OMS order must never be eligible for a second provider write.
                $safeLocalBlock = $existing->state === 'batch_blocked'
                    && ! filled($existing->oms_order_no)
                    && in_array($existing->failure_code, ['blocked', 'create_disabled', 'preflight_blocked', 'canary_order_not_authorized'], true)
                    && (int) $existing->warehouse_id === $warehouseId
                    && $existing->third_order_no === $third;
                if ($safeLocalBlock) {
                    $existing->forceFill([
                        'state' => 'batch_processing',
                        'request_fingerprint' => $fingerprint,
                        'failure_code' => null,
                        'requested_by' => $actorId,
                    ])->save();
                    return [$existing, true];
                }
                return [$existing, false];
            }
            return [XlwmsDispatchAttempt::query()->create(['order_id' => $orderId, 'warehouse_id' => $warehouseId, 'third_order_no' => $third,
                'state' => 'batch_processing', 'request_fingerprint' => $fingerprint, 'requested_by' => $actorId]), true];
        });
    }

    private function reconcileExisting(Order $order, XlwmsDispatchAttempt $attempt, WarehouseOpenApiConnection $connection, string $remoteNo, string $third, string $platform, int $orderId, \Closure $finish): array
    {
        try {
            $status = $this->oms->readExactOrderStatus($connection, $remoteNo);
            if (! $status) return $finish('batch_existing', 'not_found', 'OMS 出库单暂未找到');
            if ($status['third_order_no'] !== $third || $status['platform_order_no'] !== $platform) return $finish('batch_blocked', 'identity_mismatch', 'OMS 订单身份不匹配，未更新 ERP');
            $attempt->forceFill(['oms_status_code' => $status['status_code'], 'oms_status_checked_at' => now(), 'oms_tracking_no' => $status['tracking_no'], 'oms_status_failure_code' => null])->save();
            if ($status['status_code'] === '3' && filled($status['tracking_no'])) {
                // Auto-lowest-price may choose a carrier other than the ERP's
                // preselected USPS. OMS status endpoint does NOT prove the
                // actual last-mile carrier, so never write misleading carrier
                // and Tracking to a shipped ERP/Woo record automatically.
                if ((string) config('xlwms.us_sm_oms_logistics_channel', '') === 'Service with the lowest price') {
                    return $this->result($orderId, 'carrier_verification_required', 'OMS 已出库并有单号，请核实实际承运商后再确认 ERP 发货');
                }
                $this->shipments->markShipped($order, ['tracking_number' => $status['tracking_no'], 'shipped_at' => now()], null);
                $attempt->forceFill(['state' => 'batch_status_updated'])->save();
                return $this->result($orderId, 'status_updated', 'OMS 已出库，Tracking 已安全回写 ERP');
            }
            if ($status['status_code'] === '4') return $this->result($orderId, 'canceled', 'OMS 已取消，未标记发货');
            return $this->result($orderId, 'unknown_status', 'OMS 状态未确认，未标记发货');
        } catch (\Throwable $e) {
            return $finish('batch_blocked', $this->safeCode($e), 'OMS 状态核对失败，未更新 ERP');
        }
    }

    private function safeCode(\Throwable $e): string
    {
        return preg_match('/^[A-Z0-9_]+$/', $e->getMessage()) ? $e->getMessage() : 'OMS_STATUS_UNEXPECTED_ERROR';
    }

    /** @return array{order_id:int,code:string,reason:string} */
    private function result(int $orderId, string $code, string $reason): array
    {
        return ['order_id' => $orderId, 'code' => $code, 'reason' => $reason];
    }
}

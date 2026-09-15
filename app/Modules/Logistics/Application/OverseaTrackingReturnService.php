<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Infrastructure\Models\Carrier;
use App\Modules\Logistics\Infrastructure\Models\OverseaWarehouseReview;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Sync\Application\WooCommerceTrackingOutboundService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

final class OverseaTrackingReturnService
{
    private const int MAX_ROWS = 500;

    public function __construct(
        private readonly ShipmentService $shipments,
        private readonly WooCommerceTrackingOutboundService $trackingOutbound,
    ) {}

    /** @return array<string,mixed> */
    public function preview(string $warehouseKey, UploadedFile $file): array
    {
        $rows = $this->readCsv($file);
        abort_if($rows === [], 422, '单号表没有可处理的数据行。');
        abort_if(count($rows) > self::MAX_ROWS, 422, '单次最多上传 500 行。');

        $normalized = $this->normalizeRows($rows);
        $plan = $this->plan($warehouseKey, $normalized);
        $token = hash('sha256', json_encode([$warehouseKey, $normalized], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [...$plan, 'token' => $token, 'normalized_rows' => $normalized];
    }

    /** @param array<string,mixed> $stored @return array<string,mixed> */
    public function apply(string $warehouseKey, array $stored, string $token, Authenticatable $actor): array
    {
        abort_unless(isset($stored['token'], $stored['normalized_rows']) && hash_equals((string) $stored['token'], $token), 409, '上传预览已失效，请重新上传。');
        $plan = $this->plan($warehouseKey, (array) $stored['normalized_rows']);
        abort_if((int) $plan['blocked_count'] > 0, 409, '当前数据已变化或预览仍有阻断项，请重新预览。');

        $applyRows = collect($plan['rows'])->where('status', 'apply')->values();
        $appliedIds = [];
        DB::transaction(function () use ($applyRows, $actor, &$appliedIds): void {
            foreach ($applyRows as $row) {
                $order = Order::query()->with(['shipment.overseaReview'])->findOrFail((int) $row['order_id']);
                $shipment = $order->shipment;
                $this->shipments->markShipped($order, [
                    'warehouse_id' => $shipment?->warehouse_id,
                    'carrier' => $row['carrier'],
                    'service' => $shipment?->service,
                    'tracking_number' => $row['tracking_number'],
                    'shipped_at' => $row['shipped_at'],
                    'logistics_note' => $shipment?->logistics_note,
                ], $actor);
                OverseaWarehouseReview::query()->where('order_id', $order->id)->update(['progress' => 'shipped']);
                $appliedIds[] = (int) $order->id;
            }
        });

        $sync = [];
        foreach ($appliedIds as $orderId) {
            $order = Order::query()->with(['shipment', 'items', 'externalReferences', 'fulfillmentParent.externalReferences'])->findOrFail($orderId);
            if (! $this->hasWooRoot($order)) {
                continue;
            }
            try {
                $result = $this->trackingOutbound->pushOne($order, $actor);
                $sync[] = ['order_id' => $orderId, 'status' => (string) ($result['status'] ?? 'failed')];
            } catch (DomainException $e) {
                $sync[] = ['order_id' => $orderId, 'status' => 'blocked', 'message' => $e->getMessage()];
            } catch (Throwable $e) {
                $sync[] = ['order_id' => $orderId, 'status' => 'failed', 'message' => $e->getMessage()];
            }
        }

        return [
            'applied_count' => count($appliedIds),
            'noop_count' => (int) $plan['noop_count'],
            'sync' => $sync,
        ];
    }

    /** @param list<array<string,mixed>> $normalized @return array<string,mixed> */
    private function plan(string $warehouseKey, array $normalized): array
    {
        $code = OverseaWarehouseReviewService::WAREHOUSE_CODE_BY_KEY[$warehouseKey] ?? null;
        abort_unless($code, 404, '未知海外仓。');
        $warehouse = Warehouse::query()->where('code', $code)->where('is_active', true)->firstOrFail();
        $activeCarriers = Carrier::query()->where('is_active', true)->pluck('name')->mapWithKeys(fn ($name) => [mb_strtolower(trim((string) $name)) => (string) $name]);
        $orders = Order::query()->with(['shipment.overseaReview'])
            ->whereIn('erp_order_id', collect($normalized)->pluck('order_number')->filter()->unique()->all())
            ->get()->keyBy('erp_order_id');

        $seen = [];
        $planned = [];
        foreach ($normalized as $row) {
            $number = (string) ($row['order_number'] ?? '');
            $tracking = (string) ($row['tracking_number'] ?? '');
            $rawShippedAt = (string) ($row['shipped_at'] ?? '');
            $carrierInput = trim((string) ($row['carrier'] ?? ''));
            $messages = [];

            if ($number === '') {
                $messages[] = '订单号为空';
            }
            if ($tracking === '' || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/+\-]{0,127}\z/', $tracking) !== 1) {
                $messages[] = 'Tracking No. 无效';
            }
            if ($rawShippedAt === '' || preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', $rawShippedAt) !== 1) {
                $messages[] = '发货时间必须包含真实时区偏移，例如 2026-09-15T10:30:00-07:00';
            }
            if ($number !== '' && isset($seen[$number])) {
                $messages[] = '同一文件订单号重复';
            }
            $seen[$number] = true;

            $order = $orders->get($number);
            $shipment = $order?->shipment;
            $review = $shipment?->overseaReview;
            if (! $order) {
                $messages[] = 'ERP 找不到该订单号';
            } elseif (! $shipment) {
                $messages[] = '订单没有 Shipment';
            } else {
                if ((int) $shipment->warehouse_id !== (int) $warehouse->id) {
                    $messages[] = '订单不属于当前海外仓';
                }
                if ($review?->status !== 'approved' || (int) $review?->reviewed_warehouse_id !== (int) $warehouse->id) {
                    $messages[] = '订单尚未完成当前仓库审核';
                }
            }

            $carrier = $carrierInput !== '' ? $activeCarriers->get(mb_strtolower($carrierInput)) : ($shipment?->carrier ?: (OverseaWarehouseReviewService::CARRIER_BY_CODE[$code] ?? null));
            if (! $carrier) {
                $messages[] = '承运商为空或未启用';
            }
            if ($carrierInput !== '' && ! $activeCarriers->has(mb_strtolower($carrierInput))) {
                $messages[] = '文件中的承运商未启用';
            }

            $shippedAt = null;
            if ($rawShippedAt !== '' && $messages === []) {
                try {
                    $shippedAt = CarbonImmutable::parse($rawShippedAt)->utc()->toIso8601String();
                } catch (Throwable) {
                    $messages[] = '发货时间无法解析';
                }
            }

            if ($shipment && $shipment->shipment_status === 'pending' && filled($shipment->tracking_number) && $shipment->tracking_number !== $tracking) {
                $messages[] = 'ERP 已有不同 Tracking，禁止覆盖';
            }

            $status = 'blocked';
            if ($messages === [] && $shipment) {
                if ($shipment->shipment_status === 'pending' && $order?->order_status?->value === 'pending_shipment') {
                    $status = 'apply';
                } elseif ($shipment->shipment_status === 'shipped') {
                    $sameTime = $shipment->shipped_at && CarbonImmutable::parse($shipment->shipped_at)->equalTo(CarbonImmutable::parse($shippedAt));
                    $sameCarrier = mb_strtolower((string) $shipment->carrier) === mb_strtolower((string) $carrier);
                    $status = $shipment->tracking_number === $tracking && $sameCarrier && $sameTime ? 'noop' : 'blocked';
                    if ($status === 'blocked') {
                        $messages[] = '订单已经发货且现有履约事实与文件不一致';
                    }
                } else {
                    $messages[] = 'Shipment 当前状态不可由回传表发货';
                }
            }

            $planned[] = [
                'row' => (int) $row['row'],
                'order_id' => $order?->id,
                'order_number' => $number,
                'tracking_number' => $tracking,
                'carrier' => $carrier,
                'shipped_at' => $shippedAt ?: $rawShippedAt,
                'status' => $status,
                'message' => implode('；', $messages),
            ];
        }

        return [
            'rows' => $planned,
            'apply_count' => collect($planned)->where('status', 'apply')->count(),
            'noop_count' => collect($planned)->where('status', 'noop')->count(),
            'blocked_count' => collect($planned)->where('status', 'blocked')->count(),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function readCsv(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'rb');
        abort_unless($handle !== false, 422, '无法读取单号表。');
        try {
            $headers = fgetcsv($handle) ?: [];
            if (isset($headers[0])) {
                $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
            }
            $map = $this->headerMap($headers);
            foreach (['order_number', 'tracking_number', 'shipped_at'] as $required) {
                abort_unless(isset($map[$required]), 422, '单号表缺少必填列：订单号、Tracking No.、发货时间(ISO8601含时区)。');
            }
            $rows = [];
            $rowNo = 2;
            while (($cells = fgetcsv($handle)) !== false) {
                if (collect($cells)->filter(fn ($value) => trim((string) $value) !== '')->isEmpty()) {
                    $rowNo++;

                    continue;
                }
                $rows[] = ['row' => $rowNo, 'cells' => $cells, 'map' => $map];
                $rowNo++;
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function normalizeRows(array $rows): array
    {
        return collect($rows)->map(function (array $row): array {
            $cells = $row['cells'];
            $map = $row['map'];
            $get = fn (string $key): string => isset($map[$key]) ? trim((string) ($cells[$map[$key]] ?? '')) : '';

            return [
                'row' => (int) $row['row'],
                'order_number' => $get('order_number'),
                'tracking_number' => $get('tracking_number'),
                'shipped_at' => $get('shipped_at'),
                'carrier' => $get('carrier'),
            ];
        })->all();
    }

    /** @return array<string,int> */
    private function headerMap(array $headers): array
    {
        $map = [];
        foreach ($headers as $i => $header) {
            $n = mb_strtolower(trim((string) $header));
            if (in_array($n, ['订单号', 'erp订单号', 'order number', 'order no.', 'order no'], true)) {
                $map['order_number'] = $i;
            } elseif (in_array($n, ['tracking no.', 'tracking no', 'tracking number', 'tracking', '追踪单号', '物流单号'], true)) {
                $map['tracking_number'] = $i;
            } elseif (str_contains($n, '发货时间') || in_array($n, ['shipped_at', 'shipped at'], true)) {
                $map['shipped_at'] = $i;
            } elseif (in_array($n, ['carrier', '承运商', '物流商'], true)) {
                $map['carrier'] = $i;
            }
        }

        return $map;
    }

    private function hasWooRoot(Order $order): bool
    {
        $root = $order->fulfillment_parent_id ? $order->fulfillmentParent : $order;
        $source = $root?->source_type instanceof \BackedEnum ? $root->source_type->value : (string) $root?->source_type;

        return $source === 'woocommerce';
    }
}

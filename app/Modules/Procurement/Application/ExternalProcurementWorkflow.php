<?php

namespace App\Modules\Procurement\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Application\Domestic\DomesticReviewDecisionService;
use App\Modules\NotificationCenter\Application\NotificationPublisher;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Procurement\Infrastructure\Models\ProcurementOrder;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * An external purchase is a fulfillment gate, NOT a stock-holding warehouse.
 * The actual customer shipment is always from FACTORY after Vita release.
 */
final class ExternalProcurementWorkflow
{
    public const SOURCE_CODE = 'OUTSOURCE';

    public function __construct(
        private readonly DomesticReviewDecisionService $domestic,
        private readonly AuditRecorder $audit,
        private readonly NotificationPublisher $notifications,
    ) {}

    public function createForAllocatedOrder(Order $order, Authenticatable $actor): ProcurementOrder
    {
        $order->loadMissing('items', 'shipment.warehouse');
        abort_unless($order->order_status?->value === 'pending_shipment'
            && $order->shipment?->shipment_status === 'pending'
            && strtoupper((string) $order->shipment?->warehouse?->code) === 'FACTORY'
            && $order->items->isNotEmpty(), 409, '外采只允许尚未发货的中国工厂履约订单');

        $existing = ProcurementOrder::query()->where('source_order_id', $order->getKey())->first();
        if ($existing) {
            abort_unless($existing->origin === 'external'
                && $existing->received_at === null && $existing->released_at === null
                && in_array($existing->status, ['pending', 'cancelled'], true), 409, '已有采购任务正在执行，禁止覆盖或重复创建');
            $existing->items()->delete();
            $existing->forceFill(['status' => 'pending', 'completion_reason' => null,
                'completed_at' => null, 'status_updated_at' => now()])->save();
            $procurement = $existing;
        } else {
            // Keep the existing date-based purchasing number contract.
            $date = now()->format('Ymd');
            $last = ProcurementOrder::query()->where('procurement_number', 'like', "PO-{$date}-%")
                ->lockForUpdate()->orderByDesc('procurement_number')->value('procurement_number');
            $next = $last ? ((int) substr($last, -4)) + 1 : 1;
            $procurement = ProcurementOrder::query()->create([
                'procurement_number' => sprintf('PO-%s-%04d', $date, $next),
                'source_order_id' => $order->getKey(),
                'origin' => 'external',
                'status' => 'pending',
                'created_by' => $actor->getAuthIdentifier(),
                'status_updated_at' => now(),
            ]);
        }
        foreach ($order->items as $item) {
            $procurement->items()->create([
                'source_order_item_id' => $item->id,
                'product_id' => $item->product_id,
                'sku' => $item->sku,
                'product_summary' => $item->product_summary,
                'source_quantity' => (int) $item->quantity,
                'purchase_quantity' => (int) $item->quantity,
            ]);
        }
        $this->audit->record(
            AuditActor::user($actor, AuditSource::InternalSystem),
            'procurement.external_fulfillment_created',
            ProcurementOrder::class, $procurement->id,
            after: ['source_order_id' => $order->id, 'warehouse' => 'FACTORY',
                'item_ids' => $procurement->items->pluck('source_order_item_id')->all()],
            reason: 'vita_selected_external_procurement',
        );
        $this->notifications->publishToPermission('procurement.operate', [
            'category' => 'procurement',
            'title' => '外采待处理',
            'message' => "订单 {$order->erp_order_id} 已转外采 {$procurement->procurement_number}，请 Zhang 跟进。",
            'action_url' => '/purchase/external',
            'entity_type' => 'procurement_order',
            'entity_id' => $procurement->id,
            'actor_name' => $actor->name ?? 'Vita',
        ], (int) $actor->getAuthIdentifier());

        return $procurement;
    }

    /** Zhang manages purchasing; only the explicit received step confirms real delivery evidence. */
    public function updateProgress(ProcurementOrder $procurement, string $next, array $data, Authenticatable $actor): void
    {
        DB::transaction(function () use ($procurement, $next, $data, $actor): void {
            $locked = ProcurementOrder::query()->with(['items', 'sourceOrder.shipment'])->whereKey($procurement->id)
                ->lockForUpdate()->firstOrFail();
            abort_unless($locked->origin === 'external' && $locked->released_at === null
                && $locked->sourceOrder?->order_status?->value === 'pending_shipment'
                && $locked->sourceOrder?->shipment?->shipment_status === 'pending', 409, '外采已释放或订单已发货，不能修改采购进度');
            $allowed = match ($locked->status) {
                'pending' => ['pending', 'purchasing', 'ordered'],
                'purchasing' => ['purchasing', 'ordered'],
                'ordered' => ['ordered', 'arrived'],
                'arrived' => ['arrived'],
                default => [],
            };
            abort_unless(in_array($next, $allowed, true), 409, '采购状态流转无效，请按照采购中→已下单→已到货操作');

            foreach ($locked->items as $item) {
                $row = collect($data['items'] ?? [])->keyBy('id')->get($item->id);
                if (! is_array($row)) {
                    continue;
                }
                $purchaseQty = (int) ($row['purchase_quantity'] ?? $item->purchase_quantity);
                abort_unless($purchaseQty >= (int) $item->source_quantity, 422, '外采数量不能低于客户订单所需数量');
                $item->forceFill([
                    'purchase_url' => trim((string) ($row['purchase_url'] ?? $item->purchase_url)) ?: null,
                    'purchase_size' => trim((string) ($row['purchase_size'] ?? $item->purchase_size)) ?: null,
                    'purchase_quantity' => $purchaseQty,
                ])->save();
            }

            if ($next === 'arrived') {
                abort_unless(mb_strlen(trim((string) ($data['receipt_note'] ?? ''))) >= 4, 422, '实际到货必须填写工厂收货依据');
                $received = collect($data['items'] ?? [])->keyBy('id');
                abort_unless($received->count() === $locked->items->count(), 422, '必须逐一确认全部外采商品的实际 SKU 和到货数量');
                foreach ($locked->items as $item) {
                    $row = $received->get($item->id);
                    abort_unless(is_array($row)
                        && strcasecmp(trim((string) ($row['received_sku'] ?? '')), trim((string) $item->sku)) === 0
                        && (int) ($row['received_quantity'] ?? 0) >= (int) $item->source_quantity, 422,
                        "到货 SKU / 数量与 {$item->sku} 不一致，请核对采购实物");
                    $item->forceFill([
                        'received_sku' => trim((string) $row['received_sku']),
                        'received_quantity' => (int) $row['received_quantity'],
                    ])->save();
                }
                $locked->received_at = now();
                $locked->received_by = $actor->getAuthIdentifier();
                $locked->receipt_note = trim((string) $data['receipt_note']);
            }
            $locked->status = $next;
            $locked->carrier = trim((string) ($data['carrier'] ?? $locked->carrier)) ?: null;
            $locked->tracking_number = trim((string) ($data['tracking_number'] ?? $locked->tracking_number)) ?: null;
            $locked->updated_by = $actor->getAuthIdentifier();
            $locked->status_updated_at = now();
            $locked->save();
            $this->audit->record(
                AuditActor::user($actor, AuditSource::InternalSystem), 'procurement.external_progress_updated',
                ProcurementOrder::class, $locked->id,
                after: ['status' => $next, 'received_at' => $locked->received_at?->toIso8601String(),
                    'receipt_note' => $locked->receipt_note], reason: 'zhang_procurement_progress',
            );
            if ($next === 'arrived') {
                $this->notifications->publishToPermission('domestic.review', [
                    'category' => 'procurement',
                    'title' => '外采已到货，待 Vita 放行',
                    'message' => "外采单 {$locked->procurement_number} 已登记实际到货，请 Vita 核对放行。",
                    'action_url' => '/purchase/external',
                    'entity_type' => 'procurement_order', 'entity_id' => $locked->id,
                    'actor_name' => $actor->name ?? 'Zhang',
                ], (int) $actor->getAuthIdentifier());
            }
        });
    }

    /** Only Vita / operations (orders.edit + domestic.review) can bypass Kaven's step. */
    public function release(ProcurementOrder $procurement, Authenticatable $actor): void
    {
        abort_unless($actor->can('orders.edit') && $actor->can('domestic.review'), 403, '仅 Vita 运营可将已到货外采单释放到国内待发货');
        DB::transaction(function () use ($procurement, $actor): void {
            $locked = ProcurementOrder::query()->with(['items', 'sourceOrder.items', 'sourceOrder.shipment.warehouse'])
                ->whereKey($procurement->id)->lockForUpdate()->firstOrFail();
            $order = $locked->sourceOrder;
            abort_unless($locked->origin === 'external' && $locked->status === 'arrived'
                && $locked->received_at !== null && $locked->released_at === null
                && $order && ! $order->isOperationallyArchived()
                && $order->order_status?->value === 'pending_shipment'
                && $order->shipment?->shipment_status === 'pending'
                && blank($order->shipment?->tracking_number)
                && strtoupper((string) $order->shipment?->warehouse?->code) === 'FACTORY',
                409, '外采未确认全部到货或该订单已经离开待发货状态');
            abort_unless($locked->items->count() === $order->items->count(), 409, '外采明细与履约商品行数不一致');
            foreach ($locked->items as $item) {
                $fulfillmentItem = $order->items->firstWhere('id', $item->source_order_item_id);
                abort_unless($fulfillmentItem
                    && strcasecmp(trim((string) $item->received_sku), trim((string) $fulfillmentItem->sku)) === 0
                    && (int) $item->received_quantity >= (int) $fulfillmentItem->quantity,
                    409, '收货 SKU / 数量与最终履约商品不一致，不能放行');
            }
            // Reuse the existing audited Kaven approval writes but Vita is the
            // authorized actor and release also requires independently logged receipt.
            // Set the release flag inside the same transaction so the ordinary
            // review service's anti-bypass gate sees an authorized transition.
            $locked->forceFill([
                'status' => 'released', 'released_at' => now(),
                'released_by' => $actor->getAuthIdentifier(),
                'updated_by' => $actor->getAuthIdentifier(), 'status_updated_at' => now(),
            ])->save();
            $this->domestic->decide($order, 'approve', null, $actor);
            $this->audit->record(AuditActor::user($actor, AuditSource::InternalSystem),
                'procurement.external_released_to_domestic', ProcurementOrder::class, $locked->id,
                after: ['source_order_id' => $order->id, 'status' => 'released',
                    'received_at' => $locked->received_at->toIso8601String()],
                reason: 'vita_verified_receipt_bypassed_kaven_review',
            );
        });
    }
}

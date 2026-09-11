<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Infrastructure\Models\FulfillmentAllocation;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

final class FulfillmentAllocationSubmissionService
{
    private const ALLOWED_SOURCE_CODES = ['FACTORY', 'US-NY', 'US-LA', 'US-SJ', 'DE', 'CA'];

    public function __construct(
        private readonly WarehouseRecommendationService $recommendations,
        private readonly FulfillmentPlanPreviewService $plans,
        private readonly FulfillmentAllocationDecisionService $decisions,
        private readonly FulfillmentDerivedOrderService $derivedOrders,
    ) {}

    public function submitRecommended(Order $order, string $planKey, ?string $note, Authenticatable $actor): FulfillmentAllocation
    {
        return DB::transaction(function () use ($order, $planKey, $note, $actor): FulfillmentAllocation {
            $locked = Order::query()->with(['address', 'items.product', 'fulfillmentAllocation'])->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($locked->order_status?->value === 'pending_shipment', 409, '只有待发货订单可以提交分仓');

            $presented = $this->decisionFor($locked);
            abort_unless((bool) ($presented['can_use_recommendation'] ?? false), 409, '当前数据不足，必须由 Vita 人工判断');
            abort_unless(hash_equals((string) ($presented['recommended_plan_key'] ?? ''), trim($planKey)), 409, '系统推荐方案已变化，请刷新后重新确认');

            return $this->persistAndMaterialize($locked, [
                'decision_source' => 'system_recommendation',
                'recommended_plan_key' => $presented['recommended_plan_key'],
                'selected_plan_key' => $presented['recommended_plan_key'],
                'plan_snapshot' => $presented['recommended_plan'],
                'manual_assignments' => null,
                'decision_note' => $this->nullableNote($note),
            ], $actor);
        });
    }

    /** @param list<array{order_item_id:int,source_code:string}> $assignments */
    public function submitManual(Order $order, array $assignments, ?string $note, Authenticatable $actor): FulfillmentAllocation
    {
        return DB::transaction(function () use ($order, $assignments, $note, $actor): FulfillmentAllocation {
            $locked = Order::query()->with(['address', 'items.product', 'fulfillmentAllocation'])->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($locked->order_status?->value === 'pending_shipment', 409, '只有待发货订单可以提交分仓');

            $normalized = collect($assignments)->map(fn (array $row): array => [
                'order_item_id' => (int) ($row['order_item_id'] ?? 0),
                'source_code' => strtoupper(trim((string) ($row['source_code'] ?? ''))),
            ])->values();
            abort_if($normalized->isEmpty(), 422, '请为每个 SKU 选择发货仓库');
            abort_if($normalized->contains(fn (array $row): bool => $row['order_item_id'] <= 0 || ! in_array($row['source_code'], self::ALLOWED_SOURCE_CODES, true)), 422, '人工分仓包含无效仓库');
            abort_if($normalized->pluck('order_item_id')->duplicates()->isNotEmpty(), 422, '同一个订单商品不能拆数量到多个仓库');

            $orderItemIds = $locked->items->pluck('id')->map(fn ($id) => (int) $id)->sort()->values();
            abort_unless($normalized->pluck('order_item_id')->sort()->values()->all() === $orderItemIds->all(), 422, '必须为订单中的每个 SKU 完成分仓');

            $sources = $normalized->pluck('source_code')->unique()->values();
            abort_if($sources->count() > 2, 422, 'V1 最多允许两个履约来源');
            if ($sources->count() === 2) {
                abort_unless($sources->contains('FACTORY'), 422, 'V1 拆单只允许一个海外仓 + 中国工厂');
            }

            $warehouseByCode = Warehouse::query()->where('is_active', true)->whereIn('code', self::ALLOWED_SOURCE_CODES)->get()->keyBy('code');
            foreach ($sources as $sourceCode) {
                abort_unless($warehouseByCode->has($sourceCode), 422, "发货仓 {$sourceCode} 尚未启用");
            }

            $segments = $sources->map(function (string $sourceCode) use ($normalized, $locked, $warehouseByCode): array {
                $warehouse = $warehouseByCode->get($sourceCode);
                $items = $normalized->where('source_code', $sourceCode)->map(function (array $assignment) use ($locked): array {
                    $item = $locked->items->firstWhere('id', $assignment['order_item_id']);
                    return [
                        'order_item_id' => (int) $item->id,
                        'product_id' => $item->product_id ? (int) $item->product_id : null,
                        'sku' => $item->sku,
                        'product_summary' => $item->product_summary,
                        'quantity' => (int) $item->quantity,
                    ];
                })->values()->all();
                return [
                    'source_type' => $sourceCode === 'FACTORY' ? 'factory' : 'warehouse',
                    'source_code' => $sourceCode,
                    'warehouse_id' => (int) $warehouse->id,
                    'warehouse_name' => (string) $warehouse->name,
                    'items' => $items,
                ];
            })->values()->all();

            $signature = collect($segments)->map(fn (array $segment): string => $segment['source_code'].'['.collect($segment['items'])->map(fn (array $item): string => $item['order_item_id'].'x'.$item['quantity'])->implode(',').']')->implode('|');
            $plan = [
                'plan_key' => 'manual:'.substr(hash('sha256', $signature), 0, 16),
                'plan_type' => count($segments) > 1 ? 'split' : ($segments[0]['source_type'] === 'factory' ? 'factory' : 'single'),
                'is_split' => count($segments) > 1,
                'shipment_count' => count($segments),
                'segments' => $segments,
                'cost' => ['status' => 'not_evaluated', 'amount' => null, 'currency' => null, 'source' => null],
                'label' => count($segments) > 1 ? '人工拆分发货' : ($segments[0]['source_type'] === 'factory' ? '人工整单中国工厂发货' : '人工整单海外仓发货'),
                'human_review_required' => true,
                'execution_status' => count($segments) > 1 ? 'pending_multi_leg_execution_gate' : 'pending_single_leg_bridge',
            ];
            $presented = $this->decisionFor($locked);

            return $this->persistAndMaterialize($locked, [
                'decision_source' => 'manual',
                'recommended_plan_key' => $presented['recommended_plan_key'] ?? null,
                'selected_plan_key' => $plan['plan_key'],
                'plan_snapshot' => $plan,
                'manual_assignments' => $normalized->all(),
                'decision_note' => $this->nullableNote($note),
            ], $actor);
        });
    }

    /** @return array<string,mixed> */
    private function decisionFor(Order $order): array
    {
        $items = $order->items->map(fn ($item): array => [
            'product_id' => $item->product_id,
            'quantity' => (int) $item->quantity,
            'sku' => $item->sku,
        ])->values()->all();
        $productIds = collect($items)->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $context = array_replace($this->recommendations->prepare($productIds), $this->plans->prepare($productIds));
        $preview = $this->plans->preview(
            $order->address?->country,
            $items,
            [
                'province_state' => $order->address?->province_state,
                'city' => $order->address?->city,
                'address_line_1' => $order->address?->address_line_1,
                'address_line_2' => $order->address?->address_line_2,
            ],
            [],
            $context,
        );

        return $this->decisions->present($order, $preview);
    }

    private function persistAndMaterialize(Order $order, array $attributes, Authenticatable $actor): FulfillmentAllocation
    {
        $allocation = $this->persist($order, $attributes, $actor);
        $plan = is_array($allocation->plan_snapshot) ? $allocation->plan_snapshot : [];
        $resultOrders = $this->derivedOrders->materialize(
            $order->fresh(['address', 'items.product', 'shipment.warehouse', 'fulfillmentChildren.shipment.warehouse']),
            $plan,
            $actor,
        );
        $plan['derived_orders'] = $resultOrders;
        $plan['execution_status'] = count($resultOrders) > 1 ? 'derived_orders_materialized' : 'single_order_assigned';
        $allocation->forceFill(['plan_snapshot' => $plan])->save();

        return $allocation->fresh();
    }

    private function persist(Order $order, array $attributes, Authenticatable $actor): FulfillmentAllocation
    {
        return FulfillmentAllocation::query()->updateOrCreate(['order_id' => $order->getKey()], [
            ...$attributes,
            'status' => 'submitted',
            'submitted_by' => $actor->getAuthIdentifier(),
            'submitted_at' => now(),
        ])->fresh();
    }

    private function nullableNote(?string $note): ?string
    {
        $value = trim((string) $note);
        return $value === '' ? null : $value;
    }
}

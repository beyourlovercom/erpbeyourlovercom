<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Infrastructure\Models\Carrier;
use App\Modules\Logistics\Infrastructure\Models\FulfillmentAllocation;
use App\Modules\Logistics\Infrastructure\Models\OverseaWarehouseReview;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Procurement\Application\ExternalProcurementWorkflow;
use App\Modules\Procurement\Infrastructure\Models\ProcurementOrder;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

final class FulfillmentAllocationSubmissionService
{
    private const ALLOWED_SOURCE_CODES = ['OUTSOURCE', 'FACTORY', 'US-NY', 'US-LA', 'US-SM', 'US-AMZ', 'DE', 'CA'];

    private const MAX_SEGMENTS = 26;

    public function __construct(
        private readonly WarehouseRecommendationService $recommendations,
        private readonly FulfillmentPlanPreviewService $plans,
        private readonly FulfillmentAllocationDecisionService $decisions,
        private readonly FulfillmentDerivedOrderService $derivedOrders,
        private readonly GiftSkuResolutionService $giftSkuResolutions,
        private readonly WarehousePhysicalSkuResolver $physicalSkus,
        private readonly UsFulfillmentZoneResolver $usZones,
        private readonly WarehouseRoutingPolicy $warehouseRouting,
        private readonly CarrierRoutingPolicy $carrierRouting,
        private readonly FulfillmentPaymentGate $paymentGate,
        private readonly PendingAllocationEntryPolicy $entryPolicy,
        private readonly FulfillmentAllocationStockGate $stockGate,
        private readonly ExternalProcurementWorkflow $externalProcurement,
    ) {}

    public function submitRecommended(Order $order, string $planKey, ?string $note, Authenticatable $actor): FulfillmentAllocation
    {
        return DB::transaction(function () use ($order, $planKey, $note, $actor): FulfillmentAllocation {
            $locked = Order::query()->with(['address', 'items.product', 'fulfillmentAllocation'])->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            abort_if($locked->isOperationallyArchived(), 409, '运营归档订单不可分仓。');
            $this->paymentGate->assertAllowed($locked);
            $this->entryPolicy->assertAllowed($locked);
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

    /** @param list<array{order_item_id:int,source_code:string,gift_mapping_id?:int|null,carrier?:string|null,service?:string|null}> $assignments */
    public function submitManual(Order $order, array $assignments, ?string $note, Authenticatable $actor, bool $stockExceptionAcknowledged = false): FulfillmentAllocation
    {
        return DB::transaction(function () use ($order, $assignments, $note, $actor, $stockExceptionAcknowledged): FulfillmentAllocation {
            $locked = Order::query()->with(['address', 'items.product', 'fulfillmentAllocation'])->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            abort_if($locked->isOperationallyArchived(), 409, '运营归档订单不可分仓。');
            $this->paymentGate->assertAllowed($locked);
            $this->entryPolicy->assertAllowed($locked);
            abort_unless($locked->order_status?->value === 'pending_shipment', 409, '只有待发货订单可以提交分仓');
            $this->assertExternalProcurementMayReallocate($locked);

            $normalized = collect($assignments)->map(fn (array $row): array => [
                'order_item_id' => (int) ($row['order_item_id'] ?? 0),
                'source_code' => strtoupper(trim((string) ($row['source_code'] ?? ''))) === ExternalProcurementWorkflow::SOURCE_CODE
                    ? ExternalProcurementWorkflow::SOURCE_CODE
                    : ($this->warehouseRouting->canonicalWarehouseCode((string) ($row['source_code'] ?? '')) ?? ''),
                'gift_mapping_id' => isset($row['gift_mapping_id']) && $row['gift_mapping_id'] !== '' ? (int) $row['gift_mapping_id'] : null,
                'carrier' => trim((string) ($row['carrier'] ?? '')),
                'service' => isset($row['service']) && trim((string) $row['service']) !== '' ? trim((string) $row['service']) : null,
            ])->values();
            abort_if($normalized->isEmpty(), 422, '请为每个 SKU 选择发货仓库');
            abort_if($normalized->contains(fn (array $row): bool => $row['order_item_id'] <= 0 || ! in_array($row['source_code'], self::ALLOWED_SOURCE_CODES, true)), 422, '人工分仓包含无效仓库');
            abort_if($normalized->pluck('order_item_id')->duplicates()->isNotEmpty(), 422, '同一个订单商品不能拆数量到多个仓库');

            $orderItemIds = $locked->items->pluck('id')->map(fn ($id) => (int) $id)->sort()->values();
            abort_unless($normalized->pluck('order_item_id')->sort()->values()->all() === $orderItemIds->all(), 422, '必须为订单中的每个 SKU 完成分仓');

            $stockWarnings = $this->stockGate->assertAcknowledged($locked, $normalized->all(), $stockExceptionAcknowledged);

            $resolvedAssignments = $normalized->map(function (array $row) use ($locked): array {
                $item = $locked->items->firstWhere('id', $row['order_item_id']);
                abort_unless($item, 422, '分仓方案包含不属于原订单的 SKU');
                $outsource = $row['source_code'] === ExternalProcurementWorkflow::SOURCE_CODE;
                abort_if($outsource && strcasecmp(trim((string) ($item->source_sku ?: $item->sku)), 'gift') === 0,
                    422, '外采礼品需先指定实际商品 SKU；不可采购未确认的 gift 占位符');
                $resolution = $this->physicalSkus->resolve($item, $outsource ? 'FACTORY' : $row['source_code'], $row['gift_mapping_id']);

                // Warehouse allocation must not preselect domestic dispatch logistics.
                // Overseas allocation retains its existing recommended routing.
                $isFactory = in_array($row['source_code'], ['FACTORY', ExternalProcurementWorkflow::SOURCE_CODE], true);
                $route = $isFactory ? [] : $this->carrierRouting->warehouseRoute($locked->address?->country, $row['source_code']);
                $defaultCarrier = trim((string) ($route['carrier'] ?? ''));
                $providedCarrier = $isFactory ? '' : trim((string) ($row['carrier'] ?? ''));
                $carrier = $providedCarrier !== '' ? $providedCarrier : $defaultCarrier;
                abort_if($carrier === '' && ! $isFactory, 422, "发货仓 {$row['source_code']} 无法自动确定物流商，请 Vita 人工选择");
                if ($providedCarrier !== '') {
                    abort_unless(Carrier::query()->where('name', $providedCarrier)->where('is_active', true)->exists(), 422, "物流商 {$providedCarrier} 未启用");
                }
                $service = $row['service'] ?? null;
                if ($service === null && ($providedCarrier === '' || strcasecmp($providedCarrier, $defaultCarrier) === 0)) {
                    $service = isset($route['service']) && trim((string) $route['service']) !== '' ? trim((string) $route['service']) : null;
                }

                return [
                    ...$row,
                    'carrier' => $carrier !== '' ? $carrier : null,
                    'service' => $isFactory ? null : $service,
                    'carrier_selection_source' => $isFactory ? null : ($providedCarrier !== '' && ($defaultCarrier === '' || strcasecmp($providedCarrier, $defaultCarrier) !== 0)
                        ? 'manual'
                        : 'automatic'),
                    'resolution' => $resolution,
                ];
            })->values();

            $sources = $resolvedAssignments->pluck('source_code')->unique()->values();
            abort_if($sources->count() > self::MAX_SEGMENTS, 422, '单订单最多允许 26 个履约来源');
            $carrierConflict = $resolvedAssignments->groupBy('source_code')->contains(
                fn ($rows): bool => $rows->map(fn (array $row): string => $row['carrier'].'|'.($row['service'] ?? ''))->unique()->count() > 1
            );
            abort_if($carrierConflict, 422, '同一发货仓在同一履约组内必须使用同一物流商');

            $warehouseByCode = Warehouse::query()->where('is_active', true)
                ->whereIn(DB::raw('UPPER(code)'), self::ALLOWED_SOURCE_CODES)
                ->get()->keyBy(fn (Warehouse $warehouse): string => strtoupper(trim((string) $warehouse->code)));
            foreach ($sources as $sourceCode) {
                $actualCode = $sourceCode === ExternalProcurementWorkflow::SOURCE_CODE ? 'FACTORY' : $sourceCode;
                abort_unless($warehouseByCode->has($actualCode), 422, "发货仓 {$actualCode} 尚未启用");
            }

            $segments = $sources->map(function (string $sourceCode) use ($resolvedAssignments, $locked, $warehouseByCode): array {
                $isExternal = $sourceCode === ExternalProcurementWorkflow::SOURCE_CODE;
                $warehouse = $warehouseByCode->get($isExternal ? 'FACTORY' : $sourceCode);
                $sourceAssignments = $resolvedAssignments->where('source_code', $sourceCode)->values();
                $routeAssignment = $sourceAssignments->first();
                $items = $sourceAssignments->map(function (array $assignment) use ($locked): array {
                    $item = $locked->items->firstWhere('id', $assignment['order_item_id']);
                    $resolution = $assignment['resolution'] ?? null;

                    return [
                        'order_item_id' => (int) $item->id,
                        'product_id' => $resolution['source_product_id'],
                        'sku' => $resolution['source_sku'],
                        'source_sku' => $resolution['source_sku'],
                        'source_product_id' => $resolution['source_product_id'],
                        'source_product_summary' => $resolution['source_product_summary'],
                        'product_summary' => $resolution['source_product_summary'],
                        'quantity' => (int) $item->quantity,
                        'gift_mapping_id' => $resolution['gift_mapping_id'] ?? null,
                        'resolved_product_id' => $resolution['resolved_product_id'],
                        'resolved_fulfillment_sku' => $resolution['resolved_fulfillment_sku'],
                        'resolved_product_summary' => $resolution['resolved_product_summary'] ?? $resolution['source_product_summary'],
                    ];
                })->values()->all();

                return [
                    'source_type' => in_array($sourceCode, ['FACTORY', ExternalProcurementWorkflow::SOURCE_CODE], true) ? 'factory' : 'warehouse',
                    'source_code' => $sourceCode,
                    'procurement_required' => $isExternal,
                    'warehouse_id' => (int) $warehouse->id,
                    'warehouse_name' => $isExternal ? '外采 → 中国工厂发货' : (string) $warehouse->name,
                    'carrier' => $routeAssignment['carrier'],
                    'service' => $routeAssignment['service'],
                    'carrier_selection_source' => $routeAssignment['carrier_selection_source'],
                    'items' => $items,
                ];
            })->values()->all();

            $signature = collect($segments)->map(fn (array $segment): string => $segment['source_code'].'@'.($segment['carrier'] ?? '').'['.collect($segment['items'])->map(fn (array $item): string => $item['order_item_id'].'x'.$item['quantity'].'@'.($item['resolved_fulfillment_sku'] ?? $item['sku'] ?? ''))->implode(',').']')->implode('|');
            $plan = [
                'plan_key' => 'manual:'.substr(hash('sha256', $signature), 0, 16),
                'plan_type' => count($segments) > 1 ? 'split' : ($segments[0]['source_type'] === 'factory' ? 'factory' : 'single'),
                'is_split' => count($segments) > 1,
                'shipment_count' => count($segments),
                'segments' => $segments,
                'cost' => ['status' => 'not_evaluated', 'amount' => null, 'currency' => null, 'source' => null],
                'label' => count($segments) > 1 ? '人工拆分发货' : ($segments[0]['source_type'] === 'factory' ? '人工整单中国工厂发货' : '人工整单海外仓发货'),
                'human_review_required' => true,
                'stock_exception_acknowledgement' => $stockWarnings === [] ? null : [
                    'acknowledged' => true,
                    'actor_id' => $actor->getAuthIdentifier(),
                    'acknowledged_at' => now()->toIso8601String(),
                    'warnings' => $stockWarnings,
                ],
                'execution_status' => count($segments) > 1 ? 'pending_multi_leg_execution_gate' : 'pending_single_leg_bridge',
                'destination_zone' => strtoupper((string) $locked->address?->country) === 'US'
                    ? $this->usZones->resolve($locked->address?->postal_code, [
                        'province_state' => $locked->address?->province_state,
                        'city' => $locked->address?->city,
                    ])
                    : null,
            ];
            $presented = $this->decisionFor($locked);

            return $this->persistAndMaterialize($locked, [
                'decision_source' => 'manual',
                'recommended_plan_key' => $presented['recommended_plan_key'] ?? null,
                'selected_plan_key' => $plan['plan_key'],
                'plan_snapshot' => $plan,
                'manual_assignments' => $resolvedAssignments->map(fn (array $row): array => [
                    'order_item_id' => $row['order_item_id'],
                    'source_code' => $row['source_code'],
                    'gift_mapping_id' => $row['gift_mapping_id'],
                    'carrier' => $row['carrier'],
                    'service' => $row['service'],
                    'carrier_selection_source' => $row['carrier_selection_source'],
                    'resolved_fulfillment_sku' => $row['resolution']['resolved_fulfillment_sku'] ?? null,
                    'resolved_product_id' => $row['resolution']['resolved_product_id'] ?? null,
                ])->all(),
                'decision_note' => $this->nullableNote($note),
            ], $actor);
        });
    }

    /** @return array<string,mixed> */
    private function decisionFor(Order $order): array
    {
        $items = $order->items->map(fn ($item): array => [
            'product_id' => $item->source_product_id ?: $item->product_id,
            'quantity' => (int) $item->quantity,
            'sku' => $item->source_sku ?: $item->sku,
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

    private function assertExternalProcurementMayReallocate(Order $order): void
    {
        $ids = [$order->id, ...Order::withTrashed()->where('fulfillment_parent_id', $order->id)
            ->where('fulfillment_role', 'original')->pluck('id')->all()];
        abort_if(ProcurementOrder::query()
            ->whereIn('source_order_id', $ids)->where('origin', 'external')
            ->whereNotIn('status', ['pending', 'cancelled'])->exists(),
            409, '已有外采任务开始采购或确认到货，禁止覆盖分仓；请先人工处理采购单');
    }

    private function persistAndMaterialize(Order $order, array $attributes, Authenticatable $actor): FulfillmentAllocation
    {
        if (($attributes['decision_source'] ?? null) === 'system_recommendation') {
            $plan = $this->resolveRecommendedPlan($order, $attributes['plan_snapshot'] ?? []);
            $attributes['plan_snapshot'] = $plan;
        }
        $allocation = $this->persist($order, $attributes, $actor);
        if ($order->domestic_reject_reason !== null) {
            $order->forceFill([
                'domestic_reject_reason' => null,
                'domestic_rejected_by' => null,
                'domestic_rejected_at' => null,
            ])->save();
        }
        $plan = is_array($allocation->plan_snapshot) ? $allocation->plan_snapshot : [];
        $resultOrders = $this->derivedOrders->materialize(
            $order->fresh(['address', 'items.product', 'shipment.warehouse', 'fulfillmentChildren.shipment.warehouse']),
            $plan,
            $actor,
        );
        $plan['derived_orders'] = $resultOrders;
        // Create procurement tasks against the actual outbound order, not the
        // Woo source parent. A/B/C child lines therefore remain independent.
        $activeExternalIds = [];
        foreach (($plan['segments'] ?? []) as $index => $segment) {
            if (($segment['procurement_required'] ?? false) !== true) {
                continue;
            }
            $fulfillment = Order::query()->findOrFail((int) ($resultOrders[$index]['order_id'] ?? 0));
            $this->externalProcurement->createForAllocatedOrder($fulfillment, $actor);
            $activeExternalIds[] = (int) $fulfillment->id;
        }
        $familyIds = [$order->id, ...Order::withTrashed()->where('fulfillment_parent_id', $order->id)
            ->where('fulfillment_role', 'original')->pluck('id')->all()];
        // Pending procurement lines superseded by Vita's reallocation must
        // never remain active after their virtual source has been removed.
        ProcurementOrder::query()
            ->where('origin', 'external')->whereIn('source_order_id', $familyIds)
            ->whereNotIn('source_order_id', $activeExternalIds)
            ->where('status', 'pending')
            ->update(['status' => 'cancelled', 'status_updated_at' => now()]);
        $plan['execution_status'] = count($resultOrders) > 1 ? 'derived_orders_materialized' : 'single_order_assigned';
        $allocation->forceFill(['plan_snapshot' => $plan])->save();

        // A previously rejected overseas review must not block the next Dede
        // review after Vita has successfully reassigned the order. Its original
        // reason/actor remain in the immutable audit event recorded on reject.
        $review = OverseaWarehouseReview::query()
            ->where('order_id', $order->getKey())
            ->where('status', 'rejected')
            ->first();
        if ($review) {
            $reassigned = $order->fresh('shipment');
            $review->forceFill([
                'status' => 'pending',
                'progress' => 'pending_review',
                'submitted_warehouse_id' => $reassigned?->shipment?->warehouse_id,
                'reviewed_warehouse_id' => null,
                'note' => null,
                'reviewed_by' => null,
                'reviewed_at' => null,
            ])->save();
        }

        return $allocation->fresh();
    }

    /**
     * Recommendation and manual allocation must materialize through the same
     * physical warehouse SKU resolver, before any shipment becomes visible.
     */
    private function resolveRecommendedPlan(Order $order, array $plan): array
    {
        $segments = collect($plan['segments'] ?? [])->map(function (array $segment) use ($order): array {
            $code = (string) ($segment['source_code'] ?? '');
            $items = collect($segment['items'] ?? [])->map(function (array $row) use ($order, $code): array {
                $matches = $order->items->filter(fn ($item): bool => (int) ($item->source_product_id ?: $item->product_id) === (int) ($row['product_id'] ?? 0)
                );
                abort_unless($matches->count() === 1, 422, '推荐分仓商品不能唯一对应订单行，必须手工分仓');
                $item = $matches->first();
                $resolved = $this->physicalSkus->resolve($item, $code, null);

                return [
                    ...$row,
                    'order_item_id' => (int) $item->id,
                    'sku' => $resolved['source_sku'],
                    'source_sku' => $resolved['source_sku'],
                    'source_product_id' => $resolved['source_product_id'],
                    'source_product_summary' => $resolved['source_product_summary'],
                    'resolved_product_id' => $resolved['resolved_product_id'],
                    'resolved_fulfillment_sku' => $resolved['resolved_fulfillment_sku'],
                    'resolved_product_summary' => $resolved['resolved_product_summary'] ?? $resolved['source_product_summary'],
                ];
            })->values()->all();

            return [...$segment, 'items' => $items];
        })->values()->all();

        return [...$plan, 'segments' => $segments];
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

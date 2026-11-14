<?php

namespace App\Modules\Orders\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Finance\Application\Contracts\ConfirmedLossReader;
use App\Modules\Orders\Application\Inbound\NormalizedInboundOrder;
use App\Modules\Products\Application\Contracts\ProductExternalReferenceRepository;
use App\Modules\Products\Application\Contracts\ProductRepository;
use App\Modules\Products\Application\SkuIdentityResolver;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;

final class OrderService
{
    private const ADDRESS_FIELDS = [
        'recipient_name', 'phone', 'email', 'company', 'country', 'province_state', 'city',
        'address_line_1', 'address_line_2', 'postal_code',
    ];

    // Core exception/stockout can only mutate through OrderProgressTransitionService.
    private const OPERATIONAL_MARKERS = ['in_transit', 'return'];

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly ProductRepository $productRepository,
        private readonly SkuIdentityResolver $skuResolver,
        private readonly OrderIdentityService $orderIdentities,
        private readonly ProductExternalReferenceRepository $productExternalRefs,
        private readonly ConfirmedLossReader $confirmedLosses,
        private readonly OrderProgressQuery $progressQueries,
        private readonly OrderManualFieldProtection $manualProtection,
    ) {}

    public function create(array $attributes, array $items, ?Authenticatable $actor, bool $allowMissingAddress = false): Model
    {
        return DB::transaction(function () use ($attributes, $items, $actor, $allowMissingAddress): Model {
            $orderClass = 'App\\Modules\\Orders\\Infrastructure\\Models\\Order';
            $address = array_intersect_key($attributes, array_flip(self::ADDRESS_FIELDS));
            $orderAttributes = array_diff_key($attributes, $address);
            $isManualKol = $attributes['order_kind'] === OrderKind::KolFulfillment->value
                && blank($orderAttributes['erp_order_id'] ?? null);
            $order = $orderClass::create([
                ...$orderAttributes,
                'external_order_number' => trim((string) $attributes['external_order_number']),
                'internal_order_number' => $this->nextOrderNumber($attributes['order_kind']),
                'erp_order_id' => $attributes['order_kind'] === OrderKind::Customer->value
                    ? $this->orderIdentities->allocateCustomer((string) $attributes['order_date'])
                    : ($orderAttributes['erp_order_id'] ?? null),
                'order_status' => OrderStatus::PendingShipment,
                'owner_id' => $orderAttributes['owner_id'] ?? $actor?->getAuthIdentifier(),
            ]);
            if ($isManualKol) {
                // Approved manual KOL ERP identity: {prefix}-{MMDD}-k{orders.id}.
                // Preserve a uniqueness failure rather than silently changing the number.
                $order->erp_order_id = $this->orderIdentities->generate(
                    'k', (string) $order->id, (string) $attributes['order_date']
                );
                $order->save();
            }
            $requiredAddressFields = ['recipient_name', 'phone', 'country', 'province_state', 'city', 'address_line_1'];
            $hasCompleteRequiredAddress = collect($requiredAddressFields)->every(
                fn (string $field): bool => trim((string) ($address[$field] ?? '')) !== '',
            );
            if (! $allowMissingAddress || $hasCompleteRequiredAddress) {
                $order->address()->create($address);
            }

            foreach ($items as $item) {
                $resolved = $this->resolveProductFields($item);
                $order->items()->create([
                    ...$item,
                    'sku' => $resolved['sku'],
                    'product_summary' => $resolved['product_summary'],
                    'line_revenue' => $this->lineRevenue($item['unit_price'], (int) $item['quantity']),
                ]);
            }

            $this->record($actor, 'order.created', $order, after: $this->auditData($order), reason: 'manual_order_created');

            return $order->load('items', 'owner', 'address');
        });
    }

    /** @return array{order:Model,created:bool} */
    public function upsertInbound(NormalizedInboundOrder $data, ?Authenticatable $actor): array
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("SET time_zone = '+00:00'");
        }

        return DB::transaction(function () use ($data, $actor): array {
            $orderClass = 'App\\Modules\\Orders\\Infrastructure\\Models\\Order';
            $referenceClass = 'App\\Modules\\Orders\\Infrastructure\\Models\\OrderExternalReference';
            $reference = $referenceClass::query()
                ->where('provider', $data->provider)
                ->where('connection_key', $data->connectionKey)
                ->where('reference', $data->reference)
                ->lockForUpdate()
                ->first();

            $created = $reference === null;
            $protected = [];
            if ($reference) {
                $order = $orderClass::query()->whereKey($reference->order_id)->lockForUpdate()->firstOrFail();
                $before = $this->inboundAuditData($order);
                $protected = $this->manualProtection->forOrder($order);
                $localStatus = $order->order_status instanceof \BackedEnum ? $order->order_status->value : (string) $order->order_status;
                $nextStatus = $localStatus;
                $allocated = $order->fulfillmentAllocation()->exists() || $order->shipment()->exists();
                if (! $allocated && ! isset($protected['order.order_status'])
                    && $localStatus === OrderStatus::PendingShipment->value
                    && $data->orderStatus === OrderStatus::Cancelled->value) {
                    $nextStatus = OrderStatus::Cancelled->value;
                }
                $incomingFields = [
                    'external_order_number' => $data->externalOrderNumber,
                    'order_date' => $data->orderDate,
                    'order_note' => $data->orderNote,
                    'currency' => $data->currency,
                    'order_status' => $nextStatus,
                ];
                $safeFields = array_filter($incomingFields, fn ($value, string $key): bool => ! isset($protected['order.'.$key]) && ! ($allocated && $key === 'order_status'),
                    ARRAY_FILTER_USE_BOTH
                );
                $order->fill($safeFields)->save();
            } else {
                $order = $orderClass::create([
                    'internal_order_number' => $this->nextOrderNumber(OrderKind::Customer->value),
                    'erp_order_id' => $this->orderIdentities->generateWooCustomer($data->externalOrderNumber, $data->reference, $data->orderDate),
                    'source_type' => OrderSourceType::WooCommerce->value,
                    'order_kind' => OrderKind::Customer->value,
                    'external_order_number' => $data->externalOrderNumber,
                    'order_date' => $data->orderDate,
                    'order_note' => $data->orderNote,
                    'currency' => $data->currency,
                    'order_status' => $data->orderStatus,
                    'owner_id' => null,
                ]);
                $reference = $order->externalReferences()->create([
                    'provider' => $data->provider,
                    'connection_key' => $data->connectionKey,
                    'reference' => $data->reference,
                    'provider_modified_at' => $data->providerModifiedAt,
                ]);
                $before = null;
            }

            $allocated = ! $created && (
                $order->fulfillmentAllocation()->exists() || $order->shipment()->exists()
            );
            $safeAddress = array_filter(
                $data->address,
                fn ($value, string $key): bool => ! isset($protected['address.'.$key]) && ! $allocated,
                ARRAY_FILTER_USE_BOTH,
            );
            if ($safeAddress !== []) {
                $order->address()->updateOrCreate(['order_id' => $order->getKey()], $safeAddress);
            }
            if ($created || (! $allocated && ! isset($protected['items']))) {
                $this->reconcileInboundItems($order, $data);
            } elseif ($this->hasInboundItemDrift($order, $data)) {
                $this->record($actor, 'order.inbound_items_conflict_preserved', $order,
                    after: ['order_id' => (int) $order->id, 'manual_override' => isset($protected['items']),
                        'allocation_protected' => $allocated],
                    reason: 'erp_manual_or_warehouse_sku_authoritative',
                    source: AuditSource::WooCommerceInbound);
            }
            $reference->forceFill(['provider_modified_at' => $data->providerModifiedAt])->save();
            if ($data->settlementEvidence !== null) {
                $evidence = $data->settlementEvidence;
                $financials = $order->financials()->lockForUpdate()->first();
                if ($financials === null) {
                    $order->financials()->create([
                        ...$evidence,
                        'revenue_provenance' => 'provider_import',
                        'completeness_status' => 'INCOMPLETE',
                    ]);
                } elseif ($financials->revenue_provenance !== 'confirmed_manual') {
                    $currencyChanged = $financials->currency !== $evidence['currency'];
                    $financials->fill([
                        ...$evidence,
                        'revenue_provenance' => 'provider_import',
                        'completeness_status' => 'INCOMPLETE',
                    ]);
                    if ($currencyChanged) {
                        $financials->fx_rate = null;
                        $financials->fx_rate_date = null;
                        $financials->fx_rate_source = null;
                    }
                    $financials->save();
                }
            }
            $order = $order->fresh(['items', 'owner', 'address', 'externalReferences']);
            $this->record(
                $actor,
                $created ? 'order.inbound_created' : 'order.inbound_updated',
                $order,
                before: $before,
                after: $this->inboundAuditData($order),
                reason: 'woocommerce_inbound',
                source: AuditSource::WooCommerceInbound,
            );

            return ['order' => $order, 'created' => $created];
        });
    }

    public function update(Model $order, array $attributes, ?array $items, ?Authenticatable $actor, ?string $reason = null): Model
    {
        return DB::transaction(function () use ($order, $attributes, $items, $actor, $reason): Model {
            $lockedOrder = $order->newQuery()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $before = $this->auditData($lockedOrder);
            $before['address'] = $lockedOrder->address?->toArray();
            $before['items'] = $lockedOrder->items()->get()->toArray();
            $address = array_intersect_key($attributes, array_flip(self::ADDRESS_FIELDS));
            $orderAttributes = array_diff_key($attributes, $address);
            $lockedOrder->fill($orderAttributes)->save();
            if ($address !== []) {
                $lockedOrder->address()->updateOrCreate(['order_id' => $lockedOrder->getKey()], $address);
            }
            if ($items !== null) {
                $retainedItemIds = [];
                foreach ($items as $item) {
                    $itemId = isset($item['id']) ? (int) $item['id'] : null;
                    $existingItem = $itemId ? $lockedOrder->items()->whereKey($itemId)->firstOrFail() : null;
                    $resolved = $this->resolveProductFields($item);
                    unset($item['id']);
                    $payload = [
                        ...$item,
                        'sku' => $resolved['sku'],
                        'product_summary' => $resolved['product_summary'],
                        'line_revenue' => $this->lineRevenue($item['unit_price'], (int) $item['quantity']),
                    ];
                    if ($existingItem) {
                        $existingItem->update($payload);
                        $retainedItemIds[] = $existingItem->id;
                    } else {
                        $retainedItemIds[] = $lockedOrder->items()->create($payload)->id;
                    }
                }
                $lockedOrder->items()->whereNotIn('id', $retainedItemIds)->delete();
            }
            $after = $this->auditData($lockedOrder);
            $after['address'] = $lockedOrder->address?->fresh()?->toArray();
            $after['items'] = $lockedOrder->items()->get()->toArray();
            $this->manualProtection->protect(
                $lockedOrder,
                $this->manualProtection->changedKeys($before, $after),
                $actor,
            );
            if ($reason) {
                $after['amendment_reason'] = $reason;
            }
            $this->record($actor, 'order.updated', $lockedOrder, before: $before, after: $after, reason: 'manual_correction');

            return $lockedOrder->fresh(['items', 'owner', 'address']);
        });
    }

    /**
     * Woo re-import must retain stable ERP order_item IDs. Deleting/recreating
     * breaks Vita's saved gift selection and silently resurrects 'gift'.
     */
    private function reconcileInboundItems(Model $order, NormalizedInboundOrder $data): void
    {
        $existing = $order->items()->get()->keyBy('woocommerce_line_item_id');
        $keepIds = [];
        $seen = [];
        $wooReference = $data->provider.':'.$data->reference;
        foreach ($data->items as $item) {
            $lineId = isset($item['woocommerce_line_item_id']) ? (int) $item['woocommerce_line_item_id'] : null;
            if ($lineId && isset($seen[$lineId])) {
                throw new \RuntimeException('WOO_DUPLICATE_LINE_ITEM_ID');
            }
            if ($lineId) {
                $seen[$lineId] = true;
            }
            $resolved = $this->resolveInboundProductFields($item, $wooReference, $data->connectionKey);
            $values = [
                'product_id' => $resolved['product_id'],
                'woocommerce_line_item_id' => $lineId,
                'sku' => $resolved['sku'],
                'product_summary' => $resolved['product_summary'],
                'quantity' => (int) $item['quantity'],
                'unit_price' => (string) $item['unit_price'],
                'line_revenue' => $this->lineRevenue((string) $item['unit_price'], (int) $item['quantity']),
            ];
            $existingItem = $lineId ? $existing->get($lineId) : null;
            if ($existingItem) {
                $existingItem->fill($values);
                if ($existingItem->isDirty()) {
                    $existingItem->save();
                }
                $keepIds[] = (int) $existingItem->id;
            } else {
                $keepIds[] = (int) $order->items()->create($values)->id;
            }
        }
        $order->items()->whereNotIn('id', $keepIds)->delete();
    }

    private function hasInboundItemDrift(Model $order, NormalizedInboundOrder $data): bool
    {
        $local = $order->items()->get()->keyBy('woocommerce_line_item_id');
        if ($local->count() !== count($data->items)) {
            return true;
        }
        foreach ($data->items as $incoming) {
            $id = (int) ($incoming['woocommerce_line_item_id'] ?? 0);
            $item = $id ? $local->get($id) : null;
            if (! $item) {
                return true;
            }
            // Compare the incoming business line to the saved source identity;
            // physical warehouse SKU is intentionally allowed to differ.
            if (trim((string) ($item->source_sku ?: $item->sku)) !== trim((string) ($incoming['sku'] ?? ''))
                || (int) $item->quantity !== (int) ($incoming['quantity'] ?? 0)
                || abs((float) $item->unit_price - (float) ($incoming['unit_price'] ?? 0)) > 0.001) {
                return true;
            }
        }

        return false;
    }

    public function setOperationalMarker(Model $order, string $marker, bool $active, ?Authenticatable $actor): Model
    {
        if (! in_array($marker, self::OPERATIONAL_MARKERS, true)) {
            throw new \InvalidArgumentException('Unsupported order operational marker.');
        }

        return DB::transaction(function () use ($order, $marker, $active, $actor): Model {
            $locked = $order->newQuery()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $existing = $locked->operationalMarkers()->where('marker', $marker)->first();
            $wasActive = $existing !== null;

            if ($active && ! $existing) {
                $locked->operationalMarkers()->create([
                    'marker' => $marker,
                    'marked_by' => $actor?->getAuthIdentifier(),
                    'marked_at' => now(),
                ]);
            } elseif (! $active && $existing) {
                $existing->delete();
            }

            if ($wasActive !== $active) {
                $this->record(
                    $actor,
                    'order.operational_marker.updated',
                    $locked,
                    before: ['marker' => $marker, 'active' => $wasActive],
                    after: ['marker' => $marker, 'active' => $active],
                    reason: 'manual_operational_marker',
                );
            }

            return $locked->fresh(['operationalMarkers']);
        });
    }

    public function list(array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        return $this->filteredQuery($filters)
            ->paginate(min($perPage, 200))
            ->withQueryString();
    }

    /**
     * Bounded export adapter: eager-load relations per chunk rather than
     * issuing one query per order from a plain Eloquent cursor.
     */
    public function exportBatched(array $filters, int $chunk = 100): LazyCollection
    {
        return $this->filteredQuery($filters)->lazy($chunk);
    }

    public function exportCursor(array $filters): LazyCollection
    {
        return $this->filteredQuery($filters)->cursor();
    }

    private function filteredQuery(array $filters): Builder
    {
        $orderClass = 'App\\Modules\\Orders\\Infrastructure\\Models\\Order';
        $query = $orderClass::query()
            ->eligibleForOperations()
            ->with(['items', 'owner', 'address', 'shipment.warehouse', 'shipment.overseaReview', 'operationalMarkers', 'fulfillmentAllocation', 'originalFulfillmentChildren.shipment.warehouse', 'originalFulfillmentChildren.shipment.overseaReview', 'originalFulfillmentChildren.operationalMarkers'])
            ->when($filters['customer_email_search'] ?? false, fn ($query) => $query->with('customer'))
            ->when($filters['oms_selected_ids'] ?? null, fn ($query, array $ids) => $query->whereKey($ids))
            ->when($filters['order_kind'] ?? null, fn ($query, string $kind) => $query->where('order_kind', $kind))
            ->when($filters['owner_id'] ?? null, fn ($query, int $ownerId) => $query->where('owner_id', $ownerId))
            ->when($filters['partner_warehouse_code'] ?? null, fn ($query, string $code) => $query
                ->where('order_kind', '!=', 'fulfillment_derived')
                ->where(fn ($q) => $q->whereHas('shipment.warehouse', fn ($w) => $w->where('code', $code))
                    ->orWhereHas('originalFulfillmentChildren.shipment.warehouse', fn ($w) => $w->where('code', $code))))
            ->when($filters['warehouse_ownership'] ?? null, fn ($query, string $ownership) => $query->whereHas('shipment.warehouse', fn ($warehouse) => $warehouse->where('ownership_type', $ownership)))
            ->when($filters['search'] ?? null, function ($query, string $search) use ($filters): void {
                $search = trim($search);

                if (preg_match('/^dbid:(\d+)$/i', $search, $match) === 1) {
                    $query->whereKey((int) $match[1]);

                    return;
                }

                // Preserve the legacy exact-numeric identity contract outside the opt-in OMS Grid.
                // OMS mode explicitly opts into substring search, including ERP-facing numbers.
                if (ctype_digit($search) && ! ($filters['oms_numeric_contains'] ?? false)) {
                    $query->where(function ($nested) use ($search): void {
                        $nested->where('external_order_number', $search)
                            ->orWhereHas('externalReferences', fn ($references) => $references->where('reference', $search))
                            ->orWhereHas('shipment', fn ($shipment) => $shipment->where('tracking_number', $search));
                    });

                    return;
                }

                $query->where(function ($nested) use ($search, $filters): void {
                    $nested->where('internal_order_number', 'like', "%{$search}%")
                        ->orWhereHas('address', fn ($address) => $address->where('recipient_name', 'like', "%{$search}%"))
                        ->orWhere('external_order_number', 'like', "%{$search}%")
                        ->orWhere('erp_order_id', 'like', "%{$search}%")
                        ->orWhereHas('externalReferences', fn ($references) => $references->where('reference', 'like', "%{$search}%"))
                        ->orWhereHas('shipment', fn ($shipment) => $shipment->where('tracking_number', 'like', "%{$search}%"));
                    if ($filters['customer_email_search'] ?? false) {
                        $nested->orWhereHas('address', fn ($address) => $address->where('email', 'like', "%{$search}%"))
                            ->orWhereHas('customer', fn ($customer) => $customer->where('email', 'like', "%{$search}%"));
                    }
                    // OMS Grid can search products from any page; preserve legacy text-search contract.
                    if ($filters['oms_numeric_contains'] ?? false) {
                        $nested->orWhereHas('items', fn ($items) => $items
                            ->where('sku', 'like', "%{$search}%")
                            ->orWhere('product_summary', 'like', "%{$search}%"));
                    }
                });
            })
            ->when($filters['source_type'] ?? null, fn ($query, string $source) => $query->where('source_type', $source))
            ->when($filters['order_status'] ?? null, fn ($query, string $status) => $query->where('order_status', $status));

        $query->when($filters['workflow_status'] ?? null, function ($query, string $status): void {
            if (in_array($status, ['pending_allocation', 'pending_review', 'allocated', 'partially_shipped', 'sending', 'sent', 'shipping', 'out_of_stock', 'exception', 'cancelled', 'progress_conflict', 'completed'], true)) {
                $this->progressQueries->applyPhase($query, $status);

                return;
            }
            if (OrderAuxiliaryStatusService::isAuxiliary($status)) {
                $query->where('workflow_status', $status)
                    ->orWhere(function (Builder $old) use ($status): void {
                        $old->where(fn ($w) => $w->whereNull('workflow_status')
                            ->orWhereNotIn('workflow_status', OrderManualHoldStatus::CODES))
                            ->whereHas('operationalMarkers', fn ($m) => $m->where('marker', 'workflow_aux_'.$status));
                    });

                return;
            }
            $canonicalFallbacks = ['sending' => ['pending_shipment'], 'sent' => ['shipped'], 'finished' => ['completed', 'completed_delivered'], 'cancel' => ['cancelled']][$status] ?? [];
            $query->where(function ($nested) use ($status, $canonicalFallbacks): void {
                $nested->where('workflow_status', $status);
                if ($canonicalFallbacks !== []) {
                    $nested->orWhere(function ($fallback) use ($canonicalFallbacks): void {
                        $fallback->whereNull('workflow_status')->whereIn('order_status', $canonicalFallbacks);
                    });
                }
            });
        });

        // OMS Grid column inputs are always server-authoritative, never browser-page filters.
        $grid = $filters['oms_grid_columns'] ?? [];
        if (is_array($grid)) {
            // These queries are evaluated against the full filtered orders dataset,
            // not the currently loaded AG Grid page. Only allowlisted relations/fields.
            foreach ([
                'grid_warehouse' => ['shipment.warehouse', 'name'],
                'grid_tracking' => ['shipment', 'tracking_number'],
                'grid_city' => ['address', 'city'],
                'grid_postal_code' => ['address', 'postal_code'],
                'grid_carrier' => ['shipment', 'carrier'],
            ] as $key => [$relation, $column]) {
                if (! empty($grid[$key])) {
                    $value = $grid[$key];
                    $query->whereHas($relation, fn ($related) => $related->where($column, 'like', "%{$value}%"));
                }
            }
            // A single SKU item must satisfy *all* item-column filters.
            // Separate whereHas clauses could falsely match different lines
            // of the same multi-SKU order.
            $sku = (string) ($grid['grid_sku'] ?? '');
            $name = (string) ($grid['grid_product_names'] ?? '');
            $qtyInput = (string) ($grid['grid_quantity'] ?? '');
            if ($sku !== '' || $name !== '' || $qtyInput !== '') {
                $quantity = $qtyInput === '' ? null : filter_var($qtyInput, FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 0, 'max_range' => 99999],
                ]);
                if ($quantity === false) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->whereHas('items', function ($items) use ($sku, $name, $quantity): void {
                        if ($sku !== '') {
                            $items->where('sku', 'like', "%{$sku}%");
                        }
                        if ($name !== '') {
                            $items->where('product_summary', 'like', "%{$name}%");
                        }
                        if ($quantity !== null) {
                            $items->where('quantity', $quantity);
                        }
                    });
                }
            }
            if (! empty($grid['grid_external_number'])) {
                $query->where('external_order_number', 'like', '%'.$grid['grid_external_number'].'%');
            }
            if (! empty($grid['grid_order_number'])) {
                $v = $grid['grid_order_number'];
                $query->where('erp_order_id', 'like', "%{$v}%");
            }
            if (! empty($grid['grid_source'])) {
                $v = strtolower(str_replace(' ', '_', $grid['grid_source']));
                $query->where('source_type', 'like', "%{$v}%");
            }
            if (! empty($grid['grid_date'])) {
                $v = $grid['grid_date'];
                $query->where('order_date', 'like', "%{$v}%");
            }
            if (! empty($grid['grid_email'])) {
                $v = $grid['grid_email'];
                $query->where(function (Builder $emailQuery) use ($v): void {
                    $emailQuery->whereHas('address', fn ($address) => $address->where('email', 'like', "%{$v}%"))
                        ->orWhereHas('customer', fn ($customer) => $customer->where('email', 'like', "%{$v}%"));
                });
            }
            if (! empty($grid['grid_recipient'])) {
                $v = $grid['grid_recipient'];
                $query->whereHas('address', fn ($address) => $address->where('recipient_name', 'like', "%{$v}%"));
            }
            if (! empty($grid['grid_country'])) {
                $v = $grid['grid_country'];
                $query->whereHas('address', fn ($address) => $address->where('country', 'like', "%{$v}%"));
            }
            if (! empty($grid['grid_owner'])) {
                $v = $grid['grid_owner'];
                $query->whereHas('owner', fn ($owner) => $owner->where('name', 'like', "%{$v}%"));
            }
            if (! empty($grid['grid_status'])) {
                $needle = mb_strtolower($grid['grid_status']);
                $codes = [];
                foreach (OrderWorkflowStatus::registry() as $code => $definition) {
                    $labels = mb_strtolower($code.' '.$definition['en'].' '.$definition['zh']);
                    if (str_contains($labels, $needle)) {
                        $codes[] = $code;
                    }
                }
                if ($codes === []) {
                    $query->whereRaw('1 = 0');
                } else {
                    // The canonical status overrides a pre-shipment work annotation.
                    $query->where(function ($statusQuery) use ($codes): void {
                        $statusQuery->where(function ($annotation) use ($codes): void {
                            $annotation->whereIn('workflow_status', $codes)
                                ->where(function ($valid): void {
                                    $valid->whereNotIn('workflow_status', ['sending', 'sent'])
                                        ->orWhere('order_status', 'pending_shipment');
                                });
                        });
                        if (in_array('sending', $codes, true)) {
                            $statusQuery->orWhere(function ($fallback): void {
                                $fallback->whereNull('workflow_status')->where('order_status', 'pending_shipment');
                            });
                        }
                        foreach (['shipped' => ['shipped'], 'completed' => ['completed', 'completed_delivered']] as $code => $statuses) {
                            if (! in_array($code, $codes, true)) {
                                continue;
                            }
                            $statusQuery->orWhere(function ($fallback) use ($statuses): void {
                                $fallback->whereIn('order_status', $statuses)
                                    ->where(function ($annotation): void {
                                        $annotation->whereNull('workflow_status')
                                            ->orWhereIn('workflow_status', ['sending', 'sent']);
                                    });
                            });
                        }
                        if (in_array('cancelled', $codes, true)) {
                            $statusQuery->orWhere('order_status', 'cancelled');
                        }
                    });
                }
            }
        }

        $this->applyBusinessView($query, (string) ($filters['view'] ?? 'all'));

        $sortColumns = [
            'order_number' => 'erp_order_id',
            'date' => 'order_date',
            'source' => 'source_type',
            'status' => 'order_status',
        ];
        $sortColumn = $sortColumns[$filters['oms_sort'] ?? 'date'] ?? 'order_date';
        $sortDirection = ($filters['oms_direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sortColumn, $sortDirection)->orderByDesc('id');
    }

    private function applyBusinessView(Builder $query, string $view): void
    {
        match ($view) {
            '', 'all' => null,
            // The split source is not an executable pending-shipment row.
            'pending' => $query->where(function (Builder $progress): void {
                $progress->where(function (Builder $customer): void {
                    $customer->where('order_kind', '!=', 'kol_fulfillment');
                    $this->progressQueries->readyForShipment($customer);
                })->orWhere(function (Builder $kol): void {
                    $kol->where('order_kind', 'kol_fulfillment')->where('order_status', 'pending_shipment')
                        ->where(fn ($q) => $q->whereNull('workflow_status')->orWhere('workflow_status', '!=', 'exception'));
                });
            })->where(function (Builder $active): void {
                $active->whereDoesntHave('originalFulfillmentChildren')
                    ->orWhereDoesntHave('fulfillmentAllocation', fn (Builder $allocation) => $allocation->where('status', 'submitted'));
            }),
            'exception' => $this->progressQueries->applyPhase($query, 'exception'),
            'in_transit' => $this->applyMarkerView($query, 'in_transit'),
            'shipped' => $query->where('order_status', OrderStatus::Shipped->value),
            'return' => $query->where(function (Builder $returns): void {
                $this->progressQueries->applyPhase($returns, 'returned_follow_up')
                    ->orWhere(function (Builder $aux): void {
                        // Existing explicit return-attention marker stays a read-only
                        // auxiliary view, not a fabricated returned Shipment.
                        $aux->whereHas('operationalMarkers', fn ($m) => $m->where('marker', 'return'));
                    });
            }),
            'loss' => $this->applyLossView($query),
            'overweight' => $query
                ->whereHas('shipment.warehouse', fn ($warehouse) => $warehouse->where('is_domestic', true))
                ->whereHas('shipment.billingEvidence', fn ($billing) => $billing->where('chargeable_weight_kg', '>', 1.5)),
            'stockout' => $this->progressQueries->applyPhase($query, 'out_of_stock'),
            'cancelled' => $this->progressQueries->applyPhase($query, 'cancelled'),
            default => null,
        };
    }

    private function applyMarkerView(Builder $query, string $marker): Builder
    {
        return $query->whereHas('operationalMarkers', fn ($markers) => $markers->where('marker', $marker));
    }

    private function applyLossView(Builder $query): Builder
    {
        return $this->confirmedLosses->applyConfirmedLossScope($query);
    }

    private function applyStockoutView(Builder $query): Builder
    {
        return $this->applyMarkerView($query, 'stockout')
            ->whereExists(function ($stockout): void {
                $stockout->selectRaw('1')
                    ->from('order_items as stockout_items')
                    ->join('shipments as stockout_shipments', 'stockout_shipments.order_id', '=', 'stockout_items.order_id')
                    ->leftJoin('warehouse_inventory as stockout_inventory', function ($join): void {
                        $join->on('stockout_inventory.warehouse_id', '=', 'stockout_shipments.warehouse_id')
                            ->on('stockout_inventory.product_id', '=', 'stockout_items.product_id');
                    })
                    ->whereColumn('stockout_items.order_id', 'orders.id')
                    ->whereNotNull('stockout_shipments.warehouse_id')
                    ->whereNotNull('stockout_items.product_id')
                    ->whereRaw('(COALESCE(stockout_inventory.quantity_on_hand, 0) - COALESCE(stockout_inventory.quantity_reserved, 0)) < stockout_items.quantity');
            });
    }

    private function nextOrderNumber(string $orderKind): string
    {
        $prefix = match ($orderKind) {
            OrderKind::Customer->value => 'CUS',
            OrderKind::KolFulfillment->value => 'KOL',
            default => 'MAN',
        };
        $orderClass = 'App\\Modules\\Orders\\Infrastructure\\Models\\Order';

        do {
            $number = $prefix.'-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
        } while ($orderClass::query()->where('internal_order_number', $number)->exists());

        return $number;
    }

    /**
     * Pre-generates the operator-facing business order number (CUS/KOL + date + 4-char code + 007),
     * shown on the create form before save so the operator can edit it prior to first save.
     */
    public function generateExternalOrderNumber(OrderKind $orderKind): string
    {
        $prefix = match ($orderKind) {
            OrderKind::Customer => 'CUS',
            OrderKind::KolFulfillment => 'KOL',
        };
        $orderClass = 'App\\Modules\\Orders\\Infrastructure\\Models\\Order';

        do {
            $number = $prefix.now()->format('Ymd').Str::upper(Str::random(4)).'007';
        } while ($orderClass::query()->where('external_order_number', $number)->exists());

        return $number;
    }

    private function resolveInboundProductFields(array $item, ?string $wooReference = null, ?string $connectionKey = null): array
    {
        $product = null;
        $remoteId = $item['woocommerce_product_id'] ?? null;

        if (! empty($remoteId)) {
            // Step 1: connection-scoped external reference lookup (M1.4 authority).
            if ($connectionKey !== null) {
                $product = $this->productExternalRefs->findProduct('woocommerce', $connectionKey, (string) $remoteId);
            }

            // Step 2: legacy global Woo ID is a bounded compatibility path for the configured primary connection only.
            $primaryConnectionKey = (string) config('services.woocommerce.connection_key', 'beyourlover.com');
            if ($product === null && $connectionKey !== null && $connectionKey === $primaryConnectionKey) {
                $product = $this->productRepository->findByWooId((int) $remoteId);
            }
        }

        $rawSku = trim((string) ($item['sku'] ?? ''));
        if (! $product && $rawSku !== '') {
            // Step 3: exact canonical SKU / SKU Alias resolver.
            $result = $this->skuResolver->resolve($rawSku);
            if ($result->isResolved()) {
                $product = $result->product;
            } else {
                $this->skuResolver->observeUnmatched($rawSku, 'woocommerce_order', $wooReference);
            }
        }

        // Always derive the persisted SKU from the resolved product's canonical SKU.
        $canonicalSku = $product !== null ? (string) $product->getAttribute('sku') : $rawSku;

        $summary = trim((string) ($item['product_summary'] ?? ''));
        if ($summary === '') {
            $summary = $product?->name ? (string) $product->name : ($rawSku !== '' ? $rawSku : 'WooCommerce item');
        }

        return [
            'product_id' => $product?->getKey(),
            'sku' => $canonicalSku,
            'product_summary' => $summary,
        ];
    }

    private function resolveProductFields(array $item): array
    {
        $product = ! empty($item['product_id']) ? $this->productRepository->findById((int) $item['product_id']) : null;

        $sku = $product ? (string) $product->sku : trim((string) ($item['sku'] ?? ''));

        $explicit = trim((string) ($item['product_summary'] ?? ''));
        if ($explicit !== '' && $explicit !== '—') {
            return ['sku' => $sku, 'product_summary' => $explicit];
        }

        if ($product) {
            if (! empty($product->name)) {
                return ['sku' => $sku, 'product_summary' => (string) $product->name];
            }

            return ['sku' => $sku, 'product_summary' => $sku];
        }

        return ['sku' => $sku, 'product_summary' => $sku];
    }

    private function lineRevenue(string|int|float $unitPrice, int $quantity): string
    {
        $parts = explode('.', (string) $unitPrice, 2);
        $whole = (int) ($parts[0] ?? 0);
        $fraction = str_pad(substr($parts[1] ?? '', 0, 2), 2, '0');
        $cents = ($whole * 100) + (int) $fraction;

        return number_format(($cents * $quantity) / 100, 2, '.', '');
    }

    private function inboundAuditData(Model $order): array
    {
        $data = $this->auditData($order);
        $data['currency'] = $order->currency;
        $data['address'] = $order->address?->toArray();
        $data['items'] = $order->items()->get()->toArray();
        $data['external_references'] = $order->externalReferences()->get(['provider', 'connection_key', 'reference', 'provider_modified_at'])->toArray();
        $financials = $order->financials()->first();
        $data['financial_evidence'] = $financials?->only([
            'currency', 'merchandise_list_amount', 'discount_amount', 'shipping_revenue', 'tax_collected',
            'provider_order_total', 'refund_amount', 'refund_tax', 'payment_method', 'payment_transaction_id', 'actual_payment_fee', 'actual_payment_fee_source', 'actual_payment_fee_status', 'paid_at', 'provider',
            'provider_reference', 'provider_order_status', 'provider_modified_at', 'revenue_provenance',
            'reconciliation_status', 'reconciliation_exception',
        ]);

        return $data;
    }

    private function record(?Authenticatable $actor, string $action, Model $order, ?array $before = null, ?array $after = null, ?string $reason = null, AuditSource $source = AuditSource::InternalSystem): void
    {
        $this->audit->record(
            $actor ? AuditActor::user($actor, $source) : AuditActor::system($source),
            $action,
            get_class($order),
            $order->getKey(),
            before: $before,
            after: $after,
            reason: $reason,
        );
    }

    private function auditData(Model $order): array
    {
        return [
            'internal_order_number' => $order->internal_order_number,
            'erp_order_id' => $order->erp_order_id,
            'external_order_number' => $order->external_order_number,
            'source_type' => $order->source_type instanceof \BackedEnum ? $order->source_type->value : $order->source_type,
            'order_kind' => $order->order_kind instanceof \BackedEnum ? $order->order_kind->value : $order->order_kind,
            'order_status' => $order->order_status instanceof \BackedEnum ? $order->order_status->value : $order->order_status,
            'owner_id' => $order->owner_id,
            'currency' => $order->currency,
        ];
    }
}

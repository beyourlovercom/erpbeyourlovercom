<?php

namespace App\Modules\Orders\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Products\Application\Contracts\ProductRepository;
use App\Modules\Orders\Application\Inbound\NormalizedInboundOrder;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

final class OrderService
{
    private const ADDRESS_FIELDS = [
        'recipient_name', 'phone', 'country', 'province_state', 'city',
        'address_line_1', 'address_line_2', 'postal_code',
    ];

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly ProductRepository $productRepository,
    ) {}

    public function create(array $attributes, array $items, ?Authenticatable $actor): Model
    {
        return DB::transaction(function () use ($attributes, $items, $actor): Model {
            $orderClass = 'App\\Modules\\Orders\\Infrastructure\\Models\\Order';
            $address = array_intersect_key($attributes, array_flip(self::ADDRESS_FIELDS));
            $orderAttributes = array_diff_key($attributes, $address);
            $order = $orderClass::create([
                ...$orderAttributes,
                'external_order_number' => trim((string) $attributes['external_order_number']),
                'internal_order_number' => $this->nextOrderNumber($attributes['order_kind']),
                'order_status' => OrderStatus::PendingShipment,
                'owner_id' => $orderAttributes['owner_id'] ?? $actor?->getAuthIdentifier(),
            ]);
            $order->address()->create($address);

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
            if ($reference) {
                $order = $orderClass::query()->whereKey($reference->order_id)->lockForUpdate()->firstOrFail();
                $before = $this->inboundAuditData($order);
                $localStatus = $order->order_status instanceof \BackedEnum ? $order->order_status->value : (string) $order->order_status;
                $nextStatus = $localStatus;
                if ($localStatus === OrderStatus::PendingShipment->value && $data->orderStatus === OrderStatus::Cancelled->value) {
                    $nextStatus = OrderStatus::Cancelled->value;
                }
                $order->fill([
                    'source_type' => OrderSourceType::WooCommerce->value,
                    'order_kind' => OrderKind::Customer->value,
                    'external_order_number' => $data->externalOrderNumber,
                    'order_date' => $data->orderDate,
                    'order_note' => $data->orderNote,
                    'currency' => $data->currency,
                    'order_status' => $nextStatus,
                ])->save();
            } else {
                $order = $orderClass::create([
                    'internal_order_number' => $this->nextOrderNumber(OrderKind::Customer->value),
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

            $order->address()->updateOrCreate(['order_id' => $order->getKey()], $data->address);
            $order->items()->delete();
            foreach ($data->items as $item) {
                $resolved = $this->resolveInboundProductFields($item);
                $order->items()->create([
                    'product_id' => $resolved['product_id'],
                    'sku' => $resolved['sku'],
                    'product_summary' => $resolved['product_summary'],
                    'quantity' => (int) $item['quantity'],
                    'unit_price' => (string) $item['unit_price'],
                    'line_revenue' => $this->lineRevenue((string) $item['unit_price'], (int) $item['quantity']),
                ]);
            }
            $reference->forceFill(['provider_modified_at' => $data->providerModifiedAt])->save();
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

    public function update(Model $order, array $attributes, ?array $items, ?Authenticatable $actor): Model
    {
        return DB::transaction(function () use ($order, $attributes, $items, $actor): Model {
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
                $lockedOrder->items()->delete();
                foreach ($items as $item) {
                    $resolved = $this->resolveProductFields($item);
                    $lockedOrder->items()->create([
                        ...$item,
                        'sku' => $resolved['sku'],
                        'product_summary' => $resolved['product_summary'],
                        'line_revenue' => $this->lineRevenue($item['unit_price'], (int) $item['quantity']),
                    ]);
                }
            }
            $after = $this->auditData($lockedOrder);
            $after['address'] = $lockedOrder->address?->fresh()?->toArray();
            $after['items'] = $lockedOrder->items()->get()->toArray();
            $this->record($actor, 'order.updated', $lockedOrder, before: $before, after: $after, reason: 'manual_correction');

            return $lockedOrder->fresh(['items', 'owner', 'address']);
        });
    }

    public function list(array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        return $this->filteredQuery($filters)
            ->paginate(min($perPage, 200))
            ->withQueryString();
    }

    public function exportCursor(array $filters): \Illuminate\Support\LazyCollection
    {
        return $this->filteredQuery($filters)->cursor();
    }

    private function filteredQuery(array $filters): \Illuminate\Database\Eloquent\Builder
    {
        $orderClass = 'App\\Modules\\Orders\\Infrastructure\\Models\\Order';

        return $orderClass::query()
            ->with(['items', 'owner', 'address', 'shipment.warehouse'])
            ->when($filters['order_kind'] ?? null, fn ($query, string $kind) => $query->where('order_kind', $kind))
            ->when($filters['warehouse_ownership'] ?? null, fn ($query, string $ownership) => $query->whereHas('shipment.warehouse', fn ($warehouse) => $warehouse->where('ownership_type', $ownership)))
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('internal_order_number', 'like', "%{$search}%")
                        ->orWhereHas('address', fn ($address) => $address->where('recipient_name', 'like', "%{$search}%"))
                        ->orWhere('external_order_number', 'like', "%{$search}%");
                });
            })
            ->when($filters['source_type'] ?? null, fn ($query, string $source) => $query->where('source_type', $source))
            ->when($filters['order_status'] ?? null, fn ($query, string $status) => $query->where('order_status', $status))
            ->latest('order_date')
            ->latest('id');
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

    private function resolveInboundProductFields(array $item): array
    {
        $product = null;
        if (! empty($item['woocommerce_product_id'])) {
            $product = $this->productRepository->findByWooId((int) $item['woocommerce_product_id']);
        }
        $rawSku = trim((string) ($item['sku'] ?? ''));
        if (! $product && $rawSku !== '') {
            $product = $this->productRepository->findBySku($rawSku);
        }

        $summary = trim((string) ($item['product_summary'] ?? ''));
        if ($summary === '') {
            $summary = $product?->name ? (string) $product->name : ($rawSku !== '' ? $rawSku : 'WooCommerce item');
        }

        return [
            'product_id' => $product?->getKey(),
            'sku' => $product?->sku ? (string) $product->sku : $rawSku,
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
            'external_order_number' => $order->external_order_number,
            'source_type' => $order->source_type instanceof \BackedEnum ? $order->source_type->value : $order->source_type,
            'order_kind' => $order->order_kind instanceof \BackedEnum ? $order->order_kind->value : $order->order_kind,
            'order_status' => $order->order_status instanceof \BackedEnum ? $order->order_status->value : $order->order_status,
            'owner_id' => $order->owner_id,
            'currency' => $order->currency,
        ];
    }
}
